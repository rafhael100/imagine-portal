<?php

declare(strict_types=1);

namespace App\Labs\F33;

use App\Domain\Organizations\OrganizationId;

/**
 * Strictly fictional, versioned input to the F33 witness-first lab.
 * This is NOT an authenticated user request or a production event format.
 */
final readonly class F33SyntheticRevocationEvent
{
    public function __construct(
        public OrganizationId $organization,
        public string $eventId,
        public string $kind,
        public string $subjectRef,
        public int $sessionFloor,
        public int $membershipFloor,
        public int $changedAt,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || preg_match('/\AF33-[A-Z0-9-]{1,56}\z/D', $eventId) !== 1
            || !in_array($kind, ['session', 'membership'], true)
            || !in_array($organization->value, ['org-a', 'org-b'], true)
            || $sessionFloor < 1 || $sessionFloor > 1000000
            || $membershipFloor < 1 || $membershipFloor > 1000000
            || $changedAt < 1800000000 || $changedAt > 1800003600) {
            throw new \InvalidArgumentException('F33 refuses a non-fictional revocation event.');
        }

        $suffix = $organization->value === 'org-a' ? 'A' : 'B';
        $required = $kind === 'session' ? 'DEMO-SESSION-'.$suffix : 'DEMO-USER-'.$suffix;
        if ($subjectRef !== $required) {
            throw new \InvalidArgumentException('F33 synthetic subject does not match its organization.');
        }
    }
}
