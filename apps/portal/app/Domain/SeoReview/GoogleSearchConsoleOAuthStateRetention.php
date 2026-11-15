<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * GSC Slice K: bounded, privacy-preserving PostgreSQL state retirement.
 * Must run from a privileged internal scheduler with a dedicated DB role.
 * This is NOT an OAuth token revocation service or a public route.
 */
final class GoogleSearchConsoleOAuthStateRetention
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Delete at most $limit expired or safely consumed temporary states.
     * The 60-second consumed grace allows a backend callback to finish
     * immediately after the atomic state claim; it is not a token TTL.
     * @return int|null deleted count, or null on invalid/untrusted request
     */
    public function purge(mixed $trustedNow,mixed $limit=250): ?int
    {
        if(!is_int($trustedNow)||$trustedNow<1600000000||$trustedNow>2200000600||
           !is_int($limit)||$limit<1||$limit>500)return null;
        try{
            $sql='WITH candidates AS (
                SELECT state_hash FROM portal_gsc_oauth_states
                WHERE expires_at<:expired_before
                   OR (consumed_at IS NOT NULL AND consumed_at<:consumed_before)
                ORDER BY expires_at, state_hash
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
            )
            DELETE FROM portal_gsc_oauth_states AS target
            USING candidates
            WHERE target.state_hash=candidates.state_hash';
            $statement=$this->pdo->prepare($sql);
            if(!$statement)return null;
            $statement->bindValue(':expired_before',$trustedNow,PDO::PARAM_INT);
            $statement->bindValue(':consumed_before',$trustedNow-60,PDO::PARAM_INT);
            $statement->bindValue(':limit',$limit,PDO::PARAM_INT);
            $statement->execute();
            return $statement->rowCount();
        }catch(Throwable){
            return null;
        }
    }

    public static function authorizesTokenRevocation(): bool{return false;}
    public static function authorizesCustomerDisplay(): bool{return false;}
}
