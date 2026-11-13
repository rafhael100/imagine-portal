<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3).'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';

use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Gate;

if (!extension_loaded('sodium')) {
    throw new RuntimeException('The F369 Ed25519 test needs sodium');
}

function check(array $assessment, string $reason): void
{
    if (($assessment['reasonCode'] ?? null) !== $reason) {
        throw new RuntimeException('Unexpected F369 result: '.json_encode($assessment));
    }
    foreach (['canAuthorizeHttp','canAuthorizeBusiness','canReactivateSession',
              'canRecoverFromRestore','canDeploy'] as $key) {
        if (($assessment[$key] ?? null) !== false) {
            throw new RuntimeException('F369 unexpectedly authorized '.$key);
        }
    }
}

function sign(array $receipt, string $secret): array
{
    $receipt['signature'] = bin2hex(sodium_crypto_sign_detached(
        Gate::canonical($receipt), $secret
    ));
    return $receipt;
}

$now = 1763000000;
$snapshot = [
    'organization' => 'org_f368',
    'instance' => 'instance_f368',
    'generation' => 5,
    'session_revision' => 3,
    'membership_revision' => 7,
    'witnessed_sequence' => 12,
];
$witness = [
    'schema' => Gate::SCHEMA,
    'organization' => 'org_f368',
    'instance' => 'instance_f368',
    'generation_floor' => 5,
    'session_floor' => 4,
    'membership_floor' => 7,
    'sequence' => 13,
    'issued_at' => $now,
    'expires_at' => $now + 60,
    'signature' => '',
];
$kp = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($kp);
$public = bin2hex(sodium_crypto_sign_publickey($kp));
$proof = sign($witness,$secret);
$gate = new Gate();
$count = 0;
$verify = static function(array $snap,array $receipt,string $key,int $moment,
    string $reason)use($gate,&$count):void{
    check($gate->verify($snap,$receipt,$key,$moment),$reason);
    $count++;
};
$verify($snapshot,$proof,$public,$now,'WITNESS_RESTORED_EPOCH_CONFLICT');

$safeSnapshot = [
    ...$snapshot,
    'session_revision' => 4,
    'witnessed_sequence' => 13,
];
$verify($safeSnapshot,$proof,$public,$now,'WITNESS_MATCHED_STILL_NO_SERVING');
$verify($safeSnapshot,$proof,$public,$now+121,'WITNESS_FRESHNESS_DENIED');
$verify($safeSnapshot,$proof,$public,$now-31,'WITNESS_FRESHNESS_DENIED');
$verify($safeSnapshot,[...$proof,'sequence'=>12],$public,$now,
    'WITNESS_SIGNATURE_DENIED');
$verify($safeSnapshot,[...$proof,'signature'=>str_repeat('0',128)],$public,$now,
    'WITNESS_SIGNATURE_DENIED');
$verify($safeSnapshot,[...$proof,'signature'=>'bad'],$public,$now,
    'WITNESS_CONTRACT_DENIED');
$verify($safeSnapshot,[...$proof,'email'=>'no@example.invalid'],$public,$now,
    'WITNESS_CONTRACT_DENIED');
$verify([...$safeSnapshot,'extra'=>1],$proof,$public,$now,
    'WITNESS_CONTRACT_DENIED');
$verify([...$safeSnapshot,'organization'=>'other_org'],$proof,$public,$now,
    'WITNESS_SCOPE_DENIED');
$verify([...$safeSnapshot,'generation'=>4],$proof,$public,$now,
    'WITNESS_RESTORED_EPOCH_CONFLICT');
$verify([...$safeSnapshot,'membership_revision'=>6],$proof,$public,$now,
    'WITNESS_RESTORED_EPOCH_CONFLICT');
$verify([...$safeSnapshot,'session_revision'=>5],$proof,$public,$now,
    'WITNESS_RESTORED_EPOCH_CONFLICT');
$verify([...$safeSnapshot,'witnessed_sequence'=>14],$proof,$public,$now,
    'WITNESS_RESTORED_EPOCH_CONFLICT');
$verify([...$safeSnapshot,'session_revision'=>true],$proof,$public,$now,
    'WITNESS_CONTRACT_DENIED');
$otherKeypair = sodium_crypto_sign_keypair();
$verify($safeSnapshot,$proof,bin2hex(sodium_crypto_sign_publickey($otherKeypair)),
    $now,'WITNESS_SIGNATURE_DENIED');
$verify($safeSnapshot,$proof,str_repeat('0',64),$now,'WITNESS_SIGNATURE_DENIED');
$verify($safeSnapshot,$proof,'malformed',$now,'WITNESS_CONTRACT_DENIED');
$verify($safeSnapshot,[...$proof,'issued_at'=>$now-121],$public,$now,
    'WITNESS_FRESHNESS_DENIED');
$verify($safeSnapshot,[...$proof,'expires_at'=>$now+121],$public,$now,
    'WITNESS_FRESHNESS_DENIED');
$verify($safeSnapshot,[...$proof,'sequence'=>'13'],$public,$now,
    'WITNESS_CONTRACT_DENIED');
$verify($safeSnapshot,[...$proof,'organization'=>'../../org'],$public,$now,
    'WITNESS_CONTRACT_DENIED');

if ($argc === 3) {
    if (getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
        || getenv('PORTAL_RESTORE_WITNESS_CI') !== 'DISPOSABLE_ONLY') {
        throw new RuntimeException('F369 live PG fixture restricted to disposable CI');
    }
    $read = static function(string $path):array {
        if (is_link($path))throw new RuntimeException('No fixture symlinks');
        $stat=lstat($path);
        if(!is_array($stat)||($stat['mode']&0170000)!==0100000
            ||($stat['mode']&0077)!==0||$stat['size']>4096||$stat['size']<1) {
            throw new RuntimeException('Unsafe witness file');
        }
        $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Invalid witness data');
        return $data;
    };
    $snap=$read($argv[1]);
    $remote=$read($argv[2]);
    if(array_keys($snap)!==array_keys($snapshot)
        ||array_keys($remote)!==[
            'organization','instance','generation_floor','session_floor',
            'membership_floor','sequence'
        ]) {
        throw new RuntimeException('Incorrect live two-cluster receipt shape');
    }
    $liveNow=time();
    $p=[
        'schema'=>Gate::SCHEMA,
        'organization'=>$remote['organization'],
        'instance'=>$remote['instance'],
        'generation_floor'=>$remote['generation_floor'],
        'session_floor'=>$remote['session_floor'],
        'membership_floor'=>$remote['membership_floor'],
        'sequence'=>$remote['sequence'],
        'issued_at'=>$liveNow,
        'expires_at'=>$liveNow+60,
        'signature'=>'',
    ];
    $attested=sign($p,$secret);
    $verify($snap,$attested,$public,$liveNow,'WITNESS_RESTORED_EPOCH_CONFLICT');
    $verify($snap,[...$attested,'session_floor'=>3],$public,$liveNow,
        'WITNESS_SIGNATURE_DENIED');
    echo "PASS F369: signed actual second-PG-cluster epoch denies stale restored Portal snapshot; no serving.\n";
}
sodium_memzero($secret);
echo "PASS F369: $count Ed25519 signature/scope/freshness/epoch checks; always NO SERVING.\n";
