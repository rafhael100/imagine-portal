<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * SEO F111: PostgreSQL-backed **review** of a tenant-specific Portal session,
 * membership, resource-to-property and recent recorded GSC grant.
 *
 * This does NOT mint Portal sessions, authenticate passwords, verify a real
 * second factor, validate Google OAuth or authorize displaying customer data.
 * DB entries are CI-only synthetic records. Do not bind to HTTP routes.
 */
final readonly class PortalSeoPgIdentityReviewLab
{
    private const REF='/\A[a-z0-9][a-z0-9_-]{2,63}\z/D';
    private const HANDLE='/\A[A-Za-z0-9_-]{43}\z/D';
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;
    private SensitiveParameterValue $pepper;

    public function __construct(
        PDO $pdo,
        #[SensitiveParameter] string $serverSessionHmacKey,
    ) {
        if (strlen($serverSessionHmacKey)!==32)
            throw new \InvalidArgumentException('F111: invalid server key');
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($pdo);
        $this->pepper=new SensitiveParameterValue($serverSessionHmacKey);
    }

    private static function enabled():bool
    {
        return PHP_SAPI==='cli' &&
            getenv('APP_ENV')==='testing' &&
            getenv('CI')==='true' &&
            getenv('PORTAL_SEO_F111_PG_CI')==='1';
    }

    /**
     * A tenant selection is ONLY a query intention until membership and
     * session are proven in DB. The actor is never accepted from a caller.
     */
    public function inspect(
        mixed $tenantRef,
        #[SensitiveParameter] mixed $sessionHandle,
        mixed $resourceHandle,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ): ?array {
        if (!self::enabled() ||
            !is_string($tenantRef) ||
            preg_match(self::REF,$tenantRef)!==1 ||
            !is_string($sessionHandle) ||
            preg_match(self::HANDLE,$sessionHandle)!==1 ||
            !is_string($resourceHandle) ||
            preg_match(self::HANDLE,$resourceHandle)!==1 ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000)
            return null;

        $propertyFingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if ($propertyFingerprint===null)return null;

        $result=$this->scope->run(
            ['tenantRef'=>$tenantRef,'actorRef'=>'preauth-lookup'],
            fn(PDO $db):?array => $this->inspectInsideActiveTenantTransaction(
                $db,$tenantRef,$sessionHandle,$resourceHandle,
                $exactGoogleSiteUrl,$trustedNow
            )
        );
        return is_array($result)?$result:null;
    }

    /**
     * F112: use the same PG transaction for current session, membership and
     * resource checks AND OAuth callback state consumption. This method is
     * ONLY for the existing test-only PostgreSQL transaction; any unscoped,
     * non-RLS, bypass-role, non-test or invalid caller returns NULL.
     * Does not authorize HTTP or prove MFA/identity for production.
     */
    public function inspectInsideActiveTenantTransaction(
        PDO $db,
        mixed $tenantRef,
        #[SensitiveParameter] mixed $sessionHandle,
        mixed $resourceHandle,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ):?array {
        if (!self::enabled() || !$db->inTransaction() ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            !is_string($tenantRef) ||
            preg_match(self::REF,$tenantRef)!==1 ||
            !is_string($sessionHandle) ||
            preg_match(self::HANDLE,$sessionHandle)!==1 ||
            !is_string($resourceHandle) ||
            preg_match(self::HANDLE,$resourceHandle)!==1 ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000)
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if($fingerprint===null)return null;

        try{
            if($db->query(
                "SELECT current_setting('app.tenant_ref',true)"
            )->fetchColumn()!==$tenantRef)return null;

            $role=$db->query('SELECT rolsuper,rolbypassrls FROM pg_roles
                WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role) ||
                !in_array($role['rolsuper']??null,[false,'f','0',0],true) ||
                !in_array($role['rolbypassrls']??null,[false,'f','0',0],true))
                return null;

            $key=$this->pepper->getValue();
            $sessionDigest=hash_hmac('sha256',
                'portal-session-review-v1|'.$tenantRef.'|'.$sessionHandle,$key);
            $resourceDigest=hash_hmac('sha256',
                'portal-seo-resource-review-v1|'.$tenantRef.'|'.$resourceHandle,$key);
            return $this->inspectWithDigests(
                $db,$tenantRef,$sessionDigest,$resourceDigest,
                $fingerprint,$exactGoogleSiteUrl,$trustedNow
            );
        }catch(\Throwable){
            return null;
        }
    }

    private function inspectWithDigests(
        PDO $db,
        string $tenantRef,
        string $sessionDigest,
        string $resourceDigest,
        string $propertyFingerprint,
        string $exactGoogleSiteUrl,
        int $trustedNow
    ):?array {
                $statement=$db->prepare(
                    'SELECT actor_ref,issued_at,expires_at,mfa_verified_at,
                        revoked_at,account_revision,session_revision
                     FROM portal_seo_review_sessions
                     WHERE tenant_ref=:tenant AND session_digest=:digest FOR SHARE'
                );
                if (!$statement || !$statement->execute([
                    'tenant'=>$tenantRef,'digest'=>$sessionDigest
                ]))return null;
                $session=$statement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($session))return null;

                $actor=$session['actor_ref']??null;
                if (!is_string($actor) ||
                    preg_match(self::REF,$actor)!==1)return null;
                $issued=self::pgInt($session['issued_at']??null);
                $expires=self::pgInt($session['expires_at']??null);
                $mfaAt=self::pgInt($session['mfa_verified_at']??null);
                $revoked=$session['revoked_at']??null;
                $accountRevision=self::pgInt($session['account_revision']??null);
                $sessionRevision=self::pgInt($session['session_revision']??null);
                if ($issued===null || $expires===null || $mfaAt===null ||
                    $issued>$trustedNow || $expires<=$trustedNow ||
                    $expires-$issued>86400 ||
                    $mfaAt<$issued || $mfaAt>$trustedNow ||
                    $mfaAt<$trustedNow-600 ||
                    $revoked!==null ||
                    $accountRevision===null || $accountRevision<=0 ||
                    $sessionRevision!==$accountRevision)
                    return null;

                $members=$db->prepare(
                    'SELECT status,permission,checked_at,revoked_at
                     FROM portal_seo_review_memberships
                     WHERE tenant_ref=:tenant AND actor_ref=:actor FOR SHARE'
                );
                if (!$members || !$members->execute([
                    'tenant'=>$tenantRef,'actor'=>$actor
                ]))return null;
                $member=$members->fetch(PDO::FETCH_ASSOC);
                if (!is_array($member) ||
                    $member['status']!=='active' ||
                    $member['permission']!=='seo.view' ||
                    $member['revoked_at']!==null)
                    return null;
                $checkedAt=self::pgInt($member['checked_at']??null);
                if ($checkedAt===null || $checkedAt>$trustedNow ||
                    $checkedAt<$trustedNow-900)return null;

                $resources=$db->prepare(
                    'SELECT property_fingerprint,status,updated_at
                     FROM portal_seo_review_resources
                     WHERE tenant_ref=:tenant AND resource_digest=:digest FOR SHARE'
                );
                if (!$resources || !$resources->execute([
                    'tenant'=>$tenantRef,'digest'=>$resourceDigest
                ]))return null;
                $resource=$resources->fetch(PDO::FETCH_ASSOC);
                if (!is_array($resource) ||
                    $resource['status']!=='active' ||
                    !is_string($resource['property_fingerprint']) ||
                    !hash_equals($propertyFingerprint,
                        $resource['property_fingerprint']))
                    return null;
                $updatedAt=self::pgInt($resource['updated_at']??null);
                if ($updatedAt===null || $updatedAt>$trustedNow ||
                    $updatedAt<$trustedNow-86400)return null;

                // A fresh recorded grant is an extra conservative condition,
                // NOT proof of a live authorized Google access token.
                $grant=(new GoogleSearchConsoleRecordedPropertyGrant($db))
                    ->inspect($tenantRef,$actor,$exactGoogleSiteUrl,$trustedNow);
                if (!is_array($grant) ||
                    ($grant['recordedGrantFresh']??null)!==true ||
                    ($grant['tenantRef']??null)!==$tenantRef ||
                    ($grant['actorRef']??null)!==$actor ||
                    ($grant['propertyFingerprint']??null)!==$propertyFingerprint ||
                    ($grant['recordedScope']??null)!==
                        GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
                    ($grant['googleApiCheckedNow']??null)!==false ||
                    ($grant['customerDisplayAuthorized']??null)!==false)
                    return null;

                return [
                    'contractVersion'=>'portal-seo-f111-identity-review-lab-v1',
                    'sessionRecordFresh'=>true,
                    'mfaTimestampFreshInSyntheticRecord'=>true,
                    'membershipRecordFresh'=>true,
                    'propertyRecordMatched'=>true,
                    'recordedGscGrantFresh'=>true,
                    'origin'=>'CI_SYNTHETIC_RECORDS_NOT_TRUSTED_ISSUER',
                    'realSessionIssuerVerified'=>false,
                    'realMfaVerified'=>false,
                    'liveGoogleGrantVerified'=>false,
                    'customerDisplayAuthorized'=>false,
                    'oauthExchangeAuthorized'=>false,
                    'productionReadAuthorized'=>false,
                    'networkPerformed'=>false,
                    'releaseStatus'=>'HOLD_TRUSTED_PORTAL_ISSUER_AND_GOOGLE'
                ];
    }

    private static function pgInt(mixed $value):?int
    {
        if (is_int($value))return $value>=0?$value:null;
        if (!is_string($value) || strlen($value)>16 ||
            preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1)
            return null;
        $n=(int)$value;
        return $n>=0 && (string)$n===$value?$n:null;
    }

    public static function authorizesHttp():bool{return false;}
    public static function authenticatesPortal():bool{return false;}
    public static function authorizesGoogleOAuth():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
