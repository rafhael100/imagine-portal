<?php
declare(strict_types=1);

require_once __DIR__.'/../../app/Domain/SeoReview/OfflineOpenSeoDailyPreview.php';
use App\Domain\SeoReview\OfflineOpenSeoDailyPreview as Preview;

function requireCondition(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('F104 synthetic check failed: '.$message);
}
if (PHP_SAPI !== 'cli' || $argc !== 3 ||
    !in_array($argv[2], ['complete','partial','empty'], true)) {
    throw new RuntimeException('F104 synthetic fixture path and scenario required');
}
$raw = file_get_contents($argv[1]);
if ($raw === false || strlen($raw) > 32768) {
    throw new RuntimeException('F104 synthetic fixture is missing or excessive');
}
$dto = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
$scenario = $argv[2];
$view = Preview::fromDto($dto);
requireCondition($view !== null, 'legitimate offline DTO rejected');
requireCondition(!$view->canQueryGoogle() && !$view->canPublish() &&
    !$view->canStoreTokens() && !$view->hasVerifiedTenantGrant() &&
    !$view->isCustomerDisplayAuthorized(), 'authorization escaped lab');

requireCondition($view->summary['clicks'] === ($scenario === 'empty' ? 0 : 3),
    'click count mismatch');
requireCondition($view->summary['impressions'] === ($scenario === 'empty' ? 0 : 30),
    'impression count mismatch');
requireCondition($view->period['returnedDays'] === ($scenario === 'empty' ? 0 : 2),
    'returned date count mismatch');
requireCondition($view->missingDatesUnknown === ($scenario !== 'complete'),
    'missing dates must not be fabricated');
requireCondition(strpos(json_encode($view, JSON_THROW_ON_ERROR), 'example.com') === false,
    'property URL leaked into preview');

// Reject malformed or forged authority, identity, shape and metrics without
// echoing the poisoned values to stdout/stderr.
$bad = [];
$bad[] = array_merge($dto, ['tenantRef'=>'another-tenant']);
$bad[] = array_merge($dto, ['propertyFingerprint'=>str_repeat('a', 64)]);
$bad[] = array_merge($dto, ['oauthVerifiedForImagine'=>true]);
$bad[] = array_merge($dto, ['portalTenantGrantVerified'=>true]);
$bad[] = array_merge($dto, ['customerDisplayAuthorized'=>true]);
$bad[] = array_merge($dto, ['productionReadAuthorized'=>true]);
$bad[] = array_merge($dto, ['networkPerformed'=>true]);
$bad[] = array_merge($dto, ['releaseStatus'=>'PRODUCTION_READY']);
$bad[] = array_merge($dto, ['rawQuery'=>'personal@example.invalid']);
$bad[] = array_merge($dto, ['caveats'=>['only one']]);
$bad[] = array_merge($dto, ['summary'=>array_merge(
    $dto['summary'], ['clicks'=>99]
)]);
$bad[] = array_merge($dto, ['period'=>array_merge(
    $dto['period'], ['unreturnedDays'=>99]
)]);
$bad[] = array_merge($dto, ['daily'=>array_merge($dto['daily'], [[
    'date'=>'2099-01-01','clicks'=>1,'impressions'=>10,
    'ctr'=>0.1,'averagePosition'=>8
]])]);
$bad[] = array_merge($dto, ['coverage'=>'COMPLETE_GOOGLE_VERIFIED']);
$bad[] = array_merge($dto, ['sourceCompletenessNotProven'=>false]);
if (count($dto['daily']) >= 2) {
    $reversed = $dto['daily'];
    $reversed = array_reverse($reversed);
    $bad[] = array_merge($dto, ['daily'=>$reversed]);
}
if (count($dto['daily']) >= 1) {
    $wrong = $dto['daily'];
    $wrong[0]['ctr'] = 0.9;
    $bad[] = array_merge($dto, ['daily'=>$wrong]);
}
foreach ($bad as $index => $item) {
    requireCondition(Preview::fromDto($item) === null,
        'invalid DTO accepted '.$index);
}
putenv('APP_ENV=production');
requireCondition(Preview::fromDto($dto) === null, 'preview enabled outside test');
putenv('APP_ENV=testing');
putenv('PORTAL_OPENSEO_DAILY_LAB=0');
requireCondition(Preview::fromDto($dto) === null, 'preview enabled without lab gate');
putenv('PORTAL_OPENSEO_DAILY_LAB=1');
requireCondition(Preview::fromDto($dto) !== null, 'gate restoration failed');

echo "Portal F104: {$scenario} synthetic strict DTO accepted; ".
    count($bad)." tamper cases rejected; no production authority".PHP_EOL;
