<?php

declare(strict_types=1);

/**
 * F1-394 cross-repo test: genuine Portal issuer feeds Sites F1-391 verifier.
 * Everything (principal, acceptance, keys, witness) is synthetic and offline.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F394_APPROVAL_CI')!=='1' ||
    getenv('SITES_F391_APPROVAL_LAB')!=='1') {
    fwrite(STDERR,"F1-394 requires isolated CI test mode.\n");
    exit(1);
}
$root=dirname(__DIR__);
$sites=(string)getenv('F394_SITES_CHECKOUT');
if (!is_file($sites.'/src/Publication/SignedPortalClientApprovalGate.php')) {
    throw new RuntimeException('Pinned Sites F1-391 verifier unavailable.');
}
spl_autoload_register(static function(string $class)use($root,$sites):void {
    foreach([
        'App\\Domain\\SitesIntegration\\'=>$root.'/apps/portal/app/Domain/SitesIntegration/',
        'Imagine\\Sites\\Publication\\'=>$sites.'/src/Publication/',
        'Imagine\\Sites\\Domain\\'=>$sites.'/src/Domain/',
    ] as $prefix=>$dir) {
        if(!str_starts_with($class,$prefix)) continue;
        $className=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$className)) {
            throw new RuntimeException('Unexpected fixture class.');
        }
        require_once $dir.$className.'.php';
        return;
    }
});
use App\Domain\SitesIntegration\PortalClientAcceptanceSnapshot;
use App\Domain\SitesIntegration\PortalCurrentClientAcceptanceSource;
use App\Domain\SitesIntegration\PortalEd25519ClientAcceptanceIssuer;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Publication\PortalClientApprovalReceiptSource;
use Imagine\Sites\Publication\IndependentClientApprovalWitness;
use Imagine\Sites\Publication\SignedPortalClientApprovalGate;

const F394_NOW=1800000000;
$keys=sodium_crypto_sign_keypair();
$private=sodium_crypto_sign_secretkey($keys);
$public=bin2hex(sodium_crypto_sign_publickey($keys));
$digest=hash('sha256','FICTIONAL APPROVED IMMUTABLE ONEPAGE HTML');
function snapshot(
    string $digest,
    bool $mfa=true,bool $accepted=true,bool $current=true,
    int $revision=4,string $site='site-f394',
):PortalClientAcceptanceSnapshot {
    return new PortalClientAcceptanceSnapshot(
        'tenant-f394',$site,'release-f394',$digest,
        $revision,'client-approval-f394-0001',7,F394_NOW-25,
        $mfa,$accepted,$current
    );
}
final class F394Source implements PortalCurrentClientAcceptanceSource
{
    public ?PortalClientAcceptanceSnapshot $record=null;
    public bool $outage=false;
    public int $calls=0;
    public function readCurrent(
        string $siteRef,string $releaseRef,int $revision
    ):?PortalClientAcceptanceSnapshot {
        ++$this->calls;
        if($this->outage)throw new RuntimeException('fictional consent database down');
        return $this->record;
    }
}
final class F394Receipt implements PortalClientApprovalReceiptSource
{
    public ?array $proof=null;
    public function fetchCurrentReceipt(ReleaseManifest $manifest,int $revision):?array {
        return $this->proof;
    }
}
final class F394Witness implements IndependentClientApprovalWitness
{
    public bool $valid=true;
    public int $checks=0;
    public function confirmsCurrent(
        ReleaseManifest $manifest,int $revision,
        string $reference,int $epoch
    ):bool {
        ++$this->checks;
        return $this->valid && $manifest->tenantRef==='tenant-f394' &&
            $revision===4 && $reference==='client-approval-f394-0001' &&
            $epoch===7;
    }
}
function f394Check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-394 FAILED: '.$label);
    ++$checks;
}
function f394Denied(callable $action):bool {
    try{$action();}catch(DomainException){return true;}
    return false;
}
$checks=0;
$source=new F394Source();
$source->record=snapshot($digest);
$issuer=new PortalEd25519ClientAcceptanceIssuer($source);
$manifest=new ReleaseManifest(
    'tenant-f394','site-f394','release-f394',$digest
);
f394Check(f394Denied(static fn()=>(new PortalEd25519ClientAcceptanceIssuer())
    ->issue('site-f394','release-f394',4,F394_NOW,$private)),
    'default Portal approval issuer refuses without official consent source');
$wire=$issuer->issue('site-f394','release-f394',4,F394_NOW,$private);
f394Check(array_keys($wire)===[
    'schemaVersion','issuer','audience','issuedAt','expiresAt','acceptedAt',
    'tenantRef','siteRef','releaseRef','artifactDigest','editorialRevision',
    'acceptanceRef','acceptanceEpoch','actorContext','decision','signature'
], 'Portal issuer matches actual Sites F1-391 canonical receipt schema');
$receipt=new F394Receipt();
$receipt->proof=$wire;
$witness=new F394Witness();
$verifier=new SignedPortalClientApprovalGate(
    $receipt,$witness,$public,F394_NOW
);
f394Check($verifier->verify($manifest,4)->allowed,
    'original pinned Sites verifier validates Portal issuer bytes and signature');
f394Check($witness->checks===1,
    'independent Sites witness mandatory for valid signed acceptance');
$witness->valid=false;
f394Check(!$verifier->verify($manifest,4)->allowed,
    'revoked acceptance cannot authorize even when original signature valid');
$witness->valid=true;
$receipt->proof=$wire;
$receipt->proof['acceptanceEpoch']=8;
f394Check(!$verifier->verify($manifest,4)->allowed,
    'unsigned epoch change is rejected by original Sites verifier');
$receipt->proof=$wire;
$receipt->proof['decision']='rejected';
f394Check(!$verifier->verify($manifest,4)->allowed,
    'client refusal is not converted into approval');

$source->record=null;
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'missing authoritative consent record denied');
$source->record=snapshot($digest,false);
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'without verified client MFA no proof is emitted');
$source->record=snapshot($digest,true,false);
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'without explicit customer action no proof is emitted');
$source->record=snapshot($digest,true,true,false);
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'revoked stored consent cannot be signed');
$source->record=snapshot($digest,true,true,true,5);
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'stale editorial revision not signed');
$source->record=snapshot($digest,true,true,true,4,'site-other');
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'cross-site authoritative mismatch denied');
$source->record=snapshot('not-a-hash');
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'malformed snapshot digest refused');
$source->record=snapshot($digest);
$source->outage=true;
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private
)), 'Portal consent ledger outage fails closed');
$source->outage=false;
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,''
)), 'secret key missing fails closed');
f394Check(f394Denied(static fn()=>$issuer->issue(
    'site-f394','release-f394',4,F394_NOW,$private,61
)), 'overlong validity refused');

echo "$checks F1-394 Portal signed acceptance -> pinned Sites F1-391 checks passed.\n";
echo "NO-GO: fake MFA/consent source and witness; no real customer or deployment.\n";
