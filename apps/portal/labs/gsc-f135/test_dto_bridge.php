<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscWorkflowPreview.php';

use App\Domain\SeoReview\OfflineGscWorkflowPreview as Preview;

putenv('APP_ENV=testing');
putenv('PORTAL_GSC_WORKFLOW_LAB=1');

function ensure(bool $ok): void
{
    if (!$ok) throw new RuntimeException('GSC-F135 standalone assertion failed');
}

$sample=[
    'contractVersion'=>'seo-gsc-offline-workflow-v1',
    'tenantRef'=>'imagine-demo',
    'propertyFingerprint'=>hash('sha256','sc-domain:example.com'),
    'sourceType'=>'operator_supplied_not_verified',
    'status'=>'OFFLINE_REVIEW_ONLY',
    'pagination'=>[
        'pagesReceived'=>1,'rowsReceived'=>2,
        'mayHaveMore'=>false,'sourceMayBeTruncated'=>true,
    ],
    'summary'=>[
        'inputRows'=>2,'redactedRows'=>1,'analyzedRows'=>1,
        'clicks'=>2,'impressions'=>10,
    ],
    'oauthVerified'=>false,'backendTenantGrantVerified'=>false,
    'propertyConsentVerified'=>false,'customerDisplayAuthorized'=>false,
    'productionReadAuthorized'=>false,'networkPerformed'=>false,
    'releaseStatus'=>'HOLD_EXTERNAL_BACKEND_VERIFICATION',
];
$preview=Preview::fromDto($sample);
ensure($preview!==null && !$preview->canQueryGoogle() &&
    !$preview->canPublish() && !$preview->canStoreTokens());

foreach(['oauthVerified','backendTenantGrantVerified',
    'propertyConsentVerified','customerDisplayAuthorized',
    'productionReadAuthorized','networkPerformed'] as $flag) {
    $bad=$sample;
    $bad[$flag]=true;
    ensure(Preview::fromDto($bad)===null);
}

foreach([
    [...$sample,'rawQuery'=>'private@example.com'],
    [...$sample,'tenantRef'=>'another-tenant'],
    [...$sample,'pagination'=>[...$sample['pagination'],'rowsReceived'=>3]],
    [...$sample,'summary'=>[...$sample['summary'],'clicks'=>11]],
] as $bad) ensure(Preview::fromDto($bad)===null);

putenv('PORTAL_GSC_WORKFLOW_LAB=0');
ensure(Preview::fromDto($sample)===null);
putenv('PORTAL_GSC_WORKFLOW_LAB=1');
putenv('APP_ENV=production');
ensure(Preview::fromDto($sample)===null);
echo "GSC-F135 standalone DTO bridge: OK\n";
