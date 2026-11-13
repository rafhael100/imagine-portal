<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||$argc!==4
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F377_CI')!=='DISPOSABLE_ONLY')exit(64);

$root=dirname(__DIR__,5);
require $root.'/apps/portal/app/Infrastructure/Recovery/PinnedHttpsWitnessPublisherCandidate.php';
require $root.'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';

use App\Infrastructure\Recovery\PinnedHttpsWitnessPublisherCandidate as Publisher;
use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;

try {
    $folder=realpath($argv[1]);
    $tlsPin=$argv[2];
    $ca=$argv[3];
    if(!is_string($folder)||!is_dir($folder)||!is_file($ca))throw new RuntimeException();
    $key=trim((string)file_get_contents($folder.'/publisher-hmac.b64'));
    $public=trim((string)file_get_contents($folder.'/signing-public.hex'));
    $endpoint='https://witness.f377.test.invalid:18444/v1/portal/witness-advance';
    $resolve='witness.f377.test.invalid:18444:127.0.0.1';
    $publisher=new Publisher($endpoint,$tlsPin,'org_f377','instance_f377',$ca,$resolve);
    $checks=0;
    $check=static function(bool $ok)use(&$checks):void{
        if(!$ok)throw new RuntimeException('F377 protocol assertion failed');
        $checks++;
    };
    $deny=static function(callable $callback)use($check):void{
        try{$callback();}catch(Throwable){$check(true);return;}
        $check(false);
    };
    $assertResult=static function(array $result,string $expected)use($check):void{
        $check(($result['status']??null)===$expected);
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                'canRecoverFromRestore','canDeploy']as$field) {
            $check(($result[$field]??null)===false);
        }
    };

    $assertResult($publisher->attemptAdvance(12,5,4,7,$key),
        'LAB_WITNESS_ADVANCED_STILL_NO_SERVING');
    $assertResult($publisher->attemptAdvance(12,5,4,7,$key),
        'LAB_WITNESS_CONFLICT_STILL_NO_SERVING');
    $assertResult($publisher->attemptAdvance(13,5,5,7,$key),
        'LAB_WITNESS_ADVANCED_STILL_NO_SERVING');
    $assertResult($publisher->attemptAdvance(14,5,4,7,$key),
        'LAB_WITNESS_CONFLICT_STILL_NO_SERVING');

    $assertResult($publisher->attemptAdvance(14,0,5,7,$key),'PUBLISH_INPUT_DENIED');
    $assertResult($publisher->attemptAdvance(0,5,5,7,$key),'PUBLISH_INPUT_DENIED');
    $assertResult($publisher->attemptAdvance(14,5,5,7,'wrong-key'),'PUBLISH_INPUT_DENIED');

    // The HMAC is not stored as an object property and must not appear in
    // debugging/log export. Nor does the publisher retain session state.
    $check(!str_contains(var_export($publisher,true),$key));
    $deny(static fn()=>$publisher->attemptAdvance(
        14,5,6,7,base64_encode(random_bytes(32))
    ));

    $wrongTls=new Publisher(
        $endpoint,'sha256//'.base64_encode(str_repeat("\0",32)),
        'org_f377','instance_f377',$ca,$resolve
    );
    $deny(static fn()=>$wrongTls->attemptAdvance(14,5,6,7,$key));

    $wrongScope=new Publisher($endpoint,$tlsPin,'other_org','instance_f377',$ca,$resolve);
    $deny(static fn()=>$wrongScope->attemptAdvance(14,5,6,7,$key));

    foreach([
        'http://witness.f377.test.invalid:18444/v1/portal/witness-advance',
        'https://127.0.0.1:18444/v1/portal/witness-advance',
        'https://user@witness.f377.test.invalid:18444/v1/portal/witness-advance',
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance?org=other',
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance#fragment',
        'https://witness.f377.test.invalid:18445/v1/portal/witness-advance',
        'https://witness.f377.test.invalid:18444/v1/other',
    ]as$url){
        $deny(static fn()=>new Publisher($url,$tlsPin,'org_f377','instance_f377',$ca,$resolve));
    }

    // Retrieve the independent Worker-generated Ed25519 receipt over the
    // same real, CA/SPKI-validated TLS connection, then verify using F369.
    $curl=curl_init('https://witness.f377.test.invalid:18444/v1/portal/recovery-witness');
    if($curl===false)throw new RuntimeException();
    try{
        if(!curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PINNEDPUBLICKEY=>$tlsPin,
            CURLOPT_CAINFO=>$ca,
            CURLOPT_RESOLVE=>[$resolve],
            CURLOPT_PROXY=>'',
            CURLOPT_NOPROXY=>'*',
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT_MS=>1500,
            CURLOPT_CONNECTTIMEOUT_MS=>650,
        ]))throw new RuntimeException();
        $response=curl_exec($curl);
        if(!is_string($response)||curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)
            throw new RuntimeException();
        $receipt=json_decode($response,true,8,JSON_THROW_ON_ERROR);
    }finally{curl_close($curl);}
    $snapshot=[
        'organization'=>'org_f377','instance'=>'instance_f377',
        'generation'=>5,'session_revision'=>5,
        'membership_revision'=>7,'witnessed_sequence'=>14,
    ];
    $verifier=new Verifier();
    $result=$verifier->verify($snapshot,$receipt,$public,time());
    $check($result['reasonCode']==='WITNESS_MATCHED_STILL_NO_SERVING');
    $check($result['canAuthorizeHttp']===false && $result['canAuthorizeBusiness']===false);
    $snapshot['session_revision']=4;
    $snapshot['witnessed_sequence']=13;
    $check($verifier->verify($snapshot,$receipt,$public,time())['reasonCode']
        ==='WITNESS_RESTORED_EPOCH_CONFLICT');
    $forged=$receipt;
    $forged['sequence']++;
    $check($verifier->verify($snapshot,$forged,$public,time())['reasonCode']
        ==='WITNESS_SIGNATURE_DENIED');

    echo "PASS F377: $checks real TLS HMAC-published Worker SQLite CAS, Ed25519 readback and fail-closed publisher assertions; NO SERVING.\n";
}catch(Throwable $e){
    fwrite(STDERR,"FAIL F377: publisher/witness wire contract failed.\n");
    exit(1);
}
