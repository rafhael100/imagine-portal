<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Read-only, legally nonbinding fixture; never a signed agreement,
 * proof of entitlement, payment status or record from a real customer.
 */
final readonly class DemoContractRecord
{
    public function __construct(
        public string $reference,
        public OrganizationId $organizationId,
        public string $serviceReference,
        public string $serviceLabel,
        public DemoContractStage $stage,
    ) {
        if (preg_match('/\ADEMO-CTR-[A-Z0-9-]{1,40}\z/D', $reference) !== 1
            || preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $serviceReference) !== 1) {
            throw new InvalidArgumentException('Only fictional contract/service references are permitted.');
        }
        if (trim($serviceLabel) === '' || strlen($serviceLabel) > 160) {
            throw new InvalidArgumentException('Invalid fictitious service label.');
        }
    }

    public function signatureLabel(): string
    {
        return 'Não realizada';
    }

    public function grantsServiceAccess(): bool
    {
        return false;
    }

    public function createsPaymentObligation(): bool
    {
        return false;
    }

    public function isLegallyBinding(): bool
    {
        return false;
    }
}
