<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F387_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/SignedRevocationIntentReadbackCandidateVerifier.php';

use App\Infrastructure\Recovery\SignedRevocationIntentReadbackCandidateVerifier as Verifier;

try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $name)use($dir):array{
        $path=$dir.'/'.$name;
        if(!is_file($path)||is_link($path)||filesize($path)<2
            ||filesize($path)>4096)throw new RuntimeException();
        $value=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException();
        return $value;
    };
    $receipt=$read('intent-readback.json');
    $expected=$read('expected.json');
    $public=$read('public-key.json')['hex']??'';
    $verifier=new Verifier();
    $checks=0;
    $assess=static function(array $rec,string $org,string $inst,
        string $id,string $hash,string $nonce,string $key,
        int $now,string $status)use(&$checks,$verifier):void{
        $result=$verifier->verify($rec,$org,$inst,$id,$hash,$nonce,$key,$now);
        if(($result['reasonCode']??null)!==$status)throw new RuntimeException('Unexpected readback result');
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
        ]as$flag){
            if(($result[$flag]??null)!==false)throw new RuntimeException('Readback elevated privilege');
        }
        $checks++;
    };
    $org=$expected['organization'];
    $inst=$expected['instance'];
    $id=$expected['intent_id'];
    $hash=$expected['session_sha256'];
    $nonce=$expected['nonce'];
    $now=time();
    $confirmed='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING';
    $binding='INTENT_REQUEST_BINDING_DENIED';
    $contract='INTENT_CONTRACT_DENIED';
    $sig='INTENT_SIGNATURE_DENIED';
    $assess($receipt,$org,$inst,$id,$hash,$nonce,$public,$now,$confirmed);
    $assess($receipt,$org,$inst,str_repeat('0',32),$hash,$nonce,$public,$now,$binding);
    $assess($receipt,$org,$inst,$id,str_repeat('0',64),$nonce,$public,$now,$binding);
    $assess($receipt,$org,$inst,$id,$hash,str_repeat('0',64),$public,$now,$binding);
    $assess($receipt,'other_org',$inst,$id,$hash,$nonce,$public,$now,'INTENT_SCOPE_DENIED');
    $assess($receipt,$org,'other_instance',$id,$hash,$nonce,$public,$now,'INTENT_SCOPE_DENIED');
    $assess([...$receipt,'status'=>'local_revoked'],$org,$inst,$id,$hash,$nonce,$public,$now,$contract);
    $assess([...$receipt,'intent_sequence'=>true],$org,$inst,$id,$hash,$nonce,$public,$now,$contract);
    $assess([...$receipt,'intent_sequence'=>$receipt['observed_sequence']+1],
        $org,$inst,$id,$hash,$nonce,$public,$now,$sig);
    $assess([...$receipt,'session_floor'=>$receipt['session_floor']+1],
        $org,$inst,$id,$hash,$nonce,$public,$now,$sig);
    $assess([...$receipt,'signature'=>str_repeat('0',128)],
        $org,$inst,$id,$hash,$nonce,$public,$now,$sig);
    $assess($receipt,$org,$inst,$id,$hash,$nonce,str_repeat('0',64),$now,$sig);
    $assess([...$receipt,'status'=>'pending_local_reconciliation','extra'=>'unsafe'],
        $org,$inst,$id,$hash,$nonce,$public,$now,$contract);
    $assess($receipt,$org,$inst,$id,$hash,$nonce,$public,$now+90,'INTENT_FRESHNESS_DENIED');
    $assess($receipt,$org,$inst,$id,$hash,'invalid',$public,$now,$contract);
    echo "PASS F387 PHP: {$checks} signed intent readback checks prove only pending local reconciliation; all serving and PG-revoked flags FALSE.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F387: independent intent readback PHP signature verification\n");
    exit(1);
}
