<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

/**
 * Slice J — server-side callback flow that binds the PKCE state to a trusted
 * tenant/actor context, then consumes it ATOMICALLY in PostgreSQL.
 *
 * No HTTP routes, MFA claims, Google calls, OAuth exchange, or token storage.
 * Never expose the object containing the code/verifier to a user interface.
 */
final class GoogleSearchConsoleOAuthCallbackCoordinator
{
    public function __construct(
        private readonly GoogleSearchConsoleOAuthStateLedger $ledger,
        private readonly GoogleSearchConsoleOAuthCallbackPolicy $policy
    ) {}

    public function review(
        #[\SensitiveParameter] mixed $callback,
        mixed $trustedContext,
        mixed $trustedNow
    ): ?GoogleSearchConsoleOAuthCallbackOutcome {
        if (!is_array($callback) || array_is_list($callback) ||
            !isset($callback['state']) || !is_string($callback['state'])) {
            return null;
        }
        // The pending record comes ONLY from the trusted DB, not from the
        // browser. It is never passed outside this server-side call chain.
        $pending=$this->ledger->inspectPending(
            $callback['state'],$trustedContext,$trustedNow
        );
        if ($pending===null)return null;
        $decision=$this->policy->inspect(
            $callback,$pending,$trustedContext,$trustedNow
        );
        if ($decision===null || $decision['requiresAtomicStateConsumption']!==true
            || $decision['canExchangeTokens']!==false) return null;

        // CAS update is the exclusive one-use gate: only one concurrent caller
        // can pass. A failed decryption leaves the state consumed (fail-closed).
        $consumed=$this->ledger->consume(
            $callback['state'],$trustedContext,$trustedNow
        );
        if ($consumed===null)return null;
        $verifier=$consumed->revealForTrustedBackend();
        if (!hash_equals($pending['codeVerifier'],$verifier))return null;

        $isCode=$decision['decision']==='CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION';
        if ($isCode) {
            return new GoogleSearchConsoleOAuthCallbackOutcome(
                'CODE_CONSUMED_AWAITING_TRUSTED_TOKEN_BACKEND',
                $callback['code'],$verifier
            );
        }
        return new GoogleSearchConsoleOAuthCallbackOutcome(
            $decision['decision'],null,null
        );
    }

    public static function authorizesGoogleHttp(): bool{return false;}
    public static function authorizesCustomerDisplay(): bool{return false;}
}

/**
 * Code and verifier are guarded as SensitiveParameterValue objects.
 * json_encode(), print_r(), debug dumps and var_export() do not include them.
 * A future authenticated backend MUST exchange or discard them immediately,
 * not persist the result or expose the secrets to the browser.
 */
final class GoogleSearchConsoleOAuthCallbackOutcome
{
    private readonly ?\SensitiveParameterValue $code;
    private readonly ?\SensitiveParameterValue $verifier;

    public function __construct(
        private readonly string $decision,
        #[\SensitiveParameter] ?string $code,
        #[\SensitiveParameter] ?string $verifier
    ){
        $this->code=$code===null?null:new \SensitiveParameterValue($code);
        $this->verifier=$verifier===null?null:new \SensitiveParameterValue($verifier);
    }

    public function safeReview(): array
    {
        return [
            'contractVersion'=>'portal-gsc-oauth-one-time-callback-review-v1',
            'decision'=>$this->decision,
            'stateConsumed'=>true,
            'oauthVerified'=>false,
            'tenantGrantVerified'=>false,
            'propertyAuthorizationVerified'=>false,
            'tokenExchangeAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_EXTERNAL_AUTHENTICATED_BACKEND'
        ];
    }

    /** SERVER ONLY. Never include this object or its secrets in JSON/logs. */
    public function takeForTrustedBackend(): ?array
    {
        return $this->code===null || $this->verifier===null ? null : [
            'code'=>$this->code->getValue(),
            'codeVerifier'=>$this->verifier->getValue()
        ];
    }

    public function __debugInfo(): array{return $this->safeReview();}
}
