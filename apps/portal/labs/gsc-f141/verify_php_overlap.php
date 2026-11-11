<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPageOverlapPreview.php';
use App\Domain\SeoReview\OfflineGscQueryPageOverlapPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'){
    fwrite(STDERR,"GSC-F141 test-only invocation required\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F141 local fixture refused\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $view=Preview::fromDto($dto);
    if($view===null||
        $view->summary['queriesOnMultiplePages']!==1||
        $view->summary['redactedRows']!==1||
        $view->reviewStatus!=='POSSIBLE_QUERY_PAGE_OVERLAP_REVIEW'||
        $view->canReadGoogle()||$view->canStoreTokens()||
        $view->canPublish()||$view->hasVerifiedTenantGrant())
        throw new RuntimeException();
    foreach(['oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
        'productionReadAuthorized','networkPerformed'] as $flag){
        $evil=$dto;
        $evil[$flag]=true;
        if(Preview::fromDto($evil)!==null)throw new RuntimeException();
    }
    $raw=$dto;
    $raw['rawQuery']='person@example.com';
    if(Preview::fromDto($raw)!==null)throw new RuntimeException();
    $wrong=$dto;
    $wrong['propertyFingerprint']=str_repeat('b',64);
    if(Preview::fromDto($wrong)!==null)throw new RuntimeException();
    $truncated=$dto;
    $truncated['summary']['possibleTruncation']=true;
    if(Preview::fromDto($truncated)!==null)throw new RuntimeException();
    $truncated['reviewStatus']='PARTIAL_COVERAGE_REVIEW_ONLY';
    if(Preview::fromDto($truncated)===null)throw new RuntimeException();
    putenv('APP_ENV=production');
    if(Preview::fromDto($dto)!==null)throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F141 fail-closed overlap parity check failed\n");
    exit(1);
}
echo "GSC-F141 SEO F57 to Portal: synthetic aggregate contract valid\n";
