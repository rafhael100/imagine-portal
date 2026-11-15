<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthPreflight.php';
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleOAuthTokenResponsePolicy.php';
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Pkce;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy as Policy;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_TOKEN_RESPONSE_LAB')!=='1'){
    fwrite(STDERR,"GSC Slice M: offline synthetic response only\n");exit(2);
}
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException();};
try{
    $parser=new Policy();
    $now=1790000000;
    $access='synthetic-access-token-not-valid-for-any-provider';
    $refresh='synthetic-refresh-token-not-valid-for-any-provider';
    $body=[
        'access_token'=>$access,
        'refresh_token'=>$refresh,
        'expires_in'=>3599,
        'scope'=>Pkce::READONLY_SCOPE,
        'token_type'=>'Bearer'
    ];
    $result=$parser->inspect(200,json_encode($body,JSON_THROW_ON_ERROR),$now);
    $check($result!==null);
    $safe=$result->safeReview();
    $check($safe['scope']===Pkce::READONLY_SCOPE&&
        $safe['expiresAt']===$now+3599&&
        $safe['refreshTokenCandidate']===true&&
        $safe['providerOriginVerified']===false&&
        $safe['googleIdentityVerified']===false&&
        $safe['tenantGrantVerified']===false&&
        $safe['propertyAuthorizationVerified']===false&&
        $safe['tokenPersistenceAuthorized']===false&&
        $safe['customerDisplayAuthorized']===false&&
        $safe['networkPerformed']===false);
    $secrets=$result->takeForTrustedVault();
    $check($secrets['accessToken']===$access&&
        $secrets['refreshToken']===$refresh&&
        $secrets['scope']===Pkce::READONLY_SCOPE&&
        $secrets['expiresAt']===$now+3599);
    $check(!Policy::authorizesGoogleIdentity()&&
        !Policy::authorizesTokenPersistence()&&
        !Policy::authorizesCustomerDisplay());
    foreach([
        json_encode($result,JSON_THROW_ON_ERROR),
        var_export($result,true),
        print_r($result,true),
        json_encode($safe,JSON_THROW_ON_ERROR)
    ] as $log){
        $check(!str_contains($log,$access)&&!str_contains($log,$refresh));
    }
    $withoutRefresh=$body;
    unset($withoutRefresh['refresh_token']);
    $v=$parser->inspect(200,json_encode($withoutRefresh),$now);
    $check($v!==null&&$v->safeReview()['refreshTokenCandidate']===false&&
        !array_key_exists('refreshToken',$v->takeForTrustedVault()));

    $invalid=[
        [...$body,'scope'=>'https://www.googleapis.com/auth/webmasters'],
        [...$body,'scope'=>Pkce::READONLY_SCOPE.' https://example.com/private'],
        [...$body,'token_type'=>'MAC'],
        [...$body,'expires_in'=>0],
        [...$body,'expires_in'=>3601],
        [...$body,'expires_in'=>'3599'],
        [...$body,'refresh_token'=>null],
        [...$body,'access_token'=>"\r\nAuthorization: synthetic"],
        [...$body,'access_token'=>'short'],
        [...$body,'extra'=>'PRIVATE-TOKEN-METADATA'],
        [...$body,'id_token'=>'PRIVATE-ID-TOKEN'],
        [...$body,'error'=>'invalid_grant'],
        [...$body,'refresh_token'=>str_repeat('a',4097)]
    ];
    foreach($invalid as $candidate){
        $check($parser->inspect(200,json_encode($candidate),$now)===null);
    }
    foreach([
        ['not-json',200],
        ['[]',200],
        ['{"access_token":"PRIVATE-SECRET"',200],
        [json_encode($body),400],
        [json_encode($body),401],
        [json_encode($body),429],
        [str_repeat('A',16385),200],
    ] as [$raw,$status]){
        $check($parser->inspect($status,$raw,$now)===null);
    }
    foreach([null,0,'1790000000',2200000001] as $badNow){
        $check($parser->inspect(200,json_encode($body),$badNow)===null);
    }
}catch(Throwable){
    fwrite(STDERR,"GSC Slice M: token candidate contract refused\n");exit(1);
}
echo "GSC Slice M: token response scope limited; private candidates withheld\n";
