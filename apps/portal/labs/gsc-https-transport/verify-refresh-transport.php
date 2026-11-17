<?php
declare(strict_types=1);

if (PHP_SAPI!=='cli' || $argc!==4 || getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_HTTPS_TRANSPORT_LAB')!=='1' || getenv('PORTAL_GSC_REFRESH_LAB')!=='1' ||
    getenv('GITHUB_ACTIONS')!=='true' || getenv('RUNNER_ENVIRONMENT')!=='github-hosted' ||
    basename($argv[1])!=='SeoReview') {
    fwrite(STDERR,"GSC refresh: disposable TLS laboratory required\n");exit(2);
}
$portal=dirname(__DIR__,2);
foreach(['GoogleSearchConsoleOAuthPreflight.php','GoogleSearchConsoleOAuthTokenExchangePlan.php',
    'GoogleSearchConsoleOAuthTokenResponsePolicy.php','GoogleSearchConsolePropertyIdentifier.php',
    'GoogleSearchConsoleEncryptedTokenVault.php'] as $file)require_once $argv[1].'/'.$file;
require_once $portal.'/app/Domain/SeoReview/GoogleSearchConsoleSiteListingReview.php';
require_once $portal.'/app/Domain/SeoReview/GoogleSearchConsoleRefreshTokenPostRequest.php';
require_once $portal.'/app/Infrastructure/SeoReview/GoogleSearchConsoleHttpsTransportCandidate.php';

use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenSecret as Secret;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight as Preflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenCandidate as Candidate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest as CodeRequest;
use App\Domain\SeoReview\GoogleSearchConsoleRefreshTokenPostRequest as RefreshRequest;
use App\Infrastructure\SeoReview\GoogleSearchConsoleHttpsTransportCandidate as Client;

$checks=0;$failed=false;
$secrets=[RefreshRequest::SYNTHETIC_CLIENT_SECRET,
    'synthetic-refreshed-access-token-1234567890','synthetic-rotated-refresh-token-1234567890'];
$config=['clientId'=>RefreshRequest::SYNTHETIC_CLIENT_ID,'clientSecret'=>RefreshRequest::SYNTHETIC_CLIENT_SECRET];
function refreshCheck(bool $ok,string $label): void
{
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException($label);
}
function refreshMetrics(): array
{
    global $argv;
    $raw=file_get_contents($argv[3]);
    refreshCheck(is_string($raw) && strlen($raw)<4096,'bounded numeric metrics');
    $m=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    refreshCheck(is_array($m),'metrics object exists');
    $keys=array_keys($m);sort($keys);
    refreshCheck($keys===['redirect_target','sites','token','total','unexpected','violations'],'metrics contain no payload');
    foreach($m as $count)refreshCheck(is_int($count) && $count>=0,'valid counter');
    return $m;
}
function refreshDelta(array $before,int $token,int $sites,string $label): void
{
    $after=refreshMetrics();
    refreshCheck($after['token']-$before['token']===$token,$label.' token count');
    refreshCheck($after['sites']-$before['sites']===$sites,$label.' sites count');
    refreshCheck($after['total']-$before['total']===$token+$sites,$label.' total count');
    foreach(['redirect_target','unexpected','violations'] as $key)
        refreshCheck($after[$key]===$before[$key],$label.' no redirected or malformed HTTP request');
}
function refreshNoLeaks(mixed $value): void
{
    global $secrets;
    ob_start();var_dump($value);$dump=ob_get_clean();
    $rendered=$dump.var_export($value,true).json_encode($value,JSON_THROW_ON_ERROR);
    foreach($secrets as $secret)refreshCheck(!str_contains($rendered,$secret),'no secret in diagnostics');
}
function refreshSource(Client $client,string $code='synthetic-good-with-refresh'): Secret
{
    global $secrets;
    $initial=$client->exchange(new CodeRequest([
        'client_id'=>RefreshRequest::SYNTHETIC_CLIENT_ID,
        'client_secret'=>RefreshRequest::SYNTHETIC_CLIENT_SECRET,'code'=>$code,
        'code_verifier'=>str_repeat('A',43),
        'redirect_uri'=>'https://portal.example.test/integrations/google/search-console/callback',
        'grant_type'=>'authorization_code',
    ]),time());
    refreshCheck($initial instanceof Candidate,'initial fixture response parsed');
    $data=$initial->takeForTrustedVault();
    foreach(['accessToken','refreshToken'] as $name)
        if (isset($data[$name]))$secrets[]=$data[$name];
    // This transport-only harness models the opaque vault result. It does not
    // claim encryption/PG authority: the integrated Kernel test reads it from
    // the actual encrypted, RLS-protected predecessor under the durable claim.
    return new Secret($data);
}

