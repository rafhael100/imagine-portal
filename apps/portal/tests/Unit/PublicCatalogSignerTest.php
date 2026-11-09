<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CommercialPublishing\PublicCatalogSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicCatalogSignerTest extends TestCase
{
    private function snapshot(array $itemOverrides = []): array
    {
        return [
            'schemaVersion' => '1.0',
            'releaseId' => 'release_20261109_01',
            'publishedAt' => '2026-11-09T03:00:00Z',
            'items' => [array_replace([
                'id'=>'service_landing_1',
                'slug'=>'landing-pages',
                'title'=>'Criação de Landing Pages profissionais',
                'description'=>'Planejamento de páginas comerciais para apresentar ofertas e facilitar novos contatos.',
                'path'=>'/servicos/landing-pages/',
                'status'=>'published',
                'version'=>1
            ], $itemOverrides)],
        ];
    }

    public function test_signs_only_public_versioned_service_using_ed25519(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $envelope = PublicCatalogSigner::sign($this->snapshot(), 'portal_test_key', $private);
        self::assertSame('imagine-catalog-v1', $envelope['protocol']);
        $payload = base64_decode($envelope['payloadBase64'], true);
        $signature = base64_decode($envelope['signatureBase64'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature, "imagine-commercial-catalog-v1\0".$payload, $public
        ));
        self::assertSame($this->snapshot(), json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_refuses_private_data_even_when_signer_gets_a_valid_private_key(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($keypair);
        foreach (['price'=>900, 'customerEmail'=>'private@example.com', 'apiToken'=>'secret'] as $field=>$value) {
            try {
                PublicCatalogSigner::sign($this->snapshot([$field=>$value]), 'portal_test_key', $private);
                self::fail('Private field was signed: '.$field);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('private', $exception->getMessage());
            }
        }
    }

    public function test_refuses_draft_or_mismatched_path(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($keypair);
        foreach ([['status'=>'draft'], ['path'=>'/admin/']] as $change) {
            try {
                PublicCatalogSigner::sign($this->snapshot($change), 'portal_test_key', $private);
                self::fail('Signer accepted a draft or a mismatched path.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Unpublished', $exception->getMessage());
            }
        }
    }

    public function test_rejects_invalid_signature_key_and_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PublicCatalogSigner::sign($this->snapshot(), 'bad key', random_bytes(64));
    }
}
