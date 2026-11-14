<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F117_PROPERTY_LAB')!=='1' ||
    $argc!==2 ||
    basename($argv[1])!=='GoogleSearchConsolePropertyIdentifier.php'){
    fwrite(STDERR,"F117 offline property evidence CI only\n");exit(2);
}
require_once $argv[1];
require_once dirname(__DIR__,2).
    '/app/Domain/SeoReview/OfflinePortalGscSiteListEvidenceReview.php';

use App\Domain\SeoReview\OfflinePortalGscSiteListEvidenceReview as Review;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier as SiteId;

function checkF117(bool $value):void{
    if(!$value){
        $location=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1);
        throw new RuntimeException(
            'F117 synthetic review assertion line '.
            ($location[0]['line']??'?')
        );
    }
}
function evidence(array $entries):string{
    return json_encode(['siteEntry'=>$entries],JSON_THROW_ON_ERROR);
}
function site(string $url,string $level):array{
    return ['siteUrl'=>$url,'permissionLevel'=>$level];
}
$review=new Review();
$now=time();
$positive=0;$denied=0;
try{
    checkF117(!Review::canProvisionGrant() &&
        !Review::canAuthorizeCustomer() && !Review::canContactGoogle());
    foreach([
        ['sc-domain:example.com','siteOwner'],
        ['https://example.com/','siteOwner'],
        ['http://www.example.com/','siteFullUser'],
        ['sc-domain:example.net','siteFullUser'],
    ] as [$exact,$level]){
        $body=evidence([
            site('sc-domain:other.example','siteOwner'),
            site($exact,$level),
            site('https://example.org/','siteRestrictedUser'),
        ]);
        $result=$review->inspect(200,$body,$exact,$now);
        checkF117(is_array($result));
        checkF117($result['contractVersion']===
            'portal-gsc-f117-site-list-evidence-lab-v1');
        checkF117($result['exactSiteUrlMatched']===true);
        checkF117($result['candidatePermissionLevel']===$level);
        checkF117($result['siteIdentifierFingerprint']===
            SiteId::fingerprint($exact));
        checkF117($result['siteEntryCount']===3);
        foreach([
            'providerOriginVerified','googleAccessTokenVerified',
            'googleAccountOwnershipVerified','googleSiteAccessCheckedNow',
            'consentVerified','portalTenantMembershipAuthorized',
            'recordedGrantWritten','customerDisplayAuthorized',
            'productionReadAuthorized','googleNetworkPerformed',
        ] as $flag)checkF117($result[$flag]===false);
        $safe=json_encode($result,JSON_THROW_ON_ERROR);
        checkF117(!str_contains($safe,$exact));
        checkF117(!str_contains($safe,'access_token'));
        $positive++;
    }
    $exact='sc-domain:example.com';
    $valid=evidence([site($exact,'siteOwner')]);
    foreach([
        evidence([site($exact,'siteRestrictedUser')]),
        evidence([site($exact,'siteUnverifiedUser')]),
        evidence([site('sc-domain:other.example','siteOwner')]),
        evidence([site('https://example.com/','siteOwner')]),
        evidence([site('sc-domain:example.com.','siteOwner')]),
        evidence([site($exact,'siteOwner'),site($exact,'siteOwner')]),
        evidence([site($exact,'siteOwner'),site($exact,'siteFullUser')]),
        evidence([site($exact,'siteOwner'),site('ftp://example.org/','siteOwner')]),
        evidence([site($exact,'siteOwner'),site('https://EXAMPLE.org/','siteFullUser')]),
        evidence([site($exact,'siteAdmin')]),
        evidence([site($exact,'')]),
        evidence([]),
        '{}','[]','{"siteEntry":{}}',
        '{"siteEntry":[{"siteUrl":"sc-domain:example.com"}]}',
        '{"siteEntry":[{"siteUrl":"sc-domain:example.com","permissionLevel":"siteOwner","owner":true}]}',
        '{"siteEntry":[],"access_token":"forged"}',
        '{not json',
        '<script>alert("x")</script>',
        str_repeat('Z',32769),
    ] as $json){
        checkF117($review->inspect(200,$json,$exact,$now)===null);
        $denied++;
    }
    foreach([
        0,100,201,204,206,301,302,400,401,403,404,429,500,503,599,
        '200',null,true
    ] as $status){
        checkF117($review->inspect($status,$valid,$exact,$now)===null);
        $denied++;
    }
    foreach([
        'sc-domain:EXAMPLE.com','sc-domain:example.com.',
        'http://127.0.0.1/','ftp://example.com/',
        'https://EXAMPLE.com/','https://example.com/../',
        'https://example.com/?q=1','sc-domain:example.com%2f',
        '',null
    ] as $requested){
        checkF117($review->inspect(200,$valid,$requested,$now)===null);
        $denied++;
    }
    $massive=[];
    for($i=0;$i<201;$i++){
        $massive[]=site("sc-domain:example{$i}.com",'siteOwner');
    }
    checkF117($review->inspect(200,evidence($massive),$exact,$now)===null);
    $denied++;
    putenv('PORTAL_GSC_F117_PROPERTY_LAB=0');
    checkF117($review->inspect(200,$valid,$exact,$now)===null);
    putenv('PORTAL_GSC_F117_PROPERTY_LAB=1');
    $denied++;
    putenv('APP_ENV=production');
    checkF117($review->inspect(200,$valid,$exact,$now)===null);
    putenv('APP_ENV=testing');
    $denied++;
    checkF117($review->inspect(200,$valid,$exact,1500000000)===null);
    $denied++;
    checkF117(is_array($review->inspect(200,$valid,$exact,$now)));
    echo "F117: {$positive} strictly matched synthetic site candidates; ".
        "{$denied} property, permission, status, size and environment ".
        "denials; 0 provider authority / 0 tenant grants\n";
}catch(Throwable $e){
    fwrite(STDERR,"F117 failed: ".$e->getMessage()."\n");
    exit(1);
}
