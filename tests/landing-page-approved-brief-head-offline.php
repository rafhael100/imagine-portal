<?php
declare(strict_types=1);

// Pure domain test: no official actor, database, write or service is configured.
foreach ([
 'SiteContractSnapshot','PortalSiteContractSource','DenyingPortalSiteContractSource',
 'LandingPageSiteOrderBinding','PortalLandingPageSiteOrderBindingSource',
 'DenyingPortalLandingPageSiteOrderBindingSource',
 'PortalLandingPageHeadServiceWitness','DenyingPortalLandingPageHeadServiceWitness',
 'LandingPageCurrentHeadS2sRequestCandidate','LandingPageCurrentHeadAdmissionCandidate',
 'ApprovedLandingPageBriefHead','CurrentApprovedLandingPageBriefHeadSource',
 'DenyingCurrentApprovedLandingPageBriefHeadSource','LandingPageCurrentApprovedBriefGate',
] as $name) require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/'.$name.'.php';

use App\Domain\SitesIntegration\{SiteContractSnapshot,PortalSiteContractSource,
 LandingPageSiteOrderBinding,PortalLandingPageSiteOrderBindingSource,
 PortalLandingPageHeadServiceWitness,LandingPageCurrentHeadS2sRequestCandidate,
 LandingPageCurrentHeadAdmissionCandidate,ApprovedLandingPageBriefHead,
 CurrentApprovedLandingPageBriefHeadSource,LandingPageCurrentApprovedBriefGate};

