<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackPolicy.php';
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackPolicy as Callback;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_CALLBACK_LAB')!=='1'){
    fwrite(STDERR,"GSC Slice H: synthetic review only\n");
    exit(2);
}
$check=static function(bool $value):void{
    if(!$value)throw new RuntimeException();
};
try{
    $preflight=new Pkce();
    $policy=new Callback($preflight);
    $config=[
        'clientId'=>'123456-exampleclient.apps.googleusercontent.com',
        'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback'
    ];
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $now=1790000000;
    $prepared=$preflight->prepare($config,$context,$now);
    $check(is_array($prepared));
    $state=$prepared['publicAuthorization']['state'];
    $secret=$prepared['serverOnly'];
    $check(!Callback::authorizesGoogleHttp()&&!Callback::authorizesTokenExchange()&&
        !Callback::authorizesCustomerDisplay());
    $code='4/0AbCdEfGhIJKL-opaque-code_TEST';
    $callback=['state'=>$state,'code'=>$code];
    $result=$policy->inspect($callback,$secret,$context,$now+1);
    $check(is_array($result)&&$result=== [
        'decision'=>'CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION',
        'requiresAtomicStateConsumption'=>true,'canExchangeTokens'=>false,
        'oauthVerified'=>false,'customerDisplayAuthorized'=>false,
        'publicationAuthorized'=>false
    ]);
    $check(strpos(json_encode($result,JSON_THROW_ON_ERROR),$code)===false);
    $withExtras=[...$callback,'scope'=>Pkce::READONLY_SCOPE,
        'authuser'=>'0','prompt'=>'consent'];
    $check($policy->inspect($withExtras,$secret,$context,$now+1)===$result);

    foreach([
        ['access_denied','CONSENT_DENIED_HOLD'],
        ['server_error','PROVIDER_ERROR_HOLD'],
        ['temporarily_unavailable','PROVIDER_ERROR_HOLD']
    ] as [$error,$decision]){
        $out=$policy->inspect(['state'=>$state,'error'=>$error],
            $secret,$context,$now+1);
        $check(is_array($out)&&$out['decision']===$decision&&
            $out['requiresAtomicStateConsumption']===true&&
            $out['canExchangeTokens']===false);
    }

    $invalid=[
        ['state'=>$state,'code'=>$code,'error'=>'access_denied'],
        ['state'=>$state,'code'=>$code,'scope'=>'https://www.googleapis.com/auth/webmasters'],
        ['state'=>$state,'code'=>$code,'scope'=>'https://www.googleapis.com/auth/webmasters.readonly https://example.com'],
        ['state'=>$state,'code'=>$code,'authuser'=>'0;admin'],
        ['state'=>$state,'code'=>$code,'prompt'=>'publish'],
        ['state'=>$state,'code'=>$code,'redirect_uri'=>'https://evil.example'],
        ['state'=>$state,'code'=>$code,'client_secret'=>'SYNTHETIC-SECRET'],
        ['state'=>$state,'code'=>$code,'error_description'=>'SYNTHETIC-SECRET'],
        ['state'=>$state,'code'=>"\r\nAuthorization: Bearer SECRET"],
        ['state'=>$state,'code'=>'abc'],
        ['state'=>$state,'code'=>['nested']],
        ['state'=>$state,'code'=>str_repeat('A',2049)],
        ['state'=>$state,'error'=>'invalid_grant_custom'],
        ['state'=>$state,'code'=>null],
        ['code'=>$code],
        ['state'=>'wrong-value','code'=>$code],
        ['state'=>$state],
        [$state,$code]
    ];
    foreach($invalid as $params){
        $check($policy->inspect($params,$secret,$context,$now+1)===null);
    }

    foreach([
        [$callback,$secret,$context,$now+601],
        [$callback,$secret,$context,$now-1],
        [$callback,$secret,['tenantRef'=>'other-demo','actorRef'=>'operator-123'],$now+1],
        [$callback,$secret,['tenantRef'=>'imagine-demo','actorRef'=>'wrong-actor'],$now+1],
        [$callback,[...$secret,'consumed'=>true],$context,$now+1],
        [$callback,[...$secret,'stateSha256'=>str_repeat('0',64)],$context,$now+1],
        [$callback,[...$secret,'extra'=>'SYNTHETIC-SECRET'],$context,$now+1],
    ] as [$params,$record,$session,$clock]){
        $check($policy->inspect($params,$record,$session,$clock)===null);
    }
} catch(Throwable){
    fwrite(STDERR,"GSC Slice H: callback classification contract refused\n");
    exit(1);
}
echo "GSC Slice H: callbacks classified without token disclosure or authorization\n";
