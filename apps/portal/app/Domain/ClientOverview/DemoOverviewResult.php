<?php

declare(strict_types=1);

namespace App\Domain\ClientOverview;

use InvalidArgumentException;

/**
 * Immutable in-memory DISPLAY projection only. It is not a database read,
 * customer identity, entitlement, billing decision or operational snapshot.
 */
final readonly class DemoOverviewResult
{
    /**
     * @param list<array{reference:string, service:string, request_status:string, contract_status:string}> $rows
     */
    private function __construct(
        public string $reason,
        public array $rows,
        public int $serviceCount,
        public int $requestCount,
        public int $contractCount,
    ) {
        if ($reason !== 'demo_consistent'
            && ($rows !== [] || $serviceCount !== 0 || $requestCount !== 0 || $contractCount !== 0)) {
            throw new InvalidArgumentException('Rejected preview must not contain partial data.');
        }
    }

    /** @param list<array{reference:string, service:string, request_status:string, contract_status:string}> $rows */
    public static function consistent(array $rows): self
    {
        if (!array_is_list($rows) || count($rows) === 0 || count($rows) > 50) {
            throw new InvalidArgumentException('Expected a bounded list of demonstrative rows.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== ['reference', 'service', 'request_status', 'contract_status']) {
                throw new InvalidArgumentException('Invalid demonstrative row.');
            }
            foreach ($row as $value) {
                if (!is_string($value) || trim($value) === '' || strlen($value) > 2000) {
                    throw new InvalidArgumentException('Invalid demonstrative text.');
                }
            }
        }
        // In this mock, exactly one request and one contract is associated
        // with each service. Future real data may have 0-N of either.
        return new self('demo_consistent', $rows, count($rows), count($rows), count($rows));
    }

    public static function rejected(string $reason): self
    {
        if (!in_array($reason, [
            'invalid_fixture_input',
            'duplicate_reference',
            'foreign_organization',
            'inconsistent_service_reference',
            'missing_service_relation',
        ], true)) {
            throw new InvalidArgumentException('Unknown preview rejection.');
        }

        return new self($reason, [], 0, 0, 0);
    }

    public function isConsistent(): bool
    {
        return $this->reason === 'demo_consistent';
    }

    public function mayExposeCustomerData(): bool
    {
        return false;
    }

    public function authorizesServiceAccess(): bool
    {
        return false;
    }

    public function authorizesContractOrPayment(): bool
    {
        return false;
    }
}
