<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackPolicy.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthStateLedger.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackCoordinator.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthTokenExchangePlan.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackOutcome as Outcome;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenExchangePlan as Planner;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_TOKEN_PLAN_LAB')!=='1'){
    fwrite(STDERR,"GSC Slice L: offline synthetic token plan only\n");
    exit(2);
}
$check=static function(bool $value):void{
    if(!$value)throw new RuntimeException();
};
try{
    $redirect='https://portal.example.com/integrations/google/search-console/callback';
    $clientId='123456-exampleclient.apps.googleusercontent.com';
    $secret='synthetic-only-not-a-real-credential';
    $config=['clientId'=>$clientId,'clientSecret'=>$secret,
        'registeredRedirectUri'=>$redirect];
    $code='4/0AbCdEfGhIJKL-opaque-code_TEST';
    $verifier=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $outcome=new Outcome('CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND',
        $code,$verifier);
    $planner=new Planner();
    $check(!Planner::authorizesHttp()&&!Planner::authorizesTokenStorage()&&
        !Planner::authorizesCustomerDisplay());
    $check(Pkce::validRegistration([
        'clientId'=>$clientId,'registeredRedirectUri'=>$redirect
    ]));
    $request=$planner->prepare($config,$outcome);
    $check($request!==null);
    $review=$request->safeReview();
    $check($review['endpoint']==='https://oauth2.googleapis.com/token'
        &&$review['method']==='POST'
        &&$review['contentType']==='application/x-www-form-urlencoded'
        &&$review['redirectsAllowed']===false
        &&$review['timeoutSeconds']===10
        &&$review['googleTokenVerified']===false
        &&$review['tokenStorageAuthorized']===false
        &&$review['customerDisplayAuthorized']===false
        &&$review['releaseStatus']==='HOLD_EXTERNAL_AUTHENTICATED_BACKEND');
    foreach([
        json_encode($request,JSON_THROW_ON_ERROR),
        var_export($request,true),
        print_r($request,true),
        json_encode($review,JSON_THROW_ON_ERROR)
    ] as $safeOutput){
        $check(!str_contains($safeOutput,$code)
            &&!str_contains($safeOutput,$verifier)
            &&!str_contains($safeOutput,$secret));
    }
    $transport=$request->takeForTrustedHttpsTransport();
    $check($transport['url']==='https://oauth2.googleapis.com/token' &&
        $transport['method']==='POST' &&
        $transport['allowRedirects']===false &&
        $transport['timeoutSeconds']===10 &&
        $transport['headers'] === [
            'Content-Type'=>'application/x-www-form-urlencoded',
            'Accept'=>'application/json'
        ]);
    parse_str($transport['body'],$params);
    $check(array_keys($params)===[
        'client_id','client_secret','code','code_verifier',
        'redirect_uri','grant_type'
    ]);
    $check($params['client_id']===$clientId &&
        $params['client_secret']===$secret &&
        $params['code']===$code &&
        $params['code_verifier']===$verifier &&
        $params['redirect_uri']===$redirect &&
        $params['grant_type']==='authorization_code' &&
        !isset($params['scope'],$params['refresh_token'],$params['access_token']));

    $denied=new Outcome('CONSENT_DENIED_HOLD',null,null);
    $check($planner->prepare($config,$denied)===null);
    $bad=[
        [...$config,'scope'=>Pkce::READONLY_SCOPE],
        [...$config,'registeredRedirectUri'=>'https://evil.example/token'],
        [...$config,'registeredRedirectUri'=>'http://portal.example.com/integrations/google/search-console/callback'],
        [...$config,'registeredRedirectUri'=>$redirect.'?next=evil'],
        [...$config,'clientId'=>'client_id'],
        [...$config,'clientSecret'=>"line\r\nAuthorization: synthetic"],
        [...$config,'clientSecret'=>str_repeat('a',513)],
        [...$config,'clientSecret'=>'abc']
    ];
    foreach($bad as $candidate)$check($planner->prepare($candidate,$outcome)===null);
    foreach([
        new Outcome('CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND',"bad\r\ncode",$verifier),
        new Outcome('CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND',$code,'not-pkce'),
        new Outcome('PROVIDER_ERROR_HOLD',$code,$verifier)
    ] as $forged)$check($planner->prepare($config,$forged)===null);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice L: token request plan contract refused\n");
    exit(1);
}
echo "GSC Slice L: fixed token endpoint and safe server-only form validated; no HTTP\n";
