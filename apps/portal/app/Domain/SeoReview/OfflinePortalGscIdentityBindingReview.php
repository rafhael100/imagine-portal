<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F106: atomic, read-only composition of a candidate Portal principal,
 * a Portal→GSC identity link and an existing GSC property grant under one
 * transaction with forced PostgreSQL tenant RLS.
 *
 * LABORATORY ONLY. The caller-supplied principal is NOT authenticated here.
 * No HTTP route or customer display capability can use this result.
 */
final readonly class OfflinePortalGscIdentityBindingReview
{
    private const READONLY_SCOPE =
        'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(private PDO $db) {}

    /** Fixed-scope, fail-closed synthetic review. All inputs are untrusted. */
    public function inspect(
        mixed $principal,
        mixed $exactGoogleSiteUrl,
        mixed $now,
        mixed $dto,
    ): ?array {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_GSC_F106_BINDING_LAB') !== '1' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1' ||
            !self::candidatePrincipal($principal,$now) ||
            !is_array($dto) || !self::simpleRef($dto['tenantRef']??null))
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if ($fingerprint === null ||
            ($dto['propertyFingerprint']??null) !== $fingerprint)
            return null;

        $preview=OfflineOpenSeoDailyPreview::fromDto($dto);
        if ($preview === null) return null;

        // The F104 DTO nominates only a candidate RLS scope. Without
        // independent Portal session authentication this does NOT verify
        // tenant ownership. The link and grant must both match in SQL.
        $tenant=$dto['tenantRef'];
        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->db);
        $result=$scope->run([
            'tenantRef'=>$tenant,
            'actorRef'=>'scope-review-f106',
        ],function(PDO $db)use($principal,$now,$tenant,$fingerprint,
                $exactGoogleSiteUrl,$preview):?array {
            $stmt=$db->prepare(
                'SELECT actor_ref FROM portal_gsc_identity_scope_links
                 WHERE tenant_ref=:tenant
                   AND portal_organization_id=CAST(:org AS uuid)
                   AND portal_identity_id=CAST(:actor AS uuid)
                   AND organization_revision=:org_rev
                   AND membership_revision=:member_rev
                   AND link_status=:active AND revoked_at IS NULL
                   AND issued_at<=:now_issued AND expires_at>:now_expiry'
            );
            if (!$stmt || !$stmt->execute([
                'tenant'=>$tenant,
                'org'=>$principal['organizationUuid'],
                'actor'=>$principal['identityUuid'],
                'org_rev'=>$principal['organizationRevision'],
                'member_rev'=>$principal['membershipRevision'],
                'active'=>'active','now_issued'=>$now,'now_expiry'=>$now,
            ])) return null;
            $link=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($link) || !self::simpleRef($link['actor_ref']??null)
                || $stmt->fetch(PDO::FETCH_ASSOC)!==false)
                return null;

            // Existing grant check happens within the SAME transaction as
            // mapping resolution, preventing torn reads and cross-tenant
            // scope confusion during concurrent review.
            $recorded=(new GoogleSearchConsoleRecordedPropertyGrant($db))
                ->inspect($tenant,$link['actor_ref'],
                    $exactGoogleSiteUrl,$now);
            if (!is_array($recorded) ||
                ($recorded['contractVersion']??null) !==
                    'portal-gsc-property-grant-record-review-v1' ||
                ($recorded['tenantRef']??null) !== $tenant ||
                ($recorded['actorRef']??null) !== $link['actor_ref'] ||
                ($recorded['propertyFingerprint']??null) !== $fingerprint ||
                ($recorded['recordedGrantFresh']??null) !== true ||
                ($recorded['recordedScope']??null) !== self::READONLY_SCOPE ||
                ($recorded['googleApiCheckedNow']??null) !== false ||
                ($recorded['sessionMfaVerified']??null) !== false ||
                ($recorded['tenantMembershipVerified']??null) !== false ||
                ($recorded['propertyAuthorizationVerified']??null) !== false ||
                ($recorded['customerDisplayAuthorized']??null) !== false ||
                ($recorded['publicationAuthorized']??null) !== false)
                return null;

            return [
                'contractVersion'=>'portal-gsc-f106-identity-binding-review-lab-v1',
                'status'=>'SYNTHETIC_MATCH_NOT_AUTHORIZATION',
                'propertyFingerprint'=>$fingerprint,
                'period'=>$preview->period,
                'summary'=>$preview->summary,
                'missingDatesUnknown'=>$preview->missingDatesUnknown,
                'identityBindingRecorded'=>true,
                'recordedGrantFresh'=>true,
                'sessionAuthenticated'=>false,
                'sessionMfaVerified'=>false,
                'tenantMembershipVerified'=>false,
                'googlePropertyVerifiedNow'=>false,
                'sourceOriginVerified'=>false,
                'customerDisplayAuthorized'=>false,
                'productionReadAuthorized'=>false,
                'publicationAuthorized'=>false,
                'networkPerformed'=>false,
                'releaseStatus'=>'HOLD_REAL_AUTH_AND_GOOGLE_VERIFICATION',
            ];
        });
        return is_array($result)?$result:null;
    }

    private static function simpleRef(mixed $ref):bool
    {
        return is_string($ref) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$ref)===1;
    }
    private static function candidatePrincipal(mixed $value,mixed $now):bool
    {
        if (!is_int($now) || $now<1600000000 || $now>2200000000 ||
            !is_array($value) || array_is_list($value) ||
            count($value)!==6 ||
            array_diff(array_keys($value),[
                'organizationUuid','identityUuid',
                'organizationRevision','membershipRevision',
                'sessionIssuedAt','sessionExpiresAt'
            ])!==[]) return false;

        foreach (['organizationUuid','identityUuid'] as $key) {
            if (!is_string($value[$key]) ||
                preg_match(
                    '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                    $value[$key]
                )!==1) return false;
        }
        foreach (['organizationRevision','membershipRevision']
            as $field) {
            if (!is_int($value[$field]) || $value[$field]<1 ||
                $value[$field]>2147483647) return false;
        }
        return is_int($value['sessionIssuedAt']) &&
            is_int($value['sessionExpiresAt']) &&
            $value['sessionIssuedAt']<=$now &&
            $value['sessionExpiresAt']>$now &&
            $value['sessionExpiresAt']-$value['sessionIssuedAt']<=86400;
    }

    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesPortalMembership():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
    public static function canPublish():bool{return false;}
}
