<?php

declare(strict_types=1);

/** Signed intent cursor v1 Worker-to-PHP interoperability, disposable only. */
if(PHP_SAPI!=='cli'||$argc!==2
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F392_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,4).'/apps/portal/app/Infrastructure/Recovery/SignedRevocationCursorCandidateVerifier.php';

use App\Infrastructure\Recovery\SignedRevocationCursorCandidateVerifier as Verifier;

try{
    $dir=realpath($argv[1]);
    if(!is_string($dir)||!is_dir($dir))throw new RuntimeException();
    $json=static function(string $filename)use($dir):array{
        $path=$dir.'/'.$filename;
        if(!is_file($path)||is_link($path)
            ||filesize($path)<2||filesize($path)>4096)throw new RuntimeException();
        $data=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException();
        return $data;
    };
    $first=$json('first.json');
    $second=$json('second.json');
    $empty=$json('empty.json');
    $expected=$json('expected.json');
    $pub=$json('public.json')['hex']??'';
    $org=$expected['org'];
    $inst=$expected['instance'];
    $verify=new Verifier();
    $checks=0;
    $assess=static function(array $receipt,string $tenant,string $instance,
        int $after,string $nonce,string $key,int $time,string $reason)
        use($verify,&$checks):void {
        $result=$verify->verify($receipt,$tenant,$instance,
            $after,$nonce,$key,$time);
        if(($result['reasonCode']??null)!==$reason)throw new RuntimeException('F392 unexpected verdict');
        foreach([
            'canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
            'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
            'canClaimQueueFullyProcessed',
        ]as$field){
            if(($result[$field]??null)!==false)throw new RuntimeException('F392 serving claim');
        }
        $checks++;
    };
    $now=time();
    $pending='CURSOR_INTENT_PENDING_STILL_NO_SERVING';
    $none='CURSOR_NO_INTENT_NOT_AUTHORITY';
    $contract='CURSOR_CONTRACT_DENIED';
    $binding='CURSOR_REQUEST_BINDING_DENIED';
    $sign='CURSOR_SIGNATURE_DENIED';
    $assess($first,$org,$inst,0,$expected['first_nonce'],$pub,$now,$pending);
    $assess($second,$org,$inst,13,$expected['second_nonce'],$pub,$now,$pending);
    $assess($empty,$org,$inst,14,$expected['empty_nonce'],$pub,$now,$none);
    if($first['intent_id']!==$expected['first_id']
        ||$second['intent_id']!==$expected['second_id']
        ||$first['session_sha256']!==$expected['first_hash']
        ||$second['session_sha256']!==$expected['second_hash'])throw new RuntimeException();

    $assess($first,$org,$inst,13,$expected['first_nonce'],$pub,$now,$binding);
    $assess($first,$org,$inst,0,str_repeat('0',64),$pub,$now,$binding);
    $assess($first,'other_org',$inst,0,$expected['first_nonce'],$pub,$now,
        'CURSOR_SCOPE_DENIED');
    $assess($first,$org,'other_instance',0,$expected['first_nonce'],$pub,$now,
        'CURSOR_SCOPE_DENIED');
    $assess($first,$org,$inst,0,$expected['first_nonce'],str_repeat('0',64),$now,$sign);
    $assess([...$first,'signature'=>str_repeat('0',128)],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$sign);
    $assess([...$first,'intent_sequence'=>14],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$sign);
    $assess([...$first,'session_sha256'=>str_repeat('0',64)],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$sign);
    $assess([...$first,'after_sequence'=>1],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$binding);
    $assess([...$first,'intent_sequence'=>true],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$contract);
    $assess([...$first,'intent_id'=>''],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$contract);
    $assess([...$empty,'intent_id'=>$expected['first_id']],
        $org,$inst,14,$expected['empty_nonce'],$pub,$now,$contract);
    $assess([...$empty,'status'=>'pending_local_reconciliation'],
        $org,$inst,14,$expected['empty_nonce'],$pub,$now,$contract);
    $assess([...$first,'extra'=>'unsafe'],
        $org,$inst,0,$expected['first_nonce'],$pub,$now,$contract);
    $assess($first,$org,$inst,0,$expected['first_nonce'],$pub,$now+90,
        'CURSOR_FRESHNESS_DENIED');
    $assess($first,$org,$inst,0,'bad-nonce',$pub,$now,$contract);
    echo "PASS F392 PHP: {$checks} signed Worker cursor/page/restart controls; neither pending nor empty reply grants serving.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F392: independent signed recovery cursor cross-language verification\n");
    exit(1);
}
