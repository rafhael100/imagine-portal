<?php
declare(strict_types=1);

/** Real PHP curl over disposable localhost HTTPS to the actual F376 v2 Worker. */
if(PHP_SAPI!=='cli'||$argc!==4
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F380_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/PortalLiveRecoveryEpochSourceCandidate.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/ChallengeBoundWitnessCandidateVerifier.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/NonceBoundHttpsWitnessReadCandidate.php';

use App\Infrastructure\Recovery\PortalLiveRecoveryEpochSourceCandidate as LocalSource;
use App\Infrastructure\Recovery\ChallengeBoundWitnessCandidateVerifier as Verifier;
use App\Infrastructure\Recovery\NonceBoundHttpsWitnessReadCandidate as Reader;

final class FakeF380LocalEpoch implements LocalSource
{
    public bool $offline=false;
    public function __construct(public array $snapshot){}
    public function currentEpoch():array{
        if($this->offline)throw new RuntimeException('Simulated SQL source unavailable');
        return $this->snapshot;
    }
}

try{
    $dir=realpath($argv[1]);
    $pin=$argv[2];$ca=$argv[3];
    if(!is_string($dir)||!is_dir($dir)||!is_file($ca))throw new RuntimeException();
    $pub=trim((string)file_get_contents($dir.'/ed-public.hex'));
    $endpoint='https://witness.f380.test.invalid:18446/v2/portal/recovery-witness';
    $resolve='witness.f380.test.invalid:18446:127.0.0.1';
    $snapshot=[
        'organization'=>'org_f380','instance'=>'instance_f380',
        'generation'=>5,'session_revision'=>4,
        'membership_revision'=>7,'witnessed_sequence'=>13,
    ];
    $source=new FakeF380LocalEpoch($snapshot);
    $reader=new Reader($source,new Verifier(),$endpoint,$pin,$pub,
        'org_f380','instance_f380',$ca,$resolve);

    $count=0;
    $check=static function(bool $success)use(&$count):void{
        if(!$success)throw new RuntimeException('F380 synthetic invariant failed');
        $count++;
    };
    $assess=static function(array $result,string $expected)use($check):void{
        $check(($result['status']??null)===$expected);
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                  'canRecoverFromRestore','canDeploy']as$field){
            $check(($result[$field]??null)===false);
        }
    };
    $scenario=static function(string $mode)use($dir):void{
        file_put_contents($dir.'/scenario',$mode."\n");
    };
    $scenario('ok');
    $assess($reader->inspectReadOnly(static fn():bool=>true),
        'LAB_CHALLENGED_READ_STILL_NO_SERVING');
    $assess($reader->inspectReadOnly(static fn():bool=>false),
        'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');
    $assess($reader->inspectReadOnly(static fn():string=>'not-a-boolean'),
        'CANDIDATE_READ_INVALID');
    $assess($reader->inspectReadOnly(static function():never{
        throw new RuntimeException('Simulated private read diagnostic');
    }),'CANDIDATE_READ_UNAVAILABLE');

    // A signed response from a previous request must NOT pass a new nonce.
    $scenario('replay');
    $called=false;
    $assess($reader->inspectReadOnly(static function()use(&$called):bool{
        $called=true;return true;
    }),'WITNESS_PRECHECK_DENIED');
    $check(!$called);
    $scenario('ok');
    $assess($reader->inspectReadOnly(function()use($scenario):bool{
        $scenario('replay');return true;
    }),'WITNESS_POSTCHECK_DENIED');

    foreach(['tamper','unavailable','timeout','redirect','oversized',
              'huge-headers','wrong-mime','legacy','advanced']as$mode){
        $scenario($mode);
        $called=false;
        $assess($reader->inspectReadOnly(static function()use(&$called):bool{
            $called=true;return true;
        }),'WITNESS_PRECHECK_DENIED');
        $check(!$called);
    }
    $scenario('ok');

    // A change in the LOCAL authority between the two checks denies read.
    $assess($reader->inspectReadOnly(static function()use($source):bool{
        $source->snapshot['session_revision']=5;
        return true;
    }),'WITNESS_POSTCHECK_DENIED');
    $source->snapshot=$snapshot;
    $source->offline=true;
    $assess($reader->inspectReadOnly(static fn():bool=>true),
        'WITNESS_PRECHECK_DENIED');
    $source->offline=false;

    $wrongTls='sha256//'.base64_encode(str_repeat(chr(0),32));
    $badTls=new Reader($source,new Verifier(),$endpoint,$wrongTls,$pub,
        'org_f380','instance_f380',$ca,$resolve);
    $assess($badTls->inspectReadOnly(static fn():bool=>true),
        'WITNESS_PRECHECK_DENIED');
    $badEd=new Reader($source,new Verifier(),$endpoint,$pin,str_repeat('0',64),
        'org_f380','instance_f380',$ca,$resolve);
    $assess($badEd->inspectReadOnly(static fn():bool=>true),
        'WITNESS_PRECHECK_DENIED');

    $denyConstruction=static function(string $url,?string $over)use(
        $source,$pin,$pub,$ca,$check
    ):void{
        try {
            new Reader($source,new Verifier(),$url,$pin,$pub,
                'org_f380','instance_f380',$ca,$over);
        }catch(RuntimeException){$check(true);return;}
        $check(false);
    };
    foreach([
        'http://witness.f380.test.invalid:18446/v2/portal/recovery-witness',
        'https://127.0.0.1:18446/v2/portal/recovery-witness',
        'https://guest@witness.f380.test.invalid:18446/v2/portal/recovery-witness',
        'https://guest:secret@witness.f380.test.invalid:18446/v2/portal/recovery-witness',
        'https://witness.f380.test.invalid:18446/v2/portal/recovery-witness?tenant=other',
        'https://witness.f380.test.invalid:18446/v2/portal/recovery-witness#fragment',
        'https://witness.f380.test.invalid:18446/v1/portal/recovery-witness',
        'https://witness.f380.test.invalid:18447/v2/portal/recovery-witness',
    ]as$url){
        $denyConstruction($url,$resolve);
    }
    $denyConstruction($endpoint,'witness.bad:18446:127.0.0.1');

    $log=file($dir.'/requests.jsonl',FILE_IGNORE_NEW_LINES);
    $check(is_array($log)&&count($log)>=20);
    $nonces=[];
    foreach($log as $line){
        $item=json_decode($line,true,8,JSON_THROW_ON_ERROR);
        $check(is_string($item['nonce'])
            &&preg_match('/\A[a-f0-9]{64}\z/D',$item['nonce'])===1);
        $nonces[]=$item['nonce'];
    }
    $check(count($nonces)===count(array_unique($nonces)));
    echo "PASS F380: {$count} HTTPS signed nonce fresh every read, replay/epoch/role/transport refusals; NO SERVING.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F380: live challenged HTTPS witness candidate did not pass\n");
    exit(1);
}
