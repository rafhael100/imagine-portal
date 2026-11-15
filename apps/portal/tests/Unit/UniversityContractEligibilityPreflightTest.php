<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\ServicePortfolio\UniversityContractAuthority;
use App\Domain\ServicePortfolio\UniversityContractSnapshot;
use App\Domain\ServicePortfolio\UniversityContractStatus;
use App\Domain\ServicePortfolio\UniversityEligibilityPreflight;
use App\Domain\ServicePortfolio\UniversityEligibilityPreflightResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UniversityContractEligibilityPreflightTest extends TestCase
{
    private const NOW = 1800000000;

    private function company(string $id = 'synthetic-company-a'): OrganizationId
    {
        return new OrganizationId($id);
    }

    private function contract(
        string $reference = 'synthetic-service-1',
        ?OrganizationId $company = null,
        UniversityContractStatus $status = UniversityContractStatus::Active,
        bool $eligible = true,
        bool $trial = false,
        int $observed = self::NOW - 10,
        int $expires = self::NOW + 30,
    ): UniversityContractSnapshot {
        return new UniversityContractSnapshot(
            serviceReference: $reference,
            organizationId: $company ?? $this->company(),
            product: PortfolioProduct::Onepage,
            status: $status,
            eligibleUnderContract: $eligible,
            trialOnly: $trial,
            sourceRevision: 1,
            observedAtEpoch: $observed,
            expiresAtEpoch: $expires,
        );
    }

    /** @param array<mixed>|null $entries */
    private function inspect(?array $entries, ?OrganizationId $company = null): UniversityEligibilityPreflightResult
    {
        $authority = new class($entries) implements UniversityContractAuthority {
            public function __construct(private ?array $entries)
            {
            }

            public function currentFor(OrganizationId $organization): ?array
            {
                return $this->entries;
            }
        };

        return (new UniversityEligibilityPreflight($authority))
            ->inspect($company ?? $this->company(), self::NOW);
    }

    public function test_default_authority_fails_closed_without_querying_the_portal(): void
    {
        $decision = (new UniversityEligibilityPreflight())
            ->inspect($this->company(), self::NOW);

        self::assertSame('source_unavailable', $decision->reason);
        self::assertSame(0, $decision->candidateCount);
        self::assertFalse($decision->mayIssueUniversityGrant());
        self::assertFalse($decision->provesRealEligibility());
        self::assertFalse($decision->mayExposeCustomerData());
    }

    public function test_hypothetical_active_eligible_service_is_not_a_real_grant(): void
    {
        $result = $this->inspect([$this->contract()]);
        self::assertSame('contract_candidate', $result->reason);
        self::assertSame(1, $result->candidateCount);
        self::assertTrue($result->hasCandidate());
        self::assertFalse($result->mayIssueUniversityGrant());
        self::assertFalse($result->provesRealEligibility());
        self::assertFalse($result->mayExposeCustomerData());
        self::assertTrue((new \ReflectionClass(UniversityContractSnapshot::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(UniversityEligibilityPreflightResult::class))->isReadOnly());
    }

    public function test_no_active_eligible_service_revokes_candidate_and_preserves_deny(): void
    {
        foreach ([
            UniversityContractStatus::Preparing,
            UniversityContractStatus::Trial,
            UniversityContractStatus::Suspended,
            UniversityContractStatus::Cancelled,
        ] as $state) {
            $result = $this->inspect([$this->contract(status: $state)]);
            self::assertSame('no_eligible_service', $result->reason);
            self::assertSame(0, $result->candidateCount);
            self::assertFalse($result->mayIssueUniversityGrant());
        }
        foreach ([
            $this->contract(eligible: false),
            $this->contract(trial: true),
        ] as $excluded) {
            self::assertFalse($this->inspect([$excluded])->hasCandidate());
        }
        self::assertSame('no_eligible_service', $this->inspect([])->reason);
    }

    public function test_last_active_service_cancelled_stops_candidacy_immediately(): void
    {
        $active = $this->contract();
        $cancelled = $this->contract(status: UniversityContractStatus::Cancelled);
        $otherIneligible = $this->contract('synthetic-service-2', eligible: false);
        self::assertSame(1, $this->inspect([$active, $otherIneligible])->candidateCount);
        $result = $this->inspect([$cancelled, $otherIneligible]);
        self::assertSame('no_eligible_service', $result->reason);
        self::assertFalse($result->hasCandidate());
    }

    public function test_mixed_company_is_atomically_rejected_without_partial_count(): void
    {
        $foreign = $this->contract('synthetic-service-2', $this->company('synthetic-company-b'));
        $result = $this->inspect([$this->contract(), $foreign]);
        self::assertSame('wrong_organization', $result->reason);
        self::assertSame(0, $result->candidateCount);
        self::assertFalse($result->mayIssueUniversityGrant());
    }

    public function test_duplicate_malformed_or_oversized_sources_are_rejected(): void
    {
        $valid = $this->contract();
        self::assertSame('duplicate_service', $this->inspect([$valid, $valid])->reason);
        self::assertSame('invalid_source', $this->inspect(['bad-key' => $valid])->reason);
        self::assertSame('invalid_source', $this->inspect([new \stdClass()])->reason);
        self::assertSame('invalid_source', $this->inspect(array_fill(0, 51, $valid))->reason);
        self::assertSame('source_unavailable', $this->inspect(null)->reason);

        $throwing = new class implements UniversityContractAuthority {
            public function currentFor(OrganizationId $organization): ?array
            {
                throw new RuntimeException('synthetic provider outage');
            }
        };
        $result = (new UniversityEligibilityPreflight($throwing))
            ->inspect($this->company(), self::NOW);
        self::assertSame('source_unavailable', $result->reason);
        self::assertFalse($result->hasCandidate());
    }

    public function test_stale_expired_future_and_overlong_snapshots_fail_closed(): void
    {
        foreach ([
            $this->contract(observed: self::NOW - 61),
            $this->contract(observed: self::NOW + 1),
            $this->contract(expires: self::NOW),
            $this->contract(expires: self::NOW + 61),
        ] as $stale) {
            $result = $this->inspect([$this->contract('synthetic-service-2'), $stale]);
            self::assertSame('stale_source', $result->reason);
            self::assertSame(0, $result->candidateCount);
        }

        self::assertSame(1, $this->inspect([
            $this->contract(observed: self::NOW - 60, expires: self::NOW + 60),
        ])->candidateCount);
    }

    public function test_invalid_context_or_snapshot_cannot_fabricate_eligibility(): void
    {
        self::assertSame('invalid_context', (new UniversityEligibilityPreflight())
            ->inspect(null, self::NOW)->reason);
        self::assertSame('invalid_context', (new UniversityEligibilityPreflight())
            ->inspect($this->company(), 0)->reason);

        $this->expectException(InvalidArgumentException::class);
        new UniversityContractSnapshot(
            'synthetic-service-1', $this->company(), PortfolioProduct::UniversidadeImagine,
            UniversityContractStatus::Active, true, false, 1, self::NOW - 1, self::NOW + 1,
        );
    }
}
