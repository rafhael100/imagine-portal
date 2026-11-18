<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\PortalSitesPendingReceiptValidator;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Strict semantic and type parity with Sites project-intake receipt v1. */
final class PortalSitesPendingReceiptValidatorTest extends TestCase
{
    private const ORDER = '77f5a17b-4c82-468e-83ab-75d2b470aef1';
    private const SITE = '80a36fb4-ff36-424e-acb7-2e4ae1d4df1f';

    private function command(): array
    {
        return [
            'schemaVersion'=>'1',
            'operationId'=>'operation-'.self::ORDER,
            'tenantRef'=>'company-original-ref',
            'orderRef'=>self::ORDER,
            'contractRef'=>'onepage-contract-2026-v1',
            'contractRevision'=>2,
            'productCode'=>'onepage',
            'idempotencyKey'=>'idem-'.self::ORDER,
        ];
    }

    private function receipt(array $command): array
    {
        return [
            'schemaVersion'=>'sites.project-intake.receipt.v1',
            'tenantRef'=>$command['tenantRef'],
            'orderRef'=>$command['orderRef'],
            'contractRef'=>$command['contractRef'],
            'contractRevision'=>$command['contractRevision'],
            'productCode'=>$command['productCode'],
            'operationId'=>$command['operationId'],
            'idempotencyKey'=>$command['idempotencyKey'],
            'siteRef'=>self::SITE,
            'commandDigest'=>hash('sha256',json_encode([
                $command['schemaVersion'],$command['tenantRef'],$command['orderRef'],
                $command['contractRef'],$command['contractRevision'],$command['productCode'],
            ],JSON_THROW_ON_ERROR)),
            'status'=>'recorded',
            'state'=>'awaiting_official_authority',
            'authorityVerified'=>false,
            'clientAccepted'=>false,
            'canPublish'=>false,
        ];
    }

    public function test_exact_pending_receipt_returns_same_wire_data_without_claiming_any_other_authority(): void
    {
        $wire=$this->command();$receipt=$this->receipt($wire);
        self::assertSame($receipt,PortalSitesPendingReceiptValidator::validate($wire,$receipt));
        self::assertSame($receipt,PortalSitesPendingReceiptValidator::validate($wire,$receipt));
        self::assertFalse($receipt['authorityVerified']);
        self::assertFalse($receipt['clientAccepted']);
        self::assertFalse($receipt['canPublish']);
    }

    public function test_rejects_every_receipt_selector_tampering_type_coercion_and_authority_escalation(): void
    {
        $wire=$this->command();$receipt=$this->receipt($wire);
        $tampered = [
            ['schemaVersion'=>'sites.project-intake.receipt.v2'],['tenantRef'=>'other-company'],
            ['orderRef'=>'other-order'],['contractRef'=>'other-contract'],['contractRevision'=>'2'],
            ['contractRevision'=>3],['productCode'=>'performance'],['operationId'=>'other-operation'],
            ['idempotencyKey'=>'another-key'],['siteRef'=>$wire['orderRef']],
            ['siteRef'=>'00000000-0000-0000-0000-000000000000'],
            ['siteRef'=>'not-a-uuid'],['commandDigest'=>str_repeat('0',64)],
            ['status'=>'processed'],['state'=>'production'],
            ['authorityVerified'=>true],['clientAccepted'=>true],['canPublish'=>true],
            ['paid'=>true], ['published'=>true], ['paymentStatus'=>'RECEIVED'],
        ];
        foreach ($tampered as $difference) {
            try {
                PortalSitesPendingReceiptValidator::validate($wire,array_replace($receipt,$difference));
                self::fail('A changed receipt must be refused.');
            } catch (DomainException $error) {
                self::assertSame('Unverified pending Sites intake receipt.',$error->getMessage());
                self::assertNull($error->getPrevious());
            }
        }
        foreach (array_keys($receipt) as $key) {
            $missing=$receipt;unset($missing[$key]);
            $this->expectInvalid($wire,$missing);
        }
    }

    public function test_rejects_bogus_commands_and_stale_command_context(): void
    {
        $wire=$this->command();$receipt=$this->receipt($wire);
        $invalid=[
            ['schemaVersion'=>'2'],['contractRevision'=>'2'],['contractRevision'=>0],
            ['productCode'=>'crm'],['tenantRef'=>'forged company'],
            ['orderRef'=>str_repeat('q',200)],['operationId'=>''],['idempotencyKey'=>'?'],
            ['other_field'=>'forged'],
        ];
        foreach ($invalid as $difference) {
            $this->expectInvalid(array_replace($wire,$difference),$receipt);
        }
        $missing=$wire;unset($missing['tenantRef']);$this->expectInvalid($missing,$receipt);
        $otherOrder=array_replace($wire,['orderRef'=>'another-order']);
        $this->expectInvalid($otherOrder,$receipt);
    }

    private function expectInvalid(array $wire,array $receipt): void
    {
        try {
            PortalSitesPendingReceiptValidator::validate($wire,$receipt);
            self::fail('Invalid command/receipt accepted.');
        } catch (DomainException $error) {
            self::assertSame('Unverified pending Sites intake receipt.',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
