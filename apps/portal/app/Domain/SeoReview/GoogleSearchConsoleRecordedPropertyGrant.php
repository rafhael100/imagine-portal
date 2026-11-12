<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * Slice O — read-only inspection of a previously recorded tenant/actor/GSC
 * property grant. Rows can ONLY be provisioned by a future independently
 * authenticated Google + Portal backend, not by this class or a client.
 *
 * A matching row is NOT proof of a live Google grant or permission to display.
 */
final class GoogleSearchConsoleRecordedPropertyGrant
{
    public const READONLY_SCOPE = GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE;
    private const MAX_AGE_SECONDS=900;

    public function __construct(private readonly PDO $pdo) {}

    public function inspect(
        mixed $trustedTenantRef,
        mixed $trustedActorRef,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ): ?array {
        if (!self::validRef($trustedTenantRef)||
            !self::validRef($trustedActorRef)||
            !is_int($trustedNow)||$trustedNow<1600000000||
            $trustedNow>2200000000)return null;
        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if ($fingerprint===null)return null;
        try{
            $statement=$this->pdo->prepare(
                'SELECT verified_at,expires_at FROM portal_gsc_property_grants
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:fingerprint AND scope=:scope
                AND grant_status=:active AND revoked_at IS NULL
                AND verified_at<=:now_verified
                AND verified_at>=:fresh_after
                AND expires_at>=:now_expiry LIMIT 1'
            );
            if(!$statement)return null;
            $statement->execute([
                'tenant'=>$trustedTenantRef,'actor'=>$trustedActorRef,
                'fingerprint'=>$fingerprint,'scope'=>self::READONLY_SCOPE,
                'active'=>'active','now_verified'=>$trustedNow,
                'fresh_after'=>$trustedNow-self::MAX_AGE_SECONDS,
                'now_expiry'=>$trustedNow
            ]);
            $row=$statement->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))return null;
            return [
                'contractVersion'=>'portal-gsc-property-grant-record-review-v1',
                'tenantRef'=>$trustedTenantRef,
                'actorRef'=>$trustedActorRef,
                'propertyFingerprint'=>$fingerprint,
                'recordedGrantFresh'=>true,
                'recordedScope'=>self::READONLY_SCOPE,
                'verifiedAt'=>(int)$row['verified_at'],
                'expiresAt'=>(int)$row['expires_at'],
                'googleApiCheckedNow'=>false,
                'sessionMfaVerified'=>false,
                'tenantMembershipVerified'=>false,
                'propertyAuthorizationVerified'=>false,
                'customerDisplayAuthorized'=>false,
                'publicationAuthorized'=>false,
                'networkPerformed'=>false,
                'releaseStatus'=>'HOLD_TRUSTED_AUTH_AND_GOOGLE_PROOF'
            ];
        }catch(Throwable){
            return null;
        }
    }

    private static function validRef(mixed $value):bool
    {
        return is_string($value)
            &&preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$value)===1;
    }
    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesGoogleRequest():bool{return false;}
    public static function authorizesGrantProvisioning():bool{return false;}
}
