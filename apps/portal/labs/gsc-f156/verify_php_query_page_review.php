<?php
declare(strict_types=1);

/**
 * GSC-F156 — verify the actual SEO RC32 -> PHP F155 DTO boundary in CI.
 * No HTTP, no OAuth, no real tenant data and no route registration.
 */
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPageReviewPreview.php';
use App\Domain\SeoReview\OfflineGscQueryPageReviewPreview as Review;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_QUERY_PAGE_REVIEW_LAB')!=='1'||
    count($argv)!==3||
    !in_array($argv[2],['complete','redacted','partial','empty','multi','multi_redacted'],true)){
    fwrite(STDERR,"GSC-F156: private CI laboratory only\n");
    exit(2);
}
$path=$argv[1];$mode=$argv[2];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F156: invalid synthetic input\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    $report=Review::fromDto($dto);
    if($report===null||$report->canQueryGoogle()||
       $report->canPublish()||$report->hasVerifiedTenantGrant())
        throw new RuntimeException();

    // A plan SHA is not authorization, but the test must prove which
    // immutable SEO source generated this synthetic DTO.
    $pinned='f46b65aaef9b10e32d02e415907cd916a43b548e';
    if(!hash_equals($pinned,$dto['scope']['sourceRevision']))throw new RuntimeException();
    $expected=[
        'complete'=>['SUPPLIED_BATCH_ONLY_UNVERIFIED',1,0,2,['review_query_page_overlap']],
        'redacted'=>['REDACTED_ROWS_NO_SIGNALS',0,1,2,[]],
        'partial'=>['PARTIAL_BATCH_NO_SIGNALS',1,0,2,[]],
        'empty'=>['SUPPLIED_BATCH_ONLY_UNVERIFIED',0,0,0,[]],
        'multi'=>['SUPPLIED_BATCH_ONLY_UNVERIFIED',1,0,3,['review_query_page_overlap']],
        'multi_redacted'=>['REDACTED_ROWS_NO_SIGNALS',0,1,3,[]]
    ][$mode];
    if($report->coverageStatus!==$expected[0]||
        $report->summary['queriesOnMultiplePages']!==$expected[1]||
        $report->summary['redactedRows']!==$expected[2]||
        $report->summary['receivedRows']!==$expected[3]||
        $report->suggestedReviews!==$expected[4])throw new RuntimeException();

    foreach([
        ['customerDisplayAuthorized'=>true],
        ['publicationAuthorized'=>true],
        ['backendTenantGrantVerified'=>true],
        ['oauthVerified'=>true],
        ['sourceType'=>'live_google_verified'],
        ['rawQuery'=>'SYNTHETIC-PRIVATE-NEVER'],
        ['scope'=>[...$dto['scope'],'tenantRef'=>'other-tenant']],
        ['summary'=>[...$dto['summary'],'receivedRows'=>99]],
        ['pagination'=>[...$dto['pagination'],'lastPageRows'=>99]],
        ['coverageStatus'=>'VERIFIED'],
        ['suggestedReviews'=>['approve_redirect']]
    ] as $change){
        if(Review::fromDto([...$dto,...$change])!==null)
            throw new RuntimeException();
    }
    if(str_starts_with($mode,'multi')){
        if($dto['pagination']['pagesReceived']!==2||
            $dto['pagination']['pageSize']!==2||
            $dto['pagination']['lastPageRows']!==1||
            $dto['pagination']['mayHaveMore']!==false)
            throw new RuntimeException();
    }
    $forged=$dto;
    if(in_array($mode,['redacted','partial','multi_redacted'],true)){
        $forged['suggestedReviews']=['review_query_page_overlap'];
        if(Review::fromDto($forged)!==null)throw new RuntimeException();
    }
}catch(Throwable){
    fwrite(STDERR,"GSC-F156: Node RC32 -> PHP review contract refused\n");
    exit(1);
}
echo "GSC-F156: ".$mode." pinned Node RC32 -> PHP preview accepted, permissions denied\n";
