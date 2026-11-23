<?php

declare(strict_types=1);

// Offline boundary tests only: synthetic source/witness implementations, no
// real Portal approved briefing, no DB/PSP/HTTP/client or CLI to emit secrets.
$root = dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration';
foreach ([
    'PortalSiteContractSource', 'SiteContractSnapshot', 'DenyingPortalSiteContractSource',
    'OnepageBriefSnapshotCandidateEncoder', 'ApprovedOnepageBriefSnapshot',
    'ApprovedOnepageBriefSnapshotSource', 'DenyingApprovedOnepageBriefSnapshotSource',
    'CurrentOnepageBriefApprovalWitness', 'DenyingCurrentOnepageBriefApprovalWitness',
    'OnepageApprovedBriefExportGate',
] as $name) require_once $root.'/'.$name.'.php';

use App\Domain\SitesIntegration\{
    SiteContractSnapshot, PortalSiteContractSource,
    ApprovedOnepageBriefSnapshot, ApprovedOnepageBriefSnapshotSource,
    CurrentOnepageBriefApprovalWitness, OnepageApprovedBriefExportGate,
    OnepageBriefSnapshotCandidateEncoder
};

$checks = 0;
$ok = static function (bool $accept, string $label) use (&$checks): void {
    if (!$accept) throw new RuntimeException('Onepage approved brief gate failed: '.$label);
    ++$checks;
};
$deny = static function(callable $operation,string $label) use ($ok): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $ok($error->getMessage()==='ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED' &&
            $error->getPrevious()===null,$label.' returns the closed error without leaking evidence');
        return;
    }
    $ok(false,$label.' incorrectly passed');
};
$contract = new SiteContractSnapshot(
    'synthetic-org-a','synthetic-order-a','synthetic-contract-a',1,'onepage',
);
$site = '11111111-1111-4111-8111-111111111111';
$selection = [
    'siteRef' => $site,
    'snapshotRef' => '22222222-2222-4222-8222-222222222222',
    'operationId' => 'synthetic-brief-operation',
    'idempotencyKey' => 'synthetic-brief-key',
    'sourceVersion' => 12,
    'expectedBriefRevision' => 0,
    'payloadRef' => 'synthetic-brief-source',
];
$source = json_encode([
    'company_name'=>'Oficina Sintética',
    'company_intro'=>'Conteúdo fictício selecionado para laboratório.',
    'services'=>"Uma revisão documental.\nOutro serviço fictício.",
    'current_site'=>'','contact_site'=>'https://contato.example.invalid/',
    'domain'=>'example.invalid','email'=>'contato@example.invalid',
    'phone'=>'','address'=>'','whatsapp'=>'','notes'=>'Nada real.',
    'ai_support'=>false,'logo_unavailable'=>true,'manual_unavailable'=>true,
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
$record = new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'synthetic-approval-a',1,
);
$contracts = new class($contract) implements PortalSiteContractSource {
    public int $calls = 0;
    public ?SiteContractSnapshot $next = null;
    public bool $denied = false;
    public bool $deniedAfterFirst = false;
    public function __construct(public SiteContractSnapshot $current) {}
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot {
        ++$this->calls;
        if ($this->denied || ($this->deniedAfterFirst && $this->calls > 1)) return null;
        return $this->calls > 1 && $this->next !== null ? $this->next : $this->current;
    }
};
$briefs = new class($record) implements ApprovedOnepageBriefSnapshotSource {
    public int $calls = 0;
    public bool $denied = false;
    public ?ApprovedOnepageBriefSnapshot $next = null;
    public function __construct(public ApprovedOnepageBriefSnapshot $current) {}
    public function readExact(string $orderRef,string $siteRef,int $sourceVersion):?ApprovedOnepageBriefSnapshot {
        ++$this->calls;
        if ($this->denied) return null;
        return $this->calls > 1 && $this->next !== null ? $this->next : $this->current;
    }
};
$witness = new class implements CurrentOnepageBriefApprovalWitness {
    public int $calls = 0;
    public bool $approved = true;
    public bool $revokeAfterFirst = false;
    public function isCurrent(ApprovedOnepageBriefSnapshot $brief): bool {
        ++$this->calls;
        if ($this->revokeAfterFirst && $this->calls === 1) {
            $this->approved = false; // next witness read observes the revocation
            return true;
        }
        return $this->approved;
    }
};
$reset = static function() use ($contracts,$briefs,$witness,$contract,$record): void {
    $contracts->calls=0;$contracts->next=null;$contracts->denied=false;$contracts->deniedAfterFirst=false;$contracts->current=$contract;
    $briefs->calls=0;$briefs->next=null;$briefs->denied=false;$briefs->current=$record;
    $witness->calls=0;$witness->approved=true;$witness->revokeAfterFirst=false;
};
$makeRecord = static function (
    ?SiteContractSnapshot $context=null, ?array $selector=null,
    ?string $sourceBytes=null, string $approvalRef='synthetic-approval-a',
) use ($contract,$selection,$source): ApprovedOnepageBriefSnapshot {
    $bytes=$sourceBytes??$source;
    return new ApprovedOnepageBriefSnapshot($context??$contract,$selector??$selection,
        $bytes,hash('sha256',$bytes),$approvalRef,1);
};
$gate = new OnepageApprovedBriefExportGate($contracts,$briefs,$witness);
$deny(static fn()=>(new OnepageApprovedBriefExportGate())->prepare(
    $contract->orderRef,$site,12,
),'default source, contract and approval witness all deny');

$reset();
$produced=$gate->prepare($contract->orderRef,$site,12);
$expected=OnepageBriefSnapshotCandidateEncoder::build($contract,$selection,$source);
$ok($produced===$expected && $produced['sourceBytes']===$source,
    'explicit authorized mock sources produce only the exact same immutable candidate pair');
$ok($contracts->calls===2 && $briefs->calls===2 && $witness->calls===2,
    'contract, source and witness are rechecked around export');
foreach(['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $key){
    $ok($produced[$key]===false,'successful synthetic boundary never promotes '.$key);
}
$ok($produced['payloadSha256']===hash('sha256',$source),
    'client data bytes remain original and are bound by their hash');
$ok($gate->prepare($contract->orderRef,$site,12)===$expected,
    're-running the same synthetic source does not normalize bytes');
// Re-running on the same stubs uses values, never an object identity promise.
$reset();

$deny(static fn()=> $gate->prepare('invalid order',$site,12),
    'rejects malformed order selector before using a source');
$deny(static fn()=> $gate->prepare($contract->orderRef,'not-a-uuid',12),
    'rejects invalid site UUID');
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,-1),
    'rejects negative source version');
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,9007199254740992),
    'rejects source version outside JSON safe range');
