<?php
declare(strict_types=1);

/**
 * GSC-F157 — verify actual Node RC32 trend and HTML review policy in PHP.
 * Only synthetic file supplied through CI; not production or a web route.
 */
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscOverlapTrendPreview.php';
use App\Domain\SeoReview\OfflineGscOverlapTrendPreview as Preview;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_TRENDS_LAB')!=='1'||
    count($argv)!==3||
    !in_array($argv[2],['complete','redacted','partial'],true)){
    fwrite(STDERR,"GSC-F157: private synthetic CLI only\n");
    exit(2);
}
$path=$argv[1];$mode=$argv[2];
if(!is_file($path)||is_link($path)||!is_readable($path)||
    filesize($path)===false||filesize($path)>65536){
    fwrite(STDERR,"GSC-F157: invalid local fixture\n");
    exit(2);
}
try{
    $text=file_get_contents($path);
    if(!is_string($text))throw new RuntimeException();
    $dto=json_decode($text,true,32,JSON_THROW_ON_ERROR);
    $out=Preview::fromDto($dto);
    if($out===null||$out->canQueryGoogle()||$out->canPublish()||
        $out->hasVerifiedTenantGrant()||count($dto['caveats'])!==4||
        $dto['networkPerformed']!==false)
        throw new RuntimeException();
    $expected=[
        'complete'=>['COMPLETE_SUPPLIED_BATCHES',true,0.4,1,
            ['review_overlap_share_increase']],
        'redacted'=>['REDACTED_GSC_ROWS_NO_SIGNALS',false,null,1,[]],
        'partial'=>['PARTIAL_GSC_BATCHES_NO_SIGNALS',false,null,0,[]]
    ][$mode];
    if($dto['coverageStatus']!==$expected[0]||
        $out->coverageComplete!==$expected[1]||
        $out->shareChange!==$expected[2]||
        $dto['current']['redactedRows']!==$expected[3]||
        $out->reviewSignals!==$expected[4])
        throw new RuntimeException();
    foreach([
        ['oauthVerified'=>true],
        ['tenantGrantVerified'=>true],
        ['customerDisplayAuthorized'=>true],
        ['releaseStatus'=>'PRODUCTION'],
        ['overlapShareChange'=>0.99],
        ['rawQuery'=>'PERSONAL-SECRET-PRIVATE'],
        ['propertyFingerprint'=>str_repeat('b',64)]
    ] as $tamper){
        if(Preview::fromDto([...$dto,...$tamper])!==null)
            throw new RuntimeException();
    }
    if($mode!=='complete'){
        $tamper=[...$dto,'reviewSignals'=>['review_overlap_share_increase']];
        if(Preview::fromDto($tamper)!==null)throw new RuntimeException();
    }
}catch(Throwable){
    fwrite(STDERR,"GSC-F157: pinned RC32 trend privacy contract refused\n");
    exit(1);
}
echo "GSC-F157: ".$mode." RC32 trend validated, privileges denied\n";
