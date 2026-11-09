<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * F1-11: test-only Portal side of the Sites CreateProject v1 wire contract.
 * No HTTP, credentials, session, payment proof, authorization or S2S operation.
 */
final class SyntheticSitesEnvelopeSigner
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const FIELDS = [
        'schemaVersion','operationId','tenantRef','orderRef','contractRef',
        'contractRevision','productCode','idempotencyKey',
    ];

    /** @param array<string,mixed> $command
     *  @return array<string,mixed>
     */
    public static function sign(
        array $command,
        string $syntheticKey,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
    ): array {
        if (strlen($syntheticKey) < 32) {
            throw new InvalidArgumentException('Short synthetic key.');
        }
        if ($expiresAt <= $issuedAt || $expiresAt - $issuedAt > 120) {
            throw new InvalidArgumentException('Synthetic message TTL must be 1-120 seconds.');
        }
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,64}\z/D', $nonce)) {
            throw new InvalidArgumentException('Invalid nonce.');
        }
        if (array_keys($command) !== self::FIELDS || $command['schemaVersion'] !== '1' ||
            !is_int($command['contractRevision']) || $command['contractRevision'] < 1 ||
            !in_array($command['productCode'], ['onepage','landing_page'], true)) {
            throw new InvalidArgumentException('Unknown or reordered command fields.');
        }
        foreach (['operationId','tenantRef','orderRef','contractRef','idempotencyKey'] as $field) {
            if (!is_string($command[$field]) ||
                !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $command[$field])) {
                throw new InvalidArgumentException('Invalid command identifier.');
            }
        }
        $unsigned = [
            'schemaVersion'=>'1',
            'issuer'=>'imagine-portal',
            'audience'=>'imagine-sites',
            'issuedAt'=>$issuedAt,
            'expiresAt'=>$expiresAt,
            'nonce'=>$nonce,
            'payload'=>$command,
        ];
        $unsigned['mac'] = hash_hmac('sha256', json_encode($unsigned, self::FLAGS), $syntheticKey);
        return $unsigned;
    }
}
