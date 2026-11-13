<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

/**
 * First-account email transport prerequisites, not a delivery API or login.
 *
 * No sender, mailer, key, HTTP endpoint, queue, organization permission or
 * operational approval is provided here. Each prerequisite must be supplied
 * from an independently approved, authenticated source before a later
 * integration is even considered.
 */
final class PortalEmailDeliveryPolicy
{
    /**
     * True only if a FUTURE audited issuer could consider an SMTP transport.
     * This never sends email, confirms a mailbox, or authorizes any customer.
     */
    public static function transportPrerequisitesSatisfied(
        string $transport,
        bool $tlsVerified,
        bool $senderDomainVerified,
        bool $operatorProvisioningApproved,
        bool $independentAppSecApproved,
        bool $externalRollbackWitnessReady,
    ): bool {
        // Log may write the 256-bit bearer to application logs; array
        // silently discards it. Failover or roundrobin may select an
        // unintended provider. None are acceptable for real onboarding.
        return $transport === 'smtp'
            && $tlsVerified
            && $senderDomainVerified
            && $operatorProvisioningApproved
            && $independentAppSecApproved
            && $externalRollbackWitnessReady;
    }

    public static function grantsPortalAccess(): bool
    {
        return false;
    }
}
