<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackPolicy.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthStateLedger.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthCallbackCoordinator.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthTokenExchangePlan.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackPolicy as Policy;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthStateLedger as Ledger;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackCoordinator as Coordinator;

if (PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_COORDINATOR_LAB')!=='1'||
    getenv('PORTAL_GSC_LEDGER_DSN')!==
        'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test') {
    fwrite(STDERR,"GSC Slice J: PostgreSQL test only\n");exit(2);
}
$check=static function(bool $value):void{
    if(!$value)throw new RuntimeException();
};
try{
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $pdo=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),$opts);
    $pdo2=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),$opts);
    $pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');
    $pdo->exec((string)file_get_contents(dirname(__DIR__).'/gsc-slice-i/test-postgres-schema.sql'));

    $key=random_bytes(32);
    $pkce=new Pkce();
    $coordinator=new Coordinator(new Ledger($pdo,$key),new Policy($pkce));
    $other=new Coordinator(new Ledger($pdo2,$key),new Policy($pkce));
    $context=['tenantRef'=>'imagine-demo','actorRef'=>'operator-123'];
    $config=[
        'clientId'=>'123456-exampleclient.apps.googleusercontent.com',
        'registeredRedirectUri'=>'https://portal.example.com/integrations/google/search-console/callback'
    ];
    $now=1790000000;
    $newState=static function() use ($pkce,$config,$context,$now):array{
        $i=$pkce->prepare($config,$context,$now);
        if(!is_array($i))throw new RuntimeException();
        return $i;
    };
    $check(!Coordinator::authorizesGoogleHttp()&&
        !Coordinator::authorizesCustomerDisplay());
    $intent=$newState();
    $state=$intent['publicAuthorization']['state'];
    $verifier=$intent['serverOnly']['codeVerifier'];
    $check((new Ledger($pdo,$key))->store($intent['serverOnly'],$now));
    $code='4/0AbCdEfghJkLmNo-SYNTHETIC-GSC-CODE';
    $result=$coordinator->review(['state'=>$state,'code'=>$code],
        $context,$now+1);
    $check($result!==null);
    $public=$result->safeReview();
    $check($public['decision']==='CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND'
        &&$public['stateConsumed']===true
        &&$public['oauthVerified']===false
        &&$public['tokenExchangeAuthorized']===false
        &&$public['customerDisplayAuthorized']===false
        &&$public['publicationAuthorized']===false
        &&$public['releaseStatus']==='HOLD_EXTERNAL_AUTHENTICATED_BACKEND');
    $tokenPlan=(new \App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenExchangePlan())
        ->prepare([
            'clientId'=>$config['clientId'],
            'clientSecret'=>'synthetic-only-not-a-real-credential',
            'registeredRedirectUri'=>$config['registeredRedirectUri']
        ],$result);
    $check($tokenPlan!==null);
    $check(Coordinator::issuedByConsumedState($result));
    $forged=new \App\Domain\SeoReview\GoogleSearchConsoleOAuthCallbackOutcome(
        'CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND',$code,$verifier
    );
    $cloned=clone $result;
    $check(!Coordinator::issuedByConsumedState($forged) &&
        !Coordinator::issuedByConsumedState($cloned));
    $check($forged->safeReview()['stateConsumed']===false &&
        $cloned->safeReview()['stateConsumed']===false);
    $check($forged->takeForTrustedBackend()===null &&
        $cloned->takeForTrustedBackend()===null);

    $planner=new \App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenExchangePlan();
    $credentials=[
        'clientId'=>$config['clientId'],
        'clientSecret'=>'synthetic-only-not-a-real-credential',
        'registeredRedirectUri'=>$config['registeredRedirectUri']
    ];
    $check($planner->prepare($credentials,$forged)===null);
    $check($planner->prepare($credentials,$cloned)===null);
    $check($planner->prepare([
        ...$credentials,'registeredRedirectUri'=>'https://evil.example/callback'
    ],$result)===null);

    $post=$tokenPlan->takeForTrustedHttpsTransport();
    parse_str($post['body'],$form);
    $check($post['url']==='https://oauth2.googleapis.com/token'
        &&$form['code']===$code
        &&$form['code_verifier']===$verifier
        &&$form['grant_type']==='authorization_code'
        &&$tokenPlan->safeReview()['networkPerformed']===false);
    $backend=$result->takeForTrustedBackend();
    $check(is_array($backend)&&$backend['code']===$code
        &&$backend['codeVerifier']===$verifier);
    foreach([
        json_encode($result,JSON_THROW_ON_ERROR),
        var_export($result,true),
        print_r($result,true),
        json_encode($public,JSON_THROW_ON_ERROR)
    ] as $visible){
        $check(!str_contains($visible,$code)&&!str_contains($visible,$verifier)
            &&!str_contains($visible,$state));
    }
    $check($coordinator->review(['state'=>$state,'code'=>$code],
        $context,$now+2)===null);
    $check($other->review(['state'=>$state,'code'=>$code],
        $context,$now+2)===null);

    $denied=$newState();
    $check((new Ledger($pdo,$key))->store($denied['serverOnly'],$now));
    $s2=$denied['publicAuthorization']['state'];
    $cancel=$coordinator->review(['state'=>$s2,'error'=>'access_denied'],
        $context,$now+3);
    $check($cancel!==null&&
        Coordinator::issuedByConsumedState($cancel) &&
        $cancel->safeReview()['stateConsumed']===true &&
        $cancel->safeReview()['decision']==='CONSENT_DENIED_HOLD'&&
        $cancel->takeForTrustedBackend()===null);
    $check($other->review(['state'=>$s2,'error'=>'access_denied'],
        $context,$now+4)===null);

    $third=$newState();
    $check((new Ledger($pdo,$key))->store($third['serverOnly'],$now));
    $s3=$third['publicAuthorization']['state'];
    $check($coordinator->review(['state'=>$s3,'code'=>$code],
        ['tenantRef'=>'other-demo','actorRef'=>'operator-123'],$now+3)===null);
    $check($coordinator->review(['state'=>$s3,'code'=>"bad\r\ncode"],
        $context,$now+3)===null);
    $check($coordinator->review(['state'=>$s3,'code'=>$code,
        'scope'=>'https://www.googleapis.com/auth/webmasters'],
        $context,$now+3)===null);
    // A failed validation must not consume a perfectly good pending state.
    $check($other->review(['state'=>$s3,'code'=>$code],
        $context,$now+4)!==null);
    $check($coordinator->review(['state'=>$s3,'code'=>$code],
        $context,$now+5)===null);

    $expired=$newState();
    $check((new Ledger($pdo,$key))->store($expired['serverOnly'],$now));
    $check($coordinator->review([
        'state'=>$expired['publicAuthorization']['state'],'code'=>$code
    ],$context,$now+601)===null);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice J: one-time callback coordinator contract refused\n");
    exit(1);
}finally{
    if(isset($pdo)&&$pdo instanceof PDO){
        try{$pdo->exec('DROP TABLE IF EXISTS portal_gsc_oauth_states');}
        catch(Throwable){}
    }
}
echo "GSC Slice J: callback consumed once; secrets hidden, denied and cross-tenant refused\n";
