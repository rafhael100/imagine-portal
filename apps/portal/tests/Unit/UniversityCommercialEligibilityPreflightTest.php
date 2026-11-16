<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\ServicePortfolio\UniversityCommercialEligibilityPreflight;
use App\Domain\ServicePortfolio\UniversityCommercialPolicyAuthority;
use App\Domain\ServicePortfolio\UniversityCommercialPolicySnapshot;
use App\Domain\ServicePortfolio\UniversityContractAuthority;
use App\Domain\ServicePortfolio\UniversityContractSnapshot;
use App\Domain\ServicePortfolio\UniversityContractStatus;
use App\Domain\ServicePortfolio\UniversityServiceCoverageAuthority;
use App\Domain\ServicePortfolio\UniversityServiceCoverageSnapshot;
use App\Domain\ServicePortfolio\UniversityServiceRevisionAuthority;
use App\Domain\ServicePortfolio\UniversityServiceRevisionSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UniversityCommercialEligibilityPreflightTest extends TestCase
{
    private const NOW = 1800000000;

    private function org(string $id = 'synthetic-company-a'): OrganizationId
    {
        return new OrganizationId($id);
    }

    private function contract(
        string $ref = 'svc-onepage-a',
        UniversityContractStatus $status = UniversityContractStatus::Active,
        int $revision = 3,
        ?OrganizationId $organization = null,
    ): UniversityContractSnapshot {
        return new UniversityContractSnapshot(
            $ref, $organization ?? $this->org(), PortfolioProduct::Onepage,
            $status, true, false, $revision, self::NOW - 5, self::NOW + 30,
        );
    }

    /** @param array<string,int> $revisions @param list<string> $revoked */
    private function revisions(
        array $revisions = ['svc-onepage-a' => 3],
        array $revoked = [],
    ): UniversityServiceRevisionSnapshot {
        return new UniversityServiceRevisionSnapshot(
            $this->org(), $revisions, $revoked, self::NOW - 5, self::NOW + 30,
        );
    }

    /** @param array<string,list<string>> $eligible */
    private function policy(
        array $eligible = ['sites_onepage' => ['sku-onepage-monthly']],
        int $revision = 7,
        int $observed = self::NOW - 5,
        int $expires = self::NOW + 30,
        int $from = self::NOW - 86400,
        int $until = self::NOW + 86400,
    ): UniversityCommercialPolicySnapshot {
        return new UniversityCommercialPolicySnapshot(
            $revision, $eligible, $from, $until, $observed, $expires,
        );
    }

    private function coverage(
        string $reference = 'svc-onepage-a',
        string $sku = 'sku-onepage-monthly',
        int $contractRevision = 3,
        int $policyRevision = 7,
        int $from = self::NOW - 3600,
        int $until = self::NOW + 3600,
        ?OrganizationId $organization = null,
    ): UniversityServiceCoverageSnapshot {
        return new UniversityServiceCoverageSnapshot(
            $reference, $organization ?? $this->org(), PortfolioProduct::Onepage,
            $sku, $contractRevision, $policyRevision,
            $from, $until, self::NOW - 5, self::NOW + 30,
        );
    }

    /** @param list<UniversityContractSnapshot>|null $contracts
     *  @param list<UniversityServiceCoverageSnapshot>|null $coverage
     */
    private function inspect(
        ?array $contracts,
        ?UniversityServiceRevisionSnapshot $revisions,
        ?UniversityCommercialPolicySnapshot $policy,
        ?array $coverage,
        ?OrganizationId $organization = null,
    ): \App\Domain\ServicePortfolio\UniversityCommercialEligibilityResult {
        $contractsSource = new class($contracts) implements UniversityContractAuthority {
            public function __construct(private ?array $result) {}
            public function currentFor(OrganizationId $organization): ?array { return $this->result; }
        };
        $revisionSource = new class($revisions) implements UniversityServiceRevisionAuthority {
            public function __construct(private ?UniversityServiceRevisionSnapshot $result) {}
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot { return $this->result; }
        };
        $policySource = new class($policy) implements UniversityCommercialPolicyAuthority {
            public function __construct(private ?UniversityCommercialPolicySnapshot $result) {}
            public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot { return $this->result; }
        };
        $coverageSource = new class($coverage) implements UniversityServiceCoverageAuthority {
            public function __construct(private ?array $result) {}
            public function currentFor(OrganizationId $organization): ?array { return $this->result; }
        };

        return (new UniversityCommercialEligibilityPreflight(
            $contractsSource, $revisionSource, $policySource, $coverageSource,
        ))->inspect($organization ?? $this->org(), self::NOW);
    }

    public function test_default_authorities_unavailable_and_never_issue_grants(): void
    {
        $decision = (new UniversityCommercialEligibilityPreflight())
            ->inspect($this->org(), self::NOW);
        self::assertSame('source_unavailable', $decision->reason);
        self::assertSame(0, $decision->candidateCount);
        self::assertFalse($decision->mayIssuePortalGrant());
        self::assertFalse($decision->provesPaidContract());
    }

    public function test_matching_four_sources_remain_strictly_non_authorizing(): void
    {
        $policy = $this->policy();
        $candidate = $this->inspect(
            [$this->contract()], $this->revisions(), $policy, [$this->coverage()],
        );
        self::assertSame('candidate_pending_official_authority', $candidate->reason);
        self::assertSame(1, $candidate->candidateCount);
        self::assertFalse($candidate->mayIssuePortalGrant());
        self::assertFalse($candidate->provesPaidContract());
        self::assertFalse($policy->mayAuthorizeUniversity());
        self::assertFalse($this->coverage()->mayAuthorizeUniversity());
    }

    public function test_unapproved_sku_and_missing_plan_fail_closed(): void
    {
        $unapproved = $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(),
            [$this->coverage(sku: 'sku-not-approved')],
        );
        self::assertSame('sku_not_approved', $unapproved->reason);
        self::assertSame(0, $unapproved->candidateCount);

        self::assertSame('missing_active_service_coverage', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(), [],
        )->reason);
        self::assertSame('sku_not_approved', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(eligible: []),
            [$this->coverage()],
        )->reason);
    }

    public function test_policy_expiry_effective_period_and_future_observation_are_rejected(): void
    {
        foreach ([
            $this->policy(observed: self::NOW - 61),
            $this->policy(observed: self::NOW + 1),
            $this->policy(expires: self::NOW),
            $this->policy(expires: self::NOW + 61),
            $this->policy(from: self::NOW + 1),
            $this->policy(until: self::NOW),
        ] as $candidate) {
            self::assertSame('stale_or_inactive_policy', $this->inspect(
                [$this->contract()], $this->revisions(), $candidate, [$this->coverage()],
            )->reason);
        }
    }

    public function test_current_month_and_revision_must_match_exactly(): void
    {
        foreach ([
            $this->coverage(contractRevision: 4),
            $this->coverage(policyRevision: 8),
        ] as $wrongRevision) {
            self::assertSame('contract_policy_revision_mismatch', $this->inspect(
                [$this->contract()], $this->revisions(), $this->policy(),
                [$wrongRevision],
            )->reason);
        }

        foreach ([
            $this->coverage(from: self::NOW + 1),
            $this->coverage(until: self::NOW),
        ] as $outOfPeriod) {
            self::assertSame('stale_or_expired_coverage', $this->inspect(
                [$this->contract()], $this->revisions(), $this->policy(),
                [$outOfPeriod],
            )->reason);
        }
    }

    public function test_foreign_tenant_and_extraneous_or_duplicate_coverage_fail_closed(): void
    {
        self::assertSame('wrong_coverage_tenant', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(),
            [$this->coverage(organization: $this->org('synthetic-company-b'))],
        )->reason);
        self::assertSame('duplicate_or_extra_coverage', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(),
            [$this->coverage(), $this->coverage()],
        )->reason);
        self::assertSame('duplicate_or_extra_coverage', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(),
            [$this->coverage(), $this->coverage(reference: 'svc-invented')],
        )->reason);
    }

    public function test_last_qualifying_service_cancelled_even_with_policy_and_coverage(): void
    {
        $decision = $this->inspect(
            [$this->contract(status: UniversityContractStatus::Cancelled, revision: 4)],
            $this->revisions(['svc-onepage-a' => 4], ['svc-onepage-a']),
            $this->policy(), [$this->coverage()],
        );
        self::assertSame('contract_no_eligible_service', $decision->reason);
        self::assertSame(0, $decision->candidateCount);
        self::assertFalse($decision->mayIssuePortalGrant());
    }

    public function test_one_of_two_services_cancelled_does_not_hide_other_covered_active_service(): void
    {
        $active = $this->contract('svc-onepage-a', UniversityContractStatus::Active, 3);
        $cancelled = $this->contract('svc-onepage-b', UniversityContractStatus::Cancelled, 9);
        $decision = $this->inspect(
            [$active, $cancelled],
            $this->revisions(
                ['svc-onepage-a' => 3, 'svc-onepage-b' => 9],
                ['svc-onepage-b'],
            ),
            $this->policy(),
            [$this->coverage()],
        );

        self::assertSame('candidate_pending_official_authority', $decision->reason);
        self::assertSame(1, $decision->candidateCount);
        self::assertFalse($decision->mayIssuePortalGrant());

        $lastCancelled = $this->inspect(
            [
                $this->contract('svc-onepage-a', UniversityContractStatus::Cancelled, 4),
                $cancelled,
            ],
            $this->revisions(
                ['svc-onepage-a' => 4, 'svc-onepage-b' => 9],
                ['svc-onepage-a', 'svc-onepage-b'],
            ),
            $this->policy(),
            [],
        );
        self::assertSame('contract_no_eligible_service', $lastCancelled->reason);
        self::assertSame(0, $lastCancelled->candidateCount);
    }

    public function test_trial_suspended_and_unapproved_billing_status_cannot_qualify(): void
    {
        foreach ([
            UniversityContractStatus::Trial,
            UniversityContractStatus::Suspended,
            UniversityContractStatus::Preparing,
        ] as $status) {
            $decision = $this->inspect(
                [$this->contract(status: $status)],
                $this->revisions(),
                $this->policy(),
                [$this->coverage()],
            );
            self::assertSame('contract_no_eligible_service', $decision->reason);
        }

        $trialOnly = new UniversityContractSnapshot(
            'svc-onepage-a', $this->org(), PortfolioProduct::Onepage,
            UniversityContractStatus::Active, true, true, 3,
            self::NOW - 5, self::NOW + 30,
        );
        self::assertSame('contract_no_eligible_service', $this->inspect(
            [$trialOnly], $this->revisions(), $this->policy(), [$this->coverage()],
        )->reason);
    }

    public function test_product_mismatch_is_denied_even_when_the_sku_is_listed(): void
    {
        $coverage = new UniversityServiceCoverageSnapshot(
            'svc-onepage-a', $this->org(), PortfolioProduct::PerformanceAI,
            'perf-approved-sku', 3, 7, self::NOW - 3600,
            self::NOW + 3600, self::NOW - 5, self::NOW + 30,
        );
        $policy = $this->policy([
            'sites_onepage' => ['sku-onepage-monthly'],
            'performance_ai' => ['perf-approved-sku'],
        ]);
        self::assertSame('contract_policy_revision_mismatch', $this->inspect(
            [$this->contract()], $this->revisions(), $policy, [$coverage],
        )->reason);
    }

    public function test_independent_revocation_revision_mismatch_preempts_policy(): void
    {
        $decision = $this->inspect(
            [$this->contract()], $this->revisions(['svc-onepage-a' => 4], ['svc-onepage-a']),
            $this->policy(), [$this->coverage()],
        );
        self::assertSame('contract_contract_revision_mismatch', $decision->reason);
        self::assertSame(0, $decision->candidateCount);
    }

    public function test_unavailable_source_and_tenant_length_mismatch_are_blocked(): void
    {
        self::assertSame('source_unavailable', $this->inspect(
            [$this->contract()], $this->revisions(), null, [$this->coverage()],
        )->reason);
        self::assertSame('source_unavailable', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(), null,
        )->reason);

        $overlongPortalTenant = $this->org(str_repeat('a', 81));
        self::assertSame('unsupported_tenant_identifier', $this->inspect(
            [$this->contract()], $this->revisions(), $this->policy(), [$this->coverage()],
            $overlongPortalTenant,
        )->reason);
    }

    public function test_malformed_policy_product_and_duplicate_sku_are_rejected(): void
    {
        foreach ([
            ['universidade_imagine' => ['forbidden-benefit-sku']],
            ['bogus_product' => ['bogus-plan']],
            ['sites_onepage' => ['duplicated', 'duplicated']],
            ['sites_onepage' => ['UppercaseSku']],
        ] as $wrongPolicy) {
            try {
                $this->policy($wrongPolicy);
                self::fail('Malformed policy was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_sources_expiring_by_end_of_read_are_not_treated_as_current(): void
    {
        // A server can pass start-of-read time() immediately before a tick.
        // End-of-read must conservatively advance that epoch for TTL checks.
        foreach ([
            $this->policy(expires: self::NOW + 1),
            $this->policy(until: self::NOW + 1),
        ] as $expiringPolicy) {
            self::assertSame('stale_or_inactive_policy', $this->inspect(
                [$this->contract()], $this->revisions(), $expiringPolicy,
                [$this->coverage()],
            )->reason);
        }

        $expiringRevocation = new UniversityServiceRevisionSnapshot(
            $this->org(), ['svc-onepage-a' => 3], [],
            self::NOW - 5, self::NOW + 1,
        );
        self::assertSame('revocation_expired_during_read', $this->inspect(
            [$this->contract()], $expiringRevocation, $this->policy(),
            [$this->coverage()],
        )->reason);

        $expiringContract = new UniversityContractSnapshot(
            'svc-onepage-a', $this->org(), PortfolioProduct::Onepage,
            UniversityContractStatus::Active, true, false, 3,
            self::NOW - 5, self::NOW + 1,
        );
        self::assertSame('contract_expired_during_read', $this->inspect(
            [$expiringContract], $this->revisions(), $this->policy(),
            [$this->coverage()],
        )->reason);

        foreach ([
            new UniversityServiceCoverageSnapshot(
                'svc-onepage-a', $this->org(), PortfolioProduct::Onepage,
                'sku-onepage-monthly', 3, 7, self::NOW - 3600,
                self::NOW + 3600, self::NOW - 5, self::NOW + 1,
            ),
            $this->coverage(until: self::NOW + 1),
        ] as $expiringCoverage) {
            self::assertSame('stale_or_expired_coverage', $this->inspect(
                [$this->contract()], $this->revisions(), $this->policy(),
                [$expiringCoverage],
            )->reason);
        }
    }

    public function test_slow_coverage_source_fails_closed_even_when_snapshots_look_fresh(): void
    {
        $contracts = new class($this->contract()) implements UniversityContractAuthority {
            public function __construct(private UniversityContractSnapshot $record) {}
            public function currentFor(OrganizationId $organization): ?array { return [$this->record]; }
        };
        $revisions = new class($this->revisions()) implements UniversityServiceRevisionAuthority {
            public function __construct(private UniversityServiceRevisionSnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot { return $this->data; }
        };
        $policy = new class($this->policy()) implements UniversityCommercialPolicyAuthority {
            public function __construct(private UniversityCommercialPolicySnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot { return $this->data; }
        };
        $coverage = new class($this->coverage()) implements UniversityServiceCoverageAuthority {
            public function __construct(private UniversityServiceCoverageSnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?array {
                usleep(20_000);
                return [$this->data];
            }
        };

        $result = (new UniversityCommercialEligibilityPreflight(
            $contracts, $revisions, $policy, $coverage, 2,
        ))->inspect($this->org(), self::NOW);
        self::assertSame('source_read_timeout', $result->reason);
        self::assertSame(0, $result->candidateCount);
        self::assertFalse($result->mayIssuePortalGrant());
    }

    public function test_invalid_time_budget_is_not_accepted(): void
    {
        foreach ([0, -1, 5001] as $budget) {
            try {
                new UniversityCommercialEligibilityPreflight(maxSourceReadMs: $budget);
                self::fail('Invalid source latency budget was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_independent_outages_in_all_four_sources_never_produce_candidates(): void
    {
        $records = new class($this->contract()) implements UniversityContractAuthority {
            public bool $unavailable = false;
            public function __construct(private UniversityContractSnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?array {
                if ($this->unavailable) {
                    throw new RuntimeException('contracts offline');
                }
                return [$this->data];
            }
        };
        $revocations = new class($this->revisions()) implements UniversityServiceRevisionAuthority {
            public bool $unavailable = false;
            public function __construct(private UniversityServiceRevisionSnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot {
                if ($this->unavailable) {
                    throw new RuntimeException('revisions offline');
                }
                return $this->data;
            }
        };
        $policy = new class($this->policy()) implements UniversityCommercialPolicyAuthority {
            public bool $unavailable = false;
            public function __construct(private UniversityCommercialPolicySnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot {
                if ($this->unavailable) {
                    throw new RuntimeException('policy offline');
                }
                return $this->data;
            }
        };
        $coverage = new class($this->coverage()) implements UniversityServiceCoverageAuthority {
            public bool $unavailable = false;
            public function __construct(private UniversityServiceCoverageSnapshot $data) {}
            public function currentFor(OrganizationId $organization): ?array {
                if ($this->unavailable) {
                    throw new RuntimeException('coverage offline');
                }
                return [$this->data];
            }
        };

        foreach ([$records, $revocations, $policy, $coverage] as $source) {
            $source->unavailable = true;
            $result = (new UniversityCommercialEligibilityPreflight(
                $records, $revocations, $policy, $coverage,
            ))->inspect($this->org(), self::NOW);
            self::assertSame('source_unavailable', $result->reason);
            self::assertFalse($result->mayIssuePortalGrant());
            $source->unavailable = false;
        }

        $healthy = (new UniversityCommercialEligibilityPreflight(
            $records, $revocations, $policy, $coverage,
        ))->inspect($this->org(), self::NOW);
        self::assertSame('candidate_pending_official_authority', $healthy->reason);
        self::assertFalse($healthy->mayIssuePortalGrant());
    }

    public function test_sources_are_read_once_and_outages_never_produce_candidates(): void
    {
        $goodContracts = [$this->contract()];
        $goodRevision = $this->revisions();
        $goodPolicy = $this->policy();
        $goodCoverage = [$this->coverage()];

        $contracts = new class($goodContracts) implements UniversityContractAuthority {
            public int $calls = 0;
            public function __construct(private array $first) {}
            public function currentFor(OrganizationId $organization): ?array {
                return ++$this->calls === 1 ? $this->first : null;
            }
        };
        $revisions = new class($goodRevision) implements UniversityServiceRevisionAuthority {
            public int $calls = 0;
            public function __construct(private UniversityServiceRevisionSnapshot $first) {}
            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot {
                return ++$this->calls === 1 ? $this->first : null;
            }
        };
        $policy = new class($goodPolicy) implements UniversityCommercialPolicyAuthority {
            public int $calls = 0;
            public function __construct(private UniversityCommercialPolicySnapshot $first) {}
            public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot {
                return ++$this->calls === 1 ? $this->first : null;
            }
        };
        $coverage = new class($goodCoverage) implements UniversityServiceCoverageAuthority {
            public int $calls = 0;
            public function __construct(private array $first) {}
            public function currentFor(OrganizationId $organization): ?array {
                return ++$this->calls === 1 ? $this->first : null;
            }
        };
        $decision = (new UniversityCommercialEligibilityPreflight(
            $contracts, $revisions, $policy, $coverage
        ))->inspect($this->org(), self::NOW);
        self::assertSame('candidate_pending_official_authority', $decision->reason);
        self::assertSame(1, $contracts->calls);
        self::assertSame(1, $revisions->calls);
        self::assertSame(1, $policy->calls);
        self::assertSame(1, $coverage->calls);

        $outage = new class implements UniversityCommercialPolicyAuthority {
            public function currentFor(OrganizationId $organization): ?UniversityCommercialPolicySnapshot {
                throw new RuntimeException('Synthetic commercial policy provider outage');
            }
        };
        self::assertSame('source_unavailable', (new UniversityCommercialEligibilityPreflight(
            $contracts, $revisions, $outage, $coverage,
        ))->inspect($this->org(), self::NOW)->reason);
    }
}
