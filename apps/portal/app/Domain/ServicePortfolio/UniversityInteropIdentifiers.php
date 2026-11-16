<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;

/**
 * Pure, non-authorizing schema compatibility for the two independent
 * identity domains. Portal permits 128 bytes; University accepts only
 * 80 bytes for org and 120 for subject. NEVER truncate, alias, hash or
 * silently remap identities to fit; future business SSO must prove origin.
 */
final class UniversityInteropIdentifiers
{
    private function __construct()
    {
    }

    public static function supportsOrganization(OrganizationId $organization): bool
    {
        return strlen($organization->value) <= 80
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\z/D', $organization->value) === 1;
    }

    public static function supportsSubject(PortalUserId $subject): bool
    {
        return strlen($subject->value) <= 120
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/D', $subject->value) === 1;
    }

    public static function mayIssueGrant(): bool
    {
        return false;
    }
}
