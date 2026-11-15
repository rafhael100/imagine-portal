<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * SEO-F110: disposable-CI-only OAuth PKCE state storage/consumption bound
 * to the existing PostgreSQL RLS transaction.
 *
 * This deliberately DOES NOT resolve or authenticate a Portal session,
 * verify MFA/tenant membership, verify a Google account, exchange tokens,
 * store tokens, issue grants, or expose HTTP routes. A context or an
 * OAuth state supplied by the browser is never proof of permission.
 */
final readonly class GoogleSearchConsoleOAuthRlsLabBoundary
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;

    public function __construct(
        private PDO $pdo,
        #[\SensitiveParameter] private string $encryptionKey,
    ) {
        $this->scope = new GoogleSearchConsoleTenantScopedTransaction($pdo);
    }

    private static function enabled(): bool
    {
        return PHP_SAPI === 'cli'
            && getenv('APP_ENV') === 'testing'
            && getenv('CI') === 'true'
            && getenv('PORTAL_SEO_F110_PG_CI') === '1';
    }

    private static function validContext(mixed $ctx): bool
    {
        if (!is_array($ctx) || array_is_list($ctx)
            || count($ctx) !== 2
            || !array_key_exists('tenantRef', $ctx)
            || !array_key_exists('actorRef', $ctx)) return false;

        foreach (['tenantRef','actorRef'] as $key) {
            if (!is_string($ctx[$key]) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D', $ctx[$key]) !== 1)
                return false;
        }
        return true;
    }

    /**
     * Only accept a server-only record from a previous PKCE preflight.
     * This method does not start OAuth in a browser and does not accept
     * an OAuth callback as authority to create records.
     */
    public function storePending(mixed $serverContext, mixed $serverOnly, mixed $now): bool
    {
        if (!self::enabled() || !self::validContext($serverContext)
            || !is_array($serverOnly)
            || ($serverOnly['tenantRef'] ?? null) !== $serverContext['tenantRef']
            || ($serverOnly['actorRef'] ?? null) !== $serverContext['actorRef']
            || !is_int($now)) return false;

        $result=$this->scope->run(
            $serverContext,
            function (PDO $db) use ($serverOnly,$now): bool {
                $ledger=new GoogleSearchConsoleOAuthStateLedger($db,$this->encryptionKey);
                return $ledger->store($serverOnly,$now);
            }
        );
        return $result===true;
    }

    /**
     * Consume one pending state via the existing callback policy and atomic
     * PostgreSQL UPDATE. Return only the sanitized review fields. Code,
     * state and PKCE verifier stay on the server and are not returned.
     */
    public function reviewAndConsume(
        mixed $serverContext,
        #[\SensitiveParameter] mixed $callback,
        mixed $now
    ): ?array {
        if (!self::enabled() || !self::validContext($serverContext)
            || !is_array($callback) || array_is_list($callback)
            || !is_int($now)) return null;

        $result=$this->scope->run(
            $serverContext,
            function (PDO $db) use ($callback,$serverContext,$now): ?array {
                $ledger=new GoogleSearchConsoleOAuthStateLedger($db,$this->encryptionKey);
                $coordinator=new GoogleSearchConsoleOAuthCallbackCoordinator(
                    $ledger,
                    new GoogleSearchConsoleOAuthCallbackPolicy(
                        new GoogleSearchConsoleOAuthPreflight()
                    )
                );
                $outcome=$coordinator->review($callback,$serverContext,$now);
                return $outcome?->safeReview();
            }
        );
        if (!is_array($result)
            || ($result['contractVersion']??null)
                !=='portal-gsc-oauth-one-time-callback-review-v1'
            || ($result['stateConsumed']??null)!==true
            || ($result['oauthVerified']??null)!==false
            || ($result['tenantGrantVerified']??null)!==false
            || ($result['propertyAuthorizationVerified']??null)!==false
            || ($result['tokenExchangeAuthorized']??null)!==false
            || ($result['customerDisplayAuthorized']??null)!==false
            || ($result['publicationAuthorized']??null)!==false
            || ($result['networkPerformed']??null)!==false
            || ($result['releaseStatus']??null)!=='HOLD_EXTERNAL_AUTHENTICATED_BACKEND')
            return null;

        return $result;
    }

    public static function authorizesGoogleHttp(): bool { return false; }
    public static function authorizesTokenExchange(): bool { return false; }
    public static function authorizesCustomerDisplay(): bool { return false; }
    public static function authenticatesPortalSession(): bool { return false; }
}
