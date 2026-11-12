<?php
declare(strict_types=1);
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsoleOAuthCallbackPolicy.php',
    'GoogleSearchConsoleOAuthStateLedger.php',
    'GoogleSearchConsoleOAuthCallbackCoordinator.php',
    'GoogleSearchConsoleOAuthStateRetention.php',
    'GoogleSearchConsoleOAuthTokenExchangePlan.php',
    'GoogleSearchConsoleOAuthTokenResponsePolicy.php'
] as $file)require_once $root.$file;

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackPolicy as CallbackPolicy;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateLedger as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackCoordinator as Coordinator;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateRetention as Retention;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenExchangePlan as ExchangePlan;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy as TokenPolicy;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_END_TO_END_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice N: isolated PostgreSQL test only\n");
    exit(2);
}
$check=static function(bool $ok):void{
    if(!$ok)throw new RuntimeException();
};
try{
    $pdo=new PDO(getenv('PORTAL_GSC_LEDGER_DSN'),
        getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');
    $pdo->exec((string)file_get_contents(
        dirname(__DIR__).'/gsc-slice-i/test-postgres-schema.sql'
    ));
    $now=1790000000;
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $clientId='123456-exampleclient.apps.googleusercontent.com';
    $redirect='https://portal.example.com/integrations/google/search-console/callback';
    $config=['clientId'=>$clientId,'registeredRedirectUri'=>$redirect];
    $credentials=[
        ...$config,'clientSecret'=>'synthetic-only-not-a-real-credential'
    ];

    $pkce=new Pkce();
    $ledger=new Ledger($pdo,random_bytes(32));
    $callback=new Coordinator($ledger,new CallbackPolicy($pkce));
    $exchange=new ExchangePlan();
    $response=new TokenPolicy();
    $maintenance=new Retention($pdo);
    $intent=$pkce->prepare($config,$context,$now);
    $check(is_array($intent));
    $state=$intent['publicAuthorization']['state'];
    $verifier=$intent['serverOnly']['codeVerifier'];
    $check($intent['publicAuthorization']['scope']===Pkce::READONLY_SCOPE);
    $check($ledger->store($intent['serverOnly'],$now));

    // This is a synthetic code receipt, not evidence of Google sign-in.
    $code='4/0AbCdEfGhIJKL-SYNTHETIC-CODE-N';
    $outcome=$callback->review([
        'state'=>$state,'code'=>$code,'scope'=>Pkce::READONLY_SCOPE
    ],$context,$now+1);
    $check($outcome!==null);
    $check($callback->review(['state'=>$state,'code'=>$code],
        $context,$now+2)===null);
    $check($outcome->safeReview()['stateConsumed']===true);

    $request=$exchange->prepare($credentials,$outcome);
    $check($request!==null);
    $http=$request->takeForTrustedHttpsTransport();
    $check(is_array($http));
    $check($request->takeForTrustedHttpsTransport()===null);
    parse_str($http['body'],$form);
    $check($http['method']==='POST'&&
        $http['url']==='https://oauth2.googleapis.com/token'&&
        $http['allowRedirects']===false&&
        $form['code']===$code&&$form['code_verifier']===$verifier&&
        $form['client_id']===$clientId&&
        $form['redirect_uri']===$redirect&&
        $form['grant_type']==='authorization_code');

    // Simulate ONLY the shape of a token reply. No HTTPS call or provider
    // assertion is made. The response policy must reject expanded scopes.
    $access='synthetic-token-access-value-for-test-only';
    $refresh='synthetic-token-refresh-value-for-test-only';
    $reply=[
        'access_token'=>$access,'refresh_token'=>$refresh,
        'expires_in'=>3599,'scope'=>Pkce::READONLY_SCOPE,
        'token_type'=>'Bearer'
    ];
    $candidate=$response->inspect(200,json_encode($reply),$now+2);
    $check($candidate!==null);
    $safe=$candidate->safeReview();
    $check($safe['scope']===Pkce::READONLY_SCOPE&&
        $safe['expiresAt']===$now+3601&&
        $safe['googleIdentityVerified']===false&&
        $safe['providerOriginVerified']===false&&
        $safe['tokenPersistenceAuthorized']===false&&
        $safe['customerDisplayAuthorized']===false);
    $check($response->inspect(200,json_encode([
        ...$reply,
        'scope'=>'https://www.googleapis.com/auth/webmasters'
    ]),$now+2)===null);
    $check($response->inspect(401,json_encode($reply),$now+2)===null);
    $check($response->inspect(200,json_encode([
        ...$reply,'extra'=>'PRIVATE-CANDIDATE'
    ]),$now+2)===null);

    foreach([
        json_encode($intent['publicAuthorization'],JSON_THROW_ON_ERROR),
        json_encode($outcome->safeReview(),JSON_THROW_ON_ERROR),
        json_encode($request->safeReview(),JSON_THROW_ON_ERROR),
        json_encode($candidate,JSON_THROW_ON_ERROR),
        var_export($candidate,true),
        print_r($candidate,true)
    ] as $visible){
        $check(!str_contains($visible,$code)
            &&!str_contains($visible,$verifier)
            &&!str_contains($visible,$access)
            &&!str_contains($visible,$refresh));
    }
    $check($maintenance->purge($now+64)===1);
    $check($pdo->query(
        'SELECT COUNT(*) FROM portal_gsc_oauth_states'
    )->fetchColumn()==0);
    $check($candidate->safeReview()['networkPerformed']===false);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice N: full synthetic OAuth review contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');}
        catch(Throwable){}
    }
}
echo "GSC Slice N: PKCE to consumed state to token response, safe and offline\n";
