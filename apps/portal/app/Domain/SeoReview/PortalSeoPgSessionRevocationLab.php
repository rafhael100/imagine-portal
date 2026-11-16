<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * F113: CI-only revocation writer using a narrowly scoped SQL role.
 * Real backend MUST verify issuer, MFA and revocation authority first.
 * A possession of a session handle is not authorization to revoke users.
 *
 * No HTTP, public route, Google API or production session provisioning.
 */
final readonly class PortalSeoPgSessionRevocationLab
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;
    private SensitiveParameterValue $hmacKey;

    public function __construct(PDO $pdo,
        #[SensitiveParameter] string $serverSessionHmacKey
    ){
        if(strlen($serverSessionHmacKey)!==32)
            throw new \InvalidArgumentException('F113 invalid server key');
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($pdo);
        $this->hmacKey=new SensitiveParameterValue($serverSessionHmacKey);
    }

    public function revoke(
        mixed $tenant,
        #[SensitiveParameter] mixed $opaqueSession,
        mixed $trustedNow
    ):bool {
        if(PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F113_PG_CI')!=='1' ||
            !is_string($tenant) ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$tenant)!==1 ||
            !is_string($opaqueSession) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$opaqueSession)!==1 ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000)
            return false;

        $digest=hash_hmac('sha256',
            'portal-session-review-v1|'.$tenant.'|'.$opaqueSession,
            $this->hmacKey->getValue());
        $success=$this->scope->run(
            ['tenantRef'=>$tenant,'actorRef'=>'preauth-lookup'],
            function(PDO $db)use($tenant,$digest,$trustedNow):bool{
                if(!PortalSeoPgSessionAdvisoryLockLab::acquire(
                    $db,$tenant,$digest))return false;

                $stmt=$db->prepare(
                    'UPDATE portal_seo_review_sessions
                     SET revoked_at=:now
                     WHERE tenant_ref=:tenant AND session_digest=:digest
                     AND revoked_at IS NULL
                     AND issued_at<=:issued AND expires_at>:expiry
                     RETURNING session_digest'
                );
                if(!$stmt || !$stmt->execute([
                    'now'=>$trustedNow,'tenant'=>$tenant,'digest'=>$digest,
                    'issued'=>$trustedNow,'expiry'=>$trustedNow
                ]))return false;
                return $stmt->fetchColumn()===$digest;
            }
        );
        return $success===true;
    }

    public static function authorizesRevokingRealSessions():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
