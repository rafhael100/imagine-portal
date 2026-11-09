<?php

declare(strict_types=1);

namespace App\Domain\ClientOverview;

use InvalidArgumentException;

/**
 * Immutable, bounded, NON-AUTHORITATIVE demonstration page.
 * A prepared preview is never evidence of tenant ownership or service access.
 */
final readonly class DemoServiceRelationPage
{
    /**
     * @param list<array{reference:string,service:string,request_count:int,contract_count:int}> $rows
     */
    private function __construct(
        public string $reason,
        public array $rows,
        public int $totalServices,
        public int $totalRequests,
        public int $totalContracts,
        public ?string $nextCursor,
    ) {
        if ($reason !== 'demo_prepared'
            && ($rows !== [] || $totalServices !== 0 || $totalRequests !== 0
                || $totalContracts !== 0 || $nextCursor !== null)) {
            throw new InvalidArgumentException('Blocked page cannot disclose partial mock data.');
        }
    }

    /**
     * @param list<array{reference:string,service:string,request_count:int,contract_count:int}> $rows
     */
    public static function prepared(
        array $rows,
        int $totalServices,
        int $totalRequests,
        int $totalContracts,
        ?string $nextCursor,
    ): self {
        if (!array_is_list($rows) || count($rows) < 1 || count($rows) > 20
            || $totalServices < count($rows) || $totalServices > 50
            || $totalRequests < 0 || $totalRequests > 200
            || $totalContracts < 0 || $totalContracts > 200
            || ($nextCursor !== null && preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $nextCursor) !== 1)) {
            throw new InvalidArgumentException('Invalid synthetic relation page.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['reference', 'service', 'request_count', 'contract_count']
                || !is_string($row['reference'])
                || preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $row['reference']) !== 1
                || !is_string($row['service']) || trim($row['service']) === ''
                || strlen($row['service']) > 2000
                || !is_int($row['request_count']) || $row['request_count'] < 0
                || !is_int($row['contract_count']) || $row['contract_count'] < 0) {
                throw new InvalidArgumentException('Invalid synthetic relation row.');
            }
        }
        return new self('demo_prepared', $rows, $totalServices, $totalRequests, $totalContracts, $nextCursor);
    }

    public static function blocked(string $reason): self
    {
        if (!in_array($reason, [
            'missing_organization', 'invalid_fixture_input', 'duplicate_reference',
            'foreign_organization', 'inconsistent_service_reference',
            'invalid_pagination', 'unknown_cursor',
            'source_unavailable', 'untrusted_db_session', 'unsafe_db_schema',
            'invalid_lab_identity_evidence', 'revoked_lab_identity',
            'untrusted_lab_authority',
        ], true)) {
            throw new InvalidArgumentException('Unknown relation preview rejection.');
        }

        return new self($reason, [], 0, 0, 0, null);
    }

    public function isPreparedPreview(): bool
    {
        return $this->reason === 'demo_prepared';
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

    public function grantsUniversityBenefit(): bool
    {
        return false;
    }
}
