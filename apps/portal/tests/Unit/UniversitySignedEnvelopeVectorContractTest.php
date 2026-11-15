<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Shared public test-vector contract for the candidate Portal/University
 * Ed25519 envelope. NOT an operational issuer or access grant.
 */
final class UniversitySignedEnvelopeVectorContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__).'/Fixtures/uni-v1-ed25519-public-vector.json';
        self::assertSame(
            'b6925128a7d66efe1e304710d085d51c5e854215230fa0f02505d201b4381e7c',
            hash_file('sha256', $path),
        );

        return json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    }

    private function decodeUrl(string $value): string
    {
        $raw = base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );
        self::assertIsString($raw);
        return $raw;
    }

    public function test_public_vector_signature_matches_the_university_protocol(): void
    {
        self::assertTrue(extension_loaded('sodium'), 'Ed25519 verification requires libsodium.');
        $vector = $this->fixture();

        $parts = explode('.', $vector['envelope']);
        self::assertCount(3, $parts);
        self::assertSame('uni-v1', $parts[0]);

        $key = base64_decode($vector['public_key_base64'], true);
        self::assertIsString($key);
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($key));

        $signature = $this->decodeUrl($parts[2]);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($signature));
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature, 'uni-v1.'.$parts[1], $key,
        ));

        $claims = json_decode($this->decodeUrl($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('imagine-portal', $claims['iss']);
        self::assertSame('imagine-universidade', $claims['aud']);
        self::assertSame('synthetic-vector-k1', $claims['kid']);
        self::assertSame($vector['expected_company'], $claims['org']);
        self::assertSame($vector['expected_permission'], $claims['perm']);
        self::assertSame(1800000000, $claims['iat']);
        self::assertSame(1800000030, $claims['exp']);
    }

    public function test_domain_separator_rejects_modified_or_other_service_envelopes(): void
    {
        self::assertTrue(extension_loaded('sodium'));
        $vector = $this->fixture();
        $parts = explode('.', $vector['envelope']);
        $key = base64_decode($vector['public_key_base64'], true);
        $signature = $this->decodeUrl($parts[2]);

        self::assertFalse(sodium_crypto_sign_verify_detached(
            $signature, 'uni-v2.'.$parts[1], $key,
        ));
        self::assertFalse(sodium_crypto_sign_verify_detached(
            $signature, 'uni-v1.'.$parts[1].'modified', $key,
        ));
    }
}
