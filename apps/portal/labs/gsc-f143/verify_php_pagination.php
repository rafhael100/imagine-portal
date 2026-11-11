<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPageOverlapPreview.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscQueryPagePaginationPreview.php';
use App\Domain\SeoReview\OfflineGscQueryPagePaginationPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
    getenv('PORTAL_GSC_PAGINATION_LAB')!=='1'){
    fwrite(STDERR,"GSC-F143: test-only execution required\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F143: local file refused\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if(!is_string($bytes))throw new RuntimeException();
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $preview=Preview::fromDto($dto);
    if($preview===null ||
        $preview->pagination['pagesReceived']!==2||
        $preview->pagination['rowsReceived']!==4||
        $preview->pagination['nextStartRow']!==4||
        !$preview->pagination['mayHaveMore']||
        $preview->aggregate->summary['queriesOnMultiplePages']!==1||
        $preview->aggregate->summary['redactedRows']!==1||
        $preview->canQueryGoogle()||$preview->canStoreTokens()||
        $preview->canPublish()||$preview->canDisplayToCustomers())
        throw new RuntimeException();

    foreach(['oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
        'productionReadAuthorized','networkPerformed'] as $field){
        $forged=$dto;
        $forged[$field]=true;
        if(Preview::fromDto($forged)!==null)throw new RuntimeException();
    }
    foreach([
        ['nextStartRow'=>6],
        ['pagesReceived'=>3],
        ['rowsReceived'=>3],
        ['maxPagesReached'=>true],
        ['mayHaveMore'=>false],
        ['oauthVerified'=>true]
    ] as $changes){
        $tampered=$dto;
        $tampered['pagination']=[...$tampered['pagination'],...$changes];
        if(Preview::fromDto($tampered)!==null)throw new RuntimeException();
    }
    $hidden=$dto;
    $hidden['rawQueries']=['someone@example.com'];
    if(Preview::fromDto($hidden)!==null)throw new RuntimeException();
    $wrong=$dto;
    $wrong['propertyFingerprint']=str_repeat('c',64);
    if(Preview::fromDto($wrong)!==null)throw new RuntimeException();
    putenv('APP_ENV=production');
    if(Preview::fromDto($dto)!==null)throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F143: paginated contract or safety assertion failed\n");
    exit(1);
}
echo "GSC-F143 F59→PHP: two-page synthetic overlap verified and production denied\n";
