<?php
declare(strict_types=1);

namespace App\Infrastructure\SeoReview;

use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenCandidate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenExchangePlan;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenResponsePolicy;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\GoogleSearchConsoleSiteListingReview;
use CurlHandle;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Unregistered HTTPS transport candidate. Its ONLY factory connects to fixed
 * loopback TLS fixtures, never Google. No operational constructor/config flag.
 * A CI CA proves a laboratory TLS peer, not Google identity or tenant access.
 */
final readonly class GoogleSearchConsoleHttpsTransportCandidate
{
    private const SITES_ENDPOINT='https://www.googleapis.com/webmasters/v3/sites';
    private const TOKEN_CONNECT_TO='oauth2.googleapis.com:443:127.0.0.1:18443';
    private const SITES_CONNECT_TO='www.googleapis.com:443:127.0.0.1:18444';
    private const MAX_HEADERS=8192;
    private const MAX_TOKEN_BODY=16384;
    private const MAX_SITES_BODY=32768;

    private function __construct(private string $caFile) {}

    public static function forTlsLab(string $caFile): ?self
    {
        if (!self::labEnabled() || !self::validCaFile($caFile))return null;
        return new self($caFile);
    }

    /** Consume the existing one-use envelope before any transport attempt. */
    public function exchange(
        #[SensitiveParameter] GoogleSearchConsoleOAuthTokenPostRequest $envelope,
        int $trustedNow,
    ): ?GoogleSearchConsoleOAuthTokenCandidate {
        if (!self::labEnabled() || !self::validCaFile($this->caFile) ||
            !self::validTime($trustedNow))return null;
        $request=$envelope->takeForTrustedHttpsTransport();
        if (!is_array($request) ||
            ($request['method']??null)!=='POST' ||
            ($request['url']??null)!==GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT ||
            ($request['allowRedirects']??null)!==false ||
            ($request['timeoutSeconds']??null)!==10 ||
            ($request['headers']??null)!==[
                'Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json',
            ] || !is_string($request['body']??null) || strlen($request['body'])>8192)
            return null;
        $form=[];parse_str($request['body'],$form);
        if (array_keys($form)!==['client_id','client_secret','code','code_verifier','redirect_uri','grant_type'] ||
            ($form['grant_type']??null)!=='authorization_code' ||
            !is_string($form['code']??null) ||
            preg_match('/\Asynthetic-[A-Za-z0-9._~\/:-]{1,118}\z/D',$form['code'])!==1 ||
            !is_string($form['client_secret']??null) ||
            preg_match('/\Asynthetic-[\x21-\x7e]{1,502}\z/D',$form['client_secret'])!==1 ||
            !is_string($form['code_verifier']??null) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$form['code_verifier'])!==1 ||
            !GoogleSearchConsoleOAuthPreflight::validRegistration([
                'clientId'=>$form['client_id']??null,'registeredRedirectUri'=>$form['redirect_uri']??null,
            ]))return null;
        unset($form);
        $response=$this->request('POST',GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT,
            $request['body'],null,self::MAX_TOKEN_BODY);
        unset($request);
        if ($response===null)return null;
        try {
            $candidate=(new GoogleSearchConsoleOAuthTokenResponsePolicy())->inspect(
                200,$response,$trustedNow);
            if (!$candidate instanceof GoogleSearchConsoleOAuthTokenCandidate)return null;
            // The only execution mode is synthetic. Do not pass a real-looking
            // bearer from a fixture into the next request or back to a caller.
            $data=$candidate->takeForTrustedVault();
            foreach (['accessToken','refreshToken'] as $field)
                if (array_key_exists($field,$data) && !self::syntheticToken($data[$field]))return null;
            return $candidate;
        } catch(Throwable) {return null;}
        finally {unset($response,$data);}
    }

    /**
     * The opaque candidate is backend input, not proof of consumed state or
     * Google authority. Caller must keep the existing F115 provenance/fences.
     */
    public function inspectSites(
        #[SensitiveParameter] GoogleSearchConsoleOAuthTokenCandidate $candidate,
        string $exactSite,
        int $trustedNow,
    ): ?array {
        if (!self::labEnabled() || !self::validCaFile($this->caFile) ||
            !self::validTime($trustedNow))return null;
        $site=GoogleSearchConsolePropertyIdentifier::exactSiteUrl($exactSite);
        if ($site===null)return null;
        $data=$candidate->takeForTrustedVault();
        if (!self::syntheticToken($data['accessToken']??null) ||
            ($data['scope']??null)!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
            !is_int($data['expiresAt']??null) || $data['expiresAt']<=$trustedNow ||
            $data['expiresAt']>$trustedNow+3600)return null;
        $response=$this->request('GET',self::SITES_ENDPOINT,null,$data['accessToken'],self::MAX_SITES_BODY);
        unset($data);
        if ($response===null)return null;
        try {
            $parsed=json_decode($response,true,16,JSON_THROW_ON_ERROR);
            if (!(new GoogleSearchConsoleSiteListingReview())->containsExactFullAccessCandidate($parsed,$site))
                return null;
            return [
                'contractVersion'=>'portal-gsc-https-transport-candidate-lab-v1',
                'exactSiteUrlMatched'=>true,
                'siteIdentifierFingerprint'=>GoogleSearchConsolePropertyIdentifier::fingerprint($site),
                'tlsLabVerified'=>true,
                'providerOriginVerified'=>false,
                'googleOAuthVerified'=>false,
                'googleNetworkPerformed'=>false,
                'tokenPersistenceAuthorized'=>false,
                'customerDisplayAuthorized'=>false,
                'productionAuthorized'=>false,
                'releaseStatus'=>'HOLD_REAL_GOOGLE_IDENTITY_CONSENT_AND_APPSEC',
            ];
        } catch(Throwable) {return null;}
        finally {unset($response,$parsed);}
    }

    /** Fixed endpoints only. Raw response exists solely inside this class. */
    private function request(
        string $method,string $endpoint,#[SensitiveParameter] ?string $form,
        #[SensitiveParameter] ?string $bearer,int $maxBody,
    ): ?string {
        if (!self::labEnabled())return null;
        if ($endpoint===GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT && $method==='POST' &&
            $form!==null && $bearer===null) {
            $connectTo=self::TOKEN_CONNECT_TO;$port=18443;
        } elseif ($endpoint===self::SITES_ENDPOINT && $method==='GET' && $form===null && $bearer!==null) {
            $connectTo=self::SITES_CONNECT_TO;$port=18444;
        } else return null;
        $handle=null;$body='';$headerBytes=0;$mime=null;$encoding=null;$invalidHeader=false;
        try {
            $handle=curl_init($endpoint);
            if (!$handle instanceof CurlHandle)return null;
            $headers=['Accept: application/json','Accept-Encoding: identity',
                'Cache-Control: no-store','Pragma: no-cache','Connection: close','Expect:'];
            if ($method==='POST')$headers[]='Content-Type: application/x-www-form-urlencoded';
            else $headers[]='Authorization: Bearer '.$bearer;
            $options=[
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>2000,CURLOPT_NOSIGNAL=>true,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                CURLOPT_CAINFO=>$this->caFile,CURLOPT_CONNECT_TO=>[$connectTo],
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                CURLOPT_FRESH_CONNECT=>true,CURLOPT_FORBID_REUSE=>true,CURLOPT_DNS_CACHE_TIMEOUT=>0,
                CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_VERBOSE=>false,
                CURLOPT_HEADERFUNCTION=>static function(CurlHandle $h,string $line)
                    use(&$headerBytes,&$mime,&$encoding,&$invalidHeader):int {
                    $length=strlen($line);$headerBytes+=$length;
                    if ($headerBytes>self::MAX_HEADERS)return 0;
                    if (stripos($line,'Content-Type:')===0) {
                        if ($mime!==null)$invalidHeader=true;
                        $mime=strtolower(trim(substr($line,13)));
                    }
                    if (stripos($line,'Content-Encoding:')===0) {
                        if ($encoding!==null)$invalidHeader=true;
                        $encoding=strtolower(trim(substr($line,17)));
                        if ($encoding!=='identity')$invalidHeader=true;
                    }
                    return $invalidHeader?0:$length;
                },
                CURLOPT_WRITEFUNCTION=>static function(CurlHandle $h,string $chunk)use(&$body,$maxBody):int {
                    if (strlen($body)+strlen($chunk)>$maxBody)return 0;
                    $body.=$chunk;return strlen($chunk);
                },
            ];
            if ($method==='POST') {$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$form;}
            else $options[CURLOPT_HTTPGET]=true;
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR]='https';$options[CURLOPT_REDIR_PROTOCOLS_STR]='https';
            } else {
                $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;$options[CURLOPT_REDIR_PROTOCOLS]=CURLPROTO_HTTPS;
            }
            if (!curl_setopt_array($handle,$options) || curl_exec($handle)!==true || $invalidHeader ||
                curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200 ||
                curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!==$endpoint ||
                curl_getinfo($handle,CURLINFO_PRIMARY_IP)!=='127.0.0.1' ||
                curl_getinfo($handle,CURLINFO_PRIMARY_PORT)!==$port ||
                !is_string($mime) || preg_match('/\Aapplication\/json(?:;\s*charset=(?:utf-8|"utf-8"))?\z/iD',$mime)!==1)
                return null;
            return $body;
        } catch(Throwable) {
            // Do not include curl errors, response data, URLs, form or headers.
            return null;
        } finally {
            if ($handle instanceof CurlHandle)curl_close($handle);
            unset($body,$headers,$options,$form,$bearer);
        }
    }

    private static function labEnabled(): bool
    {
        return PHP_SAPI==='cli' && getenv('APP_ENV')==='testing' &&
            getenv('PORTAL_GSC_HTTPS_TRANSPORT_LAB')==='1' &&
            getenv('GITHUB_ACTIONS')==='true' && getenv('RUNNER_ENVIRONMENT')==='github-hosted' &&
            extension_loaded('curl') && extension_loaded('sodium');
    }

    private static function validTime(int $now): bool
    {
        return $now>=1600000000 && $now<=2200000000;
    }

    private static function validCaFile(string $path): bool
    {
        return is_file($path) && !is_link($path) && is_readable($path) &&
            filesize($path)>0 && filesize($path)<=16384;
    }

    private static function syntheticToken(mixed $token): bool
    {
        return is_string($token) && str_starts_with($token,'synthetic-') &&
            preg_match('/\A[\x21-\x7e]{20,4096}\z/D',$token)===1;
    }

    public function __debugInfo(): array
    {
        return ['component'=>'GSC synthetic TLS transport candidate','googleNetworkPerformed'=>false,
            'productionAuthorized'=>false];
    }
    public function __serialize(): array {throw new LogicException('Transport candidate cannot be serialized');}
    public function __unserialize(array $data): void {throw new LogicException('Transport candidate cannot be deserialized');}
    private function __clone() {}
}
