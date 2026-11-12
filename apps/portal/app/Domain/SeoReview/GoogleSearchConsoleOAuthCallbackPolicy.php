<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

/**
 * Slice H: pure offline callback *classification*, NOT an OAuth callback.
 *
 * The callback's state MUST be checked against a trusted, server-side record
 * via the PKCE preflight. Neither success nor error authorizes token exchange.
 * A separate transactional state consumer, session/MFA and Google token-scope
 * verification are required before any real HTTP handling.
 */
final class GoogleSearchConsoleOAuthCallbackPolicy
{
    private const OPTIONAL = ['scope','authuser','prompt'];
    private const ERRORS = [
        'access_denied','server_error','temporarily_unavailable',
        'invalid_scope','interaction_required','login_required',
        'admin_policy_enforced','org_internal',
    ];

    public function __construct(private readonly GoogleSearchConsoleOAuthPreflight $preflight) {}

    /**
     * @return array{decision:string,requiresAtomicStateConsumption:bool,
     *               canExchangeTokens:bool,oauthVerified:bool,
     *               customerDisplayAuthorized:bool,publicationAuthorized:bool}|null
     */
    public function inspect(
        #[\SensitiveParameter] mixed $callbackParameters,
        #[\SensitiveParameter] mixed $storedServerRecord,
        mixed $trustedCurrentContext,
        mixed $now,
    ): ?array {
        if (!is_array($callbackParameters) || array_is_list($callbackParameters)
            || count($callbackParameters)<2 || count($callbackParameters)>5
            || !array_key_exists('state',$callbackParameters)
            || !is_string($callbackParameters['state'])) return null;

        $hasCode=array_key_exists('code',$callbackParameters);
        $hasError=array_key_exists('error',$callbackParameters);
        if ($hasCode===$hasError) return null;

        $accepted=array_merge(['state',$hasCode?'code':'error'],self::OPTIONAL);
        foreach ($callbackParameters as $key=>$value){
            if (!is_string($key) || !in_array($key,$accepted,true)
                || !is_string($value)) return null;
        }
        if (array_key_exists('scope',$callbackParameters)
            && $callbackParameters['scope']!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE)
            return null;
        if (array_key_exists('authuser',$callbackParameters)
            && preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$callbackParameters['authuser'])!==1)
            return null;
        if (array_key_exists('prompt',$callbackParameters)
            && !in_array($callbackParameters['prompt'],['consent','select_account','none'],true))
            return null;
        if (!$this->preflight->matchesStoredState(
            $callbackParameters['state'],$storedServerRecord,$trustedCurrentContext,$now
        )) return null;

        if ($hasCode) {
            $code=$callbackParameters['code'];
            // Do not echo, hash, log, store or return an authorization code.
            // Google's code is opaque; allow only non-control URL-safe bytes.
            if (strlen($code)<8 || strlen($code)>2048
                || preg_match('/\A[A-Za-z0-9._~\/:-]+\z/D',$code)!==1)
                return null;
            $decision='CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION';
        } else {
            if (!in_array($callbackParameters['error'],self::ERRORS,true))return null;
            $decision=$callbackParameters['error']==='access_denied'
                ?'CONSENT_DENIED_HOLD':'PROVIDER_ERROR_HOLD';
        }

        return [
            'decision'=>$decision,
            // A successful code receipt is NOT proof of a used-once state,
            // of the current authenticated Portal session, or of Google scope.
            'requiresAtomicStateConsumption'=>$hasCode,
            'canExchangeTokens'=>false,
            'oauthVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
        ];
    }

    public static function authorizesGoogleHttp(): bool {return false;}
    public static function authorizesTokenExchange(): bool {return false;}
    public static function authorizesCustomerDisplay(): bool {return false;}
}
