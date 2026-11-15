<?php
declare(strict_types=1);

$domain=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach([
    'GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleSearchAnalyticsPlan.php',
    'GoogleSearchConsoleQueryPagePaginationPlan.php',
    'OfflineGscQueryPageOverlapPreview.php',
    'OfflineGscQueryPagePaginationPreview.php',
    'GoogleSearchConsoleNextPageIntent.php',
] as $name)require_once $domain.$name;

use App\Domain\SeoReview\GoogleSearchConsoleNextPageIntent as Planner;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
    getenv('PORTAL_GSC_PAGINATION_LAB')!=='1'||
    getenv('PORTAL_GSC_REQUEST_LAB')!=='1'){
    fwrite(STDERR,"GSC-F151: private test context required\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F151: local fixture refused\n");
    exit(2);
}
try{
    $json=file_get_contents($path);
    if(!is_string($json))throw new RuntimeException();
    $dto=json_decode($json,true,64,JSON_THROW_ON_ERROR);
    $planner=new Planner();
    $request=$planner->fromOfflineDto($dto,'sc-domain:example.com');
    if(!is_array($request)||
        $request['contractVersion']!=='portal-gsc-next-page-intent-review-v1'||
        $request['status']!=='SYNTHETIC_NEXT_PAGE_INTENT_NOT_AUTHORIZED'||
        ($request['request']['body']['startRow']??null)!==4||
        ($request['request']['body']['rowLimit']??null)!==2||
        ($request['request']['body']['dimensions']??null)!==['query','page']||
        ($request['request']['body']['aggregationType']??null)!=='byPage'||
        ($request['request']['body']['dataState']??null)!=='final'||
        $request['networkPerformed']!==false||
        $request['oauthVerified']!==false||
        $request['productionReadAuthorized']!==false||
        Planner::authorizesOutboundHttp()||
        Planner::authorizesTokenAccess())
        throw new RuntimeException();

    if($planner->fromOfflineDto($dto,'https://example.com/')!==null)
        throw new RuntimeException();
    if($planner->fromOfflineDto($dto,'sc-domain:evil.invalid')!==null)
        throw new RuntimeException();
    $legacy=$dto;
    $legacy['contractVersion']='seo-gsc-query-page-pagination-offline-v1';
    unset($legacy['pagination']['pageSize'],$legacy['pagination']['lastPageRows']);
    if($planner->fromOfflineDto($legacy,'sc-domain:example.com')!==null)
        throw new RuntimeException();
    foreach([
        ['nextStartRow'=>6],
        ['pageSize'=>3],
        ['lastPageRows'=>1],
        ['mayHaveMore'=>false],
        ['maxPagesReached'=>true],
    ] as $forged){
        $malicious=$dto;
        $malicious['pagination']=[...$malicious['pagination'],...$forged];
        if($planner->fromOfflineDto($malicious,'sc-domain:example.com')!==null)
            throw new RuntimeException();
    }
    foreach(['oauthVerified','customerDisplayAuthorized','tenantGrantVerified'] as $flag){
        $malicious=$dto;
        $malicious[$flag]=true;
        if($planner->fromOfflineDto($malicious,'sc-domain:example.com')!==null)
            throw new RuntimeException();
    }
    putenv('APP_ENV=production');
    if($planner->fromOfflineDto($dto,'sc-domain:example.com')!==null)
        throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F151: next-page intent parity or security assertion failed\n");
    exit(1);
}
echo "GSC-F151: v2 continuation planned syntactically; no HTTP or token enabled\n";
