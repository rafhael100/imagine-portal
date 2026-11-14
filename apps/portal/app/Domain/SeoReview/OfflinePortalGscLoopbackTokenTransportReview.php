<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use CurlHandle;
use SensitiveParameter;
use Throwable;

/**
 * F116: loopback-only disposable token transport rehearsal.
 *
 * This class deliberately CAN'T contact Google or arbitrary hosts. The
 * fixed HTTP loopback transport exercises the real cURL request/parser
 * boundary with synthetic one-use envelopes. It is NOT a production
 * Google OAuth transport, OAuth callback or token persistence layer.
 *
 * A production transport must use exact Google HTTPS origin + valid TLS,
 * audited socket/proxy/redirect policy and trusted F115 provenance.
 */
final class OfflinePortalGscLoopbackTokenTransportReview
{
    private const LAB_ENDPOINT='http://127.0.0.1:18765/token';
    private const MAX_RESPONSE=16384;

    /**
     * Consumes the synthetic token POST envelope at most once, even when
     * the request fails. No body, header, credentials or token is returned.
     */
    public function inspect(
        GoogleSearchConsoleOAuthTokenPostRequest $envelope,
        mixed $trustedNow,
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_GSC_F116_LOOPBACK_LAB')!=='1' ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000 ||
            !extension_loaded('curl') ||
            !extension_loaded('sodium'))
            return null;

        // There is NO URL argument. An attacker cannot use the browser's
        // callback or a DNS rebinding trick to select an external endpoint.
        $transport=$envelope->takeForTrustedHttpsTransport();
        if (!is_array($transport) ||
            ($transport['method']??null)!=='POST' ||
            ($transport['url']??null)!==
                GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT ||
            ($transport['allowRedirects']??null)!==false ||
            ($transport['timeoutSeconds']??null)!==10 ||
            ($transport['headers']??null)!==[
                'Content-Type'=>'application/x-www-form-urlencoded',
                'Accept'=>'application/json',
            ] ||
            !is_string($transport['body']??null) ||
            strlen($transport['body'])>8192) return null;

        $params=[];
        parse_str($transport['body'],$params);
        if (!is_array($params) ||
            array_keys($params)!==[
                'client_id','client_secret','code','code_verifier',
                'redirect_uri','grant_type',
            ] ||
            ($params['grant_type']??null)!=='authorization_code' ||
            !is_string($params['code']??null) ||
            preg_match('/\A[A-Za-z0-9._~\/:-]{8,128}\z/D',
                $params['code'])!==1 ||
            !is_string($params['code_verifier']??null) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',
                $params['code_verifier'])!==1 ||
            !is_string($params['client_secret']??null) ||
            strlen($params['client_secret'])<8 ||
            !GoogleSearchConsoleOAuthPreflight::validRegistration([
                'clientId'=>$params['client_id']??null,
                'registeredRedirectUri'=>$params['redirect_uri']??null,
            ])) return null;

        $response='';
        $responseSize=0;
        $mediaType=null;
        $tooLarge=false;
        $handle=null;
        try {
            $handle=curl_init(self::LAB_ENDPOINT);
            if (!$handle instanceof CurlHandle)return null;
            $options=[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$transport['body'],
                CURLOPT_HTTPHEADER=>[
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'Connection: close',
                ],
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>3,
                CURLOPT_NOSIGNAL=>true,
                CURLOPT_PROXY=>'',
                CURLOPT_NOPROXY=>'127.0.0.1',
                CURLOPT_USERAGENT=>'Imagine-GSC-F116-Offline-Review/1',
                CURLOPT_HEADERFUNCTION=>static function(
                    CurlHandle $curl,string $header
                ) use (&$mediaType):int {
                    if (stripos($header,'Content-Type:')===0)
                        $mediaType=strtolower(trim(substr($header,13)));
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION=>static function(
                    CurlHandle $curl,string $chunk
                ) use (&$response,&$responseSize,&$tooLarge):int {
                    $responseSize+=strlen($chunk);
                    if ($responseSize>self::MAX_RESPONSE){
                        $tooLarge=true;
                        return 0;
                    }
                    $response.=$chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR'))
                $options[CURLOPT_PROTOCOLS_STR]='http';
            else
                $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP;
            if (defined('CURLOPT_REDIR_PROTOCOLS_STR'))
                $options[CURLOPT_REDIR_PROTOCOLS_STR]='';
            if(!curl_setopt_array($handle,$options))return null;
            if(curl_exec($handle)!==true || $tooLarge)return null;
            $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            $effective=curl_getinfo($handle,CURLINFO_EFFECTIVE_URL);
            if ($status!==200 || $effective!==self::LAB_ENDPOINT ||
                $mediaType!=='application/json')return null;
            $candidate=(new GoogleSearchConsoleOAuthTokenResponsePolicy())
                ->inspect($status,$response,$trustedNow);
            if (!$candidate instanceof GoogleSearchConsoleOAuthTokenCandidate)
                return null;
            $safe=$candidate->safeReview();
            if(($safe['providerOriginVerified']??null)!==false ||
                ($safe['tokenPersistenceAuthorized']??null)!==false ||
                ($safe['customerDisplayAuthorized']??null)!==false)
                return null;

            return [
                'contractVersion'=>'portal-gsc-f116-local-token-transport-lab-v1',
                'status'=>'SYNTHETIC_RESPONSE_SHAPE_VERIFIED_ONLY',
                'localTransportAttempted'=>true,
                'localHttpStatus'=>200,
                'loopbackOnly'=>true,
                'redirectsRejected'=>true,
                'maxResponseBytes'=>self::MAX_RESPONSE,
                'tokenResponseStructureVerified'=>true,
                'readonlyScopeMatched'=>true,
                'syntheticRefreshCandidatePresent'=>$safe['refreshTokenCandidate'],
                'providerOriginVerified'=>false,
                'googleIdentityVerified'=>false,
                'googleOAuthVerifiedNow'=>false,
                'propertyAuthorizationVerified'=>false,
                'tokenExchangeAuthorizedForProduction'=>false,
                'tokenPersistenceAuthorized'=>false,
                'customerDisplayAuthorized'=>false,
                'networkToGooglePerformed'=>false,
                'releaseStatus'=>'HOLD_REAL_GOOGLE_HTTPS_AND_APPSEC',
            ];
        }catch(Throwable) {
            return null;
        }finally{
            if($handle instanceof CurlHandle)curl_close($handle);
            // No secret, response body or token escapes the method.
            $response='';
        }
    }

    public static function canCallGoogle():bool{return false;}
    public static function canStoreTokens():bool{return false;}
    public static function canAuthorizeCustomer():bool{return false;}
}
