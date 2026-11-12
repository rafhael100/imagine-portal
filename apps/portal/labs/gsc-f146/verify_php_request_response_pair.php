<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsolePropertyIdentifier.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleSearchAnalyticsPlan.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleQueryPagePaginationPlan.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPageOverlapPreview.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPagePaginationPreview.php';

use App\Domain\SeoReview\GoogleSearchConsoleQueryPagePaginationPlan as Planner;
use App\Domain\SeoReview\OfflineGscQueryPagePaginationPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
    getenv('PORTAL_GSC_PAGINATION_LAB')!=='1'||
    getenv('PORTAL_GSC_REQUEST_LAB')!=='1'){
    fwrite(STDERR,"GSC-F146: synthetic CLI test only\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F146: local fixture refused\n");
    exit(2);
}
try {
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $validated=Preview::fromDto($dto);
    $property='sc-domain:example.com';
    $period=['startDate'=>'2026-10-01','endDate'=>'2026-10-07'];
    if($validated===null ||
        $dto['tenantRef']!=='imagine-demo'||
        $dto['propertyFingerprint']!==hash('sha256',$property)||
        $dto['period']!==$period||
        $validated->pagination['pagesReceived']!==2||
        $validated->pagination['rowsReceived']!==4||
        $validated->pagination['nextStartRow']!==4||
        !$validated->pagination['mayHaveMore'])
        throw new RuntimeException();
    $planner=new Planner();
    for($i=0;$i<$validated->pagination['pagesReceived'];$i++){
        $planned=$planner->buildRequestShape(
            $property,$period['startDate'],$period['endDate'],2,$i*2
        );
        if(!is_array($planned)||$planned['method']!=='POST'||
            ($planned['body']['dimensions']??null)!==['query','page']||
            ($planned['body']['aggregationType']??null)!=='byPage'||
            ($planned['body']['dataState']??null)!=='final'||
            ($planned['body']['startRow']??null)!==$i*2||
            ($planned['body']['rowLimit']??null)!==2||
            parse_url($planned['url'],PHP_URL_HOST)!=='www.googleapis.com')
            throw new RuntimeException();
    }
    $next=$planner->buildRequestShape(
        $property,$period['startDate'],$period['endDate'],2,
        $validated->pagination['nextStartRow']
    );
    if(($next['body']['startRow']??null)!==4||
        Planner::authorizesOutboundRequest()||
        Planner::authorizesGoogleAccess()||
        Planner::authorizesPublication()||
        $validated->canQueryGoogle()||$validated->canPublish())
        throw new RuntimeException();
    // Even a mathematically valid candidate plan never authenticates Google.
    $wrong=$dto;
    $wrong['propertyFingerprint']=str_repeat('e',64);
    if(Preview::fromDto($wrong)!==null)throw new RuntimeException();
    putenv('PORTAL_GSC_REQUEST_LAB=0');
    if(getenv('PORTAL_GSC_REQUEST_LAB')==='1')throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F146: request/response contract parity refused\n");
    exit(1);
}
echo "GSC-F146: provider-shaped plans and F59 page offsets agree; no HTTP permitted\n";
