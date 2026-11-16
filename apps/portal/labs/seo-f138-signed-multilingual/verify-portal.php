<?php
declare(strict_types=1);

require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoSignedMultilingualV2Lab.php';
use App\Domain\SeoReview\PortalSeoSignedMultilingualV2Lab as Verify;

[$script,$envelopeFile,$publicKeyFile,$scenario]=array_pad($argv,4,'');
$scenarios=[
    'valid','blocked','tampered','wrong-key','other-tenant','expired',
    'wrong-contract','bad-flags','wrong-status','extra-claim','wrong-prefix'
];
if(PHP_SAPI!=='cli'||getenv('CI')!=='true'||
   getenv('APP_ENV')!=='testing'||getenv('PORTAL_SEO_F138_LAB')!=='1'||
   !in_array($scenario,$scenarios,true)) throw new RuntimeException('F138: CI lab only');

function requireF138(bool $valid,string $error):void{
    if(!$valid)throw new RuntimeException('F138: '.$error);
}
$source=file_get_contents($envelopeFile);
$keyFile=file_get_contents($publicKeyFile);
requireF138(is_string($source)&&strlen($source)>100&&strlen($source)<35000,'envelope');
requireF138(is_string($keyFile)&&strlen($keyFile)<100,'public key file');
$key=base64_decode(trim($keyFile),true);
requireF138(is_string($key)&&strlen($key)===32,'raw Ed25519 public key');
$envelope=json_decode($source,true,32,JSON_THROW_ON_ERROR);
$verifier=new Verify();
$result=$verifier->review(
    $envelope,$key,'demo-tenant','github-actions-f138',
    '38b80fe77bd74ea6e19b8751014038ac3b9f8400',time()
);
if(in_array($scenario,['valid','blocked'],true)){
    requireF138(is_array($result),'valid Engine signature rejected');
    requireF138($result['reviewStatus']===
        ($scenario==='blocked'?'blocked':'human_independent_review_required'),
        'signed release status');
    requireF138($result['ed25519SignatureVerifiedInLab']===true,'signature');
    requireF138($result['engineCommitMatched']===true,'engine commit');
    requireF138($result['tenantAndRunMatchedSyntheticCaller']===true,'tenant/run');
    requireF138($result['summary']['inspectedEntries']===3,'inventory count');
    requireF138($result['summary']['auditedBuildPages']===4,'HTML count');
    requireF138($result['summary']['missingHreflangLinks']===
        ($scenario==='blocked'?9:0),'true HTML hreflang count');
    foreach(['realTenantAuthenticated','productionSignerCustodyVerified',
        'replayLedgerLinked','publicationAuthorized','indexingAuthorized',
        'customerDisplayAuthorized'] as $flag)
        requireF138($result[$flag]===false,'false authority: '.$flag);
    requireF138(!Verify::allowsCustomerDisplay()&&
        !Verify::allowsProductionPublish(),'no production release');
    $out=json_encode($result,JSON_THROW_ON_ERROR);
    foreach(['2304400','fortaleza-ce','site-onepage',
              'imaginecomunicacao.com.br','another-tenant'] as $secret)
        requireF138(!str_contains($out,$secret),'private route or tenant leak');
    requireF138($verifier->review($envelope,$key,'other-tenant',
        'github-actions-f138',
        '38b80fe77bd74ea6e19b8751014038ac3b9f8400',time())===null,
        'caller supplied another tenant accepted');
    requireF138($verifier->review($envelope,$key,'demo-tenant',
        'another-run',
        '38b80fe77bd74ea6e19b8751014038ac3b9f8400',time())===null,
        'caller supplied another run accepted');
    requireF138($verifier->review($envelope,$key,'demo-tenant',
        'github-actions-f138',str_repeat('f',40),time())===null,
        'other Engine SHA accepted');
}else{
    requireF138($result===null,'negative scenario accepted: '.$scenario);
}
echo 'F138: '.$scenario.' verified in CI; not an authorization'.PHP_EOL;
