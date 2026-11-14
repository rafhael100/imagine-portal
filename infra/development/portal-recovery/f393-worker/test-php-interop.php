<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F393_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/SignedRevocationAckClaimCandidateVerifier.php';

use App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier as Verifier;

try {
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $read=static function(string $file)use($dir):array {
        $path=$dir.'/'.$file;
        if(!is_file($path)||is_link($path)
            ||filesize($path)<2||filesize($path)>4096)throw new RuntimeException();
        $value=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException();
        return $value;
    };
    $receipt=$read('receipt.json');
    $expected=$read('expected.json');
    $pub=$read('public.json')['hex']??'';
    $org=$expected['organization'];
    $instance=$expected['instance'];
    $id=$expected['intent_id'];
    $digest=$expected['session_sha256'];
    $nonce=$expected['nonce'];
    $now=time();
    $verifier=new Verifier();
    $checks=0;
    $assess=static function(
        array $r,string $organization,string $instance,
        string $id,string $digest,string $nonce,string $public,int $at,
        string $reason
    )use($verifier,&$checks):void {
        $v=$verifier->verify(
            $r,$organization,$instance,$id,$digest,$nonce,$public,$at
        );
        if(($v['reasonCode']??null)!==$reason)throw new RuntimeException();
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed',
        ]as$flag) {
            if(($v[$flag]??null)!==false)throw new RuntimeException();
        }
        $checks++;
    };
    $ok='ACK_CLAIM_RECORDED_NOT_POSTGRES_PROOF_NO_SERVING';
    $binding='ACK_REQUEST_BINDING_DENIED';
    $contract='ACK_CONTRACT_DENIED';
    $signature='ACK_SIGNATURE_DENIED';
    $assess($receipt,$org,$instance,$id,$digest,$nonce,$pub,$now,$ok);
    $assess($receipt,$org,$instance,str_repeat('0',32),$digest,$nonce,$pub,$now,$binding);
    $assess($receipt,$org,$instance,$id,str_repeat('0',64),$nonce,$pub,$now,$binding);
    $assess($receipt,$org,$instance,$id,$digest,str_repeat('0',64),$pub,$now,$binding);
    $assess($receipt,'other_org',$instance,$id,$digest,$nonce,$pub,$now,'ACK_SCOPE_DENIED');
    $assess($receipt,$org,'other_instance',$id,$digest,$nonce,$pub,$now,'ACK_SCOPE_DENIED');
    $assess($receipt,$org,$instance,$id,$digest,$nonce,str_repeat('0',64),$now,$signature);
    $assess([...$receipt,'signature'=>str_repeat('0',128)],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$signature);
    $assess([...$receipt,'claimed_at'=>$receipt['claimed_at']+1],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$signature);
    $assess([...$receipt,'observed_sequence'=>$receipt['observed_sequence']+1],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$signature);
    $assess([...$receipt,'status'=>'postgres_revoked_confirmed'],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$contract);
    $assess([...$receipt,'intent_sequence'=>true],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$contract);
    $assess([...$receipt,'intent_sequence'=>$receipt['observed_sequence']+1],
        $org,$instance,$id,$digest,$nonce,$pub,$now,'ACK_WITNESS_SEQUENCE_DENIED');
    $assess([...$receipt,'intent_id'=>'bad'],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$contract);
    $assess([...$receipt,'extra'=>'unsafe'],
        $org,$instance,$id,$digest,$nonce,$pub,$now,$contract);
    $assess($receipt,$org,$instance,$id,$digest,$nonce,$pub,$now+120,'ACK_FRESHNESS_DENIED');
    $assess($receipt,$org,$instance,$id,$digest,'invalid',$pub,$now,$contract);
    echo "PASS F393 PHP: {$checks} signed ACK claim verifications; all Postgres-revoked, queue-complete, HTTP and business authority flags FALSE.\n";
} catch(Throwable) {
    fwrite(STDERR,"FAIL F393: signed ACK-claim cross-language verifier refused\n");
    exit(1);
}
