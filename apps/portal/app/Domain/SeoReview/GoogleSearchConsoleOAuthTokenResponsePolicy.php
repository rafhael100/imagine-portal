<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

/**
 * Slice M: strict, non-authorizing parser for a Google OAuth token response.
 * Caller must prove TLS origin and HTTPS transport separately.
 * No HTTP, Google account validation or token persistence is performed.
 */
final class GoogleSearchConsoleOAuthTokenResponsePolicy
{
    private const REQUIRED=[
        'access_token','expires_in','scope','token_type'
    ];
    private const OPTIONAL=['refresh_token'];

    public function inspect(
        mixed $httpStatus,
        #[\SensitiveParameter] mixed $rawResponseBody,
        mixed $trustedNow
    ): ?GoogleSearchConsoleOAuthTokenCandidate {
        if($httpStatus!==200||!is_string($rawResponseBody)||
            $rawResponseBody===''||strlen($rawResponseBody)>16384||
            !is_int($trustedNow)||$trustedNow<1600000000||
            $trustedNow>2200000000)return null;
        try{
            $body=json_decode($rawResponseBody,true,16,JSON_THROW_ON_ERROR);
        }catch(\JsonException){
            return null;
        }
        if(!is_array($body)||array_is_list($body)||
            count($body)<4||count($body)>5)return null;
        $actual=array_keys($body);sort($actual);
        $expected=self::REQUIRED;
        if(array_key_exists('refresh_token',$body))$expected[]='refresh_token';
        sort($expected);
        if($actual!==$expected)return null;

        if($body['token_type']!=='Bearer'||
            $body['scope']!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE||
            !is_int($body['expires_in'])||$body['expires_in']<1||
            $body['expires_in']>3600||
            !self::validSecret($body['access_token'])||
            (array_key_exists('refresh_token',$body)&&
                !self::validSecret($body['refresh_token'])))return null;

        $secret=['accessToken'=>$body['access_token']];
        if(array_key_exists('refresh_token',$body)){
            $secret['refreshToken']=$body['refresh_token'];
        }
        return new GoogleSearchConsoleOAuthTokenCandidate(
            $secret,$trustedNow+$body['expires_in']
        );
    }

    private static function validSecret(mixed $value): bool
    {
        return is_string($value)&&
            preg_match('/\A[\x21-\x7e]{20,4096}\z/D',$value)===1;
    }

    public static function authorizesGoogleIdentity():bool{return false;}
    public static function authorizesTokenPersistence():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}

/**
 * Private in-memory token candidate. Caller MUST validate Google identity,
 * tenant/property grants and scope with an authenticated backend before
 * storing or using any token. This object is not an API response.
 */
final class GoogleSearchConsoleOAuthTokenCandidate
{
    private readonly \SensitiveParameterValue $secret;

    public function __construct(
        #[\SensitiveParameter] array $secret,
        private readonly int $expiresAt
    ){
        $this->secret=new \SensitiveParameterValue($secret);
    }

    public function safeReview(): array
    {
        return [
            'contractVersion'=>'portal-gsc-oauth-token-candidate-offline-v1',
            'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
            'expiresAt'=>$this->expiresAt,
            'refreshTokenCandidate'=>array_key_exists(
                'refreshToken',$this->secret->getValue()
            ),
            'providerOriginVerified'=>false,
            'googleIdentityVerified'=>false,
            'tenantGrantVerified'=>false,
            'propertyAuthorizationVerified'=>false,
            'tokenPersistenceAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_EXTERNAL_AUTHENTICATED_BACKEND'
        ];
    }

    /** SERVER ONLY; never send the result to a browser, log or artifact. */
    public function takeForTrustedVault(): array
    {
        return [...$this->secret->getValue(),
            'expiresAt'=>$this->expiresAt,
            'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE];
    }

    public function __debugInfo():array{return $this->safeReview();}
}
