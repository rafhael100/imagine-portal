<?php

declare(strict_types=1);

/** F1-396 CI-only private S2S consent resolver; no real customer IAM. */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('PORTAL_F396_RESOLVER_CI')!=='1') {
    fwrite(STDERR,"F1-396 requires explicit CI mode.\n");
    exit(1);
}
spl_autoload_register(static function(string $name):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if (!str_starts_with($name,$prefix))return;
    $short=substr($name,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
        throw new RuntimeException('Unexpected PHP class.');
    }
    require_once dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration/'.
        $short.'.php';
});
use App\Domain\SitesIntegration\PortalClientAcceptanceSnapshot;
use App\Domain\SitesIntegration\PortalCurrentClientAcceptanceSource;
use App\Domain\SitesIntegration\PortalEd25519ClientAcceptanceIssuer;
use App\Domain\SitesIntegration\PortalVerifiedSitesServiceIdentity;
use App\Domain\SitesIntegration\PortalSitesClientAcceptanceResolver;

const F396_NOW=1800000000;
$sha=hash('sha256','F396 FICTIONAL STATIC HTML');
$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=sodium_crypto_sign_publickey($keypair);
$validRequest=[
    'schemaVersion'=>'sites.portal-client-approval-fetch.v1',
    'tenantRef'=>'tenant-f396',
    'siteRef'=>'site-f396',
    'releaseRef'=>'release-f396',
    'artifactDigest'=>$sha,
    'editorialRevision'=>7,
];
final class F396Identity implements PortalVerifiedSitesServiceIdentity {
    public bool $authenticated=true;
    public bool $outage=false;
    public function isVerifiedSitesService():bool {
        if($this->outage)throw new RuntimeException('fictional IAM unavailable');
        return $this->authenticated;
    }
}
final class F396Consent implements PortalCurrentClientAcceptanceSource {
    public bool $current=true;
    public bool $mfa=true;
    public bool $accepted=true;
    public bool $outage=false;
    public string $tenant='tenant-f396';
    public string $digest;
    public int $revision=7;
    public int $reads=0;
    public function __construct(string $digest){$this->digest=$digest;}
    public function readCurrent(
        string $siteRef,string $releaseRef,int $revision
    ):?PortalClientAcceptanceSnapshot {
        ++$this->reads;
        if ($this->outage)throw new RuntimeException('fictional consent ledger down');
        if($siteRef!=='site-f396'||$releaseRef!=='release-f396')
            return null;
        return new PortalClientAcceptanceSnapshot(
            $this->tenant,'site-f396','release-f396',
            $this->digest,$this->revision,'approval-f396-client-ci-01',
            4,F396_NOW-20,$this->mfa,$this->accepted,$this->current
        );
    }
}
function f396Assert(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-396 FAILED: '.$message);
    ++$checks;
}
function f396Denied(callable $run):bool {
    try{$run();}catch(DomainException){return true;}
    return false;
}
$checks=0;
$identity=new F396Identity();
$consent=new F396Consent($sha);
$resolver=new PortalSitesClientAcceptanceResolver(
    $identity,new PortalEd25519ClientAcceptanceIssuer($consent)
);
f396Assert(f396Denied(static fn()=>(new PortalSitesClientAcceptanceResolver())
    ->resolve($validRequest,F396_NOW,$secret)),
    'default deny-by-default service and consent source');

$receipt=$resolver->resolve($validRequest,F396_NOW,$secret);
f396Assert(array_keys($receipt)===[
    'schemaVersion','issuer','audience','issuedAt','expiresAt','acceptedAt',
    'tenantRef','siteRef','releaseRef','artifactDigest','editorialRevision',
    'acceptanceRef','acceptanceEpoch','actorContext','decision','signature',
], 'Portal response preserves exact signed Sites wire');
$payload=array_slice($receipt,0,-1,true);
f396Assert(sodium_crypto_sign_verify_detached(
    sodium_hex2bin($receipt['signature']),
    json_encode($payload,JSON_THROW_ON_ERROR |
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $public
), 'signature verifies against ephemeral Portal public key');
f396Assert($consent->reads===1,'consent must come from independently loaded current record');
f396Assert($resolver->resolve($validRequest,F396_NOW,$secret)['artifactDigest']===$sha &&
    $consent->reads===2,'current state fetched for every request, not cached');

$identity->authenticated=false;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'untrusted S2S request cannot call issuer');
f396Assert($consent->reads===2,'denied S2S identity cannot access consent store');
$identity->authenticated=true;
$identity->outage=true;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'IAM source outage fails closed');
$identity->outage=false;

foreach ([
    ['tenantRef','tenant-other'],['siteRef','site-other'],
    ['releaseRef','release-other'],
    ['artifactDigest',hash('sha256','changed HTML')],
    ['editorialRevision',8],
] as [$field,$value]) {
    $request=$validRequest;
    $request[$field]=$value;
    f396Assert(f396Denied(static fn()=>$resolver->resolve(
        $request,F396_NOW,$secret
    )),'rejects mismatched '.$field);
}
foreach ([
    ['schemaVersion','wrong'],
    ['artifactDigest','bad'],
    ['editorialRevision','7'],
    ['editorialRevision',0],
    ['siteRef','bad/identifier'],
] as [$field,$value]) {
    $request=$validRequest;
    $request[$field]=$value;
    f396Assert(f396Denied(static fn()=>$resolver->resolve(
        $request,F396_NOW,$secret
    )),'strict canonical request type '.$field);
}
$extra=$validRequest;
$extra['paid']=true;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $extra,F396_NOW,$secret
)), 'client cannot inject paid=true into private request');
$reordered=['editorialRevision'=>7]+array_slice($validRequest,0,-1,true);
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $reordered,F396_NOW,$secret
)), 'noncanonical field order not accepted');

foreach(['','[]','null','<html>',str_repeat('x',2050)] as $raw) {
    f396Assert(f396Denied(static fn()=>PortalSitesClientAcceptanceResolver::
        parseJsonRequest($raw)),'reject malformed/oversized request');
}
f396Assert(PortalSitesClientAcceptanceResolver::parseJsonRequest(
    json_encode($validRequest,JSON_THROW_ON_ERROR)
)===$validRequest,'valid canonical JSON request parsed');

$consent->mfa=false;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'client identity without MFA cannot generate approval');
$consent->mfa=true;$consent->accepted=false;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'missing express consent cannot generate approval');
$consent->accepted=true;$consent->current=false;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'revoked approval cannot be newly signed');
$consent->current=true;$consent->outage=true;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,$secret
)), 'consent ledger outage fails closed');
$consent->outage=false;
f396Assert(f396Denied(static fn()=>$resolver->resolve(
    $validRequest,F396_NOW,''
)), 'missing signing key fails closed');

echo "$checks F1-396 authenticated private consent resolver checks passed.\n";
echo "NO-GO: fictional IAM and consent record; no HTTP route or operational key.\n";
