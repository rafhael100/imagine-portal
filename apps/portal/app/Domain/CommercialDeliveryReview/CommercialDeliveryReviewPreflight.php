<?php

declare(strict_types=1);

namespace App\Domain\CommercialDeliveryReview;

use App\Domain\Authorization\Permission;
use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;

/**
 * F126 internal preflight contract, NOT an authenticated review endpoint.
 *
 * Caller-supplied organization and submission are selection hints only.
 * The boundary must independently resolve the Portal session, organization
 * membership and owner of the submission from trusted server-side providers.
 *
 * There are NO trusted providers wired yet. Even a positive result using
 * synthetic test authorities never grants database, HTTP, retry, CRM or
 * visitor acknowledgement privileges.
 */
final readonly class CommercialDeliveryReviewPreflight
{
    public function __construct(private PortalAuthorityBoundary $boundary)
    {
    }

    public function inspect(
        ?string $opaquePortalSession,
        ?OrganizationId $organization,
        ?string $submissionReference,
        int $now,
    ): CommercialDeliveryReviewVerdict {
        // Fixed UUID syntax reduces accidental interpretation of raw SQL,
        // paths, CRM cookies and arbitrary client-provided resources.
        if ($submissionReference === null
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',
                $submissionReference,
            ) !== 1) {
            return CommercialDeliveryReviewVerdict::blocked();
        }

        $verdict = $this->boundary->inspect(
            $opaquePortalSession,
            $organization,
            strtolower($submissionReference),
            Permission::ViewCommercialDeliveryReview->value,
            $now,
        );

        return $verdict->syntheticChecksPass
            ? CommercialDeliveryReviewVerdict::syntheticCandidate()
            : CommercialDeliveryReviewVerdict::blocked();
    }
}
