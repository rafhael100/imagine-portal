<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * SEO F112: CI-only session-bound Google PKCE state consumption.
 *
 * The single tenant-scoped PostgreSQL transaction checks the synthetic
 * session, synthetic MFA timestamp, current membership, resource owner and
 * recorded GSC grant, then consumes the pending PKCE callback state.
 *
 * No real Portal issuer, real MFA, live Google OAuth, token exchange,
 * network call, customer permission or public HTTP route is created.
 */
final readonly class PortalSeoPgOAuthSessionBoundLab
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;
    private SensitiveParameterValue $sessionKey;
    private SensitiveParameterValue $oauthStateKey;
    private const REF='/\A[a-z0-9][a-z0-9_-]{2,63}\z/D';
    private const HANDLE='/\A[A-Za-z0-9_-]{43}\z/D';

    public function __construct(
        PDO $db,
        #[SensitiveParameter] string $serverSessionKey,
        #[SensitiveParameter] string $oauthStateEncryptionKey,
    ) {
        if (strlen($serverSessionKey)!==32 ||
            strlen($oauthStateEncryptionKey)!==32)
            throw new \InvalidArgumentException('F112: invalid server key');
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($db);
        $this->sessionKey=new SensitiveParameterValue($serverSessionKey);
        $this->oauthStateKey=new SensitiveParameterValue($oauthStateEncryptionKey);
    }

    public function reviewAndConsume(
        mixed $selectedTenant,
        #[SensitiveParameter] mixed $opaquePortalSession,
        mixed $resourceHandle,
        mixed $exactSiteUrl,
        #[SensitiveParameter] mixed $callback,
        mixed $trustedNow
    ):?array {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F110_PG_CI')!=='1' ||
            getenv('PORTAL_SEO_F111_PG_CI')!=='1' ||
            getenv('PORTAL_SEO_F112_PG_CI')!=='1' ||
            !is_string($selectedTenant) ||
            preg_match(self::REF,$selectedTenant)!==1 ||
            !is_string($opaquePortalSession) ||
            preg_match(self::HANDLE,$opaquePortalSession)!==1 ||
            !is_string($resourceHandle) ||
            preg_match(self::HANDLE,$resourceHandle)!==1 ||
            !is_array($callback) || array_is_list($callback) ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000)
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactSiteUrl
        );
        if ($fingerprint===null)return null;

        $result=$this->scope->run(
            ['tenantRef'=>$selectedTenant,'actorRef'=>'preauth-lookup'],
            function(PDO $db)use(
                $selectedTenant,$opaquePortalSession,$resourceHandle,
                $exactSiteUrl,$fingerprint,$callback,$trustedNow
            ):?array {
                // Locks the synthetic Portal session, membership and resource
                // rows against concurrent revocation until callback finishes.
                $identity=(new PortalSeoPgIdentityReviewLab(
                    $db,$this->sessionKey->getValue()
                ))->inspectInsideActiveTenantTransaction(
                    $db,$selectedTenant,$opaquePortalSession,
                    $resourceHandle,$exactSiteUrl,$trustedNow
                );
                if (!is_array($identity) ||
                    ($identity['realSessionIssuerVerified']??null)!==false ||
                    ($identity['realMfaVerified']??null)!==false ||
                    ($identity['customerDisplayAuthorized']??null)!==false)
                    return null;

                // A server-side actor is recovered ONLY from the locked
                // session row. No client-provided actor or email is trusted.
                $digest=hash_hmac('sha256',
                    'portal-session-review-v1|'.$selectedTenant.'|'.
                    $opaquePortalSession,$this->sessionKey->getValue());
                $actorQuery=$db->prepare('SELECT actor_ref FROM
                    portal_seo_review_sessions
                    WHERE tenant_ref=:tenant AND session_digest=:digest
                    FOR SHARE');
                if (!$actorQuery || !$actorQuery->execute([
                    'tenant'=>$selectedTenant,'digest'=>$digest
                ]))return null;
                $actor=$actorQuery->fetchColumn();
                if (!is_string($actor) ||
                    preg_match(self::REF,$actor)!==1)return null;

                // Lock the specific GSC grant, recheck immutable property
                // fingerprint and freshness before consuming the callback.
                // A recorded grant still does NOT verify live OAuth.
                $grant=$db->prepare('SELECT verified_at,expires_at
                    FROM portal_gsc_property_grants
                    WHERE tenant_ref=:tenant AND actor_ref=:actor
                    AND property_fingerprint=:property
                    AND scope=:scope AND grant_status=:active
                    AND revoked_at IS NULL FOR SHARE');
                if (!$grant || !$grant->execute([
                    'tenant'=>$selectedTenant,'actor'=>$actor,
                    'property'=>$fingerprint,
                    'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
                    'active'=>'active'
                ]))return null;
                $row=$grant->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) ||
                    !ctype_digit((string)$row['verified_at']) ||
                    !ctype_digit((string)$row['expires_at']) ||
                    (int)$row['verified_at']>$trustedNow ||
                    (int)$row['verified_at']<$trustedNow-900 ||
                    (int)$row['expires_at']<$trustedNow)return null;

                $context=['tenantRef'=>$selectedTenant,'actorRef'=>$actor];
                $coordinator=new GoogleSearchConsoleOAuthCallbackCoordinator(
                    new GoogleSearchConsoleOAuthStateLedger(
                        $db,$this->oauthStateKey->getValue()
                    ),
                    new GoogleSearchConsoleOAuthCallbackPolicy(
                        new GoogleSearchConsoleOAuthPreflight()
                    )
                );
                $outcome=$coordinator->review($callback,$context,$trustedNow);
                if ($outcome===null)return null;
                $safe=$outcome->safeReview();
                if (($safe['stateConsumed']??null)!==true ||
                    ($safe['oauthVerified']??null)!==false ||
                    ($safe['customerDisplayAuthorized']??null)!==false ||
                    ($safe['networkPerformed']??null)!==false)
                    throw new \RuntimeException('F112: unauthorized outcome');

                return [
                    'contractVersion'=>
                        'portal-seo-f112-session-bound-pkce-review-lab-v1',
                    'methodology'=>'VISITAS_LEADS_CLIENTES',
                    'stateConsumed'=>$safe['stateConsumed'],
                    'decision'=>$safe['decision'],
                    'sessionMembershipResourceCheckedInSameTransaction'=>true,
                    'recordedGscGrantCheckedInSameTransaction'=>true,
                    'realPortalIssuerVerified'=>false,
                    'realMfaVerified'=>false,
                    'liveGooglePermissionVerified'=>false,
                    'oauthVerified'=>false,
                    'tokenExchangeAuthorized'=>false,
                    'customerDisplayAuthorized'=>false,
                    'productionReadAuthorized'=>false,
                    'networkPerformed'=>false,
                    'releaseStatus'=>'HOLD_TRUSTED_PORTAL_ISSUER_AND_GOOGLE'
                ];
            }
        );
        return is_array($result)?$result:null;
    }

    public static function authorizesHttp():bool{return false;}
    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesTokenExchange():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