try {
    $client=Client::forTlsLab($argv[2]);
    refreshCheck($client instanceof Client,'TLS candidate factory');
    $baseline=refreshMetrics();
    $source=refreshSource($client);$beforeData=$source->takeForTrustedBackend();
    $request=RefreshRequest::prepare($source,$config,time());
    refreshCheck($request instanceof RefreshRequest,'live opaque predecessor prepares refresh');
    refreshNoLeaks($source);refreshNoLeaks($request);
    $before=refreshMetrics();$now=time();
    $refreshed=$client->refresh($request,$now);
    refreshCheck($refreshed instanceof Candidate,'actual TLS refresh response produces candidate');
    $new=$refreshed->takeForTrustedVault();
    refreshCheck($new['accessToken']==='synthetic-refreshed-access-token-1234567890' &&
        $new['accessToken']!==$beforeData['accessToken'],'access token comes from new response');
    refreshCheck($new['refreshToken']===$beforeData['refreshToken'],'omitted refresh preserves predecessor material');
    refreshCheck($new['expiresAt']===$now+600 && $new['expiresAt']>$beforeData['expiresAt'],
        'new response advances expiry');
    refreshCheck($new['scope']===Preflight::READONLY_SCOPE,'scope remains exactly readonly');
    foreach(['providerOriginVerified','googleIdentityVerified','tenantGrantVerified','propertyAuthorizationVerified',
        'tokenPersistenceAuthorized','customerDisplayAuthorized'] as $flag)
        refreshCheck($refreshed->safeReview()[$flag]===false,'refresh candidate is not authority');
    refreshNoLeaks($refreshed);
    refreshDelta($before,1,0,'one refresh POST');
    refreshCheck($request->takeForTrustedHttpsTransport()===null,'first refresh consumed material');
    $before=refreshMetrics();
    refreshCheck($client->refresh($request,time())===null,'success cannot repeat envelope');
    refreshDelta($before,0,0,'success replay');
    $before=refreshMetrics();
    $sites=$client->inspectSites($refreshed,'sc-domain:example.com',time());
    refreshCheck(is_array($sites) && $sites['tlsLabVerified']===true &&
        $sites['providerOriginVerified']===false,'new bearer obtains current synthetic property evidence');
    refreshDelta($before,0,1,'sites uses new bearer');
    refreshNoLeaks($sites);unset($new);

    $rotating=refreshSource($client,'synthetic-initial-refresh-rotate');
    $rotateRequest=RefreshRequest::prepare($rotating,$config,time());
    refreshCheck($rotateRequest instanceof RefreshRequest,'rotation source prepares');
    $before=refreshMetrics();$rotated=$client->refresh($rotateRequest,time());
    refreshCheck($rotated instanceof Candidate,'rotation response accepted');
    $data=$rotated->takeForTrustedVault();
    refreshCheck($data['refreshToken']==='synthetic-rotated-refresh-token-1234567890' &&
        $data['refreshToken']!==$rotating->takeForTrustedBackend()['refreshToken'],'returned refresh replaces predecessor');
    refreshDelta($before,1,0,'one rotated refresh');refreshNoLeaks($rotated);
    // Prove the retained/rotated value can prepare the next transport stage.
    // Cross-process admission for a stored version belongs to the SQL claim.
    $next=RefreshRequest::prepare(new Secret($data),$config,time());
    refreshCheck($next instanceof RefreshRequest,'rotated material prepares next generation');
    $before=refreshMetrics();$nextResult=$client->refresh($next,time());
    refreshCheck($nextResult instanceof Candidate &&
        $nextResult->takeForTrustedVault()['refreshToken']===$data['refreshToken'],
        'later omission preserves the rotated refresh value');
    refreshDelta($before,1,0,'one later-generation request');unset($data);

    $propertySource=refreshSource($client,'synthetic-initial-refresh-sites-missing-property');
    $propertyRequest=RefreshRequest::prepare($propertySource,$config,time());
    refreshCheck($propertyRequest instanceof RefreshRequest,'missing-property source prepares');
    $before=refreshMetrics();$propertyCandidate=$client->refresh($propertyRequest,time());
    refreshCheck($propertyCandidate instanceof Candidate,'missing-property refresh response is parsed');
    $propertyData=$propertyCandidate->takeForTrustedVault();
    refreshCheck($propertyData['accessToken']==='synthetic-sites-missing-property-access-token-1234567890' &&
        $propertyData['refreshToken']===$propertySource->takeForTrustedBackend()['refreshToken'],
        'missing-property bearer comes from refresh while prior refresh is preserved');
    $secrets[]=$propertyData['accessToken'];
    refreshDelta($before,1,0,'missing-property refresh is one POST');
    $before=refreshMetrics();
    refreshCheck($client->inspectSites($propertyCandidate,'sc-domain:example.com',time())===null,
        'actual refreshed bearer without current property access fails closed');
    refreshDelta($before,0,1,'missing-property evidence uses the actual refreshed bearer');
    refreshNoLeaks($propertyCandidate);unset($propertyData);

    foreach(['invalid-grant','redirect','cross-origin-redirect','bad-json','wrong-scope',
        'oversize','timeout','temporary-consent'] as $case) {
        $badSource=refreshSource($client,'synthetic-initial-refresh-'.$case);
        $badRequest=RefreshRequest::prepare($badSource,$config,time());
        refreshCheck($badRequest instanceof RefreshRequest,'negative response scenario prepares');
        $before=refreshMetrics();$started=microtime(true);
        refreshCheck($client->refresh($badRequest,time())===null,'negative refresh response fails closed');
        refreshCheck(microtime(true)-$started<5,'refresh timeout bounded');
        refreshCheck($badRequest->takeForTrustedHttpsTransport()===null,'failed refresh consumed envelope');
        refreshDelta($before,1,0,'failed refresh has no redirect or retry');
        $before=refreshMetrics();
        refreshCheck($client->refresh($badRequest,time())===null,'failed refresh cannot replay');
        refreshDelta($before,0,0,'failed refresh replay');refreshNoLeaks($badRequest);
    }

    $wrongCa=Client::forTlsLab(dirname($argv[2]).'/wrong-ca.pem');
    refreshCheck($wrongCa instanceof Client,'wrong trust root fixture');
    $r=RefreshRequest::prepare($source,$config,time());
    refreshCheck($r instanceof RefreshRequest,'TLS negative prepares');$before=refreshMetrics();
    refreshCheck($wrongCa->refresh($r,time())===null,'wrong CA rejects refresh before HTTP');
    refreshCheck($r->takeForTrustedHttpsTransport()===null,'TLS failure consumes refresh material');
    refreshCheck($client->refresh($r,time())===null,'good client cannot retry failed TLS envelope');
    refreshDelta($before,0,0,'TLS rejection sends no form');

    $now=time();$base=$source->takeForTrustedBackend();
    $invalid=[array_replace($base,['expiresAt'=>$now]),array_replace($base,['expiresAt'=>$now+7200]),
        array_replace($base,['scope'=>'https://www.googleapis.com/auth/webmasters']),
        array_replace($base,['refreshToken'=>"synthetic-refresh\r\nInjected: value"]),
        array_replace($base,['refreshToken'=>'not-synthetic-refresh-token-1234567890'])];
    $withoutRefresh=$base;unset($withoutRefresh['refreshToken']);$invalid[]=$withoutRefresh;
    $before=refreshMetrics();
    foreach($invalid as $data)
        refreshCheck(RefreshRequest::prepare(new Secret($data),$config,$now)===null,
            'expired, unsupported or unsafe predecessor cannot prepare');
    foreach([[],array_replace($config,['clientSecret'=>'synthetic-other-secret']),
        [...$config,'tenantRef'=>'browser-forged'],[...$config,'redirectUri'=>'https://unexpected.example.invalid']] as $badConfig)
        refreshCheck(RefreshRequest::prepare($source,$badConfig,$now)===null,'only fixed server-side configuration accepted');
    refreshDelta($before,0,0,'preparation denials make no HTTP request');

    $expires=$source->takeForTrustedBackend()['expiresAt'];
    $r=RefreshRequest::prepare($source,$config,time());
    refreshCheck($r instanceof RefreshRequest,'candidate prepares before expiry');$before=refreshMetrics();
    refreshCheck($client->refresh($r,$expires)===null,'expiry is rechecked before transmitting prepared envelope');
    refreshCheck($r->takeForTrustedHttpsTransport()===null,'stale prepared material is consumed');
    refreshDelta($before,0,0,'expired predecessor never reaches network');

    $r=RefreshRequest::prepare($source,$config,time());
    refreshCheck($r instanceof RefreshRequest,'additional gate test prepares');$before=refreshMetrics();
    try {
        putenv('PORTAL_GSC_REFRESH_LAB=0');
        refreshCheck(RefreshRequest::prepare($source,$config,time())===null,'refresh preparation is separately gated');
        refreshCheck($client->refresh($r,time())===null,'existing TLS client rechecks refresh gate');
        refreshDelta($before,0,0,'disabled refresh has no network');
    } finally {putenv('PORTAL_GSC_REFRESH_LAB=1');}
    refreshCheck($client->refresh($r,time()) instanceof Candidate,'disabled gate did not consume an unattempted envelope');
    refreshDelta($before,1,0,'restored gate permits a single attempt');

    $r=RefreshRequest::prepare($source,$config,time());
    refreshCheck($r instanceof RefreshRequest,'nonserializable envelope prepares');
    foreach(['clone','serialize','unserialize'] as $operation) {
        $denied=false;
        try {
            if ($operation==='clone')$copy=clone $r;
            elseif ($operation==='serialize')serialize($r);
            else $r->__unserialize([]);
        } catch(LogicException) {$denied=true;}
        refreshCheck($denied,'refresh material cannot be cloned or serialized');
    }
    refreshNoLeaks($r);
    $transport=$r->takeForTrustedHttpsTransport();
    refreshCheck(is_array($transport),'server-only request handoff');$fields=[];
    parse_str($transport['body'],$fields);
    refreshCheck(array_keys($fields)===['client_id','client_secret','refresh_token','grant_type'] &&
        $fields['grant_type']==='refresh_token','refresh form excludes code verifier state redirect and tenant');
    refreshCheck($r->takeForTrustedHttpsTransport()===null,'direct handoff is also one-use');
    unset($transport,$fields,$base,$beforeData,$invalid,$withoutRefresh);refreshNoLeaks($r);
    $final=refreshMetrics();
    foreach(['redirect_target','unexpected','violations'] as $key)
        refreshCheck($final[$key]===$baseline[$key],'suite emitted no redirected or malformed request');
} catch(Throwable $e) {
    $failed=true;
    $label=get_class($e)===RuntimeException::class ? $e->getMessage() :
        'unexpected '.get_class($e).' at line '.$e->getLine();
    fwrite(STDERR,'GSC refresh transport failed: '.$label."\n");
}
if (!$failed)echo "GSC refresh transport: {$checks} checks; early-only synthetic TLS, omission/rotation, ".
    "one-use, bounds, response policy and redaction checked; NO GOOGLE OR OPERATIONAL AUTHORITY\n";
exit($failed?1:0);
