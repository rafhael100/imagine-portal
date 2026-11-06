<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Organizations\OrganizationId;

/**
 * Synthetic adapter result only. No Performance tenant or service is created.
 * Acknowledging a mock event NEVER acknowledges a billable transaction.
 */
final readonly class DemoAdapterReceipt
{
    public ?string $performanceTenantId;
    public ?string $activatedServiceId;

    public function __construct(
        public string $eventId,
        public string $correlationId,
        public OrganizationId $organizationId,
        public DemoOnboardingId $onboardingId,
        public DemoOnboardingStatus $status = DemoOnboardingStatus::AwaitingMockReview,
        public string $source = 'synthetic_demo',
    ) {
        $this->performanceTenantId = null;
        $this->activatedServiceId = null;
    }

    public function isProvisioned(): bool
    {
        return false;
    }

    public function isPaymentConfirmed(): bool
    {
        return false;
    }
}
