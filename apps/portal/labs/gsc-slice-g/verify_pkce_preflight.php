<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Policy;

if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_PKCE_LAB')!=='1'){
    fwrite(STDERR,"GSC Slice G: synthetic review only\n");
    exit(2);
}
$check=static function(bool $accepted):void{
    if(!$accepted)throw new RuntimeException();
};
try{
    $planner=new Policy();
    $check(!Policy::authorizesGoogleHttp() &&
        !Policy::authorizesTokenPersistence() &&
        !Policy::authorizesCustomerDisplay());

    $callback='https://portal.example.com/integrations/google/search-console/callback';
    $config=['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
        'registeredRedirectUri'=>$callback];
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $now=1790000000;
    $a=$planner->prepare($config,$context,$now);
    $b=$planner->prepare($config,$context,$now);
    $check(is_array($a)&&is_array($b));
    foreach([$a,$b] as $intent){
        $check($intent['contractVersion']==='portal-gsc-oauth-pkce-preflight-offline-v1' &&
            $intent['releaseStatus']==='HOLD_EXTERNAL_AUTHENTICATED_BACKEND');
        foreach(['oauthVerified','tenantGrantVerified','propertyAuthorizationVerified',
            'callbackAuthenticated','tokenExchangeAuthorized',
            'customerDisplayAuthorized','networkPerformed'] as $field){
            $check($intent[$field]===false);
        }
        $pub=$intent['publicAuthorization'];
        $secret=$intent['serverOnly'];
        $check(is_array($pub)&&is_array($secret) &&
            $pub['scope']===Policy::READONLY_SCOPE &&
            $pub['codeChallengeMethod']==='S256' &&
            $secret['tenantRef']===$context['tenantRef'] &&
            $secret['actorRef']===$context['actorRef'] &&
            $secret['issuedAt']===$now && $secret['expiresAt']===$now+600 &&
            $secret['consumed']===false &&
            preg_match('/^[A-Za-z0-9_-]{43}$/D',$pub['state'])===1 &&
            preg_match('/^[A-Za-z0-9_-]{43}$/D',$secret['codeVerifier'])===1);
        $expected=rtrim(strtr(base64_encode(
            hash('sha256',$secret['codeVerifier'],true)
        ),'+/','-_'),'=');
        $check($pub['codeChallenge']===$expected &&
            $secret['stateSha256']===hash('sha256',$pub['state']) &&
            !str_contains($pub['url'],$secret['codeVerifier']) &&
            !str_contains($pub['url'],$context['tenantRef']) &&
            !str_contains($pub['url'],$context['actorRef']));
        $parts=parse_url($pub['url']);
        $check(is_array($parts) &&
            ($parts['scheme']??null)==='https' &&
            ($parts['host']??null)==='accounts.google.com' &&
            ($parts['path']??null)==='/o/oauth2/v2/auth');
        parse_str($parts['query']??'',$params);
        $check(array_keys($params)===[
            'client_id','redirect_uri','response_type','scope','state',
            'code_challenge','code_challenge_method'
        ] &&
            $params['client_id']===$config['clientId'] &&
            $params['redirect_uri']===$callback &&
            $params['response_type']==='code' &&
            $params['scope']===Policy::READONLY_SCOPE &&
            $params['state']===$pub['state'] &&
            $params['code_challenge']===$expected &&
            $params['code_challenge_method']==='S256');
        $check($planner->matchesStoredState(
            $pub['state'],$secret,$context,$now+1
        ));
        $check($planner->matchesStoredState(
            $pub['state'],$secret,$context,$now+600
        ));
        foreach([
            [$pub['state'],$secret,$context,$now-1],
            [$pub['state'],$secret,$context,$now+601],
            [$pub['state'],$secret,['tenantRef'=>'other-demo','actorRef'=>'operator-123'],$now+1],
            [$pub['state'],$secret,['tenantRef'=>'imagine-demo','actorRef'=>'other-123'],$now+1],
            ['wrong-state',$secret,$context,$now+1],
            [$pub['state'],[...$secret,'consumed'=>true],$context,$now+1],
            [$pub['state'],[...$secret,'stateSha256'=>str_repeat('0',64)],$context,$now+1],
            [$pub['state'],[...$secret,'codeVerifier'=>'invalid'], $context,$now+1],
            [$pub['state'],[...$secret,'extra'=>'SYNTHETIC-SECRET'],$context,$now+1]
        ] as [$callbackState,$stored,$current,$time]){
            $check(!$planner->matchesStoredState($callbackState,$stored,$current,$time));
        }
    }
    $check($a['publicAuthorization']['state']!==$b['publicAuthorization']['state']);
    $check($a['serverOnly']['codeVerifier']!==$b['serverOnly']['codeVerifier']);

    $invalidConfigs=[
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>'http://portal.example.com/integrations/google/search-console/callback'],
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>$callback.'?next=https://evil.example'],
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>$callback.'#fragment'],
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>'https://user@portal.example.com/integrations/google/search-console/callback'],
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>'https://portal.example.com:443/integrations/google/search-console/callback'],
        ['clientId'=>'123456-exampleclient.apps.googleusercontent.com',
            'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback/'],
        ['clientId'=>'fake','registeredRedirectUri'=>$callback],
        [...$config,'clientSecret'=>'SYNTHETIC-SECRET'],
    ];
    foreach($invalidConfigs as $bad){
        $check($planner->prepare($bad,$context,$now)===null);
    }
    foreach([
        ['tenantRef'=>'bad tenant','actorRef'=>'operator-123'],
        ['tenantRef'=>'imagine-demo','actorRef'=>'bad@example.com'],
        [...$context,'googleGrantVerified'=>true],
    ] as $badContext){
        $check($planner->prepare($config,$badContext,$now)===null);
    }
    foreach([null,'1790000000',0,2200000001,3.14] as $badTime){
        $check($planner->prepare($config,$context,$badTime)===null);
    }
}catch(Throwable){
    fwrite(STDERR,"GSC Slice G: PKCE/state contract refused\n");
    exit(1);
}
echo "GSC Slice G: PKCE/state synthetic contract accepted; no HTTP or tokens\n";
