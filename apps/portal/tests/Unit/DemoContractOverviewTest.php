<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Contracts\DemoContractRecord;
use App\Domain\Contracts\DemoContractStage;
use App\Domain\Organizations\OrganizationId;
use App\Presentation\DemoContractOverview;
use App\Presentation\DemoRequestInbox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class DemoContractOverviewTest extends TestCase
{
    public function test_all_demo_contracts_are_separate_unbound_and_have_no_payment_or_access_effect(): void
    {
        $contracts = DemoContractOverview::records();
        self::assertCount(3, $contracts);
        self::assertSame([
            'DEMO-CTR-ONEPAGE', 'DEMO-CTR-LANDING', 'DEMO-CTR-PERFORMANCE',
        ], array_column($contracts, 'reference'));
        self::assertSame([
            'DEMO-SVC-ONEPAGE', 'DEMO-SVC-LANDING', 'DEMO-SVC-PERFORMANCE',
        ], array_column($contracts, 'serviceReference'));
        self::assertCount(3, array_unique(array_column($contracts, 'serviceReference')));
        foreach ($contracts as $contract) {
            self::assertTrue(DemoRequestInbox::organization()->equals($contract->organizationId));
            self::assertSame('Não realizada', $contract->signatureLabel());
            self::assertFalse($contract->isLegallyBinding());
            self::assertFalse($contract->grantsServiceAccess());
            self::assertFalse($contract->createsPaymentObligation());
        }
    }

    public function test_each_demo_stage_is_explicitly_not_signed_paid_or_activated(): void
    {
        self::assertSame(3, count(DemoContractStage::cases()));
        self::assertNull(DemoContractStage::tryFrom('signed'));
        self::assertNull(DemoContractStage::tryFrom('paid'));
        self::assertNull(DemoContractStage::tryFrom('active'));
        self::assertSame('Revisão fictícia', DemoContractStage::UnderReview->label());
    }

    public function test_contracts_are_immutable_and_rows_are_deterministic(): void
    {
        self::assertTrue((new \ReflectionClass(DemoContractRecord::class))->isReadOnly());
        self::assertEquals(DemoContractOverview::rows(), DemoContractOverview::rows());
        $rows = DemoContractOverview::rows();
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertCount(4, $row);
            self::assertStringStartsWith('DEMO-CTR-', $row[0]);
            self::assertSame('Não realizada', $row[3]);
        }
    }

    public function test_untrusted_or_real_references_are_rejected(): void
    {
        foreach (['', 'client-123', '../hidden', 'DEMO-CTR-'] as $value) {
            try {
                new DemoContractRecord($value, new OrganizationId('DEMO-ORG'),
                    'DEMO-SVC-X', 'Exemplo', DemoContractStage::Draft);
                self::fail('Non fictional reference accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            try {
                new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG'),
                    $value, 'Exemplo', DemoContractStage::Draft);
                self::fail('Non fictional service accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_missing_or_oversized_descriptions_are_rejected(): void
    {
        foreach ([' ', str_repeat('X', 161)] as $label) {
            $this->expectException(InvalidArgumentException::class);
            new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG'),
                'DEMO-SVC-X', $label, DemoContractStage::Draft);
        }
    }

    public function test_status_must_be_typed_and_cannot_be_supplied_as_a_payment_string(): void
    {
        $this->expectException(TypeError::class);
        new DemoContractRecord('DEMO-CTR-X', new OrganizationId('DEMO-ORG'),
            'DEMO-SVC-X', 'Exemplo', 'signed');
    }
}
