<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || $argc!==5 || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_HTTPS_TRANSPORT_LAB')!=='1' ||
    getenv('GITHUB_ACTIONS')!=='true' || getenv('RUNNER_ENVIRONMENT')!=='github-hosted' ||
    !in_array($argv[3],['full','wrong-host'],true) || basename($argv[1])!=='SeoReview') {
    fwrite(STDERR,"GSC HTTPS: disposable CLI TLS laboratory required\n");exit(2);
}
$portal=dirname(__DIR__,2);
foreach(['GoogleSearchConsoleOAuthPreflight.php','GoogleSearchConsoleOAuthTokenExchangePlan.php',
    'GoogleSearchConsoleOAuthTokenResponsePolicy.php','GoogleSearchConsolePropertyIdentifier.php'] as $file)
    require_once $argv[1].'/'.$file;
require_once $portal.'/app/Domain/SeoReview/GoogleSearchConsoleSiteListingReview.php';
require_once $portal.'/app/Infrastructure/SeoReview/GoogleSearchConsoleHttpsTransportCandidate.php';

use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenCandidate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest;
use App\Infrastructure\SeoReview\GoogleSearchConsoleHttpsTransportCandidate as Client;

const HTTPS_LAB_SITE='sc-domain:example.com';
$checks=0;$failed=false;
$secrets=['synthetic-secret-f116-private-only',str_repeat('A',43),
    'synthetic-only-access-token-1234567890','synthetic-only-refresh-token-1234567890'];
function tlsCheck(bool $ok,string $label): void
{
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException($label);
}
function tlsEnvelope(string $code,array $replace=[]): GoogleSearchConsoleOAuthTokenPostRequest
{
    global $secrets;
    $secrets[]=$code;
    return new GoogleSearchConsoleOAuthTokenPostRequest(array_replace([
        'client_id'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
        'client_secret'=>'synthetic-secret-f116-private-only','code'=>$code,
        'code_verifier'=>str_repeat('A',43),
        'redirect_uri'=>'https://portal.example.test/integrations/google/search-console/callback',
        'grant_type'=>'authorization_code',
    ],$replace));
}
function tlsMetrics(): array
{
    global $argv;
    $raw=file_get_contents($argv[4]);
    tlsCheck(is_string($raw) && strlen($raw)<4096,'bounded fixture metrics exist');
    $metrics=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    tlsCheck(is_array($metrics),'fixture metrics object');
    $keys=array_keys($metrics);sort($keys);
    tlsCheck($keys===['redirect_target','sites','token','total','unexpected','violations'],
        'metrics contain counts only');
    foreach($metrics as $count)tlsCheck(is_int($count) && $count>=0,'nonnegative fixture counter');
    return $metrics;
}
function tlsDelta(array $before,int $token,int $sites,string $label): void
{
    $after=tlsMetrics();
    tlsCheck($after['token']-$before['token']===$token,$label.' token count');
    tlsCheck($after['sites']-$before['sites']===$sites,$label.' sites count');
    tlsCheck($after['total']-$before['total']===$token+$sites,$label.' total count');
    foreach(['redirect_target','unexpected','violations'] as $key)
        tlsCheck($after[$key]===$before[$key],$label.' no redirect, wrong route or malformed request');
}
function tlsNoLeaks(mixed $value): void
{
    global $secrets;
    ob_start();var_dump($value);$dump=ob_get_clean();
    $rendered=$dump.var_export($value,true).json_encode($value,JSON_THROW_ON_ERROR);
    foreach($secrets as $secret)tlsCheck(!str_contains($rendered,$secret),'safe diagnostics contain no secret');
}

