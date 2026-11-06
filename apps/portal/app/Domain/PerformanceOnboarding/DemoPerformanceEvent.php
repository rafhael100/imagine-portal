<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;
use JsonException;

/** Proposed INTEG-01-inspired envelope, intentionally NOT transport/API ready. */
final readonly class DemoPerformanceEvent
{
    public const SCHEMA_VERSION = '0.1-draft';
    public const EVENT_TYPE = 'performance.onboarding.mock_requested';
    public const PRODUCER = 'portal-imagine-mock';

    public function __construct(
        public string $eventId,
        public string $correlationId,
        public OrganizationId $organizationId,
        public DemoOnboardingId $onboardingId,
        public string $proposalFingerprint,
    ) {
        if (preg_match('/\Aevt-demo-[a-zA-Z0-9_-]{1,72}\z/D', $eventId) !== 1
            || preg_match('/\Acorr-demo-[a-zA-Z0-9_-]{1,72}\z/D', $correlationId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $proposalFingerprint) !== 1) {
            throw new InvalidArgumentException('Invalid synthetic event envelope.');
        }
    }

    /** @throws JsonException */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'event_type' => self::EVENT_TYPE,
            'producer' => self::PRODUCER,
            'event_id' => $this->eventId,
            'correlation_id' => $this->correlationId,
            'portal_organization_id' => $this->organizationId->value,
            'demo_onboarding_id' => $this->onboardingId->value,
            'proposal_fingerprint' => $this->proposalFingerprint,
        ], JSON_THROW_ON_ERROR));
    }
}
