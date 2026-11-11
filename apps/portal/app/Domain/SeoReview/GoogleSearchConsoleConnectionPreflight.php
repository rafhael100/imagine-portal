<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use App\Domain\Authorization\Permission;
use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;

/**
 * GSC-F131: connect F120's exact siteUrl to the existing Portal authority
 * boundary, without pretending it is an authenticated OAuth start route.
 *
 * Only the server-side PortalResourceAuthority may determine the owner of
 * the fingerprinted resource. Client-supplied organization IDs and site URLs
 * are LOOKUP INTENTIONS, not proof of resource ownership or authorization.
 *
 * PortalAuthorityBoundary itself uses non-operational authorities and its
 * verdict can never authorize HTTP. So neither can this preflight.
 */
final readonly class GoogleSearchConsoleConnectionPreflight
{
    private const RESOURCE_PREFIX = 'gsc-property-sha256:';

    public function __construct(private PortalAuthorityBoundary $authority)
    {
    }

    public function inspect(
        ?string $opaquePortalSession,
        ?OrganizationId $selectedOrganization,
        mixed $requestedSiteUrl,
        int $now,
    ): GoogleSearchConsoleConnectionReview {
        $fingerprint = GoogleSearchConsolePropertyIdentifier::fingerprint(
            $requestedSiteUrl,
        );

        if ($fingerprint === null
            || $selectedOrganization === null
            || $opaquePortalSession === null
            || $opaquePortalSession === ''
            || $now <= 0) {
            return GoogleSearchConsoleConnectionReview::blocked();
        }

        $verdict = $this->authority->inspect(
            $opaquePortalSession,
            $selectedOrganization,
            self::RESOURCE_PREFIX.$fingerprint,
            Permission::ConnectGoogleSearchConsole->value,
            $now,
        );

        // This is ONLY an offline composition candidate. The current Portal
        // session/membership/resource authorities are deny-all by default.
        // A future release must verify Google ownership and a transactional
        // first-party session + MFA, grants, revisions and revocations.
        return $verdict->syntheticChecksPass
            ? GoogleSearchConsoleConnectionReview::syntheticCandidate()
            : GoogleSearchConsoleConnectionReview::blocked();
    }
}
