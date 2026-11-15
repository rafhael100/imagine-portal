<?php
declare(strict_types=1);
/**
 * F119 — exercise the Portal boundary against the *actual* SEO Engine
 * F114/F117 DTO emitted from disposable local files in Node.js.
 */
require_once __DIR__.'/../../app/Domain/SeoReview/PortalSeoStaticBuildReadinessLab.php';

use App\Domain\SeoReview\PortalSeoStaticBuildReadinessLab;

[$script,$fixture,$scenario]=array_pad($argv,3,'');
if(PHP_SAPI!=='cli' || getenv('CI')!=='true' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('PORTAL_SEO_F118_LAB')!=='1' ||
   !in_array($scenario,['clean','missing','wrong-locale','private-preview'],true))
    throw new RuntimeException('F119: restricted CI-only CLI');

function check119(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('F119: '.$why);
}
$raw=file_get_contents($fixture);
check119(is_string($raw)&&strlen($raw)>10&&strlen($raw)<100000,
    'bounded fixture missing');
$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
$view=(new PortalSeoStaticBuildReadinessLab())->review($data);
check119(is_array($view),'real Engine F114 DTO did not match PHP policy');

$status=$scenario==='clean'?'HUMAN_REVIEW_REQUIRED':'REVIEW_BLOCKED';
check119($view['reviewStatus']===$status,'unexpected review status');
check119($view['sourceType']==='UNVERIFIED_OFFLINE_BUILD_CLAIM',
    'source promoted to trusted');
check119($view['counts']['municipalInventoryRows']===1,
    'inventory count');
check119($view['counts']['localBuildPages']===2,
    'static build page count');
foreach([
    'customerDisplayAuthorized','publicationAuthorized',
    'productionReadAuthorized','realSiteVerified',
    'officialIbgeVerified','portalIdentityVerified','tenantAccessVerified'
] as $flag)
    check119($view[$flag]===false,'forbidden authority flag '.$flag);

if($scenario==='missing')
    check119($view['counts']['municipalPagesMissing']===1,
        'missing inventory route not detected');
if($scenario==='wrong-locale')
    check119($view['counts']['wrongLanguagePages']===1,
        'wrong-language route not detected');
if($scenario==='private-preview')
    check119($view['counts']['privateDraftLeaks']===1,
        'private preview leak not detected');
if($scenario==='clean')
    check119($view['counts']['technicalErrors']===0,
        'clean build unexpectedly reports technical errors');

$safe=json_encode($view,JSON_THROW_ON_ERROR);
foreach(['2304400','fortaleza-ce','missing-synthetic','example.com',
    'imaginecomunicacao.com.br'] as $private)
    check119(!str_contains($safe,$private),'sensitive source leaked');

$forged=$data;
$forged['publicationAuthorized']=true;
check119((new PortalSeoStaticBuildReadinessLab())->review($forged)===null,
    'forged publication access accepted');
echo "F119: pinned SEO Engine ".$scenario." -> PHP lab safe, no publish\n";
