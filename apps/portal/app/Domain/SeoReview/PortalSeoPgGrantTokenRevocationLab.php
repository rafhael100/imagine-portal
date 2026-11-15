<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * SEO-F114: review-only *atomic* recorded-property + candidate-token
 * invalidation + audit, all within the same scoped RLS transaction.
 *
 * This does not revoke tokens at Google, authenticate Portal callers,
 * validate live Google authorization or expose customer data.
 * ONLY disposable PostgreSQL CI; never register as an HTTP service.
 */
final readonly class PortalSeoPgGrantTokenRevocationLab
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;

    public function __construct(PDO $pdo)
    {
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($pdo);
    }

    public function revoke(
        mixed $trustedContext,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ): bool {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F114_PG_CI')!=='1' ||
            !self::validContext($trustedContext) ||
            !is_int($trustedNow) || $trustedNow<1600000000 ||
            $trustedNow>2200000000)
            return false;
        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if ($fingerprint===null)return false;

        $done=$this->scope->run(
            $trustedContext,
            function(PDO $db)use(
                $trustedContext,$exactGoogleSiteUrl,$fingerprint,$trustedNow
            ):bool {
                $tenant=$trustedContext['tenantRef'];
                $actor=$trustedContext['actorRef'];

                if (!(new GoogleSearchConsolePropertyGrantRevocation($db))
                    ->revokeExact($tenant,$actor,$exactGoogleSiteUrl,$trustedNow))
                    throw new \RuntimeException('F114: grant review refused');

                // The same DB transaction must invalidate any unrevoked
                // candidate. A missing candidate is allowed. Permission
                // failure or inconsistent DB state triggers rollback of the
                // just-revoked grant.
                $update=$db->prepare(
                    'UPDATE portal_gsc_oauth_token_candidates
                     SET revoked_at=:now
                     WHERE tenant_ref=:tenant AND actor_ref=:actor
                       AND property_fingerprint=:property
                       AND revoked_at IS NULL
                     RETURNING property_fingerprint'
                );
                if (!$update || !$update->execute([
                    'now'=>$trustedNow,
                    'tenant'=>$tenant,
                    'actor'=>$actor,
                    'property'=>$fingerprint
                ]))throw new \RuntimeException('F114: candidate update failed');
                $updated=$update->fetchAll(PDO::FETCH_COLUMN);
                if (!is_array($updated) || count($updated)>1 ||
                    ($updated!==[] && $updated!==[$fingerprint]))
                    throw new \RuntimeException('F114: candidate mismatch');

                // Audit is required even without an existing token
                // candidate. Failure must undo both grant and candidate
                // changes instead of silently leaving partial revocation.
                if (!(new GoogleSearchConsoleSecurityEventAudit($db))
                    ->record('property_grant_revoked',
                        $tenant,$actor,$fingerprint,$trustedNow))
                    throw new \RuntimeException('F114: audit failed');

                return true;
            }
        );
        return $done===true;
    }

    private static function validContext(mixed $context):bool
    {
        if (!is_array($context) || array_is_list($context) ||
            count($context)!==2 ||
            !isset($context['tenantRef'],$context['actorRef']))
            return false;
        foreach (['tenantRef','actorRef'] as $key) {
            if (!is_string($context[$key]) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$context[$key])!==1)
                return false;
        }
        return true;
    }

    public static function authorizesGoogleRevocation():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesOAuthTokenUse():bool{return false;}
}
