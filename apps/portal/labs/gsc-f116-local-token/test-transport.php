<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F116_LOOPBACK_LAB')!=='1' ||
    !extension_loaded('curl') ||
    !extension_loaded('sodium') ||
    $argc!==2 ||
    basename($argv[1])!=='GoogleSearchConsoleOAuthTokenExchangePlan.php') {
    fwrite(STDERR,"F116: disposable CI only\n");exit(2);
}
$source=dirname($argv[1]);
foreach([
    'GoogleSearchConsoleOAuthPreflight.php',
    'GoogleSearchConsoleOAuthTokenExchangePlan.php',
    'GoogleSearchConsoleOAuthTokenResponsePolicy.php',
] as $path)require_once $source.'/'.$path;
require_once dirname(__DIR__,2)
    .'/app/Domain/SeoReview/OfflinePortalGscLoopbackTokenTransportReview.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest as TokenPost;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Preflight;
use App\Domain\SeoReview\OfflinePortalGscLoopbackTokenTransportReview as TestTransport;

function f116Check(bool $ok):void{
    if(!$ok)throw new RuntimeException('F116 local OAuth contract failed');
}
function syntheticRequest(string $code): TokenPost {
    return new TokenPost([
        'client_id'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
        'client_secret'=>'synthetic-secret-f116-private-only',
        'code'=>$code,
        'code_verifier'=>str_repeat('A',43),
        'redirect_uri'=>
            'https://portal.example.test/integrations/google/search-console/callback',
        'grant_type'=>'authorization_code',
    ]);
}
$test=new TestTransport();
$allowed=0;$denied=0;
try{
    f116Check(!TestTransport::canCallGoogle() &&
        !TestTransport::canStoreTokens() &&
        !TestTransport::canAuthorizeCustomer());
    f116Check(Preflight::READONLY_SCOPE===
        'https://www.googleapis.com/auth/webmasters.readonly');
    foreach(['synthetic-good-with-refresh',
        'synthetic-good-without-refresh'] as $scenario){
        $request=syntheticRequest($scenario);
        $result=$test->inspect($request,time());
        f116Check(is_array($result));
        f116Check($result['contractVersion']===
            'portal-gsc-f116-local-token-transport-lab-v1');
        foreach([
            'localTransportAttempted','loopbackOnly',
            'redirectsRejected','tokenResponseStructureVerified',
            'readonlyScopeMatched',
        ] as $flag)f116Check($result[$flag]===true);
        foreach([
            'providerOriginVerified','googleIdentityVerified',
            'googleOAuthVerifiedNow','propertyAuthorizationVerified',
            'tokenExchangeAuthorizedForProduction',
            'tokenPersistenceAuthorized','customerDisplayAuthorized',
            'networkToGooglePerformed',
        ] as $flag)f116Check($result[$flag]===false);
        f116Check($result['syntheticRefreshCandidatePresent']===
            ($scenario==='synthetic-good-with-refresh'));
        f116Check($result['localHttpStatus']===200);
        $safe=json_encode($result,JSON_THROW_ON_ERROR);
        foreach([
            'synthetic-only-access-token-1234567890',
            'synthetic-only-refresh-token-1234567890',
            'synthetic-secret-f116-private-only',
            'synthetic-good-with-refresh',
        ] as $token)f116Check(!str_contains($safe,$token));
        // Google auth codes are one use and MUST NOT be retransmitted.
        f116Check($test->inspect($request,time())===null);
        $allowed++;
    }
    foreach([
        'synthetic-redirect',
        'synthetic-denied-scope',
        'synthetic-bad-expiry',
        'synthetic-bad-type',
        'synthetic-extra-field',
        'synthetic-invalid-refresh',
        'synthetic-bad-json',
        'synthetic-oversize',
        'synthetic-http-500',
        'synthetic-unauthenticated',
        'synthetic-content-type',
        'synthetic-empty',
        'synthetic-malformed-token',
        'synthetic-noninteger-expiry',
        'synthetic-unknown-case',
    ] as $scenario){
        $request=syntheticRequest($scenario);
        f116Check($test->inspect($request,time())===null);
        f116Check($test->inspect($request,time())===null);
        $denied+=2;
    }
    foreach([
        ['code'=>'short'],
        ['code'=>"\nmalicious-token-code"],
        ['code'=>str_repeat('X',2500)],
        ['codeVerifier'=>'not-a-verifier'],
        ['clientId'=>'forged-client.apps.googleusercontent.com'],
        ['clientSecret'=>'bad'],
        ['redirect'=>'http://insecure.test/callback'],
        ['redirect'=>'https://portal.example.test/not-a-callback'],
    ] as $tampering){
        $form=[
            'client_id'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
            'client_secret'=>'synthetic-secret-f116-private-only',
            'code'=>'synthetic-good-with-refresh',
            'code_verifier'=>str_repeat('A',43),
            'redirect_uri'=>
                'https://portal.example.test/integrations/google/search-console/callback',
            'grant_type'=>'authorization_code'
        ];
        foreach($tampering as $field=>$value){
            $key=match($field){
                'code'=>'code','codeVerifier'=>'code_verifier',
                'clientId'=>'client_id','clientSecret'=>'client_secret',
                'redirect'=>'redirect_uri'
            };
            $form[$key]=$value;
        }
        $request=new TokenPost($form);
        f116Check($test->inspect($request,time())===null);
        $denied++;
    }
    $auth=syntheticRequest('synthetic-good-with-refresh');
    putenv('PORTAL_GSC_F116_LOOPBACK_LAB=0');
    f116Check($test->inspect($auth,time())===null);
    putenv('PORTAL_GSC_F116_LOOPBACK_LAB=1');
    f116Check(is_array($test->inspect($auth,time())));
    $denied++;
    $auth=syntheticRequest('synthetic-good-with-refresh');
    putenv('APP_ENV=production');
    f116Check($test->inspect($auth,time())===null);
    putenv('APP_ENV=testing');
    f116Check(is_array($test->inspect($auth,time())));
    $denied++;
    f116Check($test->inspect(syntheticRequest('synthetic-good-with-refresh'),
        1590000000)===null);
    $denied++;

    echo "F116: {$allowed} positive synthetic token structures; ".
        "{$denied} rejected attacks/retries; no Google HTTP, no vault, ".
        "no customer display authorization\n";
}catch(Throwable $e){
    fwrite(STDERR,"F116 synthetic local token transport failed\n");
    exit(1);
}
