<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPageReviewPreview.php';
use App\Domain\SeoReview\OfflineGscQueryPageReviewPreview as Review;

function portalGscF155SyntheticChecks(): void
{
    putenv('PORTAL_GSC_QUERY_PAGE_REVIEW_LAB=1');
    $dto=[
        'contractVersion'=>'seo-portal-query-page-review-offline-v1',
        'sourceType'=>'operator_supplied_query_page_batch_unverified',
        'scope'=>[
            'tenantRef'=>'imagine-demo',
            'propertyFingerprint'=>hash('sha256','sc-domain:example.com'),
            'sourceRevision'=>str_repeat('a',40)
        ],
        'period'=>['startDate'=>'2026-10-01','endDate'=>'2026-10-07'],
        'coverageStatus'=>'SUPPLIED_BATCH_ONLY_UNVERIFIED',
        'reviewStatus'=>'REQUIRES_AUTHENTICATED_HUMAN_REVIEW',
        'summary'=>[
            'receivedRows'=>2,'redactedRows'=>0,'analyzedRows'=>2,
            'distinctQueries'=>1,'queriesOnMultiplePages'=>1,
            'pagesAcrossOverlappingQueries'=>2
        ],
        'pagination'=>[
            'pagesReceived'=>1,'pageSize'=>3,
            'lastPageRows'=>2,'mayHaveMore'=>false
        ],
        'suggestedReviews'=>['review_query_page_overlap'],
        'oauthVerified'=>false,'propertyAuthorizationVerified'=>false,
        'backendTenantGrantVerified'=>false,'customerDisplayAuthorized'=>false,
        'publicationAuthorized'=>false,'networkPerformed'=>false,
        'releaseStatus'=>'HOLD_EXTERNAL_AUTHORIZATION',
        'limitations'=>['Synthetic only','No OAuth grant','No publication']
    ];
    $preview=Review::fromDto($dto);
    if($preview===null||$preview->canPublish()||
        $preview->canQueryGoogle()||$preview->hasVerifiedTenantGrant()||
        $preview->summary['queriesOnMultiplePages']!==1||
        $preview->suggestedReviews!==['review_query_page_overlap'])
        throw new RuntimeException('F155 contract rejected valid synthetic evidence');

    foreach([
        ['customerDisplayAuthorized'=>true],
        ['oauthVerified'=>true],
        ['publicationAuthorized'=>true],
        ['networkPerformed'=>true],
        ['releaseStatus'=>'APPROVED'],
        ['scope'=>[...$dto['scope'],'tenantRef'=>'other-tenant']],
        ['summary'=>[...$dto['summary'],'receivedRows'=>4]],
        ['pagination'=>[...$dto['pagination'],'pageSize'=>1]],
        ['suggestedReviews'=>[]],
        ['rawQuery'=>'example@example.com']
    ] as $change){
        if(Review::fromDto([...$dto,...$change])!==null)
            throw new RuntimeException('F155 accepted forged field');
    }

    // A different well-formed page size is structurally valid; only a
    // separately authenticated backend request can bind its true size.
    $alternative=$dto;
    $alternative['pagination']['pageSize']=4;
    $other=Review::fromDto($alternative);
    if($other===null||$other->canPublish()||
        $other->hasVerifiedTenantGrant())
        throw new RuntimeException('F155 misclassified operator-declared pagination');

    $redacted=$dto;
    $redacted['summary']=[
        'receivedRows'=>2,'redactedRows'=>1,'analyzedRows'=>1,
        'distinctQueries'=>1,'queriesOnMultiplePages'=>0,
        'pagesAcrossOverlappingQueries'=>0
    ];
    $redacted['coverageStatus']='REDACTED_ROWS_NO_SIGNALS';
    $redacted['suggestedReviews']=[];
    if(Review::fromDto($redacted)===null)
        throw new RuntimeException('F155 rejected privacy-safe synthetic evidence');
    $bad=$redacted;$bad['suggestedReviews']=['review_query_page_overlap'];
    if(Review::fromDto($bad)!==null)
        throw new RuntimeException('F155 accepted a signal after redaction');

    $partial=$dto;
    $partial['pagination']=[
        'pagesReceived'=>1,'pageSize'=>2,
        'lastPageRows'=>2,'mayHaveMore'=>true
    ];
    $partial['coverageStatus']='PARTIAL_BATCH_NO_SIGNALS';
    $partial['suggestedReviews']=[];
    if(Review::fromDto($partial)===null)
        throw new RuntimeException('F155 rejected incomplete synthetic evidence');

    putenv('APP_ENV=production');
    if(Review::fromDto($dto)!==null)
        throw new RuntimeException('F155 leaked into production');
    putenv('APP_ENV=testing');
    putenv('PORTAL_GSC_QUERY_PAGE_REVIEW_LAB');
}