$ok($contracts->calls===0 && $briefs->calls===0 && $witness->calls===0,
    'invalid public selectors never reach any injected source');

$reset();$contracts->denied=true;
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'contract source denies');
$ok($briefs->calls===0 && $witness->calls===0,
    'unauthorized initial contract must not query any private briefing or witness');

$reset();$contracts->current=new SiteContractSnapshot(
    $contract->tenantRef,'synthetic-other-order',$contract->contractRef,1,'onepage',
);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'unrelated authorized order context denies before briefing read');
$ok($briefs->calls===0 && $witness->calls===0,
    'mismatched order is rejected without reading another order briefing');

$reset();$contracts->current=new SiteContractSnapshot(
    $contract->tenantRef,$contract->orderRef,$contract->contractRef,1,'landing_page',
);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'landing contract cannot select private OnePage briefing');
$ok($briefs->calls===0 && $witness->calls===0,
    'wrong product is refused before source read, even in the same tenant');
$reset();$briefs->denied=true;
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approved source denies');
$reset();$witness->approved=false;
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'independent approval witness denies');
$reset();$witness->revokeAfterFirst=true;
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approval becomes revoked between current reads');
$ok($witness->calls===2,'revoke scenario was reached after first positive check');

$reset();$contracts->next=new SiteContractSnapshot(
    $contract->tenantRef,$contract->orderRef,$contract->contractRef,2,'onepage',
);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'contract revision changes between reads');
$ok($briefs->calls===1 && $witness->calls===1,
    'changed contract must not cause a second unauthorized briefing read');
$reset();$contracts->deniedAfterFirst=true;
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'authorized contract becomes revoked before the second private read');
$ok($contracts->calls===2 && $briefs->calls===1 && $witness->calls===1,
    'revoked contract stops before accessing private briefing on second pass');
$reset();$contracts->next=new SiteContractSnapshot(
    'synthetic-other-org',$contract->orderRef,$contract->contractRef,1,'onepage',
);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'tenant context changes between reads');
$reset();$briefs->next=$makeRecord(approvalRef:'different-approval-reference');
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approval reference changes after first read');
$reset();
$newSource=str_replace('Nada real.','Nada real, segunda versão fictícia.',$source);
$briefs->next=$makeRecord(sourceBytes:$newSource);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'briefing source bytes change between reads');

$reset();$briefs->current=$makeRecord(
    context:new SiteContractSnapshot('synthetic-other-org',
        $contract->orderRef,$contract->contractRef,1,'onepage')
);
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approved source tenant does not match the independently read contract');
$reset();$briefs->current=$makeRecord(selector:array_replace($selection,['siteRef'=>
    '44444444-4444-4444-8444-444444444444']));
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approved source chooses another Sites project');
$reset();$briefs->current=$makeRecord(selector:array_replace($selection,['sourceVersion'=>13]));
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'approved source chooses a different aggregate version');
$reset();$briefs->current=$makeRecord(sourceBytes:'{"incomplete":true}');
$deny(static fn()=> $gate->prepare($contract->orderRef,$site,12),
    'nominal approved record with invalid rich source fails the real exporter');

$reset();
$invalid = static function(callable $factory,string $label) use ($ok): void {
    try { $factory(); }
    catch(InvalidArgumentException $e) {
        $ok($e->getMessage()==='Invalid approved Onepage snapshot candidate',$label);
        return;
    }
    $ok(false,$label.' was constructed');
};
$invalid(static fn()=>new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,str_repeat('a',64),'synthetic-approval-a',1,
), 'record with mismatched original source hash rejected');
$invalid(static fn()=>new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'invalid approval ref',1,
), 'approvalRef must be opaque and bounded');
$invalid(static fn()=>new ApprovedOnepageBriefSnapshot(
    $contract,$selection,$source,hash('sha256',$source),'synthetic-approval-a',0,
), 'approval epoch must be positive');

echo "ONEPAGE APPROVED BRIEF SOURCE BOUNDARY PASS: {$checks} checks; default-deny, no official approval or transport.\n";
