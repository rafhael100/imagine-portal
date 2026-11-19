<?php

declare(strict_types=1);

// Candidate-only, synthetic Portal sources and independent pinned Sites parser.
// No official Portal approval store, mTLS server, client or external network.
$root = dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration';
foreach ([
    'PortalSiteContractSource', 'SiteContractSnapshot',
    'DenyingPortalSiteContractSource',
    'ApprovedOnepageBriefSnapshot', 'ApprovedOnepageBriefSnapshotSource',
    'DenyingApprovedOnepageBriefSnapshotSource',
    'CurrentOnepageBriefApprovalWitness', 'DenyingCurrentOnepageBriefApprovalWitness',
    'OnepageBriefSnapshotCandidateEncoder',
    'CurrentApprovedOnepageHeadSource', 'DenyingCurrentApprovedOnepageHeadSource',
    'PortalOnepageHeadServiceWitness', 'DenyingPortalOnepageHeadServiceWitness',
    'OnepageCurrentHeadS2sResponseCandidate',
] as $name) require_once $root.'/'.$name.'.php';
$sites = dirname(__DIR__).'/.pinned-sites';
foreach ([
    '/src/Production/BriefCandidateJson.php',
    '/apps/sites/app/Integration/CurrentPortalOnepageHeadSource.php',
    '/apps/sites/app/Integration/PortalOnepageHeadMtlsHttpSource.php',
] as $path) require_once $sites.$path;

use App\Domain\SitesIntegration\{
    SiteContractSnapshot, PortalSiteContractSource,
    ApprovedOnepageBriefSnapshot, ApprovedOnepageBriefSnapshotSource,
    CurrentApprovedOnepageHeadSource,
    CurrentOnepageBriefApprovalWitness, PortalOnepageHeadServiceWitness,
    OnepageCurrentHeadS2sResponseCandidate
};
use App\Integration\PortalOnepageHeadMtlsHttpSource;

