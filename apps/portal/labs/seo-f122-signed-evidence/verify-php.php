<?php
declare(strict_types=1);

/**
 * F122: independently inspect ephemeral signed evidence in CI. The public
 * key is passed as a trusted runner-local file, NEVER from the JSON envelope.
 */
require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoStaticBuildReadinessLab.php';
require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoSignedBuildEvidenceLab.php';

use App\Domain\SeoReview\PortalSeoSignedBuildEvidenceLab as Verify;

[$script,$envelopeFile,$keyFile,$scenario]=array_pad($argv,4,'');
if (PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_SEO_F118_LAB')!=='1' ||
    getenv('PORTAL_SEO_F122_LAB')!=='1' ||
    !in_array($scenario,[
        'valid','bad-signature','tampered-payload','other-tenant',
        'other-run','expired','other-engine','wrong-public-key',
        'wrong-issuer','unexpected-claim',
    ],true)) throw new RuntimeException('F122: CI-only');

function require122(bool $condition,string $message):void
{
    if (!$condition)throw new RuntimeException('F122: '.$message);
}
$bytes=file_get_contents($envelopeFile);
$pub=file_get_contents($keyFile);
require122(is_string($bytes)&&strlen($bytes)<160000,'bounded envelope');
require122(is_string($pub)&&strlen($pub)<100,'bounded key');
$trustedKey=base64_decode(trim($pub),true);
require122(is_string($trustedKey)&&strlen($trustedKey)===32,'test key');
$envelope=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);

$lab=new Verify();
$review=$lab->review(
    $envelope,$trustedKey,'demo-tenant','github-actions-f122',
    '7847c8c66d5be228a08a94282da562c790cdd440',time(),
);
if($scenario==='valid'){
    require122(is_array($review),'authentic synthetic signed report rejected');
    require122($review['ed25519SignatureVerifiedInLab']===true,
        'Ed25519 signature not checked');
    require122($review['tenantBindingVerifiedAgainstSyntheticCaller']===true,
        'tenant was not bound');
    require122($review['report']['reviewStatus']==='HUMAN_REVIEW_REQUIRED',
        'a clean build still needs human review');
    foreach([
        'customerDisplayAuthorized','publicationAuthorized',
        'realSigningAuthorityVerified','realTenantIdentityVerified',
        'replayProtectionVerified','realSiteVerified'
    ] as $flag)
        require122($review[$flag]===false,'false gate '.$flag);
    foreach(['example.com','Fortaleza','fortaleza-ce','2304400',
        'imaginecomunicacao.com.br'] as $secret)
        require122(!str_contains(json_encode($review,JSON_THROW_ON_ERROR),$secret),
            'sensitive text leak');
    require122($lab->review(
        $envelope,$trustedKey,'another-tenant','github-actions-f122',
        '7847c8c66d5be228a08a94282da562c790cdd440',time()
    )===null,'caller-controlled tenant was accepted');
    require122($lab->review(
        $envelope,$trustedKey,'demo-tenant','another-run',
        '7847c8c66d5be228a08a94282da562c790cdd440',time()
    )===null,'wrong independent run was accepted');
    $copy=$envelope;
    $copy['customerAuthorized']=true;
    require122($lab->review(
        $copy,$trustedKey,'demo-tenant','github-actions-f122',
        '7847c8c66d5be228a08a94282da562c790cdd440',time()
    )===null,'forged envelope field accepted');
    $copy=$envelope;
    $copy['signatureBase64']='INVALID';
    require122($lab->review(
        $copy,$trustedKey,'demo-tenant','github-actions-f122',
        '7847c8c66d5be228a08a94282da562c790cdd440',time()
    )===null,'invalid signature accepted');
    require122(!Verify::authorizesCustomerDisplay()&&
        !Verify::authorizesProductionPublish(),'production authority leaked');
}else{
    require122($review===null,'negative scenario accepted: '.$scenario);
}
echo "F122: ".$scenario." evidence boundary verified; no production authority\n";
