<?php

declare(strict_types=1);

/**
 * F1-387 cross-repository proof: execute the Portal issuer against the
 * EXACT pinned Sites F1-386 verifier, not a second implementation.
 * 100% fictional sources and ephemeral signing keys.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F387_ED25519_CI')!=='1') {
    fwrite(STDERR,"F1-387 requires disposable GitHub Actions CI.\n");
    exit(1);
}
$root=dirname(__DIR__);
$sites=getenv('F387_SITES_CHECKOUT');
if(!is_string($sites) || $sites==='' ||
   !is_file($sites.'/src/Integration/IndependentPortalCommercialGateway.php')) {
    throw new RuntimeException('Pinned Sites verifier source unavailable.');
}
spl_autoload_register(static function(string $name)use($root,$sites):void {
    foreach([
        'App\\Domain\\SitesIntegration\\'=>
            $root.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Integration\\'=>
            $sites.'/src/Integration/',
        'Imagine\\Sites\\Domain\\'=>
            $sites.'/src/Domain/',
    ] as $prefix=>$base) {
        if (!str_starts_with($name,$prefix)) continue;
        $class=substr($name,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$class)) {
            throw new RuntimeException('Invalid class name.');
        }
        $path=$base.$class.'.php';
        if (!is_file($path)) throw new RuntimeException('Pinned class missing.');
        require $path;
        return;
    }
});

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\PortalCurrentSettlementSource;
use App\Domain\SitesIntegration\PortalCurrentRevocationEpochSource;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\PortalEd25519EntitlementIssuer;
use Imagine\Sites\Integration\PortalEligibilityProofSource;
use Imagine\Sites\Integration\IndependentSettlementWitness;
use Imagine\Sites\Integration\IndependentRevocationWitness;
use Imagine\Sites\Integration\IndependentPortalCommercialGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;

const F387_NOW=1800000000;
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$public=bin2hex(sodium_crypto_sign_publickey($keys));
$fakeHash=hash('sha256','FICTIONAL_SETTLEMENT_NO_PAYMENT');
final class F387Contract implements PortalSiteContractSource {
    public bool $valid=true;
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot {
        if(!$this->valid || $orderRef!=='order-f387')return null;
        return new SiteContractSnapshot(
            'tenant-f387','order-f387','contract-f387',3,'onepage'
        );
    }
}
final class F387Settlement implements PortalCurrentSettlementSource {
    public bool $valid=true;
    public bool $fail=false;
    public int $reads=0;
    public function findCurrentSettledOrder(string $orderRef):?PortalSettlementEvidence {
        ++$this->reads;
        if($this->fail)throw new RuntimeException('fake PSP unavailable');
        if(!$this->valid || $orderRef!=='order-f387')return null;
        return new PortalSettlementEvidence(
            'tenant-f387','order-f387','contract-f387',3,'onepage',
            'fictional-psp-receipt-f387',
            hash('sha256','FICTIONAL_SETTLEMENT_NO_PAYMENT'),
        );
    }
}
final class F387Epoch implements PortalCurrentRevocationEpochSource {
    public ?int $epoch=7;
    public bool $fail=false;
    public int $reads=0;
    public function readCurrentActiveEpoch(
        string $tenantRef,string $orderRef,string $contractRef,int $contractRevision
    ):?int {
        ++$this->reads;
        if($this->fail)throw new RuntimeException('fake revocation store unavailable');
        if($tenantRef!=='tenant-f387'||$orderRef!=='order-f387'||
           $contractRef!=='contract-f387'||$contractRevision!==3)return null;
        return $this->epoch;
    }
}
final class F387SitesProof implements PortalEligibilityProofSource {
    public ?array $proof=null;
    public function fetchCurrentProof(SiteProjectCommand $command):?array {
        return $this->proof;
    }
}
final class F387SitesPSP implements IndependentSettlementWitness {
    public bool $valid=true;
    public int $checks=0;
    public function confirmsSettled(
        SiteProjectCommand $command,string $settlementRef,string $settlementSha256
    ):bool {
        ++$this->checks;
        return $this->valid &&
            $command->orderRef==='order-f387' &&
            $settlementRef==='fictional-psp-receipt-f387' &&
            $settlementSha256===hash('sha256','FICTIONAL_SETTLEMENT_NO_PAYMENT');
    }
}
final class F387SitesEpoch implements IndependentRevocationWitness {
    public bool $valid=true;
    public int $checks=0;
    public function isCurrent(
        SiteProjectCommand $command,int $revocationEpoch
    ):bool {
        ++$this->checks;
        return $this->valid && $command->contractRevision===3 &&
            $revocationEpoch===7;
    }
}
function check(bool $cond,string $desc):void {
    global $checks;
    if(!$cond) throw new RuntimeException('F1-387 FAIL: '.$desc);
    ++$checks;
}
function refused(callable $work):bool {
    try{$work();}catch(DomainException){return true;}
    return false;
}
$checks=0;
$contract=new F387Contract();
$settlement=new F387Settlement();
$epoch=new F387Epoch();
$issuer=new PortalEd25519EntitlementIssuer($contract,$settlement,$epoch);
$command=new SiteProjectCommand(
    '1','operation-f387-one','tenant-f387','order-f387',
    'contract-f387',3,'onepage','idem-f387-one'
);
check(refused(static fn()=>(new PortalEd25519EntitlementIssuer())
    ->issue('order-f387','operation-f387-one','idem-f387-one',F387_NOW,$secret)),
    'no registered Portal contract/payment/revocation defaults to denial');
$wire=$issuer->issue(
    'order-f387','operation-f387-one','idem-f387-one',
    F387_NOW,$secret
);
check(array_keys($wire)===[
    'schemaVersion','issuer','audience','issuedAt','expiresAt','tenantRef',
    'orderRef','contractRef','contractRevision','productCode',
    'operationFingerprint','settlementRef','settlementSha256',
    'revocationEpoch','signature'
],'Portal issuer uses exact Sites F1-386 canonical protocol fields');
check($wire['operationFingerprint']===$command->semanticFingerprint() &&
    $wire['expiresAt']===F387_NOW+60 &&
    strlen($wire['signature'])===128,
    'real Portal command fingerprint and detached Ed25519 proof bytes match Sites');
$proof=new F387SitesProof();
$proof->proof=$wire;
$psp=new F387SitesPSP();
$rev=new F387SitesEpoch();
$verifier=new IndependentPortalCommercialGateway(
    $proof,$psp,$rev,$public,F387_NOW
);
check((new SiteProjectAdmission($verifier))->evaluate($command)->allowed,
    'genuine pinned Sites F1-386 verifier accepts Portal F1-387 wire');
check($psp->checks===1 && $rev->checks===1,
    'receiver checks independent fake PSP and revocation on valid wire');
$psp->valid=false;
check(!$verifier->verify($command)->allowed &&
    $rev->checks===1,'receiver rejects missing PSP even with valid Portal signature');
$psp->valid=true;
$rev->valid=false;
check(!$verifier->verify($command)->allowed,
    'receiver rejects revoked entitlement independently');
$rev->valid=true;
$proof->proof=$wire;
$proof->proof['productCode']='landing_page';
check(!$verifier->verify($command)->allowed,
    'receiver rejects tampered product');
$proof->proof=$wire;
$proof->proof['settlementSha256']=str_repeat('e',64);
check(!$verifier->verify($command)->allowed,
    'receiver rejects forged settlement digest under unchanged signature');
$proof->proof=$wire;
$proof->proof['signature']=str_repeat('0',128);
check(!$verifier->verify($command)->allowed,
    'receiver rejects forged Ed25519 signature');
$proof->proof=$wire;
$proof->proof['expiresAt']=F387_NOW-1;
check(!$verifier->verify($command)->allowed,
    'receiver rejects stale signed Portal evidence');

$settlement->valid=false;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret
)),'issuer denies when independent settlement missing');
$settlement->valid=true;
$settlement->fail=true;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret
)),'issuer fails closed on settlement source exception');
$settlement->fail=false;
$epoch->epoch=null;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret
)),'issuer denies independently revoked entitlement');
$epoch->epoch=7;
$epoch->fail=true;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret
)),'issuer fails closed on epoch source outage');
$epoch->fail=false;
$contract->valid=false;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret
)),'issuer refuses order without authoritative contract snapshot');
$contract->valid=true;
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,''
)),'issuer refuses missing vault secret key');
check(refused(static fn()=>$issuer->issue(
    'order-f387','operation-f387-two','idem-f387-two',F387_NOW,$secret,121
)),'issuer rejects excessive TTL');
check($settlement->reads>=5 && $epoch->reads>=4,
    'issuer rechecks independent sources for each request, no cached paid status');

echo "$checks F1-387 exact Portal Ed25519 -> pinned Sites F1-386 parity and independent witness cases passed.\n";
echo "NO-GO: data and keys synthetic; no provider bound, no PSP/Portal IAM, no customer approval or deploy.\n";
