<?php
declare(strict_types=1);

require dirname(__DIR__, 3).'/apps/portal/app/Infrastructure/Recovery/IndependentWitnessCandidateVerifier.php';
require dirname(__DIR__, 3).'/apps/portal/app/Infrastructure/Recovery/PortalOnlineWitnessSource.php';
require dirname(__DIR__, 3).'/apps/portal/app/Infrastructure/Recovery/OnlineWitnessFenceCandidate.php';

use App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier as Verifier;
use App\Infrastructure\Recovery\OnlineWitnessFenceCandidate as Fence;
use App\Infrastructure\Recovery\PortalOnlineWitnessSource as Source;

if (!extension_loaded('sodium')) throw new RuntimeException('sodium required');

final class FakeOnlineWitnessSource implements Source
{
    public int $snapshotReads=0;
    public int $witnessReads=0;
    public bool $unavailable=false;

    public function __construct(
        public array $snapshots,
        public array $receipts,
    ) {}

    public function currentPortalEpoch():array
    {
        if($this->unavailable)throw new RuntimeException('sensitive internal provider error');
        $i=$this->snapshotReads++;
        return $this->snapshots[min($i,count($this->snapshots)-1)];
    }

    public function latestSignedWitness():array
    {
        if($this->unavailable)throw new RuntimeException('sensitive provider secret');
        $i=$this->witnessReads++;
        return $this->receipts[min($i,count($this->receipts)-1)];
    }
}

$kp=sodium_crypto_sign_keypair();
$key=sodium_crypto_sign_secretkey($kp);
$public=bin2hex(sodium_crypto_sign_publickey($kp));
$now=1763000000;
$snapshot=[
    'organization'=>'org_f371',
    'instance'=>'instance_f371',
    'generation'=>5,
    'session_revision'=>4,
    'membership_revision'=>7,
    'witnessed_sequence'=>13,
];
$unsigned=[
    'schema'=>Verifier::SCHEMA,
    'organization'=>'org_f371',
    'instance'=>'instance_f371',
    'generation_floor'=>5,
    'session_floor'=>4,
    'membership_floor'=>7,
    'sequence'=>13,
    'issued_at'=>$now,
    'expires_at'=>$now+60,
    'signature'=>'',
];
$sign=static function(array $value)use(&$key):array{
    $value['signature']=bin2hex(sodium_crypto_sign_detached(
        Verifier::canonical($value),$key,
    ));
    return $value;
};
$receipt=$sign($unsigned);
$count=0;
$case=static function(
    array $snapshots,
    array $receipts,
    string $expected,
    bool $callbackExpected,
    bool $return=true,
    bool $unavailable=false,
    ?int $testNow=null
) use(&$count,$now,$public):void {
    $source=new FakeOnlineWitnessSource($snapshots,$receipts);
    $source->unavailable=$unavailable;
    $called=0;
    $fence=new Fence($source,new Verifier(),$public,'org_f371','instance_f371',
        static fn():int=>$testNow ?? $now);
    $response=$fence->inspectReadOnly(static function()use(&$called,$return):bool{
        $called++;
        return $return;
    });
    if($response['status']!==$expected)throw new RuntimeException('F371 unexpected status: '.$response['status']);
    if(($called>0)!==$callbackExpected)throw new RuntimeException('F371 callback reached on denied boundary');
    foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
      'canReissueCredential','canRecoverFromRestore','canDeploy'] as $field){
        if($response[$field]!==false)throw new RuntimeException('F371 authorized forbidden action');
    }
    if($callbackExpected && ($source->snapshotReads!==2||$source->witnessReads!==2)) {
        throw new RuntimeException('F371 must re-read both independent sources after callback');
    }
    $count++;
};
$matched='LAB_READ_OBSERVED_STILL_NO_SERVING';
$denied='WITNESS_PRECHECK_DENIED';
$postDenied='WITNESS_POSTCHECK_DENIED';

$case([$snapshot],[$receipt],$matched,true);
$case([$snapshot],[$receipt],'LAB_READ_DENIED_STILL_NO_SERVING',true,false);
$case([array_replace($snapshot,['witnessed_sequence'=>12])],[$receipt],$denied,false);
$case([array_replace($snapshot,['session_revision'=>3])],[$receipt],$denied,false);
$case([$snapshot],[[...$receipt,'sequence'=>14]],$denied,false);
$case([$snapshot],[[...$receipt,'signature'=>str_repeat('f',128)]],$denied,false);
$case([$snapshot],[[]],$denied,false);
$case([$snapshot],[$receipt],$denied,false,true,true);
$case([$snapshot],[$receipt],$denied,false,true,false,$now+130);
$case([$snapshot],[$receipt],$denied,false,true,false,$now-40);
$case([$snapshot],[$receipt],$postDenied,true,true,false,$now+70);
// Current source changes after the read-only callback: must deny.
$case([$snapshot,[...$snapshot,'witnessed_sequence'=>12]],[$receipt],$postDenied,true);
$case([$snapshot],[$receipt,[...$receipt,'sequence'=>14]],$postDenied,true);
$case([$snapshot],[$receipt,[...$receipt,'signature'=>str_repeat('0',128)]],$postDenied,true);
$case([$snapshot,[...$snapshot,'organization'=>'different']],[$receipt],$postDenied,true);
$case([$snapshot],[...[$receipt],$receipt],$matched,true);
$case([[...$snapshot,'organization'=>'cross_company']],[$receipt],$denied,false);
$case([$snapshot],[[...$receipt,'instance'=>'other']],$denied,false);
$case([[...$snapshot,'session_revision'=>true]],[$receipt],$denied,false);
$case([[...$snapshot,'extra'=>1]],[$receipt],$denied,false);
$case([$snapshot],[[...$receipt,'extra'=>1]],$denied,false);
$case([$snapshot],[[...$receipt,'expires_at'=>0]],$denied,false);
$case([$snapshot],[[]],$denied,false);

$s=new FakeOnlineWitnessSource([$snapshot],[$receipt]);
$fence=new Fence($s,new Verifier(),$public,'org_f371','instance_f371',static fn():int=>$now);
$result=$fence->inspectReadOnly(static function():never{
    throw new RuntimeException('internal sensitive DB/tenant diagnostic');
});
if($result['status']!=='CANDIDATE_READ_UNAVAILABLE'
    ||json_encode($result,JSON_THROW_ON_ERROR)!==json_encode([
       'schema'=>'imagine.portal.f371.online-witness-prepost.v1',
       'status'=>'CANDIDATE_READ_UNAVAILABLE',
       'canAuthorizeHttp'=>false,'canAuthorizeBusiness'=>false,
       'canIssueSession'=>false,'canReissueCredential'=>false,
       'canRecoverFromRestore'=>false,'canDeploy'=>false,
    ],JSON_THROW_ON_ERROR))throw new RuntimeException('F371 leaked a provider diagnostic');
$count++;

$result=$fence->inspectReadOnly(static fn():string=>'true');
if($result['status']!=='CANDIDATE_READ_INVALID')throw new RuntimeException('F371 accepted non-bool read');
$count++;

$invalidKeyFence=new Fence($s,new Verifier(),'wrong','org_f371','instance_f371',static fn():int=>$now);
if($invalidKeyFence->inspectReadOnly(static fn():bool=>true)['status']!==$denied)
    throw new RuntimeException('F371 accepted unpinned key');
$count++;

sodium_memzero($key);
echo "PASS F371: {$count} pre/post signed online witness cases; every HTTP and business action remains denied.\n";
