<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\BenefitServiceFixture;
use App\Domain\ServicePortfolio\BenefitServiceState as State;
use App\Domain\ServicePortfolio\PortfolioProduct as Product;
use App\Domain\ServicePortfolio\UniversityBenefitPreviewPolicy;
use App\Domain\ServicePortfolio\UniversityBenefitPreviewResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UniversityBenefitPreviewPolicyTest extends TestCase
{
    private function fixture(
        string $reference = 'DEMO-SVC-ONEPAGE',
        string $organization = 'DEMO-ORG-A',
        Product $product = Product::Onepage,
        State $status = State::ActiveIllustration,
        bool $markedEligible = true,
    ): BenefitServiceFixture {
        return new BenefitServiceFixture(
            $reference, new OrganizationId($organization),
            $product, $status, $markedEligible,
        );
    }

    private function inspect(array $fixtures, ?OrganizationId $organization = null): UniversityBenefitPreviewResult
    {
        return (new UniversityBenefitPreviewPolicy())->inspect(
            $organization ?? new OrganizationId('DEMO-ORG-A'), $fixtures,
        );
    }

    public function test_hypothetical_active_eligible_service_does_not_grant_university_access(): void
    {
        $result = $this->inspect([$this->fixture()]);
        self::assertSame('illustrative_candidate', $result->reason);
        self::assertSame(1, $result->illustrativeCandidateCount);
        self::assertTrue($result->showsHypotheticalCandidate());
        self::assertFalse($result->mayGrantUniversityAccess());
        self::assertFalse($result->provesCustomerEligibility());
        self::assertFalse($result->authorizesStandalonePurchase());
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($this->fixture()->provesRealEligibility());
        self::assertTrue((new \ReflectionClass(UniversityBenefitPreviewResult::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(BenefitServiceFixture::class))->isReadOnly());
    }

    public function test_none_active_or_none_flagged_eligible_never_becomes_candidate(): void
    {
        foreach ([State::Preparing, State::Suspended, State::Cancelled] as $state) {
            $result = $this->inspect([$this->fixture(status: $state)]);
            self::assertSame('no_illustrative_candidate', $result->reason);
            self::assertSame(0, $result->illustrativeCandidateCount);
            self::assertFalse($result->showsHypotheticalCandidate());
        }
        $withoutEligibility = $this->inspect([$this->fixture(markedEligible: false)]);
        self::assertFalse($withoutEligibility->showsHypotheticalCandidate());
        self::assertFalse($withoutEligibility->mayGrantUniversityAccess());
        self::assertSame(0, $this->inspect([])->illustrativeCandidateCount);
    }

    public function test_suspending_last_candidate_drops_hypothetical_count(): void
    {
        $first = $this->fixture();
        $second = $this->fixture(
            'DEMO-SVC-LANDING', product: Product::LandingPage,
            status: State::Preparing,
        );
        self::assertSame(1, $this->inspect([$first, $second])->illustrativeCandidateCount);
        $suspended = $this->fixture(status: State::Suspended);
        self::assertSame(0, $this->inspect([$suspended, $second])->illustrativeCandidateCount);
        self::assertFalse($this->inspect([$suspended, $second])->mayGrantUniversityAccess());
    }

    public function test_cross_organization_data_causes_atomic_rejection_without_partial_counts(): void
    {
        $valid = $this->fixture();
        $foreign = $this->fixture('DEMO-SVC-CRM', 'DEMO-ORG-B', Product::ImagineCRM);
        $result = $this->inspect([$valid, $foreign]);
        self::assertSame('foreign_organization', $result->reason);
        self::assertSame(0, $result->illustrativeCandidateCount);
        self::assertFalse($result->showsHypotheticalCandidate());
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($result->mayGrantUniversityAccess());
    }

    public function test_duplicate_references_and_invalid_inputs_are_rejected(): void
    {
        $fixture = $this->fixture();
        $duplicate = $this->fixture(status: State::Preparing);
        self::assertSame('duplicate_reference', $this->inspect([$fixture, $duplicate])->reason);
        foreach ([[new \stdClass()], ['key' => $fixture], [null], array_fill(0, 51, $fixture)] as $invalid) {
            $result = $this->inspect($invalid);
            self::assertSame('invalid_fixture_input', $result->reason);
            self::assertSame(0, $result->illustrativeCandidateCount);
        }
        $missing = (new UniversityBenefitPreviewPolicy())->inspect(null, [$fixture]);
        self::assertSame('missing_organization', $missing->reason);
        self::assertFalse($missing->mayGrantUniversityAccess());
    }

    public function test_university_cannot_be_its_own_qualifying_subscription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fixture(product: Product::UniversidadeImagine);
    }

    public function test_non_fictional_ids_or_invalid_result_reasons_are_refused(): void
    {
        foreach (['client-123', '../abc', 'DEMO-SVC-', ''] as $ref) {
            try {
                $this->fixture(reference: $ref);
                self::fail('A non-demonstration service ID was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        UniversityBenefitPreviewResult::blocked('paid_active');
    }

    public function test_all_individual_product_codes_are_not_assumed_eligible_without_marked_fixture(): void
    {
        $fixtures = [];
        foreach ([Product::Onepage, Product::LandingPage, Product::PerformanceAI, Product::ImagineCRM] as $index => $product) {
            $fixtures[] = $this->fixture(
                'DEMO-SVC-'.($index + 1), product: $product,
                status: State::ActiveIllustration, markedEligible: false,
            );
        }
        $result = $this->inspect($fixtures);
        self::assertFalse($result->showsHypotheticalCandidate());
        self::assertSame(0, $result->illustrativeCandidateCount);
        self::assertFalse($result->provesCustomerEligibility());
    }
}
