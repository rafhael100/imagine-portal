<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use Throwable;

/**
 * F107 — laboratory-only, deny-by-default composition of three separate
 * checks: Portal session+MFA, scoped seo.view membership/resource ownership,
 * and a fresh RLS-scoped recorded Google property grant.
 *
 * Even a positive result is only a synthetic candidate for independent review.
 * No trusted production session issuer/Google OAuth verification is connected.
 * NEVER register this class as HTTP middleware, controller, policy or API.
 */
final readonly class SeoFunnelTenantPropertyBoundary
{
    private const REF = '/\A[a-z0-9][a-z0-9_-]{2,63}\z/D';
    private const SHA = '/\A[0-9a-f]{64}\z/D';

    public function __construct(
        private PortalSessionAuthority $sessions,
        private PortalMembershipAuthority $memberships,
        private PortalResourceAuthority $resources,
        private RecordedGscGrantReviewSource $recordedGrants,
        private OrganizationAuthorizationService $policy = new OrganizationAuthorizationService(),
    ) {}

    /**
     * Review inputs are lookup intentions only. Actor identity is obtained
     * solely from the server session source; never from client-supplied claims.
     *
     * @return array<string,mixed>|null Positive LAB review or generic denial.
     */
    public function inspect(
        mixed $opaqueSessionRef,
        mixed $selectedTenantRef,
        mixed $opaqueResourceRef,
        mixed $exactGscSiteUrl,
        mixed $offlineDto,
        mixed $trustedNow
    ): ?array {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_SEO_F106_LAB') !== '1' ||
            getenv('PORTAL_SEO_F107_LAB') !== '1' ||
            !is_string($opaqueSessionRef) ||
            $opaqueSessionRef === '' ||
            strlen($opaqueSessionRef) > 512 ||
            !is_string($selectedTenantRef) ||
            preg_match(self::REF, $selectedTenantRef) !== 1 ||
            !is_string($opaqueResourceRef) ||
            $opaqueResourceRef === '' ||
            strlen($opaqueResourceRef) > 512 ||
            !is_int($trustedNow) ||
            $trustedNow < 1600000000 ||
            $trustedNow > 2200000000) return null;

        $fingerprint = GoogleSearchConsolePropertyIdentifier::fingerprint($exactGscSiteUrl);
        if ($fingerprint === null) return null;

        // The F106 DTO is a non-authoritative observation, never a grant.
        // The existing strict PHP consumer validates every field and keeps
        // all three funnel stages unavailable.
        $preview = OfflineSeoFunnelPreview::fromDto($offlineDto);
        if ($preview === null ||
            !is_array($offlineDto) ||
            !isset($offlineDto['scope']['tenantRef'],
                $offlineDto['scope']['propertyFingerprint']) ||
            $offlineDto['scope']['tenantRef'] !== $selectedTenantRef ||
            !hash_equals($fingerprint,
                $offlineDto['scope']['propertyFingerprint'])) return null;

        try {
            $organization = new OrganizationId($selectedTenantRef);
            $session = $this->sessions->find($opaqueSessionRef);
            if ($session === null ||
                !$session->passesSyntheticFreshnessChecks($trustedNow)) return null;

            $actorRef = $session->actorId->value;
            if (preg_match(self::REF, $actorRef) !== 1) return null;

            // All three values must derive from trusted Portal-only sources in
            // a future real backend; mock contracts do not prove provenance.
            $membership = $this->memberships->findFor(
                $session->actorId, $organization
            );
            $owner = $this->resources->ownerOf(
                $organization, $opaqueResourceRef
            );
            $decision = $this->policy->decide(
                new AuthorizationContext(
                    $session->actorId,
                    $organization,
                    $owner,
                    Permission::ViewSeo->value
                ),
                $membership
            );
            if (!$decision->allowed) return null;

            // The only non-mock implementation uses PostgreSQL with RLS
            // context set only inside a tenant-scoped transaction.
            $grant = $this->recordedGrants->inspect(
                ['tenantRef' => $selectedTenantRef, 'actorRef' => $actorRef],
                $exactGscSiteUrl,
                $trustedNow
            );
            if (!self::validRecordedGrant(
                $grant, $selectedTenantRef, $actorRef, $fingerprint, $trustedNow
            )) return null;

            return [
                'contractVersion' => 'portal-seo-funnel-scoped-review-lab-v1',
                'methodology' => 'VISITAS_LEADS_CLIENTES',
                'origin' => 'LAB_SYNTHETIC_NOT_AUTHENTICATED',
                'portalSyntheticChecksPass' => true,
                'recordedGrantFreshInSuppliedReview' => true,
                'propertyFingerprintMatched' => true,
                'searchDiscoveryForLabReview' => $preview->searchDiscovery,
                'funnelStages' => $preview->stageAvailability,
                'googleApiCheckedNow' => false,
                'sessionIssuerVerifiedForProduction' => false,
                'tenantMembershipVerifiedForProduction' => false,
                'propertyAuthorizationVerifiedForProduction' => false,
                'oauthVerifiedForImagine' => false,
                'customerDisplayAuthorized' => false,
                'productionReadAuthorized' => false,
                'publicationAuthorized' => false,
                'networkPerformed' => false,
                'releaseStatus' => 'HOLD_TRUSTED_SESSION_AND_GOOGLE_PROOF'
            ];
        } catch (Throwable) {
            // Do not reveal whether actor, tenant, grant or property existed.
            return null;
        }
    }

    private static function validRecordedGrant(
        mixed $grant,
        string $tenant,
        string $actor,
        string $fingerprint,
        int $now
    ): bool {
        if (!is_array($grant) || array_is_list($grant)) return false;
        $expected = [
            'contractVersion','tenantRef','actorRef','propertyFingerprint',
            'recordedGrantFresh','recordedScope','verifiedAt','expiresAt',
            'googleApiCheckedNow','sessionMfaVerified',
            'tenantMembershipVerified','propertyAuthorizationVerified',
            'customerDisplayAuthorized','publicationAuthorized',
            'networkPerformed','releaseStatus'
        ];
        $actual = array_keys($grant);
        sort($actual); sort($expected);
        if ($actual !== $expected ||
            $grant['contractVersion'] !== 'portal-gsc-property-grant-record-review-v1' ||
            $grant['tenantRef'] !== $tenant ||
            $grant['actorRef'] !== $actor ||
            $grant['propertyFingerprint'] !== $fingerprint ||
            preg_match(self::SHA, $grant['propertyFingerprint']) !== 1 ||
            $grant['recordedGrantFresh'] !== true ||
            $grant['recordedScope'] !==
                GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
            !is_int($grant['verifiedAt']) ||
            !is_int($grant['expiresAt']) ||
            $grant['verifiedAt'] > $now ||
            $grant['verifiedAt'] < $now - 900 ||
            $grant['expiresAt'] < $now ||
            $grant['releaseStatus'] !== 'HOLD_TRUSTED_AUTH_AND_GOOGLE_PROOF')
            return false;

        foreach ([
            'googleApiCheckedNow','sessionMfaVerified','tenantMembershipVerified',
            'propertyAuthorizationVerified','customerDisplayAuthorized',
            'publicationAuthorized','networkPerformed'
        ] as $key) {
            if ($grant[$key] !== false) return false;
        }
        return true;
    }

    public static function authorizesHttp(): bool { return false; }
    public static function authorizesGoogleRequest(): bool { return false; }
    public static function authorizesCustomerDisplay(): bool { return false; }
}
