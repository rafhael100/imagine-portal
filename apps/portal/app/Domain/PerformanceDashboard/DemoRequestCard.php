<?php

namespace App\Domain\PerformanceDashboard;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/** A placeholder workflow item without any mutation or approval endpoint. */
final readonly class DemoRequestCard
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $title,
        public DemoRequestStage $stage,
    ) {
        if (preg_match('/\Ademo_request_[a-z0-9_]{1,64}\z/D', $id) !== 1
            || trim($title) === ''
            || strlen($title) > 100) {
            throw new InvalidArgumentException('Invalid synthetic request card.');
        }
    }
}
