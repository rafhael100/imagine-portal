<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use JsonException;

/**
 * Validates the shape and meaning of a pending Sites receipt against the exact
 * Portal-generated command. This is NOT authentication of the sender, payment,
 * production authorization or a signed publication entitlement.
 */
final class PortalSitesPendingReceiptValidator
{
    private const COMMAND_FIELDS = [
        'schemaVersion','operationId','tenantRef','orderRef',
        'contractRef','contractRevision','productCode','idempotencyKey',
    ];

    private const RECEIPT_FIELDS = [
        'schemaVersion','tenantRef','orderRef','contractRef','contractRevision',
        'productCode','operationId','idempotencyKey','siteRef','commandDigest',
        'status','state','authorityVerified','clientAccepted','canPublish',
    ];

    /**
     * Only a trusted service-to-service receiver may supply receipt bytes.
     * The validated result is a *record of intake*, never a payment or GO decision.
     *
     * @param array<string, mixed> $command
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public static function validate(array $command, array $receipt): array
    {
        if (!self::exactKeys($command, self::COMMAND_FIELDS)
            || !self::exactKeys($receipt, self::RECEIPT_FIELDS)
            || ($command['schemaVersion'] ?? null) !== '1'
            || !is_int($command['contractRevision'] ?? null)
            || $command['contractRevision'] < 1
            || !in_array($command['productCode'] ?? null, ['onepage', 'landing_page'], true)) {
            throw new DomainException('Unverified pending Sites intake receipt.');
        }

        foreach (['operationId','tenantRef','orderRef','contractRef','idempotencyKey'] as $field) {
            if (!self::reference($command[$field] ?? null)) {
                throw new DomainException('Unverified pending Sites intake receipt.');
            }
        }

        try {
            $fingerprint = hash('sha256', json_encode([
                $command['schemaVersion'], $command['tenantRef'],
                $command['orderRef'], $command['contractRef'],
                $command['contractRevision'], $command['productCode'],
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new DomainException('Unverified pending Sites intake receipt.');
        }

        $expected = [
            'schemaVersion' => 'sites.project-intake.receipt.v1',
            'tenantRef' => $command['tenantRef'],
            'orderRef' => $command['orderRef'],
            'contractRef' => $command['contractRef'],
            'contractRevision' => $command['contractRevision'],
            'productCode' => $command['productCode'],
            'operationId' => $command['operationId'],
            'idempotencyKey' => $command['idempotencyKey'],
            'commandDigest' => $fingerprint,
            'status' => 'recorded',
            'state' => 'awaiting_official_authority',
            'authorityVerified' => false,
            'clientAccepted' => false,
            'canPublish' => false,
        ];

        foreach ($expected as $key => $value) {
            if (($receipt[$key] ?? null) !== $value) {
                throw new DomainException('Unverified pending Sites intake receipt.');
            }
        }

        if (!is_string($receipt['siteRef'] ?? null)
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $receipt['siteRef']) !== 1
            || $receipt['siteRef'] === $command['orderRef']
            || $receipt['siteRef'] === '00000000-0000-0000-0000-000000000000') {
            throw new DomainException('Unverified pending Sites intake receipt.');
        }

        // Preserve the wire response. No field can promote the intake to "paid",
        // "in production" or "published" because additional keys are rejected.
        return $receipt;
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }
}
