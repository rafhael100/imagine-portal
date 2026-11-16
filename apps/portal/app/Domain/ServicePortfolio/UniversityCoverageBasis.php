<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/**
 * Closed business-status vocabulary for a future authoritative coverage source.
 *
 * Enumeration alone is NOT payment evidence or permission. In particular, a
 * Sites installation charge, webhook notification or checkout intent never
 * establishes a current qualifying service.
 */
enum UniversityCoverageBasis: string
{
    case Unverified = 'unverified';
    case InstallationPaymentOnly = 'installation_payment_only';
    case WebhookEventOnly = 'webhook_event_only';
    case RecurringContractWindow = 'recurring_contract_window';
    case ApprovedGraceWindow = 'approved_grace_window';

    public function isCurrentServiceCandidate(): bool
    {
        return $this === self::RecurringContractWindow
            || $this === self::ApprovedGraceWindow;
    }
}