$checks = 0;
$ok = static function(bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('CURRENT HEAD RESPONSE CONTRACT FAILED: '.$label);
    ++$checks;
};
$deny = static function(callable $fn,string $label) use ($ok): void {
    ob_start();
    $failed = false;
    try { $fn(); }
    catch (RuntimeException $error) {
        $failed=$error->getMessage()==='PORTAL_ONEPAGE_CURRENT_HEAD_DENIED' &&
            $error->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($failed && $output==='', $label.' fails closed');
};
$tenant = 'tenant-head-lab-A';
$order = 'order-head-lab-A';
$site = '11111111-1111-4111-8111-111111111111';
$contract = new SiteContractSnapshot($tenant,$order,'contract-head-lab',4,'onepage');
$selection = [
    'siteRef' => $site,
    'snapshotRef' => '22222222-2222-4222-8222-222222222222',
    'operationId' => 'head-current-operation-001',
    'idempotencyKey' => 'head-current-operation-key',
    'sourceVersion' => 6,
    'expectedBriefRevision' => 2,
    'payloadRef' => 'head-current-payload-001',
];
$source = json_encode([
    'company_name'=>'Clínica Exemplo',
    'company_intro'=>'Conteúdo inteiramente fictício da revisão seis.',
    'services'=>'Serviço de laboratório',
    'current_site'=>'', 'contact_site'=>'https://example.invalid/',
    'domain'=>'example.invalid', 'email'=>'team@example.invalid',
    'phone'=>'', 'address'=>'', 'whatsapp'=>'', 'notes'=>'Nenhum cliente real',
    'ai_support'=>false, 'logo_unavailable'=>true, 'manual_unavailable'=>true,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$head = new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'head-approval-lab-001',3,
);
$service = new class implements PortalOnepageHeadServiceWitness {
    public int $calls=0;
    public bool $enabled=true;
    public bool $revokeSecond=false;
    public function permits(string $service,string $tenant,string $order,string $site): bool {
        ++$this->calls;
        return $this->enabled && (!$this->revokeSecond || $this->calls===1) &&
            $service==='test-only-authorized-s2s' && $tenant==='tenant-head-lab-A' &&
            $order==='order-head-lab-A' &&
            $site==='11111111-1111-4111-8111-111111111111';
    }
};
$contracts = new class($contract) implements PortalSiteContractSource {
    public int $calls=0;
    public ?SiteContractSnapshot $next=null;
    public bool $deny=false;
    public function __construct(public SiteContractSnapshot $value) {}
    public function findAuthorizedSiteOrder(string $order): ?SiteContractSnapshot {
        ++$this->calls;
        return $this->deny ? null : ($this->calls>1 && $this->next ? $this->next : $this->value);
    }
};
$current = new class($head) implements CurrentApprovedOnepageHeadSource {
    public int $calls=0;
    public bool $deny=false;
    public ?ApprovedOnepageBriefSnapshot $next=null;
    public function __construct(public ApprovedOnepageBriefSnapshot $value) {}
    public function readCurrent(string $tenant,string $order,string $site): ?ApprovedOnepageBriefSnapshot {
        ++$this->calls;
        return $this->deny ? null : ($this->calls>1 && $this->next ? $this->next : $this->value);
    }
};
$exact = new class($head) implements ApprovedOnepageBriefSnapshotSource {
    public int $calls=0;
    public bool $deny=false;
    public ?ApprovedOnepageBriefSnapshot $next=null;
    public function __construct(public ApprovedOnepageBriefSnapshot $value) {}
    public function readExact(string $order,string $site,int $version): ?ApprovedOnepageBriefSnapshot {
        ++$this->calls;
        return $this->deny ? null : ($this->calls>1 && $this->next ? $this->next : $this->value);
    }
};
$approval = new class implements CurrentOnepageBriefApprovalWitness {
    public int $calls=0;
    public bool $enabled=true;
    public bool $revokeSecond=false;
    public function isCurrent(ApprovedOnepageBriefSnapshot $head): bool {
        ++$this->calls;
        return $this->enabled && (!$this->revokeSecond || $this->calls===1);
    }
};
$reset = static function() use ($service,$contracts,$current,$exact,$approval,$contract,$head): void {
    $service->calls=0;$service->enabled=true;$service->revokeSecond=false;
    $contracts->calls=0;$contracts->value=$contract;$contracts->next=null;$contracts->deny=false;
    $current->calls=0;$current->value=$head;$current->next=null;$current->deny=false;
    $exact->calls=0;$exact->value=$head;$exact->next=null;$exact->deny=false;
    $approval->calls=0;$approval->enabled=true;$approval->revokeSecond=false;
};
$resolve = new OnepageCurrentHeadS2sResponseCandidate(
    $service,$contracts,$current,$exact,$approval
);
$call = static fn(string $identity='test-only-authorized-s2s',
    string $company=$tenant,string $orderRef=$order,string $siteRef=$site):string =>
    $resolve->prepare($identity,$company,$orderRef,$siteRef);
$deny(static fn()=>(new OnepageCurrentHeadS2sResponseCandidate())->prepare(
    'test-only-authorized-s2s',$tenant,$order,$site
), 'default readers and service witness deny');
$reset();
$bytes=$call();
$decoded=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
$export=\App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder::build(
    $contract,$selection,$source
);
$ok($decoded=== [
    'tenantRef'=>$tenant, 'orderRef'=>$order, 'siteRef'=>$site,
    'contractRef'=>'contract-head-lab','contractRevision'=>4,'productCode'=>'onepage',
    'snapshotRef'=>$selection['snapshotRef'],'briefRevision'=>3,'sourceVersion'=>6,
    'manifestSha256'=>$export['manifestSha256'],'payloadSha256'=>$export['payloadSha256']
], 'exact eleven fields, derived from verified candidate bytes');
$ok(PortalOnepageHeadMtlsHttpSource::parseCandidateResponse($bytes,$tenant,$order,$site)===$decoded,
    'pinned Sites transport parser accepts exactly Portal response');
$ok($service->calls===2 && $contracts->calls===2 && $current->calls===2 &&
    $exact->calls===2 && $approval->calls===2,'five sources read twice');
$ok($call()===$bytes, 'idempotent byte-identical replay with stable sources');
$reset();
$deny(static fn()=> $call('unauthenticated-service'), 'wrong S2S identity');
$deny(static fn()=> $call(company:'tenant-foreign'), 'foreign tenant');
$deny(static fn()=> $call(orderRef:'order-foreign'), 'foreign order');
$deny(static fn()=> $call(siteRef:'33333333-3333-4333-8333-333333333333'), 'foreign site');
$deny(static fn()=> $call(company:'bad tenant'), 'malformed tenant');
$ok($contracts->calls===0 && $current->calls===0,
    'invalid service contexts never reach private contract or head readers');
$reset();$service->enabled=false;
$deny($call,'service denied');
$reset();$service->revokeSecond=true;
$deny($call,'service authorization revoked between reads');
$reset();$contracts->deny=true;
$deny($call,'contract unavailable');
$reset();$current->deny=true;
$deny($call,'current head unavailable');
$reset();$exact->deny=true;
$deny($call,'immutable exact record unavailable');
$reset();$approval->enabled=false;
$deny($call,'current approval witness unavailable');
$reset();$approval->revokeSecond=true;
$deny($call,'approval revoked after serialization');
$reset();$contracts->next=new SiteContractSnapshot($tenant,$order,'contract-head-lab',5,'onepage');
$deny($call,'contract revised after first read');
$reset();$contracts->value=new SiteContractSnapshot($tenant,$order,'contract-head-lab',4,'landing_page');
$deny($call,'Landing Page cannot masquerade as Onepage');
$reset();$current->next=new ApprovedOnepageBriefSnapshot(
    $contract,array_replace($selection,['sourceVersion'=>7]),$source,hash('sha256',$source),
    'head-approval-lab-002',4
);
$deny($call,'newer head invalidates current response');
$reset();$exact->value=new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'head-other-approval',4
);
$deny($call,'exact snapshot differs from current head');
$reset();$exact->next=new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'head-other-approval',4
);
$deny($call,'exact bytes approval drift between reads');
$reset();
$ok($call()===$bytes, 'valid replay still works after denials');
echo 'PORTAL ONEPAGE CURRENT HEAD CONTRACT PASS: '.$checks.
    " checks; synthetic sources, pinned Sites parser, no official head, HTTP or authority.\n";
