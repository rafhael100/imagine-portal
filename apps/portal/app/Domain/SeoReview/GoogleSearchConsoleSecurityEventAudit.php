<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * Q — minimal metadata-only GSC security events in isolated PostgreSQL.
 * No URLs, Google tokens, queries, IP addresses, user agents or payload
 * fields can be supplied. A trusted Portal backend MUST authenticate
 * the actor and tenant before invoking this class.
 */
final class GoogleSearchConsoleSecurityEventAudit
{
    private const EVENTS=[
        'oauth_pkce_prepared',
        'oauth_callback_denied',
        'oauth_state_consumed',
        'oauth_token_rejected',
        'property_grant_revoked',
        'property_access_suspended',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function record(
        mixed $eventCode,
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $propertyFingerprint,
        mixed $trustedNow
    ): bool {
        if (!is_string($eventCode) ||
            !in_array($eventCode,self::EVENTS,true) ||
            !self::validRef($trustedTenant) ||
            !self::validRef($trustedActor) ||
            !self::validFingerprint($propertyFingerprint) ||
            !self::validTime($trustedNow)) return false;

        try {
            $stmt=$this->pdo->prepare('INSERT INTO portal_gsc_security_events
                (event_id,event_code,tenant_ref,actor_ref,
                 property_fingerprint,event_at)
                VALUES (:event_id,:code,:tenant,:actor,:property,:at)');
            if (!$stmt)return false;
            $stmt->execute([
                'event_id'=>bin2hex(random_bytes(16)),
                'code'=>$eventCode,
                'tenant'=>$trustedTenant,
                'actor'=>$trustedActor,
                'property'=>$propertyFingerprint,
                'at'=>$trustedNow
            ]);
            return $stmt->rowCount()===1;
        }catch(Throwable){
            return false;
        }
    }

    /**
     * Bounded cleanup of historical audit metadata, not an OAuth token
     * revocation. Retention choice (7-365 days) requires LGPD sign-off.
     */
    public function purge(
        mixed $trustedNow,
        mixed $retentionDays,
        mixed $limit=250
    ): ?int {
        if (!self::validTime($trustedNow) ||
            !is_int($retentionDays) || $retentionDays<7 ||
            $retentionDays>365 ||
            !is_int($limit)||$limit<1||$limit>500)return null;
        $cutoff=$trustedNow-$retentionDays*86400;
        try {
            $stmt=$this->pdo->prepare('WITH chosen AS (
                SELECT event_id FROM portal_gsc_security_events
                WHERE event_at<:cutoff
                ORDER BY event_at,event_id
                FOR UPDATE SKIP LOCKED
                LIMIT :batch
            )
            DELETE FROM portal_gsc_security_events AS target
            USING chosen
            WHERE target.event_id=chosen.event_id');
            if(!$stmt)return null;
            $stmt->bindValue(':cutoff',$cutoff,PDO::PARAM_INT);
            $stmt->bindValue(':batch',$limit,PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        }catch(Throwable){
            return null;
        }
    }

    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesTokenPersistence():bool{return false;}
    public static function authorizesGrantProvisioning():bool{return false;}

    private static function validTime(mixed $value):bool
    {
        return is_int($value) &&
            $value>=1600000000 && $value<=2200000000;
    }
    private static function validRef(mixed $value):bool
    {
        return is_string($value) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$value)===1;
    }
    private static function validFingerprint(mixed $value):bool
    {
        return $value===null || (is_string($value) &&
            preg_match('/\A[0-9a-f]{64}\z/D',$value)===1);
    }
}
