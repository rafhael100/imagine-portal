<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * P — fail-closed, PostgreSQL-only lifecycle for previously verified
 * Google Search Console property grants. Does NOT provision permissions.
 *
 * Callers MUST obtain tenant/actor from an independently authenticated
 * backend; browser strings never establish ownership or authorization.
 */
final class GoogleSearchConsolePropertyGrantRevocation
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Atomically revoke one exact property record.
     * Only the first valid revocation succeeds. Never return site URLs.
     */
    public function revokeExact(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $googleSiteUrl,
        mixed $trustedNow
    ): bool {
        if (!self::validRef($trustedTenant) ||
            !self::validRef($trustedActor) ||
            !self::validNow($trustedNow)) return false;

        $fingerprint = GoogleSearchConsolePropertyIdentifier::fingerprint($googleSiteUrl);
        if ($fingerprint===null) return false;
        try {
            $stmt=$this->pdo->prepare('UPDATE portal_gsc_property_grants
                SET grant_status=:revoked, revoked_at=:revoked_time
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:property
                AND scope=:readonly AND grant_status=:active
                AND revoked_at IS NULL AND verified_at<=:verified_before
                RETURNING property_fingerprint');
            if (!$stmt) return false;
            $stmt->execute([
                'revoked'=>'revoked',
                'revoked_time'=>$trustedNow,
                'tenant'=>$trustedTenant,
                'actor'=>$trustedActor,
                'property'=>$fingerprint,
                'readonly'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
                'active'=>'active',
                'verified_before'=>$trustedNow
            ]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && ($row['property_fingerprint']??null)===$fingerprint;
        } catch(Throwable) {
            return false;
        }
    }

    /**
     * Bounded tenant/actor revocation for membership or service cancellation.
     * Call repeatedly until 0, using a privileged internal maintenance job.
     * Never accepts client-provided grants, client trust or wildcards.
     */
    public function revokeActorBatch(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $trustedNow,
        mixed $limit=100
    ): ?int {
        if (!self::validRef($trustedTenant) ||
            !self::validRef($trustedActor) ||
            !self::validNow($trustedNow) ||
            !is_int($limit) || $limit<1 || $limit>250) return null;
        try {
            $stmt=$this->pdo->prepare('WITH selected AS (
                SELECT tenant_ref,actor_ref,property_fingerprint
                FROM portal_gsc_property_grants
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND scope=:readonly AND grant_status=:active
                AND revoked_at IS NULL AND verified_at<=:now_verified
                ORDER BY property_fingerprint
                FOR UPDATE SKIP LOCKED
                LIMIT :batch_size
            )
            UPDATE portal_gsc_property_grants AS g
            SET grant_status=:revoked, revoked_at=:revoked_time
            FROM selected AS s
            WHERE g.tenant_ref=s.tenant_ref
              AND g.actor_ref=s.actor_ref
              AND g.property_fingerprint=s.property_fingerprint');
            if (!$stmt) return null;
            $stmt->bindValue(':tenant',$trustedTenant,PDO::PARAM_STR);
            $stmt->bindValue(':actor',$trustedActor,PDO::PARAM_STR);
            $stmt->bindValue(':readonly',
                GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,PDO::PARAM_STR);
            $stmt->bindValue(':active','active',PDO::PARAM_STR);
            $stmt->bindValue(':now_verified',$trustedNow,PDO::PARAM_INT);
            $stmt->bindValue(':batch_size',$limit,PDO::PARAM_INT);
            $stmt->bindValue(':revoked','revoked',PDO::PARAM_STR);
            $stmt->bindValue(':revoked_time',$trustedNow,PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch(Throwable) {
            return null;
        }
    }

    public static function authorizesGoogleHttp(): bool{return false;}
    public static function authorizesCustomerDisplay(): bool{return false;}
    public static function authorizesGrantProvisioning(): bool{return false;}

    private static function validNow(mixed $now):bool
    {
        return is_int($now) && $now>=1600000000 && $now<=2200000000;
    }
    private static function validRef(mixed $value):bool
    {
        return is_string($value) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$value)===1;
    }
}
