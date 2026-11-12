<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;
use App\Domain\Organizations\OrganizationId;
use App\Presentation\DemoContractOverview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class DemoContractOverviewTest extends TestCase
{
    public function test_three_independent_demo_service_contracts_have_no_commercial_effect(): void
    {
        $items = DemoContractOverview::records();
        self::assertCount(3, $items);
        self::assertSame(
            ['DEMO-CTR-ONEPAGE', 'DEMO-CTR-LANDING', 'DEMO-CTR-PERFORMANCE'],
            array_column($items, 'reference'),
        );
        self::assertSame(
            ['DEMO-SVC-ONEPAGE', 'DEMO-SVC-LANDING', 'DEMO-SVC-PERFORMANCE'],
            array_column($items, 'serviceReference'),
        );
        self::assertCount(3, array_unique(array_column($items, 'serviceReference')));
        foreach ($items as $item) {
            self::assertSame('DEMO-ORG-AURORA', $item->organizationId->value);
            self::assertSame('Não realizada', $item->signatureLabel());
            self::assertFalse($item->isLegallyBinding());
            self::assertFalse($item->grantsServiceAccess());
            self::assertFalse($item->createsPaymentObligation());
        }
    }

    public function test_status_values_cannot_contain_paid_signed_or_active(): void
    {
        self::assertCount(3, DemoContractStage::cases());
        foreach (['signed', 'paid', 'active'] as $status) {
            self::assertNull(DemoContractStage::tryFrom($status));
        }
        self::assertSame('Revisão fictícia', DemoContractStage::UnderReview->label());
        self::assertTrue((new \ReflectionClass(DemoContractRecord::class))->isReadOnly());
    }

    public function test_demo_contract_preview_is_deterministic_and_never_signed(): void
    {
        $rows = DemoContractOverview::rows();
        self::assertSame($rows, DemoContractOverview::rows());
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertCount(4, $row);
            self::assertStringStartsWith('DEMO-CTR-', $row[0]);
            self::assertSame('Não realizada', $row[3]);
        }
    }

    public function test_non_demo_references_are_refused(): void
    {
        foreach (['', '../secret', 'customer-123', 'DEMO-CTR-'] as $bad) {
            try {
                new DemoContractRecord($bad, new OrganizationId('DEMO-ORG-AURORA'),
                    'DEMO-SVC-X', 'Exemplo', DemoContractStage::Draft);
                self::fail('Accepted non-fictional contract reference.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            try {
                new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG-AURORA'),
                    $bad, 'Exemplo', DemoContractStage::Draft);
                self::fail('Accepted non-fictional service reference.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_or_oversized_descriptions_are_refused(): void
    {
        foreach ([' ', str_repeat('X', 161)] as $bad) {
            $this->expectInvalidDescription($bad);
        }
    }

    private function expectInvalidDescription(string $label): void
    {
        try {
            new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG-AURORA'),
                'DEMO-SVC-X', $label, DemoContractStage::Draft);
            self::fail('Invalid description accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_status_cannot_be_supplied_as_untyped_payment_text(): void
    {
        $this->expectException(TypeError::class);
        new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG-AURORA'),
            'DEMO-SVC-X', 'Exemplo', 'signed');
    }
}
