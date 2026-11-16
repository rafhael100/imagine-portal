<?php
declare(strict_types=1);

/**
 * F352 — actual independent PHP subprocess concurrency against PG18,
 * rather than a sequential promise or an in-memory mocked transaction.
 */
if(getenv('PORTAL_F352_DISPOSABLE')!=='SYNTHETIC_CONCURRENT_ONLY'
 ||getenv('PORTAL_F351_DISPOSABLE')!=='LOCAL_SYNTHETIC_WRITER_ONLY'
 ||getenv('PGHOST')!=='127.0.0.1'
 ||getenv('PGDATABASE')!=='portal_composite_f350_ci'
 ||!function_exists('proc_open'))
    throw new RuntimeException('F352 rejects non-disposable concurrency test');

function ensure(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('F352 concurrent security failure: '.$message);
}
function runBatch(array $cases): array {
    $worker=__DIR__.'/concurrent_writer_worker.php';
    $pipes=[];$children=[];
    foreach($cases as [$role,$key,$variant]){
        $cmd=[PHP_BINARY,$worker,$role,$key,$variant];
        $child=proc_open($cmd,[
            0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
        ],$out);
        ensure(is_resource($child),'spawn real independent PHP process');
        fclose($out[0]);
        $children[]=[$child,$out];
    }
    $results=[];
    foreach($children as [$process,$handles]){
        $stdout=stream_get_contents($handles[1]);
        $stderr=stream_get_contents($handles[2]);
        fclose($handles[1]);fclose($handles[2]);
        $code=proc_close($process);
        ensure(in_array($code,[0,3],true),'no uncontrolled worker error');
        ensure(!str_contains($stdout.$stderr,'example.test')&&
            !str_contains($stdout.$stderr,'PRIVATE-'),
            'no private source information output');
        if($code===0){
            $parsed=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
            ensure(is_array($parsed)&&count($parsed)===3
                &&preg_match('/^[0-9a-f-]{36}$/D',$parsed['draftId']??'')
                &&($parsed['releaseStatus']??null)==='HOLD_TENANT_AUTHN_PSP_AND_HUMAN_REVIEW',
                'synthetic worker output has exact HOLD contract');
            $results[]=['code'=>0,'draftId'=>$parsed['draftId'],
                'replayed'=>$parsed['replayed']];
        }else{
            ensure(str_contains($stderr,'replay mismatch'),'mismatched quote explicitly held');
            $results[]=['code'=>3];
        }
    }
    return $results;
}
$admin=new PDO(
 'pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=4;sslmode=disable',
 'postgres','f350-disposable-admin-only',[
     PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false
 ]);
function recorded(PDO $db,string $role,string $key): array {
    $sql=$db->prepare(<<<'SQL'
SELECT id,server_quote,quote_digest
FROM composite_cart_f350.draft_quote
WHERE tenant_role=:role AND request_hash=:hash
SQL);
    $sql->execute([':role'=>$role,':hash'=>hash('sha256',$key)]);
    return $sql->fetchAll();
}

$a='f350_tenant_a_reader';$b='f350_tenant_b_reader';
$key='f351-f352-same';
$batch=array_fill(0,8,[$a,$key,'reference']);
$rows=runBatch($batch);
ensure(count($rows)===8,'eight separate concurrent calls');
ensure(count(array_filter($rows,fn($r)=>$r['code']===0))===8,
 'all same-payload concurrent calls should succeed or replay');
$ids=array_unique(array_column($rows,'draftId'));
ensure(count($ids)===1,'one exact draft ID across eight processes');
ensure(count(array_filter($rows,fn($r)=>$r['replayed']===false))===1,
 'exactly one process writes, seven replay');
ensure(count(recorded($admin,$a,$key))===1,'unique DB index prevents duplicates');

$foreign=runBatch(array_fill(0,5,[$b,$key,'reference']));
ensure(count(array_unique(array_column($foreign,'draftId')))===1,
 'B has only one separate draft');
ensure($foreign[0]['draftId']!==$rows[0]['draftId'],
 'same request key is isolated per DB role');
ensure(count(recorded($admin,$b,$key))===1,'B own index entry exists');

$mixed=runBatch([
 [$a,'f351-f352-mismatch','reference'],
 [$a,'f351-f352-mismatch','changed']
]);
ensure(count(array_filter($mixed,fn($r)=>$r['code']===0))===1,
 'only one different payload can win');
ensure(count(array_filter($mixed,fn($r)=>$r['code']===3))===1,
 'other changed-payload concurrent replay is held');
$written=recorded($admin,$a,'f351-f352-mismatch');
ensure(count($written)===1,'no second row from a different payload');
$w=json_decode($written[0]['server_quote'],true,512,JSON_THROW_ON_ERROR);
ensure(in_array($w['setup_activation_total_cents']??null,[197000,178000],true),
 'only one independently calculated server quote stored');
ensure(($w['real_charge_enabled']??null)===false
 &&($w['public_checkout_authorized']??null)===false,
 'winner cannot create a payment/cart');

$readerA=new PDO(
 'pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=4;sslmode=disable',
 $a,'f350-disposable-a-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$readerB=new PDO(
 'pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=4;sslmode=disable',
 $b,'f350-disposable-b-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
ensure((int)$readerA->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===2,
 'A can read its two synthetic requests, never B');
ensure((int)$readerB->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===1,
 'B only reads its own request');
$rev=$admin->prepare('UPDATE composite_cart_f350.draft_quote
 SET revoked_at=now() WHERE id=:id');
$rev->execute([':id'=>$rows[0]['draftId']]);
$revoked=runBatch([[$a,$key,'reference']]);
ensure(count($revoked)===1 && $revoked[0]['code']===3,
 'revocation wins over concurrent replay');
ensure((int)$readerA->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===1,
 'old draft hidden after revocation');
ensure((int)$readerB->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===1,
 'B remains unaffected');

echo json_encode([
 'contractVersion'=>'portal-f352-real-pg18-concurrent-composite-drafts-v1',
 'realIndependentPhpProcesses'=>15,
 'sameTenantConcurrentCalls'=>8,
 'sameTenantFreshInserts'=>1,
 'sameTenantIdempotentReplays'=>7,
 'crossTenantSameRequestKeyIsolated'=>true,
 'mismatchedConcurrentQuoteHeld'=>true,
 'revokedRequestReplayHeld'=>true,
 'forceRlsReaderScopePreserved'=>true,
 'customerDataUsed'=>false,
 'realPaymentAuthorized'=>false,
 'releaseStatus'=>'HOLD_AUTHENTICATED_TENANT_WRITER_PSP_AND_HUMAN_GO'
],JSON_THROW_ON_ERROR)."\n";
