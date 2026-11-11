<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscOverlapTrendPreview.php';
use App\Domain\SeoReview\OfflineGscOverlapTrendPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_TRENDS_LAB')!=='1'){
    fwrite(STDERR,"GSC-F152: private synthetic execution required\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F152: unsafe local fixture\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $preview=Preview::fromDto($dto);
    if($preview===null||
        $preview->previous['overlapShare']!==0.1||
        $preview->current['overlapShare']!==0.5||
        $preview->shareChange!==0.4||
        $preview->reviewSignals!==['review_overlap_share_increase']||
        !$preview->coverageComplete||
        $preview->canQueryGoogle()||$preview->canPublish()||
        $preview->hasVerifiedTenantGrant())
        throw new RuntimeException();

    foreach(['oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
        'productionReadAuthorized','networkPerformed'] as $flag){
        $modified=$dto;$modified[$flag]=true;
        if(Preview::fromDto($modified)!==null)throw new RuntimeException();
    }
    foreach([
        ['previous'=>[...$dto['previous'],'overlapShare'=>0.9]],
        ['current'=>[...$dto['current'],'queriesOnMultiplePages'=>9]],
        ['overlapShareChange'=>0.9],
        ['reviewSignals'=>[]],
        ['query'=>'person@example.com'],
        ['propertyFingerprint'=>str_repeat('b',64)]
    ] as $change){
        $modified=[...$dto,...$change];
        if(Preview::fromDto($modified)!==null)throw new RuntimeException();
    }
    $partial=$dto;
    $partial['coverageComplete']=false;
    $partial['coverageStatus']='PARTIAL_GSC_BATCHES_NO_SIGNALS';
    $partial['overlapShareChange']=null;
    $partial['reviewSignals']=[];
    $report=Preview::fromDto($partial);
    if($report===null||$report->coverageComplete||$report->reviewSignals!==[])
        throw new RuntimeException();
    // SEO-F67: newer offline reports include a fourth privacy caveat.
    $modern=$dto;
    $modern['caveats'][]='Redacted lines suppress trends.';
    if(Preview::fromDto($modern)===null)throw new RuntimeException();

    // A redacted observation is partial by policy, even if visible rows
    // still show possible page overlap. Do not surface a review signal.
    $redacted=$modern;
    $redacted['current']['redactedRows']=1;
    $redacted['coverageComplete']=false;
    $redacted['coverageStatus']='REDACTED_GSC_ROWS_NO_SIGNALS';
    $redacted['overlapShareChange']=null;
    $redacted['reviewSignals']=[];
    $masked=Preview::fromDto($redacted);
    if($masked===null||$masked->coverageComplete||
        $masked->reviewSignals!==[]||
        $masked->canPublish()||$masked->canQueryGoogle()||
        $masked->hasVerifiedTenantGrant())
        throw new RuntimeException();

    foreach([
        ['coverageComplete'=>true],
        ['coverageStatus'=>'PARTIAL_GSC_BATCHES_NO_SIGNALS'],
        ['reviewSignals'=>['review_overlap_share_increase']],
        ['overlapShareChange'=>0.4],
        ['caveats'=>$dto['caveats']]
    ] as $change){
        $modified=[...$redacted,...$change];
        if(Preview::fromDto($modified)!==null)throw new RuntimeException();
    }
    $forged=$modern;
    $forged['coverageStatus']='REDACTED_GSC_ROWS_NO_SIGNALS';
    if(Preview::fromDto($forged)!==null)throw new RuntimeException();

    // Run F155 synthetic PHP DTO checks through the existing gated CI lab.
    require_once __DIR__.'/../gsc-f155/test_php_query_page_review.php';
    portalGscF155SyntheticChecks();
    putenv('APP_ENV=production');
    if(Preview::fromDto($dto)!==null)throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F152: overlap trend contract parity refused\n");
    exit(1);
}
echo "GSC-F152: SEO F65 aggregate trend accepted in PHP, no authority granted\n";
