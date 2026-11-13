<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F375_CI')!=='DISPOSABLE_ONLY')exit(64);

require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/IndependentSignedWitnessTransport.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/PortalOnlineWitnessSource.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/OnlineWitnessFenceCandidate.php';
require dirname(__DIR__,3).'/apps/portal/app/Infrastructure/Recovery/PinnedHttpsSignedWitnessTransportCandidate.php';

use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;
use App\Infrastructure\Recovery\PortalOnlineWitnessSource as Source;
use App\Infrastructure\Recovery\OnlineWitnessFenceCandidate as Fence;
use App\Infrastructure\Recovery\PinnedHttpsSignedWitnessTransportCandidate as HttpsWitness;

$dir=isset($argv[2])?realpath($argv[2]):false;
if(!is_string($dir)||!is_dir($dir))exit(64);
$mode=$argv[1]??'';
$endpoint='https://witness.f375.test.invalid:18443/v1/portal/recovery-witness';
$resolve='witness.f375.test.invalid:18443:127.0.0.1';
$checks=0;
$check=static function(bool $pass)use(&$checks):void{
    if(!$pass)throw new RuntimeException('F375 CI check failed');
    $checks++;
};
$denied=static function(callable $cb)use($check):void{
    try{$cb();}catch(Throwable){$check(true);return;}
    $check(false);
};

if($mode==='prepare' && $argc===3){
    $kp=sodium_crypto_sign_keypair();
    $secret=sodium_crypto_sign_secretkey($kp);
    $public=bin2hex(sodium_crypto_sign_publickey($kp));
    $now=time();
    $receipt=[
        'schema'=>Verifier::SCHEMA,
        'organization'=>'org_f375',
        'instance'=>'instance_f375',
        'generation_floor'=>5,
        'session_floor'=>4,
        'membership_floor'=>7,
        'sequence'=>13,
        'issued_at'=>$now,
        'expires_at'=>$now+60,
        'signature'=>'',
    ];
    $receipt['signature']=bin2hex(sodium_crypto_sign_detached(
        Verifier::canonical($receipt),$secret
    ));
    sodium_memzero($secret);
    file_put_contents($dir.'/receipt.json',json_encode($receipt,JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/ed25519-public.hex',$public);
    file_put_contents($dir.'/scenario',"ok\n");
    chmod($dir.'/receipt.json',0600);
    chmod($dir.'/ed25519-public.hex',0600);
    chmod($dir.'/scenario',0600);
    echo "PASS F375 fixture prepared: synthetic Ed25519 only\n";
    exit(0);
}
if($mode!=='verify'||$argc!==5)exit(64);
try{
    if(!extension_loaded('curl')||!extension_loaded('sodium')){
        throw new RuntimeException('F375 extensions unavailable');
    }
    $pin=$argv[3];
    $ca=$argv[4];
    $public=trim((string)file_get_contents($dir.'/ed25519-public.hex'));
    $transport=new HttpsWitness($endpoint,$pin,$ca,$resolve);
    $snapshot=[
        'organization'=>'org_f375',
        'instance'=>'instance_f375',
        'generation'=>5,
        'session_revision'=>4,
        'membership_revision'=>7,
        'witnessed_sequence'=>13,
    ];
    $source=new class($transport,$snapshot)implements Source {
        public function __construct(
            private HttpsWitness $transport,
            private array $snapshot
        ){}
        public function currentPortalEpoch():array{return $this->snapshot;}
        public function latestSignedWitness():array{return $this->transport->latestSignedWitness();}
    };
    $fence=new Fence($source,new Verifier(),$public,'org_f375','instance_f375');

    $assess=static function(array $result,string $expected)use($check):void{
        $check(($result['status']??null)===$expected);
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                'canReissueCredential','canRecoverFromRestore','canDeploy'] as $field){
            $check(($result[$field]??null)===false);
        }
    };
    $scenario=static function(string $value)use($dir):void{
        file_put_contents($dir.'/scenario',$value."\n");
    };
    $scenario('ok');
    $body=$transport->latestSignedWitness();
    $check($body['schema']===Verifier::SCHEMA && $body['sequence']===13);
    $assess($fence->inspectReadOnly(static fn():bool=>true),
        'LAB_READ_OBSERVED_STILL_NO_SERVING');
    $assess($fence->inspectReadOnly(static fn():bool=>false),
        'LAB_READ_DENIED_STILL_NO_SERVING');
    foreach(['malformed','oversized','wrong-mime','redirect',
             'unavailable','timeout','forged']as$case){
        $scenario($case);
        $assess($fence->inspectReadOnly(static fn():bool=>true),'WITNESS_PRECHECK_DENIED');
    }
    $scenario('ok');
    $otherPin='sha256//'.base64_encode(str_repeat("\0",32));
    $wrongTls=new HttpsWitness($endpoint,$otherPin,$ca,$resolve);
    $denied(static fn()=>$wrongTls->latestSignedWitness());

    foreach([
        'http://witness.f375.test.invalid:18443/v1/portal/recovery-witness',
        'https://127.0.0.1:18443/v1/portal/recovery-witness',
        'https://someone@witness.f375.test.invalid:18443/v1/portal/recovery-witness',
        'https://witness.f375.test.invalid:18443/v1/portal/recovery-witness?tenant=other',
        'https://witness.f375.test.invalid:18443/v1/portal/recovery-witness#fragment',
        'https://witness.f375.test.invalid:18443/other',
        'https://witness.f375.test.invalid:18444/v1/portal/recovery-witness',
    ]as$url){
        $denied(static fn()=>new HttpsWitness($url,$pin,$ca,$resolve));
    }
    $denied(static fn()=>new HttpsWitness($endpoint,'wrong-pin',$ca,$resolve));
    $denied(static fn()=>new HttpsWitness($endpoint,$pin,$ca,'otherhost:18443:127.0.0.1'));
    $requests=file($dir.'/requests.log',FILE_IGNORE_NEW_LINES);
    $ok=count(array_filter($requests,static fn($v)=>$v==='ok'));
    $check($ok>=5);
    echo "PASS F375: $checks HTTPS TLS-SPKI, strict JSON, signed Ed25519, redirect/timeout/tamper and no-serving checks.\n";
}catch(Throwable $e){
    fwrite(STDERR,"FAIL F375: disposable TLS witness verification did not pass\n");
    exit(1);
}
