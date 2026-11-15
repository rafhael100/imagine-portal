<?php
declare(strict_types=1);
/** F125: verify actual Engine-owned F121 signed audit against Portal F122. */
require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoStaticBuildReadinessLab.php';
require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoSignedBuildEvidenceLab.php';

use App\Domain\SeoReview\PortalSeoSignedBuildEvidenceLab as Verify;

[$script,$envelopeFile,$keyFile,$mode]=array_pad($argv,4,'');
$cases=['valid','private-preview','wrong-locale','tampered',
    'wrong-key','other-tenant','expired'];
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_SEO_F118_LAB')!=='1'||
   getenv('PORTAL_SEO_F122_LAB')!=='1'||
   getenv('PORTAL_SEO_F125_LAB')!=='1'||
   !in_array($mode,$cases,true))
    throw new RuntimeException('F125: restricted test-only verifier');

function check125(bool $yes,string $reason):void {
    if(!$yes)throw new RuntimeException('F125: '.$reason);
}
$bytes=file_get_contents($envelopeFile);
$keyBytes=file_get_contents($keyFile);
check125(is_string($bytes)&&strlen($bytes)>100&&strlen($bytes)<160000,
    'bounded envelope');
check125(is_string($keyBytes)&&strlen($keyBytes)<100,
    'bounded public key');
$pub=base64_decode(trim($keyBytes),true);
check125(is_string($pub)&&strlen($pub)===32,
    'Ed25519 public key malformed');
$envelope=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);

$lab=new Verify();
$verified=$lab->review(
    $envelope,$pub,'demo-tenant','github-actions-f122',
    '3c75b8e7b64f4173a17f4daab45cea1b8bdc5554',time()
);
if(in_array($mode,['tampered','wrong-key','other-tenant','expired'],true)){
    check125($verified===null,'forged or expired Engine envelope accepted');
}else{
    check125(is_array($verified),'real Engine signature refused');
    $report=$verified['report']??null;
    check125(is_array($report),'sanitized F118 report missing');
    $expected=$mode==='valid'?'HUMAN_REVIEW_REQUIRED':'REVIEW_BLOCKED';
    check125($report['reviewStatus']===$expected,
        'unexpected build audit status');
    if($mode==='private-preview')
        check125($report['counts']['privateDraftLeaks']===1,
            'Engine did not detect preview leak');
    if($mode==='wrong-locale')
        check125($report['counts']['wrongLanguagePages']===1,
            'Engine did not detect language mismatch');
    check125($verified['ed25519SignatureVerifiedInLab']===true,
        'cryptographic signature not verified');
    foreach(['realSigningAuthorityVerified','realTenantIdentityVerified',
        'replayProtectionVerified','customerDisplayAuthorized',
        'publicationAuthorized'] as $flag)
        check125($verified[$flag]===false,'false authority flag '.$flag);
    $safe=json_encode($verified,JSON_THROW_ON_ERROR);
    foreach(['2304400','fortaleza-ce','imaginecomunicacao.com.br'] as $private)
        check125(!str_contains($safe,$private),'private label in review');
}
echo 'F125: '.$mode.' Engine-owned signed report -> PHP review safe'."\n";
