<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineSeoHandoffPreview.php';
use App\Domain\SeoReview\OfflineSeoHandoffPreview as Preview;

if(PHP_SAPI!=='cli'||count($argv)!==2||
    getenv('APP_ENV')!=='testing'||getenv('PORTAL_SEO_HANDOFF_LAB')!=='1'){
    fwrite(STDERR,"GSC-F140: private test only\n");
    exit(2);
}
$path=$argv[1];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F140: synthetic file refused\n");
    exit(2);
}
try{
    $content=file_get_contents($path);
    if(!is_string($content))throw new RuntimeException();
    $dto=json_decode($content,true,64,JSON_THROW_ON_ERROR);
    $out=Preview::fromDto($dto);
    if($out===null||($out->organic['current']['impressions']??null)!==200||
        ($out->organic['previous']['impressions']??null)!==500||
        $out->canPublish()||$out->canDispatch()||
        $out->hasVerifiedTenantGrant()||$out->hasVerifiedGoogleProperty()||
        $out->isCustomerDisplayAuthorized())
        throw new RuntimeException();
    $codes=array_column($out->suggestedReviews,'code');
    foreach(['repair_canonical','review_impression_drop',
        'review_click_drop','review_average_position'] as $expected){
        if(!in_array($expected,$codes,true))throw new RuntimeException();
    }
    // Never treat custom "verified" status as authority, regardless of totals.
    foreach(['publicationAuthorized','propertyAuthorizationVerified',
        'customerDisplayAuthorized','backendTenantGrantVerified'] as $flag){
        $forged=$dto;
        $forged[$flag]=true;
        if(Preview::fromDto($forged)!==null)throw new RuntimeException();
    }
    $tampered=$dto;
    $tampered['suggestedReviews'][0]['rawQuery']='person@example.com';
    if(Preview::fromDto($tampered)!==null)throw new RuntimeException();
    putenv('APP_ENV=production');
    if(Preview::fromDto($dto)!==null)throw new RuntimeException();
}catch(Throwable){
    fwrite(STDERR,"GSC-F140: brief parity or security assertion failed\n");
    exit(1);
}
echo "GSC-F140 SEO F56 to Portal F24: synthetic handoff accepted, permissions denied\n";
