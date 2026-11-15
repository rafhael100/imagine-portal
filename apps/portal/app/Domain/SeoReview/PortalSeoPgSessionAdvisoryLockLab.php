<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * F113: test-only transaction-scoped advisory lock for a tenant-specific
 * session digest. BOTH the callback and approved revoker must take the same
 * lock BEFORE reading/changing session status.
 *
 * Advisory locks are cooperative: raw SQL bypassing this protocol is not
 * protected. No real issuer, MFA, login, or production authorization.
 */
final class PortalSeoPgSessionAdvisoryLockLab
{
    public static function acquire(PDO $db,mixed $tenant,mixed $sessionDigest):bool
    {
        if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F113_PG_CI')!=='1' ||
            !$db->inTransaction() ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            !is_string($tenant) ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$tenant)!==1 ||
            !is_string($sessionDigest) ||
            preg_match('/\A[0-9a-f]{64}\z/D',$sessionDigest)!==1)
            return false;

        try {
            $scope=$db->query("SELECT current_setting('app.tenant_ref',true)")
                ->fetchColumn();
            if ($scope!==$tenant)return false;

            // Namespaced signed int4 pairs: pg_advisory_xact_lock(int4,int4).
            // Hash collisions may serialize unrelated sessions but cannot
            // confer authority or return protected row data.
            $digest=hash('sha256',
                'portal-seo-session-lock-review-v1|'.$tenant.'|'.
                $sessionDigest,true);
            $unsigned=unpack('Nfirst/Nsecond',substr($digest,0,8));
            if (!is_array($unsigned))return false;
            $a=$unsigned['first']>2147483647
                ?$unsigned['first']-4294967296:$unsigned['first'];
            $b=$unsigned['second']>2147483647
                ?$unsigned['second']-4294967296:$unsigned['second'];
            $stmt=$db->prepare('SELECT pg_advisory_xact_lock(
                CAST(:first AS integer),CAST(:second AS integer))');
            if (!$stmt)return false;
            return $stmt->execute(['first'=>$a,'second'=>$b]);
        }catch(Throwable){
            return false;
        }
    }
    public static function authorizesProduction():bool{return false;}
}
