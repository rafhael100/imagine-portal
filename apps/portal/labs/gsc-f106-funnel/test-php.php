<?php
declare(strict_types=1);

require_once __DIR__.'/../../app/Domain/SeoReview/OfflineSeoFunnelPreview.php';

use App\Domain\SeoReview\OfflineSeoFunnelPreview as Preview;

function check(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('Portal SEO F106 lab: '.$reason);
}
if (PHP_SAPI !== 'cli' || $argc !== 3 ||
    !in_array($argv[2], ['complete','partial','zero'], true))
    throw new RuntimeException('Portal SEO F106 lab: fixed fixture required');

$bytes = file_get_contents($argv[1]);
if ($bytes === false || strlen($bytes) > 32768)
    throw new RuntimeException('Portal SEO F106 lab: fixture missing or oversized');

$dto = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
$scenario = $argv[2];
$view = Preview::fromDto($dto);
check($view !== null,'real SEO F106 synthetic DTO rejected');
check($view->stageAvailability === [
    'VISITAS'=>'NOT_AVAILABLE','LEADS'=>'NOT_AVAILABLE',
    'CLIENTES'=>'NOT_AVAILABLE'
], 'false funnel conversion displayed');
check($view->originUnverified === true, 'fake source verification');
check(!$view->canQueryGoogle() && !$view->canPublish() &&
    !$view->canStoreTokens() && !$view->hasVerifiedTenantGrant() &&
    !$view->canExposeCustomerData(),'authority escaped laboratory');
check($view->period['startDate'] === '2026-10-03', 'wrong current period');

if ($scenario === 'partial') {
    check($view->searchDiscovery === null,
        'partial period must suppress all discovery metrics');
} else {
    check($view->searchDiscovery !== null, 'complete input must show search review');
    check($view->searchDiscovery['clicks'] === ($scenario === 'zero' ? 0 : 3),
        'wrong search click count');
    check($view->searchDiscovery['impressions'] ===
        ($scenario === 'zero' ? 20 : 30),'wrong impressions');
}
$serialized = json_encode($view, JSON_THROW_ON_ERROR);
check(!str_contains($serialized,'example.com') &&
    !str_contains($serialized,'demo-tenant'), 'source or tenant leaked');

$bad = [];
foreach ([
    'oauthVerifiedForImagine','portalTenantGrantVerified',
    'customerDisplayAuthorized','productionReadAuthorized',
    'publicationAuthorized','networkPerformed'
] as $key) $bad[] = array_replace($dto, [$key=>true]);
foreach ([
    'requiresIndependentTenantPropertyGrant',
    'requiresVerifiedWebAnalytics','requiresAuthorizedCrmAttribution',
    'sourceCompletenessNotProven'
] as $key) $bad[] = array_replace($dto, [$key=>false]);
$bad[] = array_replace($dto,['releaseStatus'=>'PRODUCTION_READY']);
$bad[] = array_replace($dto,['tenantRef'=>'other-tenant']);
$bad[] = array_replace($dto,['sourceType'=>'google_authorized']);
$bad[] = array_replace($dto,['rawQuery'=>'SECRET']);
$bad[] = array_replace($dto,['scope'=>array_replace(
    $dto['scope'],['tenantRef'=>'another-tenant'])]);
$bad[] = array_replace($dto,['scope'=>array_replace(
    $dto['scope'],['propertyFingerprint'=>str_repeat('f',64)])]);
$bad[] = array_replace($dto,['conversionRates'=>array_replace(
    $dto['conversionRates'],['visitsToLeads'=>0.1])]);
$bad[] = array_replace($dto,['reviewSignals'=>['review_click_drop']]);
$bad[] = array_replace($dto,['stages'=>array_replace($dto['stages'], [
    0=>array_replace($dto['stages'][0],['value'=>123])
])]);
$bad[] = array_replace($dto,['stages'=>array_replace($dto['stages'], [
    1=>array_replace($dto['stages'][1],['availability'=>'VERIFIED'])
])]);
$bad[] = array_replace($dto,['period'=>array_replace(
    $dto['period'],['requestedDays'=>3])]);

if ($scenario !== 'partial') {
    $bad[] = array_replace($dto,['organicSearchDiscovery'=>array_replace(
        $dto['organicSearchDiscovery'],['clicks'=>999])]);
    $bad[] = array_replace($dto,['organicSearchDiscovery'=>array_replace(
        $dto['organicSearchDiscovery'],['source'=>'google_verified'])]);
    $bad[] = array_replace($dto,['organicSearchDiscoveryStatus'=>
        'PARTIAL_DATES_WITHHELD']);
} else {
    $bad[] = array_replace($dto,['organicSearchDiscoveryStatus'=>
        'SUBMITTED_DATES_ONLY_ORIGIN_UNVERIFIED']);
}

foreach ($bad as $i => $altered) {
    check(Preview::fromDto($altered) === null,
        'forged contract accepted '.$i);
}

putenv('APP_ENV=production');
check(Preview::fromDto($dto) === null,'preview enabled outside testing');
putenv('APP_ENV=testing');
putenv('PORTAL_SEO_F106_LAB=0');
check(Preview::fromDto($dto) === null,'preview enabled without explicit lab gate');
putenv('PORTAL_SEO_F106_LAB=1');

echo 'Portal SEO F106: '.$scenario.' synthetic DTO accepted; '.
    count($bad).' unauthorized mutations rejected; no customer data'.PHP_EOL;
