<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use App\Infrastructure\Identity\PostgresPortalSessionPrincipalCandidateReader;
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;
use Throwable;

/**
 * F107 — CLI-only, fail-closed, read-only boundary across two independently
 * pinned Portal review branches. No Laravel binding, HTTP route, token service
 * or customer display. This is a candidate; never authorize from it.
 *
 * Session observation happens on a separate identity PDO connection before
 * the GSC RLS transaction. This is NOT atomic cross-resource authorization:
 * a concurrent session revocation could occur between those observations.
 * The final real service MUST resolve that race with reviewed server-side
 * authority, revocation fencing and an explicit customer-display gate.
 */
final readonly class OfflinePortalGscSessionCandidateReview
{
    public function __construct(
        private PostgresPortalSessionPrincipalCandidateReader $portalSessionReader,
        private OfflinePortalGscIdentityBindingReview $gscReview,
    ) {}

    public function inspect(
        #[SensitiveParameter] mixed $businessSessionReference,
        mixed $expectedPortalOrganizationUuid,
        mixed $exactPropertyFromBackend,
        mixed $trustedNow,
        mixed $offlineDto,
    ): ?array {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_GSC_F107_SESSION_LAB') !== '1' ||
            getenv('PORTAL_GSC_F106_BINDING_LAB') !== '1' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1' ||
            !is_int($trustedNow) || $trustedNow < 1600000000 ||
            $trustedNow > 2200000000 ||
            !self::uuid($expectedPortalOrganizationUuid) ||
            !is_string($businessSessionReference) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$businessSessionReference) !== 1)
            return null;

        try {
            // F107 imports the unchanged first-party candidate reader from
            // a SHA-pinned Portal identity branch. It performs its own
            // restricted-role and first-party session DB verification.
            $observation=$this->portalSessionReader->read(
                $businessSessionReference
            );
            if (!self::shape($observation,[
                'identity_id','organization_id','membership_id',
                'identity_revision','membership_revision',
                'organization_revision','expires_at','authorizes_http'
            ]) || $observation['authorizes_http'] !== false ||
                !self::uuid($observation['identity_id']) ||
                !self::uuid($observation['organization_id']) ||
                !self::uuid($observation['membership_id']) ||
                $observation['organization_id'] !==
                    $expectedPortalOrganizationUuid)
                return null;

            foreach ([
                'identity_revision','membership_revision','organization_revision'
            ] as $revision) {
                if (!is_int($observation[$revision]) ||
                    $observation[$revision] < 1 ||
                    $observation[$revision] > 2147483647)
                    return null;
            }

            // The current first-party candidate reader exports an expiry but
            // NOT the session issuance timestamp. Do not invent an original
            // issuance claim: the downstream F106 input uses a test-only
            // observation instant. The read-only SQL function has separately
            // validated session issuance/MFA/business purpose and revocation.
            if (!is_string($observation['expires_at']) ||
                strlen($observation['expires_at']) > 64 ||
                preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} /',
                    $observation['expires_at']) !== 1)
                return null;
            $expiry=new DateTimeImmutable($observation['expires_at']);
            if ($expiry->getOffset() < -50400 ||
                $expiry->getOffset() > 50400)
                return null;
            $expirationEpoch=$expiry->getTimestamp();
            if ($expirationEpoch <= $trustedNow ||
                $expirationEpoch > $trustedNow + 7200)
                return null;

            $principal=[
                'organizationUuid'=>$observation['organization_id'],
                'identityUuid'=>$observation['identity_id'],
                'organizationRevision'=>$observation['organization_revision'],
                'membershipRevision'=>$observation['membership_revision'],
                // TEST NORMALIZATION, not an authenticated issued-at claim.
                'sessionIssuedAt'=>$trustedNow,
                'sessionExpiresAt'=>$expirationEpoch,
            ];
            $recorded=$this->gscReview->inspect(
                $principal,$exactPropertyFromBackend,$trustedNow,$offlineDto
            );
            if (!is_array($recorded) ||
                ($recorded['contractVersion']??null) !==
                    'portal-gsc-f106-identity-binding-review-lab-v1' ||
                ($recorded['status']??null) !==
                    'SYNTHETIC_MATCH_NOT_AUTHORIZATION' ||
                ($recorded['identityBindingRecorded']??null) !== true ||
                ($recorded['recordedGrantFresh']??null) !== true ||
                ($recorded['customerDisplayAuthorized']??null) !== false ||
                ($recorded['googlePropertyVerifiedNow']??null) !== false ||
                ($recorded['networkPerformed']??null) !== false)
                return null;

            // Deliberately narrow response: never leak raw session reference,
            // actor, UUID, Google URL, provider query or token.
            return [
                'contractVersion'=>'portal-gsc-f107-session-observation-lab-v1',
                'status'=>'OBSERVED_SESSION_AND_RECORDED_GSC_NOT_AUTHORIZATION',
                'period'=>$recorded['period'],
                'summary'=>$recorded['summary'],
                'missingDatesUnknown'=>$recorded['missingDatesUnknown'],
                'firstPartySessionRowObserved'=>true,
                'recordedIdentityLinkObserved'=>true,
                'recordedGscGrantObserved'=>true,
                'singleTransactionAcrossIdentityAndGsc'=>false,
                'sessionAuthenticatedForProduction'=>false,
                'sessionRevocationFenced'=>false,
                'googleOAuthVerifiedNow'=>false,
                'sourceOriginVerified'=>false,
                'customerDisplayAuthorized'=>false,
                'productionReadAuthorized'=>false,
                'publicationAuthorized'=>false,
                'networkPerformed'=>false,
                'releaseStatus'=>'HOLD_ATOMIC_AUTH_GOOGLE_AND_APPSEC_REVIEW'
            ];
        } catch(Throwable) {
            // Never leak PDO errors, session secrets or candidates.
            return null;
        }
    }

    private static function uuid(mixed $value):bool
    {
        return is_string($value) &&
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $value
            ) === 1;
    }

    private static function shape(mixed $dto,array $expected):bool
    {
        if(!is_array($dto) || array_is_list($dto)) return false;
        $actual=array_keys($dto);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    public static function canAuthorizeCustomer():bool{return false;}
    public static function canPublish():bool{return false;}
    public static function canQueryGoogle():bool{return false;}
}
