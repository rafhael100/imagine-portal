<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscDailyComparisonPreview.php';

use App\Domain\SeoReview\OfflineGscDailyComparisonPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_COMPARISON_LAB')!=='1'){
    fwrite(STDERR,"GSC-F138: test-only execution required\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F138: invalid local synthetic file\n");
    exit(2);
}
try{
    $bytes=file_get_contents($path);
    if($bytes===false)throw new RuntimeException();
    $data=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $preview=Preview::fromDto($data);
    if($preview===null||!$preview->coverageComplete||
        $preview->previous['impressions']!==500||
        $preview->current['impressions']!==200||
        $preview->change['impressionsFraction']!==-.6||
        !in_array('review_click_drop',$preview->reviewSignals,true)||
        $preview->canQueryGoogle()||$preview->canStoreTokens()||
        $preview->canPublish()||$preview->isCustomerDisplayAuthorized())
        throw new RuntimeException();
    // Attempt to elevate unverified provider, tenant or publication claims.
    foreach(['oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
        'productionReadAuthorized','networkPerformed'] as $flag){
        $forged=$data;
        $forged[$flag]=true;
        if(Preview::fromDto($forged)!==null)throw new RuntimeException();
    }
    $partial=$data;
    $partial['coverageComplete']=false;
    $partial['coverageStatus']='PARTIAL_DATA_NO_TREND_SIGNALS';
    if(Preview::fromDto($partial)!==null)throw new RuntimeException();
    $partial['reviewSignals']=[];
    $partial['change']['ctrPercentagePoints']=null;
    $partial['change']['averagePositionDifference']=null;
    if(Preview::fromDto($partial)===null)throw new RuntimeException();
    $malicious=$data;
    $malicious['rawQueries']=['person@example.com'];
    if(Preview::fromDto($malicious)!==null)throw new RuntimeException();
    putenv('APP_ENV=production');
    if(Preview::fromDto($data)!==null)throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F138: contract parity or HOLD assertion failed\n");
    exit(1);
}
echo "GSC-F138 Node F54 to Portal PHP: test-only comparison contract valid\n";
