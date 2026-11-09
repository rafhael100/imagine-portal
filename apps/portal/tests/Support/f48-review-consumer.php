<?php

declare(strict_types=1);

/** F1-48: standalone PHP fixture test. No Composer, HTTP, DB or secrets. */
if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only');
spl_autoload_register(static function(string $class):void{
    $prefix='App\\Domain\\SitesIntegration\\';
    if (!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe fixture class path');
    }
    require __DIR__.'/../../app/Domain/SitesIntegration/'.$part.'.php';
});
use App\Domain\SitesIntegration\{
    SyntheticSitesReviewHandoffConsumer as Consumer,
    ReviewHandoffAuthoritySource as Source,
    ReviewHandoffAuthoritySnapshot as Snapshot,
    ReviewHandoffNonceFence as Fence
};

final class F48FixtureSource implements Source
{
    public ?Snapshot $snapshot=null;
    public int $reads=0;
    public function currentFor(string $tenantRef,string $siteRef,string $workflowRef):?Snapshot
    {
        ++$this->reads;
        return $this->snapshot;
    }
}
final class F48FixtureFence implements Fence
{
    /** @var array<string,bool> */
    public array $seen=[];
    public function consumeOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}
$checks=0;
function f48Check(bool $value,string $message):void{
    global $checks;
    if(!$value)throw new RuntimeException('FAIL '.$message);
    ++$checks;echo 'PASS '.$message."\n";
}
function f48Denied(callable $call):bool{
    try{$call();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
function f48Sign(array $wire,string $key):array{
    $unsigned=array_slice($wire,0,-1,true);
    $wire['mac']=hash_hmac('sha256',json_encode($unsigned,
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$key);
    return $wire;
}

$key='F48-PUBLIC-FICTIONAL-REVIEW-WIRE-SIGNING-KEY-0000000000000';
$now=1790000000;
$c=[
  'schemaVersion'=>'sites.synthetic.review-candidate.v1',
  'tenantRef'=>'tenant-f48','siteRef'=>'site-f48','workflowRef'=>'wf-f48',
  'workflowRevision'=>3,'releaseRef'=>'release-f48',
  'artifactDigest'=>str_repeat('a',64),
  'portalAuthorityRequired'=>true,'officialClientAcceptance'=>false,'publishable'=>false
];
$wire=f48Sign([
 'schemaVersion'=>'sites.synthetic.review-handoff.v1',
 'issuer'=>'imagine-sites','audience'=>'imagine-portal',
 'issuedAt'=>$now,'expiresAt'=>$now+60,'nonce'=>'f48-nonce-test-000001',
 'candidate'=>$c,'mac'=>'',
],$key);
$src=new F48FixtureSource();$fence=new F48FixtureFence();
$src->snapshot=new Snapshot(
 'tenant-f48','site-f48','wf-f48',3,'release-f48',str_repeat('a',64),4,true
);
$consumer=new Consumer($src,$fence);
f48Check(f48Denied(fn()=>(new Consumer())->inspect($wire,$key,$now)),
 'default source and nonce fence deny with signed wire');
f48Check($consumer->inspect($wire,$key,$now)===$c &&
    count($fence->seen)===1,'mock current Portal source accepts exact proposal once');
f48Check(f48Denied(fn()=>$consumer->inspect($wire,$key,$now)),
 'second attempt denied by consumed nonce even with current authority');

$baseSource=new F48FixtureSource();$baseFence=new F48FixtureFence();
$test=new Consumer($baseSource,$baseFence);
f48Check(f48Denied(fn()=>$test->inspect($wire,$key,$now)) &&
    $baseFence->seen===[], 'missing official snapshot cannot consume nonce');
$baseSource->snapshot=new Snapshot(
 'tenant-f48','site-f48','wf-f48',3,'release-f48',str_repeat('a',64),4,false
);
f48Check(f48Denied(fn()=>$test->inspect($wire,$key,$now)) &&
    $baseFence->seen===[], 'revoked source cannot consume valid nonce');
$baseSource->snapshot=new Snapshot(
 'tenant-f48','site-f48','wf-f48',3,'release-f48',str_repeat('a',64),4,true
);
f48Check($test->inspect($wire,$key,$now)===$c,
 'nonce remains available after revocation and snapshot outage');

$badSnapshots=[
 ['tenant-b','site-f48','wf-f48',3,'release-f48',str_repeat('a',64),4,true],
 ['tenant-f48','site-b','wf-f48',3,'release-f48',str_repeat('a',64),4,true],
 ['tenant-f48','site-f48','wf-b',3,'release-f48',str_repeat('a',64),4,true],
 ['tenant-f48','site-f48','wf-f48',2,'release-f48',str_repeat('a',64),4,true],
 ['tenant-f48','site-f48','wf-f48',3,'release-b',str_repeat('a',64),4,true],
 ['tenant-f48','site-f48','wf-f48',3,'release-f48',str_repeat('b',64),4,true],
];
foreach($badSnapshots as $i=>$args){
  $src=new F48FixtureSource();$src->snapshot=new Snapshot(...$args);
  $fence=new F48FixtureFence();
  f48Check(f48Denied(fn()=>(new Consumer($src,$fence))->inspect($wire,$key,$now))
    && $fence->seen===[],'mismatched trusted snapshot denied '.$i);
}
$mutations=[
 ['issuer'=>'imagine-portal'],['audience'=>'imagine-sites'],
 ['expiresAt'=>$now+121],['nonce'=>'short']
];
foreach($mutations as $i=>$patch){
  $bad=array_replace($wire,$patch);$src=new F48FixtureSource();
  f48Check(f48Denied(fn()=>(new Consumer($src,new F48FixtureFence()))
    ->inspect($bad,$key,$now)),'invalid signed envelope denied '.$i);
}
$wrong=$wire;$wrong['candidate']['publishable']=true;
f48Check(f48Denied(fn()=>$consumer->inspect(f48Sign($wrong,$key),$key,$now)),
 'signed publishable=true forged by test key still denied');
$wrong=$wire;$wrong['candidate']['officialClientAcceptance']=true;
f48Check(f48Denied(fn()=>$consumer->inspect(f48Sign($wrong,$key),$key,$now)),
 'signed official acceptance forged by test key still denied');
$wrong=$wire;$wrong['candidate']['workflowRevision']='3';
f48Check(f48Denied(fn()=>$consumer->inspect(f48Sign($wrong,$key),$key,$now)),
 'type-confused revision denied');
$wrong=$wire;$wrong['candidate']['approvalToken']='spoof';
f48Check(f48Denied(fn()=>$consumer->inspect(f48Sign($wrong,$key),$key,$now)),
 'extra authority field denied');
f48Check(f48Denied(fn()=>$consumer->inspect($wire,$key,$now+60)),
 'expiration boundary denied');
f48Check(f48Denied(fn()=>$consumer->inspect($wire,'short',$now)),
 'weak lab key denied');
echo "\n$checks Portal F1-48 synthetic review consumer checks PASS. NO client acceptance.\n";
