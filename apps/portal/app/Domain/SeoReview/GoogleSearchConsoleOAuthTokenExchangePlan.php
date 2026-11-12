<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

/**
 * Slice L — trusted-backend-only OAuth token POST *plan*, no network.
 * The opaque code/verifier must come from the one-use PostgreSQL callback
 * coordinator, never a browser-provided token request.
 */
final class GoogleSearchConsoleOAuthTokenExchangePlan
{
    public const TOKEN_ENDPOINT='https://oauth2.googleapis.com/token';

    public function prepare(
        #[\SensitiveParameter] mixed $trustedServerConfig,
        GoogleSearchConsoleOAuthCallbackOutcome $consumedCallback
    ): ?GoogleSearchConsoleOAuthTokenPostRequest {
        if (!self::exact($trustedServerConfig,[
                'clientId','clientSecret','registeredRedirectUri'
            ])
            ||!GoogleSearchConsoleOAuthPreflight::validRegistration([
                'clientId'=>$trustedServerConfig['clientId'],
                'registeredRedirectUri'=>$trustedServerConfig['registeredRedirectUri']
            ])
            ||!is_string($trustedServerConfig['clientSecret'])
            ||preg_match('/\A[\x21-\x7e]{8,512}\z/D',
                $trustedServerConfig['clientSecret'])!==1)
            return null;

        // Status fields are self-reported by the outcome and CAN be forged.
        // Require process-local proof minted only after ledger CAS success.
        if(!GoogleSearchConsoleOAuthCallbackCoordinator::issuedByConsumedState(
            $consumedCallback
        ))return null;
        $status=$consumedCallback->safeReview();
        if (($status['decision']??null)!=='CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND'
            ||($status['stateConsumed']??null)!==true
            ||($status['tokenExchangeAuthorized']??null)!==false)return null;

        $secret=$consumedCallback->takeForTrustedBackend();
        if (!is_array($secret)||!self::exact($secret,['code','codeVerifier']))
            return null;
        $code=$secret['code'];$verifier=$secret['codeVerifier'];
        if (!is_string($code)||strlen($code)<8||strlen($code)>2048
            ||preg_match('/\A[A-Za-z0-9._~\/:-]+\z/D',$code)!==1
            ||!is_string($verifier)
            ||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$verifier)!==1)
            return null;

        // Consume the capability only after all input checks. A later
        // attempt to plan the same OAuth code must fail closed.
        if(!GoogleSearchConsoleOAuthCallbackCoordinator::claimTokenPlanOnce(
            $consumedCallback
        ))return null;

        // A trusted HTTPS client must explicitly request these sensitive
        // form fields; the plan does not execute or authorize a request.
        return new GoogleSearchConsoleOAuthTokenPostRequest([
            'client_id'=>$trustedServerConfig['clientId'],
            'client_secret'=>$trustedServerConfig['clientSecret'],
            'code'=>$code,
            'code_verifier'=>$verifier,
            'redirect_uri'=>$trustedServerConfig['registeredRedirectUri'],
            'grant_type'=>'authorization_code'
        ]);
    }

    public static function authorizesHttp(): bool{return false;}
    public static function authorizesTokenStorage(): bool{return false;}
    public static function authorizesCustomerDisplay(): bool{return false;}

    private static function exact(mixed $value,array $keys): bool
    {
        if(!is_array($value)||array_is_list($value)
            ||count($value)!==count($keys))return false;
        $actual=array_keys($value);
        sort($actual);sort($keys);
        return $actual===$keys;
    }
}

/**
 * Intentionally non-serializable request envelope. All secrets are stored
 * inside the PHP engine's SensitiveParameterValue so var_export and debug
 * logs cannot accidentally print Google auth codes or the client secret.
 */
final class GoogleSearchConsoleOAuthTokenPostRequest
{
    private \SensitiveParameterValue $form;
    private bool $transportTaken=false;

    public function __construct(#[\SensitiveParameter] array $form)
    {
        $this->form=new \SensitiveParameterValue($form);
    }

    /** Secrets must never be copied or serialized to logs/jobs/sessions. */
    public function __clone(): void
    {
        throw new \LogicException('GSC token request cannot be cloned');
    }

    public function __serialize(): array
    {
        throw new \LogicException('GSC token request cannot be serialized');
    }

    public function __unserialize(array $data):void
    {
        throw new \LogicException('GSC token request cannot be deserialized');
    }

    public function safeReview(): array
    {
        return [
            'contractVersion'=>'portal-gsc-oauth-token-exchange-plan-offline-v1',
            'endpoint'=>GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT,
            'method'=>'POST',
            'contentType'=>'application/x-www-form-urlencoded',
            'fieldNames'=>[
                'client_id','client_secret','code','code_verifier',
                'redirect_uri','grant_type'
            ],
            'redirectsAllowed'=>false,
            'timeoutSeconds'=>10,
            'networkPerformed'=>false,
            'googleTokenVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'tokenStorageAuthorized'=>false,
            'releaseStatus'=>'HOLD_EXTERNAL_AUTHENTICATED_BACKEND'
        ];
    }

    /** SERVER ONLY: passing this to user responses or logs is prohibited. */
    public function takeForTrustedHttpsTransport(): ?array
    {
        if ($this->transportTaken)return null;
        // Consume the envelope before exposing it to the trusted backend.
        // No secondary transport payload can be produced, including after a
        // failed HTTP request. Google authorization codes are one-use.
        $this->transportTaken=true;
        $fields=$this->form->getValue();
        $this->form=new \SensitiveParameterValue([]);
        return [
            'method'=>'POST',
            'url'=>GoogleSearchConsoleOAuthTokenExchangePlan::TOKEN_ENDPOINT,
            'headers'=>[
                'Content-Type'=>'application/x-www-form-urlencoded',
                'Accept'=>'application/json'
            ],
            'body'=>http_build_query($fields,'','&',PHP_QUERY_RFC3986),
            'allowRedirects'=>false,
            'timeoutSeconds'=>10
        ];
    }

    public function __debugInfo(): array
    {
        return $this->safeReview();
    }
}
