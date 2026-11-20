<?php

declare(strict_types=1);

// Exact-SHA cross-repo Landing Page REQUEST contract, no tenant authority,
// approved brief, head responder, S2S route, TLS or publication.
require_once __DIR__.'/../.pinned-sites/src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../.pinned-sites/apps/sites/app/Integration/PortalLandingPageHeadRequestCandidate.php';
require_once __DIR__.'/../.pinned-sites/apps/sites/app/Integration/PortalOnepageHeadRequestCandidate.php';
require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/LandingPageCurrentHeadS2sRequestCandidate.php';
require_once __DIR__.'/../apps/portal/app/Domain/SitesIntegration/OnepageCurrentHeadS2sRequestCandidate.php';

use App\Integration\PortalLandingPageHeadRequestCandidate as SitesLanding;
use App\Integration\PortalOnepageHeadRequestCandidate as SitesOnepage;
use App\Domain\SitesIntegration\LandingPageCurrentHeadS2sRequestCandidate as PortalLanding;
use App\Domain\SitesIntegration\OnepageCurrentHeadS2sRequestCandidate as PortalOnepage;

$checks = 0;
$ok = static function(bool $value,string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('PORTAL LANDING HEAD REQUEST FAILURE: '.$label);
    ++$checks;
};
$deny = static function(callable $fn,string $label,string $expected) use ($ok):void {
    ob_start();
    $failed=false;
    try {$fn();} catch(RuntimeException $error) {
        $failed=$error->getMessage()===$expected && $error->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($failed && $output==='',$label.' fail closed without data disclosure');
};
$tenant='tenant-landing-lab-A';$order='order-landing-lab-A';
$site='11111111-1111-4111-8111-111111111111';
$selection=['tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site];
$landing=SitesLanding::encode($tenant,$order,$site);
$onepage=SitesOnepage::encode($tenant,$order,$site);
$ok(PortalLanding::decode($landing)===$selection,
    'Portal decodes actual canonical Landing Page bytes from Sites');
$ok(PortalOnepage::decode($onepage)===$selection,
    'existing Onepage protocol still functions');
$deny(static fn()=>PortalLanding::decode($onepage),
    'Landing parser rejects Onepage request','PORTAL_LANDING_PAGE_HEAD_REQUEST_DENIED');
$deny(static fn()=>PortalOnepage::decode($landing),
    'Onepage parser rejects Landing Page request','PORTAL_ONEPAGE_CURRENT_HEAD_REQUEST_DENIED');
$ok(PortalLanding::decode(SitesLanding::encode($tenant,$order,$site))===$selection,
    'Landing Page replay stays byte stable');

foreach ([
    ['missing field','{"schemaVersion":"sites.landing-page-current-head.fetch.v1"}'],
    ['trailing line',$landing."\n"],
    ['array instead of object','[]'],
    ['different product',str_replace('"productCode":"landing_page"','"productCode":"onepage"',$landing)],
    ['schema downgrade',str_replace('head.fetch.v1','head.fetch.v0',$landing)],
    ['authority injected',substr($landing,0,-1).',"canPublish":true}'],
    ['service identity injected',substr($landing,0,-1).',"authenticatedServiceRef":"spoofed"}'],
    ['duplicate tenant',substr($landing,0,-1).',"tenantRef":"tenant-foreign"}'],
    ['escaped selector',str_replace('tenant-landing-lab-A','tenant-landing-\\u006cab-A',$landing)],
    ['reordered fields','{"tenantRef":"'.$tenant.'","schemaVersion":"sites.landing-page-current-head.fetch.v1","orderRef":"'.$order.'","siteRef":"'.$site.'","productCode":"landing_page"}'],
    ['uppercase UUID',str_replace($site,'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA',$landing)],
    ['oversized',$landing.str_repeat(' ',1100)],
    ['invalid JSON','{'],
] as [$label,$bad]) {
    $deny(static fn()=>PortalLanding::decode($bad),$label,
        'PORTAL_LANDING_PAGE_HEAD_REQUEST_DENIED');
}
$other=PortalLanding::decode(SitesLanding::encode('tenant-landing-lab-B',$order,$site));
$ok($other['tenantRef']!==$selection['tenantRef'],
    'another tenant parses ONLY as untrusted selectors, not authorization');
$ok(PortalLanding::decode($landing)===$selection,
    'valid request remains intact after all negative cases');
echo 'PORTAL LANDING HEAD REQUEST PASS: '.$checks.
    " checks; no current head source, authenticated mTLS, consent or publication.\n";
