<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * One disposable laboratory record as it existed BEFORE a database restore.
 * These values may roll back together with the database. Never trust them as
 * an external revocation authority or proof of a customer's identity.
 */
final readonly class LabRestoreSnapshot
{
    public function __construct(
        public OrganizationId $organization,
        public string $primaryClusterId,
        public int $backupSequence,
        public int $sessionRevision,
        public int $membershipRevision,
        public bool $sessionActive,
        public bool $membershipActive,
    ) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{3,63}\z/D', $primaryClusterId) !== 1
            || $backupSequence < 1
            || $sessionRevision < 1 || $membershipRevision < 1
            || $backupSequence > 1000000000
            || $sessionRevision > 1000000000 || $membershipRevision > 1000000000) {
            throw new InvalidArgumentException('Invalid non-operational restore snapshot.');
        }
    }
}
