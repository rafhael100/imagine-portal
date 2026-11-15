<?php
declare(strict_types=1);

/**
 * GSC Slice D — synthetic provider-status classifier contract.
 * The classifier processes only status/Retry-After and is not a HTTP client.
 */
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleProviderResponsePolicy.php';

use App\Domain\SeoReview\GoogleSearchConsoleProviderResponsePolicy as Policy;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_PROVIDER_POLICY_LAB')!=='1'){
    fwrite(STDERR,"GSC Slice D: private CI fixture only\n");
    exit(2);
}
try {
    $policy=new Policy();
    if(Policy::authorizesOutboundHttp()||
       Policy::authorizesTokenStorage()||
       Policy::authorizesCustomerDisplay())
        throw new RuntimeException();

    $cases=[
        [200,[],1,'HTTP_OK_CONTENT_UNVERIFIED',false,null],
        [204,[],1,'HTTP_OK_CONTENT_UNVERIFIED',false,null],
        [401,[],1,'REAUTH_REQUIRED_HOLD',false,null],
        [403,[],1,'PROPERTY_PERMISSION_HOLD',false,null],
        [404,[],1,'PROPERTY_NOT_FOUND_HOLD',false,null],
        [400,[],1,'REVIEW_HTTP_ERROR',false,null],
        [302,[],1,'REVIEW_HTTP_ERROR',false,null],
        [429,[],1,'QUOTA_REVIEW',true,4],
        [429,[],2,'QUOTA_REVIEW',true,8],
        [429,[],3,'QUOTA_REVIEW',false,null],
        [503,['Retry-After'=>'30'],2,'TRANSIENT_PROVIDER_REVIEW',true,30],
        [429,['Retry-After'=>'120'],1,'QUOTA_REVIEW',true,120],
        [500,[],1,'TRANSIENT_PROVIDER_REVIEW',true,4],
        [502,[],2,'TRANSIENT_PROVIDER_REVIEW',true,8],
        [504,[],3,'TRANSIENT_PROVIDER_REVIEW',false,null]
    ];
    foreach($cases as [$status,$headers,$attempt,$category,$retry,$delay]){
        $out=$policy->inspect($status,$headers,$attempt);
        if(!is_array($out)||array_keys($out)!==[
            'category','retryAllowed','retryAfterSeconds',
            'publicationAuthorized','providerAuthenticated'
        ]||$out['category']!==$category||
            $out['retryAllowed']!==$retry||
            $out['retryAfterSeconds']!==$delay||
            $out['publicationAuthorized']!==false||
            $out['providerAuthenticated']!==false)
            throw new RuntimeException();
    }

    $unsafe=[
        [429,['Authorization'=>'Bearer SYNTHETIC-SECRET-D'],1],
        [429,['Set-Cookie'=>'session=SYNTHETIC-SECRET-D'],1],
        [429,['Response-Body'=>'{"secret":"SYNTHETIC-SECRET-D"}'],1],
        [429,['Retry-After'=>"1\nX-Access-Token: SYNTHETIC-SECRET-D"],1],
        [429,['Retry-After'=>'121'],1],
        [429,['Retry-After'=>'0.5'],1],
        [429,['Retry-After'=>'-1'],1],
        [429,['Retry-After'=>12],1],
        [429,['Retry-After'=>'10','X-Cookie'=>'token'],1],
        [401,['Retry-After'=>'8'],1],
        [200,['Retry-After'=>'8'],1],
        [500,['Retry-After'=>'8'],1],
        [429,['Retry-After'=>'8'],0],
        [429,['Retry-After'=>'8'],4],
        [429,['Retry-After'=>'8'],'1'],
        ['429',[],1],
        [600,[],1],
        [0,[],1],
        [null,[],1],
        [429,null,1],
        [429,['some-string'],1]
    ];
    foreach($unsafe as [$status,$headers,$attempt]){
        if($policy->inspect($status,$headers,$attempt)!==null)
            throw new RuntimeException();
    }
    $sanitized=json_encode($policy->inspect(503,['retry-after'=>'10'],1),JSON_THROW_ON_ERROR);
    if(str_contains($sanitized,'SYNTHETIC-SECRET')||
        !str_contains($sanitized,'"providerAuthenticated":false'))
        throw new RuntimeException();
} catch (Throwable) {
    fwrite(STDERR,"GSC Slice D: provider classification contract refused\n");
    exit(1);
}
echo "GSC Slice D: provider status classification accepted; no HTTP, tokens or grants\n";
