<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SiteIntake\SiteEngineCreateProjectCandidateValidator as Validator;
use PHPUnit\Framework\TestCase;

final class SiteEngineCreateProjectCandidateValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function candidate(): array
    {
        return [
            'schemaVersion' => '1',
            'operationId' => 'op.demo-1',
            'tenantRef' => 'tenant.demo-1',
            'orderRef' => 'order.demo-1',
            'contractRef' => 'contract.demo-1',
            'contractRevision' => 1,
            'productCode' => 'onepage',
            'idempotencyKey' => 'idempotency.demo-1',
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function inspect(array $payload): array
    {
        return Validator::inspect(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_valid_synthetic_onepage_and_landing_page_only_pass_shape_check(): void
    {
        $candidate = self::candidate();
        self::assertSame(['valid' => true, 'reason' => 'candidate_shape_valid_only'], self::inspect($candidate));
        $candidate['productCode'] = 'landing_page';
        self::assertTrue(self::inspect($candidate)['valid']);
    }

    public function test_rejects_missing_and_extra_properties(): void
    {
        $candidate = self::candidate();
        unset($candidate['tenantRef']);
        self::assertSame('unexpected_fields', self::inspect($candidate)['reason']);
        $candidate = self::candidate();
        $candidate['authenticated'] = true;
        self::assertSame('unexpected_fields', self::inspect($candidate)['reason']);
    }

    public function test_rejects_wrong_version_and_noncanonical_product_code(): void
    {
        $candidate = self::candidate();
        $candidate['schemaVersion'] = 1;
        self::assertSame('invalid_schema_version', self::inspect($candidate)['reason']);
        $candidate = self::candidate();
        $candidate['productCode'] = 'sites_onepage';
        self::assertSame('invalid_product_code', self::inspect($candidate)['reason']);
    }

    public function test_rejects_invalid_reference_syntax_and_length(): void
    {
        foreach (['', '_bad', 'bad space', 'bad/segment', str_repeat('a', 129), 123] as $invalid) {
            $candidate = self::candidate();
            $candidate['tenantRef'] = $invalid;
            self::assertSame('invalid_reference', self::inspect($candidate)['reason']);
        }
        $candidate = self::candidate();
        $candidate['operationId'] = 'A._:-09';
        self::assertTrue(self::inspect($candidate)['valid']);
    }

    public function test_requires_positive_integer_revision_not_boolean_or_float(): void
    {
        foreach ([0, -1, '1', 1.2, true, null] as $invalid) {
            $candidate = self::candidate();
            $candidate['contractRevision'] = $invalid;
            self::assertSame('invalid_contract_revision', self::inspect($candidate)['reason']);
        }
    }

    public function test_rejects_malformed_nonobject_and_oversize_payload(): void
    {
        self::assertSame('malformed_json', Validator::inspect('{')['reason']);
        self::assertSame('not_object', Validator::inspect('[]')['reason']);
        self::assertSame('not_object', Validator::inspect('null')['reason']);
        self::assertSame('invalid_payload_size', Validator::inspect('')['reason']);
        self::assertSame('invalid_payload_size', Validator::inspect(str_repeat('x', 4097))['reason']);
    }

    public function test_duplicate_json_keys_do_not_grant_operational_authority(): void
    {
        $json = json_encode(self::candidate(), JSON_THROW_ON_ERROR);
        $json = substr_replace($json, '"schemaVersion":"0",', 1, 0);
        self::assertSame('invalid_schema_version', Validator::inspect($json)['reason']);
        self::assertFalse(Validator::mayTransmit());
        self::assertFalse(Validator::isAuthenticated());
        self::assertFalse(Validator::isCustomerApprovalVerified());
        self::assertFalse(Validator::mayPublish());
    }
}
