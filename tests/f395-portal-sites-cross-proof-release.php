<?php

declare(strict_types=1);

/**
 * F1-395: one immutable trusted order->release assignment, two separately
 * authenticated proof issuers, two ORIGINAL Sites verifiers, no publisher.
 * Disposable CI only: no real keys, users, contracts, payments or sites.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F395_RELEASE_CI')!=='1' ||
    getenv('SITES_F391_APPROVAL_LAB')!=='1') {
    fwrite(STDERR,"F1-395 requires disposable GitHub Actions.\n");
    exit(2);
}
$root=dirname(__DIR__);
$sites=getenv('F395_SITES_CHECKOUT');
if (!is_string($sites) || !is_file($sites.'/src/Publication/SignedPortalClientApprovalGate.php') ||
    !is_file($sites.'/src/Integration/IndependentPortalCommercialGateway.php')) {
    throw new RuntimeException('Pinned original Sites verifiers absent.');
}
spl_autoload_register(static function(string $name)use($root,$sites):void {
    foreach ([
        'App\\Domain\\SitesIntegration\\'=>$root.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Integration\\'=>$sites.'/src/Integration/',
        'Imagine\\Sites\\Publication\\'=>$sites.'/src/Publication/',
        'Imagine\\Sites\\Domain\\'=>$sites.'/src/Domain/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($name,$prefix)) continue;
        $short=substr($name,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Invalid fixture class.');
        }
        $file=$dir.$short.'.php';
        if (!is_file($file)) throw new RuntimeException('Pinned fixture class unavailable.');
        require_once $file;
        return;
    }
});
use App\Domain\SitesIntegration\{
    PortalSitesReleaseAssignment,PortalCurrentSiteReleaseAssignmentSource,
    PortalSitesReleaseProofBundleBuilder,PortalSiteContractSource,
    PortalCurrentSettlementSource,PortalCurrentRevocationEpochSource,
    PortalCurrentClientAcceptanceSource,PortalSettlementEvidence,
    SiteContractSnapshot,PortalClientAcceptanceSnapshot,
    PortalEd25519EntitlementIssuer,PortalEd25519ClientAcceptanceIssuer,
};
use Imagine\Sites\Integration\{
    SiteProjectCommand,PortalEligibilityProofSource,
    IndependentSettlementWitness,IndependentRevocationWitness,
    IndependentPortalCommercialGateway,
};
use Imagine\Sites\Publication\{
    PortalClientApprovalReceiptSource,IndependentClientApprovalWitness,
    SignedPortalClientApprovalGate,
};
use Imagine\Sites\Domain\ReleaseManifest;

const NOW_F395=1800000000;
$digest=hash('sha256','fictional release html exact bytes');
function assignment395(
    string $tenant='tenant-f395',
    string $releaseDigest='',
    int $version=1,
    string $order='order-f395'
):PortalSitesReleaseAssignment {
    return new PortalSitesReleaseAssignment(
        $tenant,$order,'contract-f395',2,'onepage',
        'site-f395','release-f395',$releaseDigest===''?$GLOBALS['digest']:$releaseDigest,
        4,$version
    );
}
final class F395Assignments implements PortalCurrentSiteReleaseAssignmentSource
{
    public ?PortalSitesReleaseAssignment $current=null;
    public bool $outage=false;
    public bool $changeAfterFirst=false;
    public int $reads=0;
    public function readCurrent(
        string $orderRef,string $siteRef,string $releaseRef,int $revision
    ):?PortalSitesReleaseAssignment {
        ++$this->reads;
        if ($this->outage) throw new RuntimeException('fictional assignment outage');
        if ($this->changeAfterFirst && $this->reads%2===0 &&
            $this->current!==null) {
            return assignment395(version:$this->current->bindingRevision+1);
        }
        return $this->current;
    }
}
final class F395Contracts implements PortalSiteContractSource
{
    public bool $valid=true;
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot {
        if(!$this->valid || $orderRef!=='order-f395')return null;
        return new SiteContractSnapshot('tenant-f395','order-f395','contract-f395',2,'onepage');
    }
}
final class F395Payments implements PortalCurrentSettlementSource
{
    public bool $valid=true;
    public int $calls=0;
    public function findCurrentSettledOrder(string $orderRef):?PortalSettlementEvidence {
        ++$this->calls;
        if(!$this->valid || $orderRef!=='order-f395')return null;
        return new PortalSettlementEvidence(
            'tenant-f395','order-f395','contract-f395',2,'onepage',
            'synthetic-f395-settlement',
            hash('sha256','not an actual settlement')
        );
    }
}
final class F395Epoch implements PortalCurrentRevocationEpochSource
{
    public bool $valid=true;
    public int $calls=0;
    public function readCurrentActiveEpoch(
        string $tenantRef,string $orderRef,string $contractRef,int $revision
    ):?int {
        ++$this->calls;
        if(!$this->valid || $tenantRef!=='tenant-f395' ||
            $orderRef!=='order-f395' || $contractRef!=='contract-f395' ||
            $revision!==2)return null;
        return 3;
    }
}
final class F395Acceptances implements PortalCurrentClientAcceptanceSource
{
    public bool $valid=true;
    public bool $mfa=true;
    public string $tenant='tenant-f395';
    public string $digest='';
    public int $calls=0;
    public function readCurrent(
        string $siteRef,string $releaseRef,int $revision
    ):?PortalClientAcceptanceSnapshot {
        ++$this->calls;
        if(!$this->valid) return null;
        return new PortalClientAcceptanceSnapshot(
            $this->tenant,$siteRef,$releaseRef,$this->digest,4,
            'accept-f395-proof-0001',5,NOW_F395-15,
            $this->mfa,true,true
        );
    }
}
final class F395SitesCommercial implements PortalEligibilityProofSource
{
    public ?array $receipt=null;
    public function fetchCurrentProof(SiteProjectCommand $command):?array {
        return $this->receipt;
    }
}
final class F395SitesPSP implements IndependentSettlementWitness
{
    public bool $valid=true;
    public int $calls=0;
    public function confirmsSettled(
        SiteProjectCommand $command,string $reference,string $sha
    ):bool {
        ++$this->calls;
        return $this->valid && $command->tenantRef==='tenant-f395' &&
            $reference==='synthetic-f395-settlement' &&
            $sha===hash('sha256','not an actual settlement');
    }
}
final class F395SitesRevocation implements IndependentRevocationWitness
{
    public bool $valid=true;
    public int $calls=0;
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool {
        ++$this->calls;
        return $this->valid && $epoch===3 &&
            $command->contractRef==='contract-f395';
    }
}
final class F395SitesApproval implements PortalClientApprovalReceiptSource
{
    public ?array $receipt=null;
    public function fetchCurrentReceipt(ReleaseManifest $manifest,int $revision):?array {
        return $this->receipt;
    }
}
final class F395SitesApprovalWitness implements IndependentClientApprovalWitness
{
    public bool $valid=true;
    public int $calls=0;
    public function confirmsCurrent(
        ReleaseManifest $manifest,int $revision,string $ref,int $epoch
    ):bool {
        ++$this->calls;
        return $this->valid && $revision===4 &&
            $ref==='accept-f395-proof-0001' && $epoch===5;
    }
}
$checks=0;
function f395Check(bool $condition,string $description):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-395 FAIL: '.$description);
    ++$checks;
}
function f395Refused(callable $operation):bool {
    try{$operation();}catch(DomainException){return true;}
    return false;
}
$keyCommercial=sodium_crypto_sign_keypair();
$keyAcceptance=sodium_crypto_sign_keypair();
$privateCommercial=sodium_crypto_sign_secretkey($keyCommercial);
$privateAcceptance=sodium_crypto_sign_secretkey($keyAcceptance);
$publicCommercial=bin2hex(sodium_crypto_sign_publickey($keyCommercial));
$publicAcceptance=bin2hex(sodium_crypto_sign_publickey($keyAcceptance));
$assignments=new F395Assignments();$assignments->current=assignment395();
$contract=new F395Contracts();
$psp=new F395Payments();
$epoch=new F395Epoch();
$accept=new F395Acceptances();$accept->digest=$digest;
$builder=new PortalSitesReleaseProofBundleBuilder(
    $assignments,
    new PortalEd25519EntitlementIssuer($contract,$psp,$epoch),
    new PortalEd25519ClientAcceptanceIssuer($accept),
);
$build=static fn()=>$builder->build(
    'order-f395','site-f395','release-f395',4,
    'operation-f395','idem-f395',NOW_F395,
    $privateCommercial,$privateAcceptance
);
f395Check(f395Refused(static fn()=>(new PortalSitesReleaseProofBundleBuilder())->build(
    'order-f395','site-f395','release-f395',4,
    'operation-f395','idem-f395',NOW_F395,
    $privateCommercial,$privateAcceptance
)), 'default unbound contract, settlement, acceptance AND assignment deny');
$proofs=$build();
f395Check(array_keys($proofs)===['commercialProof','clientAcceptanceReceipt'] &&
    $proofs['commercialProof']['tenantRef']==='tenant-f395' &&
    $proofs['clientAcceptanceReceipt']['tenantRef']==='tenant-f395' &&
    $proofs['clientAcceptanceReceipt']['artifactDigest']===$digest &&
    $assignments->reads===2 && $psp->calls===1 && $epoch->calls===1 && $accept->calls===1,
    'two original Portal signers and twice-checked exact release assignment');
$command=new SiteProjectCommand(
    '1','operation-f395','tenant-f395','order-f395',
    'contract-f395',2,'onepage','idem-f395'
);
$manifest=new ReleaseManifest('tenant-f395','site-f395','release-f395',$digest);
$commercialSource=new F395SitesCommercial();
$commercialSource->receipt=$proofs['commercialProof'];
$independentPsp=new F395SitesPSP();
$independentEpoch=new F395SitesRevocation();
$siteCommercial=new IndependentPortalCommercialGateway(
    $commercialSource,$independentPsp,$independentEpoch,$publicCommercial,NOW_F395
);
$approvalSource=new F395SitesApproval();
$approvalSource->receipt=$proofs['clientAcceptanceReceipt'];
$approvalWitness=new F395SitesApprovalWitness();
$siteApproval=new SignedPortalClientApprovalGate(
    $approvalSource,$approvalWitness,$publicAcceptance,NOW_F395
);
f395Check($siteCommercial->verify($command)->allowed &&
    $siteApproval->verify($manifest,4)->allowed &&
    $independentPsp->calls===1 &&
    $independentEpoch->calls===1 &&
    $approvalWitness->calls===1,
    'both ORIGINAL Sites verifiers separately consult independent authorities');
$independentPsp->valid=false;
f395Check(!$siteCommercial->verify($command)->allowed &&
    $siteApproval->verify($manifest,4)->allowed,
    'editorial acceptance alone NEVER substitutes PSP authorization');
$independentPsp->valid=true;
$approvalWitness->valid=false;
f395Check($siteCommercial->verify($command)->allowed &&
    !$siteApproval->verify($manifest,4)->allowed,
    'commercial proof alone NEVER substitutes current explicit acceptance');
$approvalWitness->valid=true;
$changedManifest=new ReleaseManifest('tenant-f395','site-f395','release-f395',
    hash('sha256','modified editorial artifact after acceptance'));
f395Check(!$siteApproval->verify($changedManifest,4)->allowed &&
    $siteCommercial->verify($command)->allowed,
    'commercially eligible project cannot publish a changed artifact');
$assignmentReads=$assignments->reads;
$assignments->current=assignment395(tenant:'other-tenant');
f395Check(f395Refused($build),'same order and release with different trusted tenant denies');
$assignments->current=assignment395(releaseDigest:hash('sha256','unapproved bytes'));
f395Check(f395Refused($build),'changed trusted release digest denies matching valid individual signatures');
$assignments->current=assignment395(order:'other-order');
f395Check(f395Refused($build),'reusing different order for same Site release denies');
$assignments->current=assignment395();
$accept->tenant='other-tenant';
f395Check(f395Refused($build),'valid issuer output from another tenant cannot cross-bind');
$accept->tenant='tenant-f395';
$accept->digest=hash('sha256','another release');
f395Check(f395Refused($build),'valid approval for other HTML cannot cross-bind');
$accept->digest=$digest;
$contract->valid=false;
f395Check(f395Refused($build),'missing authoritative commercial contract denies');
$contract->valid=true;
$psp->valid=false;
f395Check(f395Refused($build),'unsettled payment denied even if client accepted');
$psp->valid=true;
$epoch->valid=false;
f395Check(f395Refused($build),'contract revocation denied even if client accepted');
$epoch->valid=true;
$accept->valid=false;
f395Check(f395Refused($build),'missing client approval denied even if PSP settled');
$accept->valid=true;
$accept->mfa=false;
f395Check(f395Refused($build),'synthetic approval without MFA denied');
$accept->mfa=true;
$assignments->current=null;
f395Check(f395Refused($build),'missing trusted order-to-release mapping denied');
$assignments->current=assignment395();
$assignments->outage=true;
f395Check(f395Refused($build),'mapping source outage denied');
$assignments->outage=false;
$assignments->reads=0;
$assignments->changeAfterFirst=true;
f395Check(f395Refused($build),'changed binding revision between issuance and return denied');
$assignments->changeAfterFirst=false;
f395Check(f395Refused(static fn()=>$builder->build(
    'order-f395','site-f395','release-f395',4,
    'operation-f395','idem-f395',NOW_F395,
    $privateCommercial,$privateCommercial
)), 'one reused signing key cannot impersonate both independent authorities');
f395Check(f395Refused(static fn()=>$builder->build(
    'order-f395','site-f395','release-f395',5,
    'operation-f395','idem-f395',NOW_F395,
    $privateCommercial,$privateAcceptance
)), 'stale editorial revision denied');
f395Check($assignments->reads>=$assignmentReads+2,
    'independent mapping rereads cannot be bypassed by a stale proof');
echo "$checks F1-395 bound financial+editorial proofs against ORIGINAL Sites verifiers passed.\n";
echo "NO-GO: synthetic trusted sources; no endpoint, IAM, PSP, customer approval, money, domain or Site publication.\n";
