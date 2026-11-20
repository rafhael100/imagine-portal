<?php
declare(strict_types=1);

// No real client/tenant/approval/mTLS; immutable Sites request encoder only.
require_once __DIR__.'/../.pinned-sites/src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../.pinned-sites/apps/sites/app/Integration/PortalLandingPageHeadRequestCandidate.php';
require_once __DIR__.'/../.pinned-sites/apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';
foreach ([
    'SiteContractSnapshot','PortalSiteContractSource','DenyingPortalSiteContractSource',
    'PortalLandingPageHeadServiceWitness','DenyingPortalLandingPageHeadServiceWitness',
    'LandingPageSiteOrderBinding','PortalLandingPageSiteOrderBindingSource',
    'DenyingPortalLandingPageSiteOrderBindingSource',
    'LandingPageCurrentHeadS2sRequestCandidate','LandingPageCurrentHeadAdmissionCandidate',
] as $name) {
    require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/'.$name.'.php';
}
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\PortalLandingPageHeadServiceWitness;
use App\Domain\SitesIntegration\LandingPageCurrentHeadAdmissionCandidate;
use App\Domain\SitesIntegration\LandingPageSiteOrderBinding;
use App\Domain\SitesIntegration\PortalLandingPageSiteOrderBindingSource;
use App\Integration\PortalLandingPageHeadRequestCandidate as SitesLanding;
use App\Integration\PortalOnepageHeadRequestCandidate as SitesOnepage;

final class LandingAdmissionPeer implements PortalLandingPageHeadServiceWitness
{
    public int $calls = 0;
    public bool $revokeSecond = false;
    public bool $throws = false;
    public function permits(string $peer, string $tenant, string $order, string $site): bool
    {
        ++$this->calls;
        if ($this->throws) throw new RuntimeException('PRIVATE_PEER_SECRET');
        return $peer === 'verified-peer-fixture' &&
            $tenant === 'tenant-landing-A' && $order === 'order-landing-A' &&
            $site === '11111111-1111-4111-8111-111111111111' &&
            !($this->revokeSecond && $this->calls === 2);
    }
}
final class LandingAdmissionOrders implements PortalSiteContractSource
{
    public int $calls = 0;
    public bool $changeSecond = false;
    public bool $throws = false;
    public ?SiteContractSnapshot $first;
    public ?SiteContractSnapshot $second = null;
    public function __construct()
    {
        $this->first = new SiteContractSnapshot(
            'tenant-landing-A', 'order-landing-A','contract-landing-A',4,'landing_page');
    }
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        ++$this->calls;
        if ($this->throws) throw new RuntimeException('PRIVATE_CONTRACT_SECRET');
        return $this->changeSecond && $this->calls === 2 ? $this->second : $this->first;
    }
}
$bindings = new class implements PortalLandingPageSiteOrderBindingSource {
    public int $calls=0;
    public bool $changeSecond=false;
    public bool $throws=false;
    public ?LandingPageSiteOrderBinding $first;
    public ?LandingPageSiteOrderBinding $second=null;
    public function __construct() {
        $this->first = new LandingPageSiteOrderBinding('tenant-landing-A','order-landing-A',
            '11111111-1111-4111-8111-111111111111','contract-landing-A',4,'landing_page');
    }
    public function readCurrentBinding(string $tenant,string $order,string $site): ?LandingPageSiteOrderBinding {
        ++$this->calls;
        if ($this->throws) throw new RuntimeException('PRIVATE_SITE_BINDING_SECRET');
        return $this->changeSecond && $this->calls===2 ? $this->second : $this->first;
    }
};
$count = 0;
$ok = static function(bool $value, string $label) use (&$count): void {
    if (!$value) throw new RuntimeException('LANDING ADMISSION FAILURE: '.$label);
    ++$count;
};
$deny = static function(callable $action, string $label) use ($ok): void {
    ob_start(); $failed = false;
    try { $action(); }
    catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'PORTAL_LANDING_PAGE_HEAD_ADMISSION_DENIED'
            && $e->getPrevious() === null;
    }
    $output = ob_get_clean();
    $ok($failed && $output === '', $label.' fail closed without disclosure');
};
$tenant = 'tenant-landing-A'; $order = 'order-landing-A';
$site = '11111111-1111-4111-8111-111111111111';
$wire = SitesLanding::encode($tenant,$order,$site);
$peer = new LandingAdmissionPeer();
$orders = new LandingAdmissionOrders();
$gate = new LandingPageCurrentHeadAdmissionCandidate($peer,$orders,$bindings);
$reset = static function () use ($peer,$orders,$bindings): void {
    $peer->calls = 0; $peer->revokeSecond = false; $peer->throws = false;
    $orders->calls = 0; $orders->changeSecond = false; $orders->throws = false;
    $orders->first = new SiteContractSnapshot(
        'tenant-landing-A','order-landing-A','contract-landing-A',4,'landing_page');
    $orders->second = null;
    $bindings->calls=0; $bindings->changeSecond=false; $bindings->throws=false;
    $bindings->first=new LandingPageSiteOrderBinding('tenant-landing-A','order-landing-A',
        '11111111-1111-4111-8111-111111111111','contract-landing-A',4,'landing_page');
    $bindings->second=null;
};
$expected = [
    'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
    'contractRef'=>'contract-landing-A','contractRevision'=>4,'productCode'=>'landing_page',
];
$ok($gate->inspect($wire,'verified-peer-fixture') === $expected, 'independent authorized contract projection');
$ok($peer->calls===2 && $orders->calls===2 && $bindings->calls===2,
    'two independent service, contract and site binding reads');
