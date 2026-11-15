<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F150 — pure classification of untrusted provider HTTP status evidence.
 *
 * Does NOT perform HTTP, inspect body, authenticate Google, schedule retries,
 * or grant tenant access. Caller must enforce per-tenant quotas and request
 * deadlines in an independently reviewed backend.
 */
final class GoogleSearchConsoleProviderResponsePolicy
{
    /**
     * @return array{category:string,retryAllowed:bool,retryAfterSeconds:int|null,
     *               publicationAuthorized:bool,providerAuthenticated:bool}|null
     */
    public function inspect(
        mixed $statusCode,
        #[\SensitiveParameter] mixed $headers,
        mixed $attempt,
    ): ?array {
        if (!is_int($statusCode) || $statusCode<100 || $statusCode>599 ||
            !is_int($attempt) || $attempt<1 || $attempt>3 ||
            !is_array($headers) || array_is_list($headers) && $headers!==[]) {
            return null;
        }
        // Do not accept or retain provider response bodies. Only a single
        // numeric Retry-After header is allowed. No tokens or cookies.
        if(count($headers)>1) return null;
        $retryAfter=null;
        foreach($headers as $key=>$value){
            if(!is_string($key)||strtolower($key)!=='retry-after'||
                !is_string($value)||preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D',$value)!==1)
                return null;
            $retryAfter=(int)$value;
            if($retryAfter>120)return null;
        }
        $category='REVIEW_HTTP_ERROR';
        $retryAllowed=false;
        $delay=null;
        if($statusCode>=200 && $statusCode<300){
            // HTTP success is not OAuth, property verification or data integrity.
            $category='HTTP_OK_CONTENT_UNVERIFIED';
        } elseif($statusCode===401){
            $category='REAUTH_REQUIRED_HOLD';
        } elseif($statusCode===403){
            $category='PROPERTY_PERMISSION_HOLD';
        } elseif($statusCode===404){
            $category='PROPERTY_NOT_FOUND_HOLD';
        } elseif(in_array($statusCode,[429,500,502,503,504],true)){
            $category=$statusCode===429?'QUOTA_REVIEW':'TRANSIENT_PROVIDER_REVIEW';
            $retryAllowed=$attempt<3;
            if($retryAllowed){
                // Never retry infinitely. Numeric Retry-After is bounded.
                $default=min(60,2**($attempt+1));
                $delay=max($default,$retryAfter??0);
            }
        }
        if($retryAfter!==null&&!in_array($statusCode,[429,503],true))
            return null;
        return [
            'category'=>$category,
            'retryAllowed'=>$retryAllowed,
            'retryAfterSeconds'=>$delay,
            'publicationAuthorized'=>false,
            'providerAuthenticated'=>false,
        ];
    }

    public static function authorizesOutboundHttp(): bool {return false;}
    public static function authorizesTokenStorage(): bool {return false;}
    public static function authorizesCustomerDisplay(): bool {return false;}
}
