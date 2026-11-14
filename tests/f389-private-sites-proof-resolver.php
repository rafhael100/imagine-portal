<?php

declare(strict_types=1);

/**
 * F1-389: canonical Sites F1-388 request -> verified Portal IAM candidate
 * -> F1-387 issuer -> EXACT Sites F1-386 Ed25519 verifier.
 * All data, identities, settlement and revocation are fictional.
 */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F389_RESOLVER_CI')!=='1') {
    fwrite(STDERR,"F1-389 requires disposable GitHub CI opt-in.\n");
    exit(1);
}
$root=dirname(__DIR__);
$sites=(string)getenv('F389_SITES_CHECKOUT');
if(!is_dir($sites) ||
   !is_file($sites.'/src/Integration/IndependentPortalCommercialGateway.php')) {
    throw new RuntimeException('Pinned Sites verifier absent.');
}
spl_autoload_register(static function(string $name)use($root,$sites):void {
    foreach([
        'App\\Domain\\SitesIntegration\\'=>
            $root.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Integration\\'=>$sites.'/src/Integration/',
        'Imagine\\Sites\\Domain\\'=>$sites.'/src/Domain/',
    ] as $prefix=>$path) {
        if(!str_starts_with($name,$prefix))continue;
        $short=substr($name,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short))
            throw new RuntimeException('Invalid class name.');
        require $path.$short.'.php';
        return;
    }
});

use App\Domain\SitesIntegration\PortalVerifiedSitesServiceIdentity;
use App\Domain\SitesIntegration\PortalCurrentSettlementSource;
use App\Domain\SitesIntegration\PortalCurrentRevocationEpochSource;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\PortalEd25519EntitlementIssuer;
use App\Domain\SitesIntegration\PortalSitesEntitlementResolver;
use Imagine\Sites\Integration\PortalEligibilityProofSource;
use Imagine\Sites\Integration\IndependentSettlementWitness;
use Imagine\Sites\Integration\IndependentRevocationWitness;
use Imagine\Sites\Integration\IndependentPortalCommercialGateway;
use Imagine\Sites\Integration\SiteProjectCommand;

const NOW=1800000000;
final class F389ServicePrincipal implements PortalVerifiedSitesServiceIdentity {
    public bool $valid=true;
    public int $calls=0;
    public function isVerifiedSitesService():bool {
        ++$this->calls;
        return $this->valid;
    }
}
final class F389Contract implements PortalSiteContractSource {
    public bool $available=true;
    public function findAuthorizedSiteOrder(string $order):?SiteContractSnapshot {
        if(!$this->available || $order!=='order-f389')return null;
        return new SiteContractSnapshot(
            'tenant-f389','order-f389','contract-f389',3,'landing_page'
        );
    }
}
final class F389Settlement implements PortalCurrentSettlementSource {
    public bool $settled=true;
    public int $reads=0;
    public function findCurrentSettledOrder(string $order):?PortalSettlementEvidence {
        ++$this->reads;
        if(!$this->settled || $order!=='order-f389')return null;
        return new PortalSettlementEvidence(
            'tenant-f389','order-f389','contract-f389',3,'landing_page',
            'psp-fictional-f389-settled',
            hash('sha256','F389-NOT-A-REAL-PAYMENT')
        );
    }
}
final class F389Epoch implements PortalCurrentRevocationEpochSource {
    public bool $active=true;
    public int $reads=0;
    public function readCurrentActiveEpoch(
        string $tenant,string $order,string $contract,int $revision
    ):?int {
        ++$this->reads;
        if(!$this->active || $tenant!=='tenant-f389' ||
            $order!=='order-f389' || $contract!=='contract-f389' ||
            $revision!==3) return null;
        return 11;
    }
}
final class F389SitesSource implements PortalEligibilityProofSource {
    public ?array $proof=null;
    public function fetchCurrentProof(SiteProjectCommand $command):?array {
        return $this->proof;
    }
}
final class F389SitesPsp implements IndependentSettlementWitness {
    public function confirmsSettled(
        SiteProjectCommand $command,string $reference,string $sha
    ):bool {
        return $reference==='psp-fictional-f389-settled' &&
            $sha===hash('sha256','F389-NOT-A-REAL-PAYMENT') &&
            $command->orderRef==='order-f389';
    }
}
final class F389SitesEpoch implements IndependentRevocationWitness {
    public function isCurrent(SiteProjectCommand $command,int $epoch):bool {
        return $epoch===11 && $command->tenantRef==='tenant-f389';
    }
}
function check(bool $ok,string $description):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-389 FAIL: '.$description);
    ++$checks;
}
function denied(callable $fn):bool {
    try{$fn();} catch(DomainException) {return true;}
    return false;
}

