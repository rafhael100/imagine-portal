<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F395_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/SignedRevocationRetryLeaseCandidateVerifier.php';
use App\Infrastructure\Recovery\SignedRevocationRetryLeaseCandidateVerifier as Verifier;

try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):array{
        $p=$dir.'/'.$name;
        if(!is_file($p)||is_link($p)||filesize($p)<2||filesize($p)>4096){
            throw new RuntimeException();
        }
        $value=json_decode((string)file_get_contents($p),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException();
        return $value;
    };
    $receipt=$read('receipt.json');
    $expected=$read('expected.json');
    $public=$read('public.json')['hex']??'';
    $verifier=new Verifier();
    $count=0;
    $org=$expected['organization'];$inst=$expected['instance'];
    $id=$expected['intent_id'];$digest=$expected['session_sha256'];
    $lease=$expected['lease_sha256'];$nonce=$expected['nonce'];
    $now=$expected['at'];
    $assess=static function(array $r,string $o,string $i,string $intent,
        string $hash,string $leaseHash,string $challenge,string $pk,
        int $at,string $reason)use($verifier,&$count):void{
        $out=$verifier->verify($r,$o,$i,$intent,$hash,$leaseHash,$challenge,$pk,$at);
        if(($out['reasonCode']??null)!==$reason){
            throw new RuntimeException('F395 wrong signed lease verdict');
        }
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed',
        ]as$flag){
            if(($out[$flag]??null)!==false)throw new RuntimeException('F395 accidental serving');
        }
        $count++;
    };
    $good='LEASE_RECORDED_NOT_ACK_OR_SERVING';
    $binding='LEASE_BINDING_DENIED';
    $contract='LEASE_CONTRACT_DENIED';
    $sig='LEASE_SIGNATURE_DENIED';
    $assess($receipt,$org,$inst,$id,$digest,$lease,$nonce,$public,$now,$good);
    $assess($receipt,$org,$inst,str_repeat('0',32),$digest,$lease,$nonce,$public,$now,$binding);
    $assess($receipt,$org,$inst,$id,str_repeat('0',64),$lease,$nonce,$public,$now,$binding);
    $assess($receipt,$org,$inst,$id,$digest,str_repeat('0',64),$nonce,$public,$now,$binding);
    $assess($receipt,$org,$inst,$id,$digest,$lease,str_repeat('0',64),$public,$now,$binding);
    $assess($receipt,'other_org',$inst,$id,$digest,$lease,$nonce,$public,$now,'LEASE_SCOPE_DENIED');
    $assess($receipt,$org,'other_instance',$id,$digest,$lease,$nonce,$public,$now,'LEASE_SCOPE_DENIED');
    $assess($receipt,$org,$inst,$id,$digest,$lease,$nonce,str_repeat('0',64),$now,$sig);
    $assess([...$receipt,'signature'=>str_repeat('0',128)],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$sig);
    $assess([...$receipt,'attempt_count'=>$receipt['attempt_count']+1],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$contract);
    $assess([...$receipt,'lease_until'=>$receipt['lease_until']+1],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$contract);
    $assess([...$receipt,'observed_sequence'=>$receipt['observed_sequence']+1],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$sig);
    $assess([...$receipt,'status'=>'queue_complete'],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$contract);
    $assess([...$receipt,'extra'=>'forged'],
        $org,$inst,$id,$digest,$lease,$nonce,$public,$now,$contract);
    $assess($receipt,$org,$inst,$id,$digest,$lease,$nonce,$public,$now+95,'LEASE_FRESHNESS_DENIED');
    $assess($receipt,$org,$inst,$id,$digest,$lease,'invalid',$public,$now,$contract);
    echo "PASS F395 PHP: {$count} real Worker Ed25519 lease checks; all PG revoked/ACK/queue complete and HTTP flags FALSE.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F395: signed lease PHP interoperability\n");
    exit(1);
}
