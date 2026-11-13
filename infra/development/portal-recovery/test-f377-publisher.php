<?php
declare(strict_types=1);

/**
 * F377 CI ONLY: the actual Portal PHP writer calls HTTPS localhost, which
 * delegates requests to the actual F376 Cloudflare Worker module using an
 * in-memory SQLite D1 adapter. Never run against a live witness.
 */
if (PHP_SAPI!=='cli'||$argc!==4
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F377_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/PinnedHttpsWitnessPublisherCandidate.php';

use App\Infrastructure\Recovery\PinnedHttpsWitnessPublisherCandidate as Publisher;

try{
    $dir=realpath($argv[1]);
    $pin=$argv[2];
    $ca=$argv[3];
    if(!is_string($dir)||!is_dir($dir)||!is_file($ca))throw new RuntimeException();
    $key=trim((string)file_get_contents($dir.'/publisher-key.b64'));
    $endpoint='https://witness.f377.test.invalid:18444/v1/portal/witness-advance';
    $resolve='witness.f377.test.invalid:18444:127.0.0.1';

    $publisher=new Publisher($endpoint,$pin,'org_f377','instance_f377',$ca,$resolve);
    $checks=0;
    $assert=static function(bool $test)use(&$checks):void{
        if(!$test)throw new RuntimeException('Synthetic F377 invariant failed');
        $checks++;
    };
    $assess=static function(array $result,string $status)use($assert):void{
        $assert(($result['status']??null)===$status);
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                  'canRecoverFromRestore','canDeploy']as$field) {
            $assert(($result[$field]??null)===false);
        }
    };
    $failure=static function(callable $operation)use($assert):void{
        try{$operation();}
        catch(Throwable $error){
            $assert($error->getMessage()==='Independent witness publisher unavailable.');
            return;
        }
        $assert(false);
    };
    $scenario=static function(string $value)use($dir):void{
        file_put_contents($dir.'/scenario',$value."\n");
    };
    $state=static function()use($dir):array{
        $read=json_decode((string)file_get_contents($dir.'/state.json'),true,8,JSON_THROW_ON_ERROR);
        if(!is_array($read))throw new RuntimeException();
        return $read;
    };
    $scenario('ok');

    $assess($publisher->attemptAdvance(0,5,4,7,$key),'PUBLISH_INPUT_DENIED');
    $assess($publisher->attemptAdvance(12,0,4,7,$key),'PUBLISH_INPUT_DENIED');
    $assess($publisher->attemptAdvance(12,5,0,7,$key),'PUBLISH_INPUT_DENIED');
    $assess($publisher->attemptAdvance(12,5,4,0,$key),'PUBLISH_INPUT_DENIED');
    $assess($publisher->attemptAdvance(12,5,4,7,'wrong'),'PUBLISH_INPUT_DENIED');
    $assess($publisher->attemptAdvance(12,5,4,7,str_repeat('A',44)),
        'PUBLISH_INPUT_DENIED');

    $assert((int)$state()['sequence']===12);
    $assess($publisher->attemptAdvance(12,5,4,7,$key),
        'LAB_WITNESS_ADVANCED_STILL_NO_SERVING');
    $assert((int)$state()['sequence']===13);
    $assert((int)$state()['session_floor']===4);
    $assess($publisher->attemptAdvance(12,5,4,7,$key),
        'LAB_WITNESS_CONFLICT_STILL_NO_SERVING');
    $assess($publisher->attemptAdvance(13,5,3,7,$key),
        'LAB_WITNESS_CONFLICT_STILL_NO_SERVING');
    $assert((int)$state()['sequence']===13);
    $assess($publisher->attemptAdvance(13,5,5,7,$key),
        'LAB_WITNESS_ADVANCED_STILL_NO_SERVING');
    $assert((int)$state()['sequence']===14);
    $assert((int)$state()['session_floor']===5);

    $badKey=base64_encode(str_repeat(chr(0),32));
    $failure(static fn()=>$publisher->attemptAdvance(14,5,6,7,$badKey));
    $assert((int)$state()['sequence']===14);

    $badPin='sha256//'.base64_encode(str_repeat(chr(0),32));
    $wrongPin=new Publisher($endpoint,$badPin,'org_f377','instance_f377',$ca,$resolve);
    $failure(static fn()=>$wrongPin->attemptAdvance(14,5,6,7,$key));
    $assert((int)$state()['sequence']===14);

    foreach(['redirect','unavailable','timeout','unexpected-body','huge-headers']as$mode){
        $scenario($mode);
        $failure(static fn()=>$publisher->attemptAdvance(14,5,6,7,$key));
        $assert((int)$state()['sequence']===14);
    }
    $scenario('ok');

    // Endpoint, port and TLS trust cannot be supplied by a browser/user.
    $denyConstruction=static function(string $url, string $pin, ?string $resolveOverride)
        use($ca,$assert):void {
        try {new Publisher($url,$pin,'org_f377','instance_f377',$ca,$resolveOverride);}
        catch(RuntimeException){$assert(true);return;}
        $assert(false);
    };
    foreach([
        'http://witness.f377.test.invalid:18444/v1/portal/witness-advance',
        'https://127.0.0.1:18444/v1/portal/witness-advance',
        'https://guest@witness.f377.test.invalid:18444/v1/portal/witness-advance',
        'https://guest:password@witness.f377.test.invalid:18444/v1/portal/witness-advance',
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance?tenant=other',
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance#fragment',
        'https://witness.f377.test.invalid:18444/other',
        'https://witness.f377.test.invalid:18445/v1/portal/witness-advance',
        'https://witness.f377.test.invalid:18444/v1/portal/witness-advance/',
    ]as$badUrl) {
        $denyConstruction($badUrl,$pin,$resolve);
    }
    $denyConstruction($endpoint,'invalid-pin',$resolve);
    $denyConstruction($endpoint,$pin,'other:18444:127.0.0.1');
    $assert((int)$state()['sequence']===14);

    $trace=file($dir.'/requests.jsonl',FILE_IGNORE_NEW_LINES);
    $assert(is_array($trace)&&count($trace)>=5);
    $first=json_decode($trace[0],true,8,JSON_THROW_ON_ERROR);
    $assert($first['status']===204);
    $assert($first['method']==='POST');
    $assert($first['path']==='/v1/portal/witness-advance');
    $assert(is_string($first['signature'])
        &&preg_match('/\A[a-f0-9]{64}\z/D',$first['signature'])===1);
    $assert((int)$state()['sequence']===14);
    echo "PASS F377: {$checks} real PHP HTTPS -> F376 Worker D1 CAS, TLS pin/HMAC, replay, rollback, outages, no serving.\n";
}catch(Throwable $e){
    fwrite(STDERR,"FAIL F377: disposable HTTPS witness publisher contract did not pass\n");
    exit(1);
}
