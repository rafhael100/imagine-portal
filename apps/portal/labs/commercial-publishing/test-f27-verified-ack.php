<?php

declare(strict_types=1);

/**
 * F27 — ponta a ponta LAB: PHP verifica assinatura -> PostgreSQL consome nonce
 * e ACK local atomicamente. Sem HTTP, usuário real, deploy ou Cloudflare.
 */
require_once __DIR__.'/VerifiedDeliveryReceiptConsumerLab.php';

$payloadHash=$argv[1] ?? '';
$treeFile=$argv[2] ?? '';
$treeHash=is_string($treeFile) && is_file($treeFile) ? trim(file_get_contents($treeFile)) : '';
if (!preg_match('/\A[a-f0-9]{64}\z/D', $payloadHash) ||
    !preg_match('/\A[a-f0-9]{64}\z/D', $treeHash)) {
    throw new RuntimeException('F27 requires hashes from a verified Astro build.');
}
$dsn=sprintf('pgsql:host=%s;port=%s;dbname=%s',
    getenv('PGHOST') ?: '127.0.0.1',getenv('PGPORT') ?: '5432',
    getenv('PGDATABASE') ?: 'commercial_e2e_lab');
$pdo=new PDO($dsn,getenv('PGUSER') ?: '',getenv('PGPASSWORD') ?: '',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$org='tenant_bridge_01';
$release='release_bridge_01';
$dispatch='dispatch_bridge_01';
$destination='cloudflare_staging_01';
$keyId='cloudflare_lab_receipt_01';
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$public=sodium_crypto_sign_publickey($keys);
$trust=[$keyId=>$public];
$fakeKeys=sodium_crypto_sign_keypair();
$fakeSecret=sodium_crypto_sign_secretkey($fakeKeys);

$run=static function(PDO $pdo,string $sql,array $params=[]): mixed {
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
};
$pdo->beginTransaction();
try {
    $run($pdo,'INSERT INTO commercial_publishing_lab.releases
        (organization_id,release_id,payload_sha256,editor_actor,requested_at)
        VALUES(?,?,?, ?, clock_timestamp()-interval \'20 minutes\')',
        [$org,$release,$payloadHash,'editor_bridge_01']);
    $run($pdo,'INSERT INTO commercial_publishing_lab.approvals
        (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
        VALUES(?,?,?,?,?,clock_timestamp()-interval \'15 minutes\')',
        [$org,$release,$payloadHash,'editor_bridge_01','approver_bridge_01']);
    $run($pdo,'INSERT INTO commercial_publishing_lab.publish_outbox
        (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
        VALUES(?,?,?,?,clock_timestamp()-interval \'10 minutes\',?)',
        [$org,$release,$payloadHash,'publisher_bridge_01','publish_bridge_01']);
    $run($pdo,'INSERT INTO commercial_publishing_lab.publication_fence
        (organization_id,generation,enabled) VALUES(?,1,true)',[$org]);
    $run($pdo,'INSERT INTO commercial_publishing_lab.release_fence
        (organization_id,release_id,payload_sha256,generation) VALUES(?,?,?,1)',
        [$org,$release,$payloadHash]);
    $run($pdo,'INSERT INTO commercial_publishing_lab.verified_build_targets
        (organization_id,release_id,payload_sha256,tree_sha256,authority_generation,
         destination_id,environment) VALUES(?,?,?,?,1,?,?)',
        [$org,$release,$payloadHash,$treeHash,$destination,'staging']);
    $reserved=$run($pdo,
        'SELECT commercial_publishing_lab.reserve_dispatch_once(?,?,?,?)',
        [$org,$release,$payloadHash,$dispatch])->fetchColumn();
    if (!in_array($reserved,[true,'t','1',1],true)) {
        throw new RuntimeException('F27: failed to reserve synthetic dispatch.');
    }
    $pdo->commit();
} catch(Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    throw $error;
}
$lease=static fn(): int=>(int)$run($pdo,
    'SELECT commercial_publishing_lab.acquire_delivery_lease(?,?,?)',
    [$org,$release,$payloadHash])->fetchColumn();
$nonce=static fn(int $attempt): string=>(string)$run($pdo,
    'SELECT commercial_publishing_lab.issue_delivery_receipt_challenge(?,?,?,?)',
    [$org,$release,$payloadHash,$attempt])->fetchColumn();
$signReceipt=static function(array $claims, ?string $withKey=null) use ($secret,$keyId):array {
    $raw=json_encode($claims,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return [
        'protocol'=>'imagine-commercial-delivery-receipt-v1',
        'keyId'=>$keyId,
        'payloadBase64'=>base64_encode($raw),
        'signatureBase64'=>base64_encode(sodium_crypto_sign_detached(
            "imagine-commercial-delivery-receipt-v1\0".$raw,$withKey??$secret
        ))
    ];
};
$timestamp=static fn(): string=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))
    ->format('Y-m-d\TH:i:s\Z');
$claimsFor=static fn(int $attempt,string $nonce):array=>[
    'schemaVersion'=>'1.0',
    'organizationId'=>$org,'releaseId'=>$release,
    'payloadSha256'=>$payloadHash,'treeSha256'=>$treeHash,
    'dispatchId'=>$dispatch,'generation'=>1,'attempt'=>$attempt,
    'destinationId'=>$destination,'environment'=>'staging',
    'nonce'=>$nonce,'observedAt'=>$timestamp()
];
$consume=static function(array $envelope) use($pdo,$trust,$keyId,$org,$release):array{
    return VerifiedDeliveryReceiptConsumerLab::consume(
        $pdo,$envelope,$trust,$keyId,$org,$release,
        new DateTimeImmutable('now',new DateTimeZone('UTC'))
    );
};
$assertUnconsumed=static function()use($pdo,$run,$org,$release):void{
    $count=(int)$run($pdo,
        'SELECT count(*) FROM commercial_publishing_lab.consumed_delivery_receipts
         WHERE organization_id=? AND release_id=?',
        [$org,$release])->fetchColumn();
    $ack=$run($pdo,
        'SELECT acknowledged_at FROM commercial_publishing_lab.delivery_leases
         WHERE organization_id=? AND release_id=?',
        [$org,$release])->fetchColumn();
    if($count!==0 || $ack!==null)throw new RuntimeException('F27: denied proof changed ACK state.');
};
$rejections=0;
$expectDenied=static function(string $name, array $proof, string $message)
    use($consume,$assertUnconsumed,&$rejections):void{
    try{$consume($proof);}
    catch(Throwable $error){
        if(!str_contains($error->getMessage(),$message)){
            throw new RuntimeException($name.': wrong failure: '.$error->getMessage(),0,$error);
        }
        $assertUnconsumed();
        $rejections++;
        return;
    }
    throw new RuntimeException($name.': incorrect receipt was accepted.');
};

if($lease()!==1)throw new RuntimeException('F27: first lease not acquired.');
$nonce1=$nonce(1);
$first=$claimsFor(1,$nonce1);
$expectDenied('independent witness key required',
    $signReceipt($first,$fakeSecret),'signature verification failed');
$expectDenied('wrong signed build hash',
    $signReceipt([...$first,'treeSha256'=>str_repeat('0',64)]),'scope mismatch: treeSha256');
$expectDenied('wrong signed tenant',
    $signReceipt([...$first,'organizationId'=>'tenant_foreign_01']),
    'scope mismatch: organizationId');
$expectDenied('nonce not issued',
    $signReceipt([...$first,'nonce'=>bin2hex(random_bytes(16))]),
    'scope mismatch: nonce');
$expectDenied('expired observation',
    $signReceipt([...$first,'observedAt'=>(new DateTimeImmutable('-7 minutes',
        new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')]),
    'outside freshness window');

// Simulate a worker timeout and recovery. The first proof was genuinely
// signed by the toy destination, but must now be rejected as stale.
$run($pdo,'UPDATE commercial_publishing_lab.delivery_leases
    SET lease_until=clock_timestamp()-interval \'1 second\'
    WHERE organization_id=? AND release_id=?',[$org,$release]);
if($lease()!==2)throw new RuntimeException('F27: crash retry missing.');
$nonce2=$nonce(2);
if($nonce1===$nonce2)throw new RuntimeException('F27: nonce reused on retry.');
$expectDenied('stale signed attempt after recovery',
    $signReceipt($first),'scope mismatch: attempt');

$second=$claimsFor(2,$nonce2);
$verified=$consume($signReceipt($second));
if($verified['nonce']!==$nonce2 || $verified['attempt']!==2 ||
   $verified['treeSha256']!==$treeHash) {
    throw new RuntimeException('F27: proof consumed but claims differ.');
}
$count=(int)$run($pdo,'SELECT count(*) FROM commercial_publishing_lab.consumed_delivery_receipts
  WHERE organization_id=? AND release_id=?',[$org,$release])->fetchColumn();
$ack=$run($pdo,'SELECT acknowledged_at FROM commercial_publishing_lab.delivery_leases
  WHERE organization_id=? AND release_id=?',[$org,$release])->fetchColumn();
if($count!==1 || $ack===null)throw new RuntimeException('F27: no single atomic ACK.');
try{$consume($signReceipt($second));}
catch(RuntimeException $error){
    if(!str_contains($error->getMessage(),'no unacknowledged challenged delivery')){
        throw $error;
    }
    $rejections++;
}
if((int)$run($pdo,'SELECT count(*) FROM commercial_publishing_lab.consumed_delivery_receipts
  WHERE organization_id=? AND release_id=?',[$org,$release])->fetchColumn()!==1){
    throw new RuntimeException('F27: replay created a second receipt.');
}

// Revocation still wins even for a fresh signature from the toy destination.
$run($pdo,'UPDATE commercial_publishing_lab.publication_fence
  SET enabled=false,generation=generation+1,changed_at=clock_timestamp()
  WHERE organization_id=?',[$org]);
try{$consume($signReceipt($second));}
catch(RuntimeException $error){
    if(!str_contains($error->getMessage(),'authority missing or disabled'))throw $error;
    $rejections++;
}
if($rejections!==8)throw new RuntimeException('F27: insufficient negative cases: '.$rejections);
echo "F27 PHP signature -> locked PostgreSQL -> one local ACK passed: ".
    $rejections." rejected scenarios; no HTTP, no external witness, no deployment.\n";