$checks=0;
$keys=sodium_crypto_sign_keypair();
$private=sodium_crypto_sign_secretkey($keys);
$public=bin2hex(sodium_crypto_sign_publickey($keys));
$principal=new F389ServicePrincipal();
$contract=new F389Contract();
$settlement=new F389Settlement();
$revocation=new F389Epoch();
$issuer=new PortalEd25519EntitlementIssuer(
    $contract,$settlement,$revocation
);
$resolver=new PortalSitesEntitlementResolver($principal,$issuer);
$command=new SiteProjectCommand(
    '1','operation-f389','tenant-f389','order-f389',
    'contract-f389',3,'landing_page','idem-f389'
);
$query=[
    'schemaVersion'=>'sites.portal-proof-fetch.v1',
    'tenantRef'=>$command->tenantRef,
    'orderRef'=>$command->orderRef,
    'contractRef'=>$command->contractRef,
    'contractRevision'=>$command->contractRevision,
    'productCode'=>$command->productCode,
    'operationFingerprint'=>$command->semanticFingerprint(),
];
check(denied(static fn()=>(new PortalSitesEntitlementResolver())
    ->resolve($query,NOW,$private)),
    'no trusted service or commercial source defaults to denial');
$parsed=PortalSitesEntitlementResolver::parseJsonRequest(
    json_encode($query,JSON_THROW_ON_ERROR)
);
check($parsed===$query,'parse the exact F1-388 canonical request JSON');
$proof=$resolver->resolve($parsed,NOW,$private);
check($proof['issuer']==='imagine-portal' &&
    $proof['audience']==='imagine-sites' &&
    $proof['operationFingerprint']===$command->semanticFingerprint() &&
    $proof['settlementRef']==='psp-fictional-f389-settled' &&
    $proof['revocationEpoch']===11,
    'verified Sites principal obtains Portal proof matching all requested claims');
$source=new F389SitesSource();
$source->proof=$proof;
$verifier=new IndependentPortalCommercialGateway(
    $source,new F389SitesPsp(),new F389SitesEpoch(),$public,NOW
);
check($verifier->verify($command)->allowed,
    'original Sites F1-386 verifies actual Portal F1-389 resolved signature');
$settlementsBefore=$settlement->reads;
$epochsBefore=$revocation->reads;
$resolver->resolve($query,NOW,$private);
check($settlement->reads===$settlementsBefore+1 &&
    $revocation->reads===$epochsBefore+1,
    'each authorized request re-fetches both commercial independent sources');
$principal->valid=false;
$reads=$settlement->reads;
check(denied(static fn()=>$resolver->resolve($query,NOW,$private)) &&
    $settlement->reads===$reads,
    'unverified Sites principal fails before contract/PSP inspection');
$principal->valid=true;
$contract->available=false;
check(denied(static fn()=>$resolver->resolve($query,NOW,$private)),
    'missing official contract source denies endpoint');
$contract->available=true;
$settlement->settled=false;
check(denied(static fn()=>$resolver->resolve($query,NOW,$private)),
    'fake paid-looking order cannot bypass missing settlement');
$settlement->settled=true;
$revocation->active=false;
check(denied(static fn()=>$resolver->resolve($query,NOW,$private)),
    'revoked contract cannot be signed by the Portal resolver');
$revocation->active=true;

foreach([
    ['tenantRef'=>'tenant-adversary'],
    ['contractRevision'=>4],
    ['productCode'=>'onepage'],
    ['operationFingerprint'=>str_repeat('a',64)],
    ['schemaVersion'=>'sites.portal-proof-fetch.v2'],
] as $altered) {
    $changed=array_replace($query,$altered);
    check(denied(static fn()=>$resolver->resolve($changed,NOW,$private)),
        'modified request cannot obtain unrelated contract or signature');
}
$extra=$query;
$extra['paid']=true;
check(denied(static fn()=>$resolver->resolve($extra,NOW,$private)),
    'client-provided paid=true or extra authority field denied');
$reordered=['operationFingerprint'=>$query['operationFingerprint']]+$query;
check(denied(static fn()=>$resolver->resolve($reordered,NOW,$private)),
    'noncanonical field order denied');
check(denied(static fn()=>PortalSitesEntitlementResolver::parseJsonRequest(
    '{"unexpected":'
)), 'malformed JSON cannot enter the resolver');
check(denied(static fn()=>PortalSitesEntitlementResolver::parseJsonRequest(
    str_repeat('x',3000)
)), 'oversized body denied before contract access');
check(denied(static fn()=>$resolver->resolve($query,NOW,'')),
    'missing Ed25519 vault private key denied');

sodium_memzero($private);
echo "$checks F1-389 canonical Portal mTLS responder business boundary -> original Sites F1-386 verifier assertions passed.\n";
echo "NO-GO: no actual TLS peer source, live PSP, revocation adapter, IAM binding or public HTTP route.\n";
