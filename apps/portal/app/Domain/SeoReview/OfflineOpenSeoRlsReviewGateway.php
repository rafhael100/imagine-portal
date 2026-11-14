<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * GSC-F105 — bridge a synthetic SEO F104 DTO to an *existing* RLS-backed
 * recorded-grant inspection. This is NOT a live permission to read Google,
 * expose dashboard data, provision grants or publish anything.
 *
 * PDO must use a NON-OWNER and NON-BYPASSRLS PostgreSQL runtime identity.
 * The real Portal authenticator must eventually issue the trusted context;
 * this laboratory must never receive context from a request/browser.
 */
final readonly class OfflineOpenSeoRlsReviewGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(private PDO $connection) {}

    /**
     * Returns a bounded internal review envelope, never customer-facing data.
     *
     * @param mixed $trustedContext an internal, independently resolved
     *                              tenantRef/actorRef pair, NOT user claims.
     */
    public function review(
        mixed $trustedContext,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow,
        mixed $offlineDto
    ): ?array {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1' ||
            getenv('PORTAL_GSC_F105_RLS_LAB') !== '1' ||
            !self::context($trustedContext) ||
            !is_int($trustedNow) || $trustedNow < 1600000000 ||
            $trustedNow > 2200000000 ||
            !is_array($offlineDto))
            return null;

        // F104 is *unverified* and cannot nominate a company or Google site.
        // A valid DTO alone is never sufficient for any access. Reject both
        // mismatches BEFORE hitting PostgreSQL.
        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if ($fingerprint === null ||
            ($offlineDto['tenantRef'] ?? null) !== $trustedContext['tenantRef'] ||
            ($offlineDto['propertyFingerprint'] ?? null) !== $fingerprint)
            return null;

        $preview=OfflineOpenSeoDailyPreview::fromDto($offlineDto);
        if ($preview === null) return null;

        $recorded=(new GoogleSearchConsoleTenantGrantGateway($this->connection))
            ->inspectRecordedGrant(
                $trustedContext,$exactGoogleSiteUrl,$trustedNow
            );
        // These flags intentionally remain false even if PostgreSQL contains
        // a fresh, unrevoked record. No recorded row proves current OAuth,
        // MFA, a Google API response or future customer-display permission.
        if (!is_array($recorded) ||
            ($recorded['contractVersion'] ?? null) !==
                'portal-gsc-property-grant-record-review-v1' ||
            ($recorded['tenantRef'] ?? null) !== $trustedContext['tenantRef'] ||
            ($recorded['actorRef'] ?? null) !== $trustedContext['actorRef'] ||
            ($recorded['propertyFingerprint'] ?? null) !== $fingerprint ||
            ($recorded['recordedGrantFresh'] ?? null) !== true ||
            ($recorded['recordedScope'] ?? null) !== self::SCOPE ||
            ($recorded['googleApiCheckedNow'] ?? null) !== false ||
            ($recorded['sessionMfaVerified'] ?? null) !== false ||
            ($recorded['tenantMembershipVerified'] ?? null) !== false ||
            ($recorded['propertyAuthorizationVerified'] ?? null) !== false ||
            ($recorded['customerDisplayAuthorized'] ?? null) !== false ||
            ($recorded['publicationAuthorized'] ?? null) !== false ||
            ($recorded['networkPerformed'] ?? null) !== false ||
            ($recorded['releaseStatus'] ?? null) !==
                'HOLD_TRUSTED_AUTH_AND_GOOGLE_PROOF')
            return null;

        return [
            'contractVersion'=>'portal-gsc-f105-recorded-review-lab-v1',
            'status'=>'RECORDED_GRANT_NOT_LIVE_AUTHORIZATION',
            'scope'=>[
                'tenantRef'=>$trustedContext['tenantRef'],
                'propertyFingerprint'=>$fingerprint,
            ],
            'period'=>$preview->period,
            'summary'=>$preview->summary,
            'missingDatesUnknown'=>$preview->missingDatesUnknown,
            'sourceOriginVerified'=>false,
            'sessionMfaVerified'=>false,
            'backendTenantMembershipVerified'=>false,
            'googleOAuthVerifiedNow'=>false,
            'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_REAL_PORTAL_AUTH_GOOGLE_AND_REVIEW',
        ];
    }

    private static function context(mixed $value): bool
    {
        if (!is_array($value) || array_is_list($value) ||
            count($value) !== 2 ||
            !array_key_exists('tenantRef', $value) ||
            !array_key_exists('actorRef', $value))
            return false;
        foreach (['tenantRef','actorRef'] as $key) {
            if (!is_string($value[$key]) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',
                    $value[$key]) !== 1)
                return false;
        }
        return true;
    }

    public static function authorizesGoogleHttp(): bool {return false;}
    public static function authorizesCustomerDisplay(): bool {return false;}
    public static function authorizesPortalMembership(): bool {return false;}
    public static function canPublish(): bool {return false;}
}
