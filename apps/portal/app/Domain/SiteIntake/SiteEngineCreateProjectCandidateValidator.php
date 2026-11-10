<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * F51: offline shape check for imagine-sites CreateProject v1 candidate.
 * NEVER grants tenant membership, contract eligibility, customer approval,
 * authentication, delivery or publishing. No wire transport is implemented.
 */
final class SiteEngineCreateProjectCandidateValidator
{
    private const MAX_BYTES = 4096;

    /**
     * @return array{valid:bool,reason:string}
     */
    public static function inspect(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            return self::result(false, 'invalid_payload_size');
        }

        try {
            $value = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::result(false, 'malformed_json');
        }

        if (!$value instanceof \stdClass) {
            return self::result(false, 'not_object');
        }

        $fields = array_keys(get_object_vars($value));
        $expected = SiteEngineContractVocabulary::createProjectV1Keys();
        sort($fields);
        sort($expected);
        if ($fields !== $expected) {
            return self::result(false, 'unexpected_fields');
        }

        if ($value->schemaVersion !== SiteEngineContractVocabulary::SITES_CREATE_PROJECT_V1) {
            return self::result(false, 'invalid_schema_version');
        }

        foreach (['operationId', 'tenantRef', 'orderRef', 'contractRef', 'idempotencyKey'] as $key) {
            if (!is_string($value->{$key}) ||
                preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value->{$key}) !== 1) {
                return self::result(false, 'invalid_reference');
            }
        }

        if (!is_int($value->contractRevision) || $value->contractRevision < 1) {
            return self::result(false, 'invalid_contract_revision');
        }

        if (!is_string($value->productCode) ||
            SiteEngineContractVocabulary::productFromCreateProjectCode($value->productCode) === null) {
            return self::result(false, 'invalid_product_code');
        }

        if (array_keys(get_object_vars($value)) !== SiteEngineContractVocabulary::createProjectV1Keys()) {
            return self::result(false, 'noncanonical_json');
        }

        // F52: local strict encoding policy. Enforces exact PHP json_encode bytes,
        // including key order. Duplicate keys, aliases, whitespace, escaped keys
        // and noncanonical serialization fail closed. This is NOT a MAC/signature.
        try {
            $canonical = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\\JsonException) {
            return self::result(false, 'noncanonical_json');
        }
        if (!hash_equals($canonical, $json)) {
            return self::result(false, 'noncanonical_json');
        }

        return self::result(true, 'candidate_shape_valid_only');
    }

    public static function mayTransmit(): bool { return false; }
    public static function isAuthenticated(): bool { return false; }
    public static function isCustomerApprovalVerified(): bool { return false; }
    public static function mayPublish(): bool { return false; }

    /** @return array{valid:bool,reason:string} */
    private static function result(bool $valid, string $reason): array
    {
        return ['valid' => $valid, 'reason' => $reason];
    }
}