$tenant='tenant-landing-A'; $order='order-landing-A';
$site='11111111-1111-4111-8111-111111111111';
$wire=json_encode(['schemaVersion'=>'portal.landing-page-current-head.request.v1']);
$wire=LandingPageCurrentHeadS2sRequestCandidate::decode(
    '{"schemaVersion":"sites.landing-page-current-head.fetch.v1","tenantRef":"tenant-landing-A","orderRef":"order-landing-A","siteRef":"'.$site.'","productCode":"landing_page"}'
);
$request=json_encode(['schemaVersion'=>'sites.landing-page-current-head.fetch.v1',
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,'productCode'=>'landing_page'],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$checks=0;
$ok=static function(bool $assert,string $name)use(&$checks):void{
 if (!$assert) throw new RuntimeException('LANDING APPROVAL TEST FAILURE: '.$name);
 ++$checks;
};
$denied=static function(callable $f,string $name)use($ok):void{
 ob_start();$bad=false;
 try{$f();}catch(RuntimeException $e){
   $bad=$e->getMessage()==='PORTAL_LANDING_PAGE_APPROVED_BRIEF_DENIED'&&$e->getPrevious()===null;
 }
 $output=ob_get_clean();$ok($bad&&$output==='', $name);
};
$peer=new class implements PortalLandingPageHeadServiceWitness {
 public bool $allowed=true; public int $calls=0;
 public function permits(string $service,string $tenant,string $order,string $site):bool{
  ++$this->calls;
  return $this->allowed&&$service==='test-peer'&&$tenant==='tenant-landing-A'&&
   $order==='order-landing-A'&&$site==='11111111-1111-4111-8111-111111111111';
 }
};
$contracts=new class implements PortalSiteContractSource {
 public int $revision=7; public int $calls=0;
 public function findAuthorizedSiteOrder(string $order):?SiteContractSnapshot{
  ++$this->calls;
  return new SiteContractSnapshot('tenant-landing-A','order-landing-A','contract-A',$this->revision,'landing_page');
 }
};
$bindings=new class implements PortalLandingPageSiteOrderBindingSource {
 public bool $present=true;public int $calls=0;
 public function readCurrentBinding(string $tenant,string $order,string $site):?LandingPageSiteOrderBinding{
  ++$this->calls;
  return $this->present?new LandingPageSiteOrderBinding('tenant-landing-A','order-landing-A',
   '11111111-1111-4111-8111-111111111111','contract-A',7,'landing_page'):null;
 }
};
$head=new class implements CurrentApprovedLandingPageBriefHeadSource {
 public int $calls=0;public bool $present=true;public int $version=3;
 public string $tenant='tenant-landing-A'; public string $site='11111111-1111-4111-8111-111111111111';
 public string $contract='contract-A';public int $epoch=2;public string $bytes='{"headline":"Landing exemplo"}';
 public ?Closure $onSecond=null;public bool $throw=false;
 public function readCurrent(string $tenant,string $order,string $site):?ApprovedLandingPageBriefHead {
  ++$this->calls;
  if ($this->onSecond!==null&&$this->calls%2===0)($this->onSecond)();
  if($this->throw)throw new RuntimeException('PRIVATE APPROVAL INFORMATION');
  return $this->present?new ApprovedLandingPageBriefHead(
   $this->tenant,'order-landing-A',$this->site,$this->contract,7,$this->version,
   hash('sha256',$this->bytes),'approval-ref-A',$this->epoch,$this->bytes):null;
 }
};
$admission=new LandingPageCurrentHeadAdmissionCandidate($peer,$contracts,$bindings);
$gate=new LandingPageCurrentApprovedBriefGate($admission,$head);
$read=$gate->inspect($request,'test-peer');
$ok($read->sourceVersion===3&&$read->approvalEpoch===2,'latest approved version metadata read');
$ok(hash_equals(hash('sha256',$read->sourceBytes()),$read->sourceSha256),'original source bytes matched hash');
$ok($head->calls===2&&$peer->calls===4&&$contracts->calls===4&&$bindings->calls===4,
 'admission verified twice and current head read twice');
$ok(!property_exists($read,'canPublish')&&!property_exists($read,'paymentAuthorized'),
 'head does not contain operational grants');
$ok(json_encode($read,JSON_PARTIAL_OUTPUT_ON_ERROR)!==false,'explicit json guard exists');
try{json_encode($read,JSON_THROW_ON_ERROR);$failed=false;}catch(Throwable){$failed=true;}
$ok($failed,'JSON serialization of private source is forbidden');
try{serialize($read);$failed=false;}catch(Throwable){$failed=true;}
$ok($failed,'PHP serialization of private source is forbidden');
$ok(!str_contains(print_r($read,true),'Landing exemplo'),'debug output redacts briefing');
$denied(static fn()=>(new LandingPageCurrentApprovedBriefGate())->inspect($request,'test-peer'),
 'default providers deny');
$reset=static function()use($peer,$contracts,$bindings,$head):void{
 $peer->allowed=true;$peer->calls=0;
 $contracts->revision=7;$contracts->calls=0;
 $bindings->present=true;$bindings->calls=0;
 $head->calls=0;$head->present=true;$head->version=3;
 $head->tenant='tenant-landing-A';$head->site='11111111-1111-4111-8111-111111111111';
 $head->contract='contract-A';$head->epoch=2;$head->bytes='{"headline":"Landing exemplo"}';
 $head->onSecond=null;$head->throw=false;
};
$reset();$peer->allowed=false;
$denied(static fn()=>$gate->inspect($request,'test-peer'),'revoked service');
$ok($head->calls===0,'service rejected before reading briefing');
$reset();$bindings->present=false;
$denied(static fn()=>$gate->inspect($request,'test-peer'),'revoked site binding');
$ok($head->calls===0,'site rejected before reading briefing');
$reset();$head->present=false;
$denied(static fn()=>$gate->inspect($request,'test-peer'),'approval removed');
$reset();$head->tenant='tenant-landing-B';
$denied(static fn()=>$gate->inspect($request,'test-peer'),'cross tenant head');
$reset();$head->site='22222222-2222-4222-8222-222222222222';
$denied(static fn()=>$gate->inspect($request,'test-peer'),'foreign site head');
$reset();$head->contract='contract-B';
$denied(static fn()=>$gate->inspect($request,'test-peer'),'foreign contract head');
$reset();$head->onSecond=static function()use($head):void{$head->present=false;};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'approval revoked between reads');
$reset();$head->onSecond=static function()use($head):void{$head->version=4;};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'version advanced between reads');
$reset();$head->onSecond=static function()use($head):void{$head->epoch=3;};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'approval epoch changed');
$reset();$head->onSecond=static function()use($head):void{$head->bytes='{"headline":"Alterado"}';};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'snapshot changed');
$reset();$head->throw=true;
$denied(static fn()=>$gate->inspect($request,'test-peer'),'private provider failure redacted');
$reset();$contracts->revision=8;
$denied(static fn()=>$gate->inspect($request,'test-peer'),'contract revision changed');
$reset();$head->onSecond=static function()use($peer):void{$peer->allowed=false;};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'service revoked between source reads');
$reset();$head->onSecond=static function()use($bindings):void{$bindings->present=false;};
$denied(static fn()=>$gate->inspect($request,'test-peer'),'site revoked between source reads');
$reset();
foreach([
 ['empty','',hash('sha256','')],
 ['wrong digest','{"x":1}',str_repeat('0',64)],
 ['not object','[1]',hash('sha256','[1]')],
 ['invalid JSON','{',hash('sha256','{')],
 ['overlimit',str_repeat('a',150001),hash('sha256',str_repeat('a',150001))],
] as [$label,$bytes,$digest]){
 $bad=false;
 try {new ApprovedLandingPageBriefHead($tenant,$order,$site,'contract-A',7,3,$digest,'approval-A',2,$bytes);}
 catch(InvalidArgumentException){$bad=true;}
 $ok($bad,$label.' rejected by value constructor');
}
$read2=$gate->inspect($request,'test-peer');
$ok($read2->sourceSha256===$read->sourceSha256&&$head->calls===2,
 'legitimate replay revalidates custody and preserves source digest');
echo 'PORTAL LANDING APPROVED HEAD PASS: '.$checks.
 ' checks; synthetic source only, NO official ledger, S2S, release, payment or publication.'."\n";