$ok(!array_intersect([
    'approvalRef','sourceBytes','sourceState','canPublish','clientAccepted',
    'authorityVerified','paymentAuthorized','canAcceptRealOrders',
],array_keys($expected)), 'no false commercial or publication authority');
$reset();
$deny(static fn()=>(new LandingPageCurrentHeadAdmissionCandidate())->inspect(
    $wire,'verified-peer-fixture'), 'default-deny adapters');

foreach (['','unverified peer',str_repeat('x',129)] as $candidate) {
    $reset();
    $deny(static fn()=>$gate->inspect($wire,$candidate), 'missing or invalid peer ref');
    $ok($peer->calls===0 && $orders->calls===0 && $bindings->calls===0,
        'no source touched for invalid peer');
}
foreach ([
    SitesOnepage::encode($tenant,$order,$site),
    '{', $wire."\n",
    substr($wire,0,-1).',"canPublish":true}',
    substr($wire,0,-1).',"tenantRef":"tenant-foreign"}',
] as $invalid) {
    $reset();
    $deny(static fn()=>$gate->inspect($invalid,'verified-peer-fixture'),
        'Onepage, malformed or privileged payload');
    $ok($peer->calls===0 && $orders->calls===0 && $bindings->calls===0,
        'bad wire checked before private sources');
}
$reset();
$deny(static fn()=>$gate->inspect($wire,'untrusted-peer'), 'untrusted service');
$ok($peer->calls===1 && $orders->calls===0 && $bindings->calls===0,
    'no contract/site read for unauthorized peer');
$reset();
$deny(static fn()=>$gate->inspect(SitesLanding::encode('tenant-landing-B',$order,$site),
    'verified-peer-fixture'), 'foreign tenant');
$ok($orders->calls===0 && $bindings->calls===0,
    'foreign tenant rejected before contract/site');
foreach ([
    ['wrong tenant','tenant-landing-B',$order,'landing_page'],
    ['wrong order',$tenant,'order-landing-B','landing_page'],
    ['wrong product',$tenant,$order,'onepage'],
] as [$label,$t,$o,$product]) {
    $reset();
    $orders->first = new SiteContractSnapshot($t,$o,'contract-landing-A',4,$product);
    $deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'), $label);
    $ok($orders->calls===1 && $bindings->calls===0,
        $label.' refused before binding read');
}
$reset(); $orders->changeSecond = true;
$orders->second = new SiteContractSnapshot($tenant,$order,'contract-landing-A',5,'landing_page');
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'contract revision changed');
$ok($peer->calls===2 && $orders->calls===2, 'changed contract detected');
$reset(); $orders->changeSecond = true;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'contract withdrawn');
$reset(); $peer->revokeSecond = true;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'service revoked between checks');
$ok($orders->calls===1, 'no second contract read after revocation');
foreach ([
    ['wrong site','tenant-landing-A','order-landing-A',
     '22222222-2222-4222-8222-222222222222','contract-landing-A',4],
    ['foreign tenant','tenant-landing-B','order-landing-A',
     '11111111-1111-4111-8111-111111111111','contract-landing-A',4],
    ['foreign order','tenant-landing-A','order-landing-B',
     '11111111-1111-4111-8111-111111111111','contract-landing-A',4],
    ['foreign contract','tenant-landing-A','order-landing-A',
     '11111111-1111-4111-8111-111111111111','contract-landing-B',4],
    ['stale contract revision','tenant-landing-A','order-landing-A',
     '11111111-1111-4111-8111-111111111111','contract-landing-A',3],
] as [$label,$t,$o,$s,$c,$revision]) {
    $reset();
    $bindings->first=new LandingPageSiteOrderBinding($t,$o,$s,$c,$revision,'landing_page');
    $deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'binding '.$label);
    $ok($peer->calls===1 && $orders->calls===1 && $bindings->calls===1,
        'binding '.$label.' rejected before rereads');
}
$reset(); $bindings->first=null;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'missing binding');
$reset(); $bindings->changeSecond=true;
$bindings->second=new LandingPageSiteOrderBinding($tenant,$order,
    '22222222-2222-4222-8222-222222222222','contract-landing-A',4,'landing_page');
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'swapped site on second read');
$ok($peer->calls===2 && $orders->calls===2 && $bindings->calls===2,
    'binding rechecked after independent service and contract');
$reset(); $bindings->changeSecond=true; $bindings->second=null;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'binding revoked between reads');
$reset(); $bindings->throws=true;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'binding exception redacted');
$reset(); $peer->throws = true;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'peer exception redacted');
$reset(); $orders->throws = true;
$deny(static fn()=>$gate->inspect($wire,'verified-peer-fixture'),'contract exception redacted');
$reset();
$ok($gate->inspect($wire,'verified-peer-fixture')===$expected, 'deterministic replay after denials');
$ok($peer->calls===2 && $orders->calls===2 && $bindings->calls===2,
    'every replay rechecks all independent authorities');
echo 'PORTAL LANDING HEAD ADMISSION PASS: '.$count.
    " checks; synthetic S2S/contract only, no latest approval, mTLS, publishing or payment.\n";
