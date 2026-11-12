<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * T — bounded retirement of encrypted OAuth token *candidates*.
 * This is local database cleanup, NOT remote Google token revocation.
 */
final class GoogleSearchConsoleTokenCandidateRetention
{
    public function __construct(private readonly PDO $pdo) {}

    public function purge(mixed $trustedNow,mixed $limit=250):?int
    {
        if(!is_int($trustedNow)||$trustedNow<1600000000||
            $trustedNow>2200000000||!is_int($limit)||
            $limit<1||$limit>500)return null;
        try {
            $stmt=$this->pdo->prepare('WITH selected AS (
                SELECT tenant_ref,actor_ref,property_fingerprint
                FROM portal_gsc_oauth_token_candidates
                WHERE expires_at<:expired_before
                   OR (revoked_at IS NOT NULL
                       AND revoked_at<:revoked_before)
                ORDER BY expires_at,property_fingerprint
                FOR UPDATE SKIP LOCKED
                LIMIT :batch
            )
            DELETE FROM portal_gsc_oauth_token_candidates AS target
            USING selected
            WHERE target.tenant_ref=selected.tenant_ref
              AND target.actor_ref=selected.actor_ref
              AND target.property_fingerprint=selected.property_fingerprint');
            if(!$stmt)return null;
            $stmt->bindValue(':expired_before',$trustedNow,PDO::PARAM_INT);
            $stmt->bindValue(':revoked_before',$trustedNow-60,PDO::PARAM_INT);
            $stmt->bindValue(':batch',$limit,PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        }catch(Throwable){return null;}
    }

    public static function authorizesGoogleRevocation():bool{return false;}
    public static function authorizesTokenUse():bool{return false;}
}
