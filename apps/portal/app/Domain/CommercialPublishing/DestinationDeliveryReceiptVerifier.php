<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * F26 — verificar atestação sintética de destino independente ANTES de um
 * futuro ACK de lease. NÃO assina recibos, NÃO chama banco e NÃO faz deploy.
 *
 * A chave pública do DESTINO e o escopo esperado devem vir de configuração
 * operacional confiável, NUNCA do próprio envelope ou da requisição externa.
 * Uma assinatura válida não comprova acesso ao Cloudflare; só autentica a
 * afirmação feita pelo detentor da chave privada correspondente.
 */
final class DestinationDeliveryReceiptVerifier
{
    private const PROTOCOL = 'imagine-commercial-delivery-receipt-v1';
    private const CONTEXT = "imagine-commercial-delivery-receipt-v1\0";
    private const MAX_PAYLOAD_BYTES = 4096;
    private const MAX_AGE_SECONDS = 300;
    private const MAX_FUTURE_SKEW_SECONDS = 30;
    private const KEY_ID = '/\A[a-z][a-z0-9_-]{4,63}\z/D';
    private const IDENTIFIER = '/\A[a-z][a-z0-9_-]{5,79}\z/D';
    private const SHA256 = '/\A[a-f0-9]{64}\z/D';
    private const NONCE = '/\A[a-f0-9]{32}\z/D';

    private const SCOPE_FIELDS = [
        'organizationId', 'releaseId', 'payloadSha256', 'treeSha256',
        'dispatchId', 'generation', 'attempt', 'destinationId', 'environment', 'nonce'
    ];

    /**
     * @param array<string,mixed> $envelope Assinatura originada pelo DESTINO.
     * @param array<string,mixed> $expected Escopo pinado por uma autoridade confiável.
     * @param array<string,string> $trustedDestinationKeys keyId => chave pública Ed25519 RAW (32 bytes).
     * @return array<string,mixed> Claims verificadas, ainda NÃO persistidas/consumidas.
     */
    public static function verify(
        array $envelope,
        array $expected,
        array $trustedDestinationKeys,
        DateTimeImmutable $now,
    ): array {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Sodium is required to verify a destination receipt.');
        }
        self::exactFields($envelope, ['protocol','keyId','payloadBase64','signatureBase64'], 'envelope');
        self::exactFields(
            $expected, [...self::SCOPE_FIELDS, 'receiptKeyId'], 'trusted expectation'
        );

        if (($envelope['protocol'] ?? null) !== self::PROTOCOL) {
            throw new InvalidArgumentException('Delivery receipt: unsupported protocol.');
        }
        $keyId = $envelope['keyId'] ?? null;
        if (!is_string($keyId) || preg_match(self::KEY_ID, $keyId) !== 1 ||
            $keyId !== $expected['receiptKeyId']) {
            throw new InvalidArgumentException('Delivery receipt: untrusted key ID.');
        }
        $publicKey = $trustedDestinationKeys[$keyId] ?? null;
        if (!is_string($publicKey) ||
            strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Delivery receipt: unknown destination key.');
        }
        self::checkScope($expected);

        $payloadBytes = self::decode($envelope['payloadBase64'] ?? null, self::MAX_PAYLOAD_BYTES, 'payload');
        $signature = self::decode($envelope['signatureBase64'] ?? null, SODIUM_CRYPTO_SIGN_BYTES, 'signature');
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            !sodium_crypto_sign_verify_detached($signature, self::CONTEXT.$payloadBytes, $publicKey)) {
            throw new InvalidArgumentException('Delivery receipt: signature verification failed.');
        }

        try {
            $claims = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Delivery receipt: invalid JSON.');
        }
        if (!is_array($claims) || array_is_list($claims)) {
            throw new InvalidArgumentException('Delivery receipt: payload object required.');
        }
        self::exactFields($claims, [...self::SCOPE_FIELDS, 'schemaVersion', 'observedAt'], 'payload');
        // Canonical exact serialization v1: rejects ambiguous duplicate JSON keys.
        if (json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            !== $payloadBytes) {
            throw new InvalidArgumentException('Delivery receipt: noncanonical or ambiguous JSON.');
        }
        if ($claims['schemaVersion'] !== '1.0') {
            throw new InvalidArgumentException('Delivery receipt: unsupported schema.');
        }
        self::checkScope($claims);
        foreach (self::SCOPE_FIELDS as $field) {
            if ($claims[$field] !== $expected[$field]) {
                throw new InvalidArgumentException('Delivery receipt: scope mismatch: '.$field);
            }
        }
        $date = $claims['observedAt'];
        if (!is_string($date) ||
            preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $date) !== 1) {
            throw new InvalidArgumentException('Delivery receipt: invalid observation timestamp.');
        }
        $observed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, new DateTimeZone('UTC'));
        if (!$observed || $observed->format('Y-m-d\TH:i:s\Z') !== $date) {
            throw new InvalidArgumentException('Delivery receipt: invalid observation date.');
        }
        $age = $now->getTimestamp() - $observed->getTimestamp();
        if ($age > self::MAX_AGE_SECONDS || $age < -self::MAX_FUTURE_SKEW_SECONDS) {
            throw new InvalidArgumentException('Delivery receipt: observation outside freshness window.');
        }
        return $claims;
    }

    private static function checkScope(array $scope): void
    {
        foreach (['organizationId','releaseId','dispatchId','destinationId'] as $field) {
            if (!is_string($scope[$field]) ||
                preg_match(self::IDENTIFIER, $scope[$field]) !== 1) {
                throw new InvalidArgumentException('Delivery receipt: invalid '.$field.'.');
            }
        }
        foreach (['payloadSha256','treeSha256'] as $field) {
            if (!is_string($scope[$field]) ||
                preg_match(self::SHA256, $scope[$field]) !== 1) {
                throw new InvalidArgumentException('Delivery receipt: invalid '.$field.'.');
            }
        }
        foreach (['generation','attempt'] as $field) {
            if (!is_int($scope[$field]) || $scope[$field] < 1) {
                throw new InvalidArgumentException('Delivery receipt: invalid '.$field.'.');
            }
        }
        if (!in_array($scope['environment'], ['staging', 'production'], true) ||
            !is_string($scope['nonce']) || preg_match(self::NONCE, $scope['nonce']) !== 1) {
            throw new InvalidArgumentException('Delivery receipt: invalid environment or nonce.');
        }
    }

    private static function exactFields(array $record, array $allowed, string $context): void
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('Delivery receipt: '.$context.' must be an object.');
        }
        $actual = array_keys($record);
        sort($actual);
        sort($allowed);
        if ($actual !== $allowed) {
            throw new InvalidArgumentException('Delivery receipt: invalid '.$context.' fields.');
        }
    }

    private static function decode(mixed $value, int $maxBytes, string $label): string
    {
        if (!is_string($value) || $value === '' ||
            strlen($value) > (int) ceil($maxBytes / 3) * 4 + 4) {
            throw new InvalidArgumentException('Delivery receipt: invalid '.$label.' encoding.');
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) > $maxBytes ||
            base64_encode($decoded) !== $value) {
            throw new InvalidArgumentException('Delivery receipt: invalid '.$label.' encoding.');
        }
        return $decoded;
    }
}
