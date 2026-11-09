<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/** Immutable snapshot for a dry-run of the request state machine. No DB model. */
final readonly class ServiceRequestSnapshot
{
    public function __construct(
        public string $requestId,
        public OrganizationId $organizationId,
        public string $serviceReference,
        public ServiceRequestStatus $status,
        public int $revision,
    ) {
        foreach ([$requestId, $serviceReference] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid opaque Portal request/service reference.');
            }
        }
        if ($revision < 0 || $revision === PHP_INT_MAX) {
            throw new InvalidArgumentException('Invalid revision for a workflow preview.');
        }
    }

    public function withNextStatus(ServiceRequestStatus $status): self
    {
        return new self($this->requestId, $this->organizationId, $this->serviceReference, $status, $this->revision + 1);
    }
}
