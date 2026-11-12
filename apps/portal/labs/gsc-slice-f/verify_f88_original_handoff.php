<?php
declare(strict_types=1);

/**
 * GSC Slice F — strictly isolated, synthetic PHP verifier for pinned SEO-F88.
 * No production routes, token handling, database or customer display.
 */
 $phase = "input";
function denyF88(): never {
    global $phase;
    fwrite(STDERR, "GSC Slice F: synthetic contract refused at {$phase}\n");
    exit(1);
}
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('PORTAL_GSC_F88_LAB') !== '1' || $argc !== 3 ||
    !in_array($argv[2], ['complete','redacted'], true)) {
    denyF88();
}
$path=$argv[1];
if (!is_file($path) || is_link($path) || filesize($path)>1048576 ||
    filesize($path)===false) {
    denyF88();
}
try {
    $payload=file_get_contents($path, false, null, 0, 1048577);
    if (!is_string($payload) || strlen($payload)>1048576 ||
        preg_match('/SYNTHETIC-SLICE-F-PRIVATE|123\.456\.789-09|<script|Bearer /i',$payload)) {
        denyF88();
    }
    $dto=json_decode($payload,true,32,JSON_THROW_ON_ERROR);
    $phase = "envelope";
    if (!is_array($dto) || array_is_list($dto))denyF88();
    $only=function(array $value,array $allowed): bool {
        $actual=array_keys($value);
        sort($actual);sort($allowed);
        return $actual===$allowed;
    };
    if(!$only($dto,[
        'contractVersion','sourceType','coverage','brief','handoff',
        'oauthVerified','propertyAuthorizationVerified','backendTenantGrantVerified',
        'customerDisplayAuthorized','publicationAuthorized','networkPerformed',
        'releaseStatus','caveats'
    ])||
        $dto['contractVersion']!=='seo-portal-original-gsc-handoff-offline-v1'||
        $dto['sourceType']!=='operator_supplied_original_query_rows_not_google_verified'||
        $dto['releaseStatus']!=='HOLD_EXTERNAL_AUTHORIZATION'||
        !is_array($dto['coverage'])||!is_array($dto['brief'])||
        !is_array($dto['handoff'])||!is_array($dto['caveats']))denyF88();
    $phase = "privilege-flags";
    foreach(['oauthVerified','propertyAuthorizationVerified','backendTenantGrantVerified',
        'customerDisplayAuthorized','publicationAuthorized','networkPerformed'] as $flag){
        if($dto[$flag]!==false)denyF88();
    }
    $phase = "coverage";
    $c=$dto['coverage'];
    if(!$only($c,['status','metricsWithheld','reason','receivedRows','redactedRows'])||
        !is_array($c['receivedRows'])||
        !$only($c['receivedRows'],['previous','current'])||
        $c['receivedRows']['previous']!==1||
        $c['receivedRows']['current']!==($argv[2]==='redacted'?2:1)||
        $c['redactedRows']!==($argv[2]==='redacted'?1:0))denyF88();
    $withheld=$argv[2]==='redacted';
    if($c['metricsWithheld']!==$withheld||
        $c['reason']!==($withheld?'PRIVACY_REDACTION':null)||
        $c['status']!==($withheld?'REDACTED_ROWS_NO_COMPARISON':
            'SUPPLIED_ROWS_ONLY_NOT_VERIFIED'))denyF88();
    $phase = "brief-handoff";
    $brief=$dto['brief'];$handoff=$dto['handoff'];
    if(!$only($brief,[
        'contractVersion','sourceType','technical','organic',
        'recommendedReviews','limitations'
    ])||$brief['contractVersion']!=='seo-brief-offline-v1'||
        $brief['sourceType']!=='operator_supplied_offline_reports_unverified'||
        !is_array($brief['technical'])||!is_array($brief['recommendedReviews'])||
        !$only($handoff,[
            'contractVersion','origin','scope','releaseStatus',
            'customerDisplayAuthorized','propertyAuthorizationVerified',
            'backendTenantGrantVerified','publicationAuthorized','technical',
            'organic','suggestedReviews','requirements'
        ])||$handoff['contractVersion']!=='seo-portal-review-handoff-v1'||
        $handoff['releaseStatus']!=='HOLD_EXTERNAL_AUTHORIZATION'||
        $handoff['customerDisplayAuthorized']!==false||
        $handoff['propertyAuthorizationVerified']!==false||
        $handoff['backendTenantGrantVerified']!==false||
        $handoff['publicationAuthorized']!==false||
        !is_array($handoff['scope'])||
        !$only($handoff['scope'],['tenantRef','propertyFingerprint','sourceRevision'])||
        $handoff['scope']['tenantRef']!=='imagine-demo'||
        $handoff['scope']['propertyFingerprint']!==str_repeat('a',64)||
        $handoff['scope']['sourceRevision']!==str_repeat('b',40)||
        $handoff['technical']!==$brief['technical']||
        !is_array($handoff['suggestedReviews']))denyF88();
    $phase = "technical-summary";
    $technical=$brief['technical'];
    if(!$only($technical,['status','pages','errors','warnings'])||
       $technical!==[
           'status'=>'review_required','pages'=>2,'errors'=>1,'warnings'=>0
       ])denyF88();
    $phase = "review-codes";
    $reviewCodes=array_column($handoff['suggestedReviews'],'code');
    if(!in_array('repair_canonical',$reviewCodes,true))denyF88();
    $phase = "scenario";
    if($withheld){
        if($brief['organic']!==null||$handoff['organic']!==null||
            $reviewCodes!==['repair_canonical']||
            array_column($brief['recommendedReviews'],'code')!==['repair_canonical'])
            denyF88();
    }else{
        if(!is_array($brief['organic'])||!is_array($handoff['organic'])||
           $handoff['organic']['previous']['clicks']!==30||
           $handoff['organic']['current']['clicks']!==8||
           $handoff['organic']['days']!==7||
           !in_array('review_click_drop',$reviewCodes,true))denyF88();
    }
} catch(Throwable) {
    denyF88();
}
echo "GSC Slice F: PHP original-row handoff {$argv[2]} accepted; no grants or publication\n";
