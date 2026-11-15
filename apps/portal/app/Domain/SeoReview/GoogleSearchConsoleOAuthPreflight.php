<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

/**
 * Offline-only GSC OAuth PKCE/state preflight.
 * Trusted backend must supply registered config and authenticated context.
 * Never send serverOnly (code verifier) to browsers or logs.
 * No HTTP, Google verification, real MFA, token exchange or token storage.
 */
final class GoogleSearchConsoleOAuthPreflight
{
    public const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const CALLBACK_PATH = '/integrations/google/search-console/callback';

    /**
     * Syntactic config check shared by preflight and token-request planning.
     * DOES NOT prove Google Cloud registration or authority to use credentials.
     */
    public static function validRegistration(mixed $config): bool
    {
        return self::exact($config,['clientId','registeredRedirectUri'])
            && is_string($config['clientId'])
            && preg_match('/\A[0-9]{3,20}-[A-Za-z0-9_-]{4,100}\.apps\.googleusercontent\.com\z/D',
                $config['clientId'])===1
            && self::validCallback($config['registeredRedirectUri']);
    }

    public function prepare(mixed $config, mixed $context, mixed $now): ?array
    {
        if (!self::validRegistration($config) ||
            !self::exact($context,['tenantRef','actorRef']) ||
            !is_int($now) || $now<1600000000 || $now>2200000000 ||
            !self::validRef($context['tenantRef']) ||
            !self::validRef($context['actorRef'])) {
            return null;
        }
        $state=self::base64url(random_bytes(32));
        $verifier=self::base64url(random_bytes(32));
        $challenge=self::base64url(hash('sha256',$verifier,true));
        $params=[
            'client_id'=>$config['clientId'],
            'redirect_uri'=>$config['registeredRedirectUri'],
            'response_type'=>'code',
            'scope'=>self::READONLY_SCOPE,
            'state'=>$state,
            'code_challenge'=>$challenge,
            'code_challenge_method'=>'S256',
        ];
        return [
            'contractVersion'=>'portal-gsc-oauth-pkce-preflight-offline-v1',
            'publicAuthorization'=>[
                'url'=>self::AUTH_ENDPOINT.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),
                'scope'=>self::READONLY_SCOPE,
                'state'=>$state,
                'codeChallenge'=>$challenge,
                'codeChallengeMethod'=>'S256',
            ],
            // SECRET: backend memory or encrypted short-lived store only.
            'serverOnly'=>[
                'tenantRef'=>$context['tenantRef'],
                'actorRef'=>$context['actorRef'],
                'stateSha256'=>hash('sha256',$state),
                'codeVerifier'=>$verifier,
                'issuedAt'=>$now,
                'expiresAt'=>$now+600,
                'consumed'=>false
            ],
            'oauthVerified'=>false,
            'tenantGrantVerified'=>false,
            'propertyAuthorizationVerified'=>false,
            'callbackAuthenticated'=>false,
            'tokenExchangeAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_EXTERNAL_AUTHENTICATED_BACKEND',
        ];
    }

    /**
     * Only for records read from a TRUSTED store, not input from a browser.
     * Does not mark a state consumed or authorize an OAuth code exchange.
     */
    public function matchesStoredState(
        mixed $state,
        #[\SensitiveParameter] mixed $record,
        mixed $currentContext,
        mixed $now,
    ): bool {
        if (!is_string($state) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$state)!==1 ||
            !self::exact($record,[
                'tenantRef','actorRef','stateSha256','codeVerifier',
                'issuedAt','expiresAt','consumed'
            ]) ||
            !self::exact($currentContext,['tenantRef','actorRef']) ||
            !is_int($now) ||
            !is_int($record['issuedAt']) || !is_int($record['expiresAt']) ||
            $record['issuedAt']>$now || $record['expiresAt']<$now ||
            $record['expiresAt']-$record['issuedAt']!==600 ||
            $record['consumed']!==false ||
            !self::validRef($currentContext['tenantRef']) ||
            !self::validRef($currentContext['actorRef']) ||
            $record['tenantRef']!==$currentContext['tenantRef'] ||
            $record['actorRef']!==$currentContext['actorRef'] ||
            !is_string($record['stateSha256']) ||
            preg_match('/\A[0-9a-f]{64}\z/D',$record['stateSha256'])!==1 ||
            !is_string($record['codeVerifier']) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$record['codeVerifier'])!==1) {
            return false;
        }
        return hash_equals($record['stateSha256'],hash('sha256',$state));
    }

    public static function authorizesGoogleHttp(): bool { return false; }
    public static function authorizesTokenPersistence(): bool { return false; }
    public static function authorizesCustomerDisplay(): bool { return false; }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }

    private static function exact(mixed $value,array $allowed): bool
    {
        if (!is_array($value) || array_is_list($value) ||
            count($value)!==count($allowed))return false;
        $keys=array_keys($value);
        sort($keys);sort($allowed);
        return $keys===$allowed;
    }

    private static function validRef(mixed $ref): bool
    {
        return is_string($ref) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$ref)===1;
    }

    private static function validCallback(mixed $url): bool
    {
        if (!is_string($url)||strlen($url)>255 ||
            !str_starts_with($url,'https://') ||
            str_contains($url,'%')||str_contains($url,'\\')||
            str_contains($url,'?')||str_contains($url,'#'))return false;
        $parts=parse_url($url);
        if (!is_array($parts) ||
            count(array_diff(array_keys($parts),['scheme','host','path']))!==0 ||
            ($parts['scheme']??null)!=='https' ||
            ($parts['path']??null)!==self::CALLBACK_PATH)return false;
        $host=$parts['host']??null;
        return is_string($host)&&strlen($host)<=253 &&
            $host===strtolower($host) &&
            preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D',$host)===1;
    }
}