try {
    $client=Client::forTlsLab($argv[2]);
    tlsCheck($client instanceof Client,'guarded TLS factory accepted');
    tlsNoLeaks($client);
    $baseline=tlsMetrics();

    if ($argv[3]==='wrong-host') {
        // Both sockets use a CA-trusted certificate for wrong.invalid. The
        // negative sites input is intentionally synthetic, not claimed as a
        // successful exchange or Google identity/provenance proof.
        $request=tlsEnvelope('synthetic-good-with-refresh');
        tlsCheck($client->exchange($request,time())===null,'token TLS hostname mismatch denied');
        tlsDelta($baseline,0,0,'TLS fails before token form reaches HTTP');
        tlsCheck($request->takeForTrustedHttpsTransport()===null,'wrong-host failure really consumed the envelope');
        tlsCheck($client->exchange($request,time())===null,'TLS failure consumes the envelope');
        $candidate=new GoogleSearchConsoleOAuthTokenCandidate([
            'accessToken'=>'synthetic-only-access-token-1234567890',
        ],time()+300);
        tlsCheck($client->inspectSites($candidate,HTTPS_LAB_SITE,time())===null,'sites TLS hostname mismatch denied');
        tlsDelta($baseline,0,0,'TLS fails before bearer reaches HTTP');
    } else {
        // Successful requests are checked by the TLS fixture for exact SNI,
        // Host, method, path, form fields, readonly scope and header-only bearer.
        $request=tlsEnvelope('synthetic-good-with-refresh');
        $candidate=$client->exchange($request,time());
        tlsCheck($candidate instanceof GoogleSearchConsoleOAuthTokenCandidate,'TLS token POST returns opaque parsed candidate');
        tlsDelta($baseline,1,0,'one successful exchange');
        $safe=$candidate->safeReview();
        tlsCheck($safe['refreshTokenCandidate']===true,'refresh candidate is retained opaquely');
        foreach(['providerOriginVerified','googleIdentityVerified','tenantGrantVerified',
            'propertyAuthorizationVerified','tokenPersistenceAuthorized','customerDisplayAuthorized'] as $flag)
            tlsCheck($safe[$flag]===false,'TLS certificate is not Google or Portal authority');
        tlsNoLeaks($candidate);tlsNoLeaks($request);
        $before=tlsMetrics();
        $sites=$client->inspectSites($candidate,HTTPS_LAB_SITE,time());
        tlsCheck(is_array($sites) && $sites['exactSiteUrlMatched']===true &&
            $sites['siteIdentifierFingerprint']===hash('sha256',HTTPS_LAB_SITE) &&
            $sites['tlsLabVerified']===true,'same candidate drives exact sites GET');
        foreach(['providerOriginVerified','googleOAuthVerified','googleNetworkPerformed',
            'tokenPersistenceAuthorized','customerDisplayAuthorized','productionAuthorized'] as $flag)
            tlsCheck($sites[$flag]===false,'sites evidence remains non-authorizing');
        tlsNoLeaks($sites);tlsDelta($before,0,1,'one successful sites request');
        $before=tlsMetrics();
        tlsCheck($client->exchange($request,time())===null,'successful envelope cannot be repeated');
        tlsDelta($before,0,0,'replay never reaches HTTPS');

        foreach(['synthetic-good-without-refresh','synthetic-json-charset','synthetic-json-charset-quoted'] as $code) {
            $before=tlsMetrics();$r=$client->exchange(tlsEnvelope($code),time());
            tlsCheck($r instanceof GoogleSearchConsoleOAuthTokenCandidate,'valid optional refresh and JSON charset accepted');
            tlsCheck($r->safeReview()['refreshTokenCandidate']===($code!=='synthetic-good-without-refresh'),
                'refresh presence follows actual response');
            tlsNoLeaks($r);tlsDelta($before,1,0,'one valid token variant');
        }

        // A different trust root must fail both TLS connections before any
        // secret is sent as HTTP. A failed TLS attempt still uses the envelope.
        $wrongCa=Client::forTlsLab(dirname($argv[2]).'/wrong-ca.pem');
        tlsCheck($wrongCa instanceof Client,'negative CA fixture accepted as lab file');
        $before=tlsMetrics();$r=tlsEnvelope('synthetic-good-with-refresh');
        tlsCheck($wrongCa->exchange($r,time())===null,'untrusted token certificate denied');
        tlsCheck($client->exchange($r,time())===null,'failed TLS envelope cannot retry on another client');
        tlsCheck($wrongCa->inspectSites($candidate,HTTPS_LAB_SITE,time())===null,'untrusted sites certificate denied');
        tlsDelta($before,0,0,'wrong CA sends no HTTP request');

        // Environment proxy settings cannot redirect these fixed loopback
        // sockets. Restore the original environment immediately afterwards.
        $proxyNames=['HTTPS_PROXY','https_proxy','ALL_PROXY','all_proxy'];$oldProxy=[];
        foreach($proxyNames as $name){$oldProxy[$name]=getenv($name);putenv($name.'=http://127.0.0.1:9');}
        try {
            $before=tlsMetrics();$r=$client->exchange(tlsEnvelope('synthetic-good-with-refresh'),time());
            tlsCheck($r instanceof GoogleSearchConsoleOAuthTokenCandidate,'proxy environment ignored');
            tlsCheck($client->inspectSites($r,HTTPS_LAB_SITE,time())!==null,'proxy environment cannot receive bearer');
            tlsDelta($before,1,1,'fixed socket mappings override proxy environment');
        } finally {
            foreach($oldProxy as $name=>$value)putenv($value===false?$name:$name.'='.$value);
        }

        $tokenCases=['synthetic-redirect','synthetic-cross-origin-redirect','synthetic-egress-redirect',
            'synthetic-http-500','synthetic-unauthenticated','synthetic-content-type','synthetic-bad-json',
            'synthetic-json-charset-open','synthetic-json-charset-close',
            'synthetic-oversize','synthetic-header-oversize','synthetic-timeout','synthetic-denied-scope',
            'synthetic-bad-expiry','synthetic-zero-expiry','synthetic-bad-type','synthetic-extra-field',
            'synthetic-invalid-refresh','synthetic-malformed-token','synthetic-noninteger-expiry'];
        foreach($tokenCases as $code) {
            $before=tlsMetrics();$r=tlsEnvelope($code);$started=microtime(true);
            tlsCheck($client->exchange($r,time())===null,'invalid token response refused');
            tlsCheck(microtime(true)-$started<5,'token response is time bounded');
            tlsDelta($before,1,0,'negative token response has no retry or redirect');
            $after=tlsMetrics();
            tlsCheck($client->exchange($r,time())===null,'failed token envelope remains consumed');
            tlsDelta($after,0,0,'failed token replay never reaches HTTP');
            tlsNoLeaks($r);
        }

        foreach(['bad-json','content-type','oversize','header-oversize','http-500','unauthenticated',
            'redirect','cross-origin-redirect','timeout','missing-property','restricted','duplicate'] as $case) {
            $code='synthetic-sites-'.$case;
            $secrets[]=$code.'-access-token-1234567890';
            $r=$client->exchange(tlsEnvelope($code),time());
            tlsCheck($r instanceof GoogleSearchConsoleOAuthTokenCandidate,'sites scenario uses actual opaque response candidate');
            $before=tlsMetrics();$started=microtime(true);
            tlsCheck($client->inspectSites($r,HTTPS_LAB_SITE,time())===null,'invalid sites response refused');
            tlsCheck(microtime(true)-$started<5,'sites response is time bounded');
            tlsDelta($before,0,1,'negative sites response has no retry or redirect');
            tlsNoLeaks($r);
        }
        foreach(['sc-domain:other.example','https://restricted.example.com/'] as $site) {
            $before=tlsMetrics();
            tlsCheck($client->inspectSites($candidate,$site,time())===null,'exact site and minimum permission are required');
            tlsDelta($before,0,1,'site input never becomes a request destination');
        }

        // Invalid envelope fields are consumed but are never sent. The
        // transport owns no arbitrary URL argument or caller-defined headers.
        foreach([
            ['url'=>'https://unexpected.example.invalid/token'],
            ['grant_type'=>'refresh_token'],['code_verifier'=>'invalid'],
            ['client_secret'=>'not-a-synthetic-secret'],
            ['code'=>"synthetic-code\r\nInjected: yes"],
            ['redirect_uri'=>'http://portal.example.test/integrations/google/search-console/callback'],
        ] as $replace) {
            $before=tlsMetrics();$r=tlsEnvelope('synthetic-good-with-refresh',$replace);
            tlsCheck($client->exchange($r,time())===null,'malformed or non-synthetic envelope refused');
            tlsCheck($r->takeForTrustedHttpsTransport()===null,'invalid envelope is not reusable');
            tlsDelta($before,0,0,'invalid envelope never leaves process');
        }

        foreach([
            new GoogleSearchConsoleOAuthTokenCandidate(['accessToken'=>"synthetic-token\r\nInjected: yes"],time()+300),
            new GoogleSearchConsoleOAuthTokenCandidate(['accessToken'=>'synthetic-only-access-token-1234567890'],time()-1),
            new GoogleSearchConsoleOAuthTokenCandidate(['accessToken'=>'synthetic-only-access-token-1234567890'],time()+7200),
            new GoogleSearchConsoleOAuthTokenCandidate(['accessToken'=>'non-synthetic-access-token-1234567890'],time()+300),
        ] as $bad) {
            $before=tlsMetrics();
            tlsCheck($client->inspectSites($bad,HTTPS_LAB_SITE,time())===null,'invalid or expired bearer refused before HTTPS');
            tlsDelta($before,0,0,'bad bearer never reaches HTTP header');
        }

        foreach(['PORTAL_GSC_HTTPS_TRANSPORT_LAB'=>'0','APP_ENV'=>'production',
            'GITHUB_ACTIONS'=>'false','RUNNER_ENVIRONMENT'=>'self-hosted'] as $name=>$badValue) {
            $original=getenv($name);$before=tlsMetrics();$r=tlsEnvelope('synthetic-good-with-refresh');
            try {
                putenv($name.'='.$badValue);
                tlsCheck(Client::forTlsLab($argv[2])===null,'factory refuses non-laboratory context');
                tlsCheck($client->exchange($r,time())===null,'existing client rechecks laboratory gates');
                tlsCheck($client->inspectSites($candidate,HTTPS_LAB_SITE,time())===null,'sites rechecks laboratory gates');
                tlsDelta($before,0,0,'disabled client cannot perform network');
            } finally {putenv($original===false?$name:$name.'='.$original);}
            // Denied configuration did not attempt or disclose the envelope.
            tlsCheck($r->takeForTrustedHttpsTransport()!==null,'disabled gate precedes envelope consumption');
        }
        tlsCheck(Client::forTlsLab($argv[2].'.missing')===null,'missing CA is refused');
        $serialized=false;
        try {serialize($client);} catch(LogicException) {$serialized=true;}
        tlsCheck($serialized,'transport cannot be serialized into session or queue');
        tlsNoLeaks($client);
        $final=tlsMetrics();
        foreach(['redirect_target','unexpected','violations'] as $key)
            tlsCheck($final[$key]===$baseline[$key],'suite followed no redirect and exposed no invalid request');
    }
} catch(Throwable $e) {
    $failed=true;
    $label=get_class($e)===RuntimeException::class ? $e->getMessage() :
        'unexpected '.get_class($e).' at line '.$e->getLine();
    fwrite(STDERR,'GSC HTTPS laboratory failed: '.$label."\n");
}
if (!$failed)echo "GSC HTTPS {$argv[3]}: {$checks} checks; real TLS on fixed loopback sockets; ".
    "certificate/host, one-use, bounds, redirects, response policies and redaction checked; ".
    "NO GOOGLE IDENTITY OR OPERATIONAL ENABLEMENT\n";
exit($failed?1:0);
