<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F128: cooperative transaction-scoped PostgreSQL advisory lock used by
 * both signer verification/nonce acceptance and signer revocation.
 *
 * This lock is a SERIALIZATION PROTOCOL, not an authorization mechanism:
 * privileged SQL executed without the same lock can still cause a race.
 * Never permit such out-of-band key mutation in any future real service.
 */
final class PortalSeoPgSigningKeyLock
{
    public static function acquire(PDO $pdo,string $tenant,string $signer):bool
    {
        if(PHP_SAPI!=='cli' ||
           getenv('CI')!=='true' ||
           getenv('APP_ENV')!=='testing' ||
           !$pdo->inTransaction() ||
           preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',$tenant)!==1 ||
           preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',$signer)!==1)
            return false;

        // Two stable int4 advisory keys: namespace includes the tenant to
        // avoid cross-tenant contention; signer segregates distinct issuers.
        // Collisions cause contention, never an authorization bypass.
        $stmt=$pdo->prepare(
            'SELECT pg_advisory_xact_lock(hashtext(:namespace),hashtext(:signer))'
        );
        if(!$stmt)return false;
        if(!$stmt->execute([
            'namespace'=>'imagine.seo.signing.v1:'.$tenant,
            'signer'=>$signer,
        ]))return false;
        return $stmt->fetch(PDO::FETCH_NUM)!==false;
    }
}
