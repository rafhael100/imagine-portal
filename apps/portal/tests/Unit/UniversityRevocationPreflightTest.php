<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\ServicePortfolio\UniversityContractAuthority;
use App\Domain\ServicePortfolio\UniversityContractSnapshot;
use App\Domain\ServicePortfolio\UniversityContractStatus;
use App\Domain\ServicePortfolio\UniversityRevocationPreflight;
use App\Domain\ServicePortfolio\UniversityServiceRevisionAuthority;
use App\Domain\ServicePortfolio\UniversityServiceRevisionSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UniversityRevocationPreflightTest extends TestCase
{
    private const NOW = 1800000000;

    private function org(string $name = 'synthetic-organization-a'): OrganizationId
    {
        return new OrganizationId($name);
    }

    private function service(
        string $ref = 'synthetic-contract-1',
        UniversityContractStatus $status = UniversityContractStatus::Active,
        int $rev = 2,
        ?OrganizationId $org = null,
    ): UniversityContractSnapshot {
        return new UniversityContractSnapshot(
            $ref, $org ?? $this->org(), PortfolioProduct::Onepage,
            $status, true, false, $rev, self::NOW - 2, self::NOW + 30,
        );
    }

    /** @param array<string,int> $revisions @param list<string> $revoked */
    private function epochs(
        array $revisions = ['synthetic-contract-1' => 2],
        array $revoked = [],
        ?OrganizationId $org = null,
        int $observed = self::NOW - 1,
        int $expires = self::NOW + 20,
    ): UniversityServiceRevisionSnapshot {
        return new UniversityServiceRevisionSnapshot(
            $org ?? $this->org(), $revisions, $revoked, $observed, $expires,
        );
    }

    /** @param list<UniversityContractSnapshot>|null $contracts */
    private function review(
        ?array $contracts,
        ?UniversityServiceRevisionSnapshot $epochs,
        ?OrganizationId $org = null,
    ): \App\Domain\ServicePortfolio\UniversityRevocationPreflightResult {
        $source = new class($contracts) implements UniversityContractAuthority {
            public function __construct(private ?array $contracts)
            {
            }
            public function currentFor(OrganizationId $organization): ?array
            {
                return $this->contracts;
            }
        };
        $revision = new class($epochs) implements UniversityServiceRevisionAuthority {
            public function __construct(private ?UniversityServiceRevisionSnapshot $epochs)
            {
            }
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot
            {
                return $this->epochs;
            }
        };
        return (new UniversityRevocationPreflight($source, $revision))
            ->inspect($org ?? $this->org(), self::NOW);
    }

    public function test_default_sources_are_unavailable_and_cannot_issue_access(): void
    {
        $review = (new UniversityRevocationPreflight())
            ->inspect($this->org(), self::NOW);
        self::assertSame('source_unavailable', $review->reason);
        self::assertFalse($review->mayIssuePortalGrant());
        self::assertFalse($review->provesContractCurrent());
        self::assertSame(0, $review->candidateCount);
    }

    public function test_matching_fresh_revisions_only_create_non_authorizing_candidate(): void
    {
        $review = $this->review([$this->service()], $this->epochs());
        self::assertSame('candidate_pending_authority', $review->reason);
        self::assertSame(1, $review->candidateCount);
        self::assertFalse($review->mayIssuePortalGrant());
        self::assertFalse($review->provesContractCurrent());

        self::assertFalse($this->epochs()->provesActiveContract());
        self::assertFalse($this->epochs()->mayAuthorizeUniversity());
    }

    public function test_cancellation_of_last_service_revokes_candidate_without_overwriting_history(): void
    {
        $active = $this->review([$this->service()], $this->epochs());
        $cancelled = $this->review([
            $this->service(status: UniversityContractStatus::Cancelled, rev: 3),
        ], $this->epochs(['synthetic-contract-1' => 3], ['synthetic-contract-1']));
        self::assertSame(1, $active->candidateCount);
        self::assertSame('no_eligible_service', $cancelled->reason);
        self::assertSame(0, $cancelled->candidateCount);
        self::assertFalse($cancelled->mayIssuePortalGrant());
    }

    public function test_a_new_revocation_revision_vetoes_stale_active_contract(): void
    {
        $oldActive = $this->service(rev: 2);
        $result = $this->review(
            [$oldActive],
            $this->epochs(['synthetic-contract-1' => 3], ['synthetic-contract-1']),
        );
        self::assertSame('contract_revision_mismatch', $result->reason);
        self::assertSame(0, $result->candidateCount);

        $sameRevisionButRevoked = $this->review(
            [$oldActive],
            $this->epochs(['synthetic-contract-1' => 2], ['synthetic-contract-1']),
        );
        self::assertSame('revoked_active_service', $sameRevisionButRevoked->reason);
    }

    public function test_matching_multiple_services_handles_independent_cancellation(): void
    {
        $a = $this->service('synthetic-contract-1', UniversityContractStatus::Active, 2);
        $b = $this->service('synthetic-contract-2', UniversityContractStatus::Cancelled, 7);
        $result = $this->review(
            [$a, $b],
            $this->epochs(['synthetic-contract-1' => 2, 'synthetic-contract-2' => 7], ['synthetic-contract-2']),
        );
        self::assertSame('candidate_pending_authority', $result->reason);
        self::assertSame(1, $result->candidateCount);
        self::assertFalse($result->mayIssuePortalGrant());
    }

    public function test_missing_extra_or_wrong_tenant_revisions_block_atomically(): void
    {
        $contract = [$this->service()];
        self::assertSame('revision_set_mismatch', $this->review(
            $contract, $this->epochs([]),
        )->reason);
        self::assertSame('revision_set_mismatch', $this->review(
            $contract, $this->epochs(['synthetic-contract-1' => 2, 'synthetic-contract-2' => 1]),
        )->reason);
        self::assertSame('wrong_revision_organization', $this->review(
            $contract, $this->epochs(org: $this->org('synthetic-organization-b')),
        )->reason);
        self::assertSame('contract_wrong_organization', $this->review(
            [$this->service(org: $this->org('synthetic-organization-b'))],
            $this->epochs(),
        )->reason);
    }

    public function test_expired_future_or_missing_revision_source_fails_closed(): void
    {
        $contract = [$this->service()];
        foreach ([
            $this->epochs(observed: self::NOW - 61),
            $this->epochs(observed: self::NOW + 1),
            $this->epochs(expires: self::NOW),
            $this->epochs(expires: self::NOW + 61),
        ] as $invalid) {
            self::assertSame('stale_revision_source', $this->review(
                $contract, $invalid,
            )->reason);
        }
        self::assertSame('source_unavailable', $this->review($contract, null)->reason);
        self::assertSame('source_unavailable', $this->review(null, $this->epochs())->reason);
    }

    public function test_malformed_revision_batches_and_authority_errors_never_grant(): void
    {
        foreach ([
            ['x' => 0],
            ['invalid ref*' => 1],
        ] as $wrong) {
            try {
                $this->epochs($wrong);
                self::fail('Invalid revisions must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $source = new class implements UniversityContractAuthority {
            public function currentFor(OrganizationId $organization): ?array
            {
                throw new RuntimeException('Synthetic source outage');
            }
        };
        $res = (new UniversityRevocationPreflight($source))
            ->inspect($this->org(), self::NOW);
        self::assertSame('source_unavailable', $res->reason);
        self::assertFalse($res->mayIssuePortalGrant());
    }

    public function test_sources_are_read_once_without_requerying_contract_state(): void
    {
        $contract = $this->service();
        $epochs = $this->epochs();

        $contracts = new class($contract) implements UniversityContractAuthority {
            public int $reads = 0;
            public function __construct(private UniversityContractSnapshot $initial)
            {
            }
            public function currentFor(OrganizationId $organization): ?array
            {
                $this->reads++;
                return $this->reads === 1 ? [$this->initial] : null;
            }
        };
        $revisions = new class($epochs) implements UniversityServiceRevisionAuthority {
            public int $reads = 0;
            public function __construct(private UniversityServiceRevisionSnapshot $initial)
            {
            }
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot
            {
                $this->reads++;
                return $this->reads === 1 ? $this->initial : null;
            }
        };

        $result = (new UniversityRevocationPreflight($contracts, $revisions))
            ->inspect($this->org(), self::NOW);
        self::assertSame('candidate_pending_authority', $result->reason);
        self::assertFalse($result->mayIssuePortalGrant());
        self::assertSame(1, $contracts->reads);
        self::assertSame(1, $revisions->reads);
    }

    public function test_independent_revocation_source_outage_fails_closed(): void
    {
        $contracts = new class($this->service()) implements UniversityContractAuthority {
            public function __construct(private UniversityContractSnapshot $record)
            {
            }
            public function currentFor(OrganizationId $organization): ?array
            {
                return [$this->record];
            }
        };
        $unavailable = new class implements UniversityServiceRevisionAuthority {
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot
            {
                throw new RuntimeException('Synthetic revocation provider failure.');
            }
        };
        $result = (new UniversityRevocationPreflight($contracts, $unavailable))
            ->inspect($this->org(), self::NOW);
        self::assertSame('source_unavailable', $result->reason);
        self::assertSame(0, $result->candidateCount);
        self::assertFalse($result->mayIssuePortalGrant());
    }

}
