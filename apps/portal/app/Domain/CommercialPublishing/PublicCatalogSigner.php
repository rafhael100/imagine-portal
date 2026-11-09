<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Apenas contrato criptográfico de laboratório Portal -> Imagine Comercial.
 * Nunca obtém a chave privada sozinho e NÃO autoriza publicação nem tenant.
 */
final class PublicCatalogSigner
{
    private const PROTOCOL = 'imagine-catalog-v1';
    private const CONTEXT = "imagine-commercial-catalog-v1\0";
    private const MAX_PAYLOAD = 1048576;

    public static function sign(array $publishedSnapshot, string $keyId, string $secretKey): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The sodium extension is required.');
        }
        if (preg_match('/\A[a-z][a-z0-9_-]{4,63}\z/D', $keyId) !== 1) {
            throw new InvalidArgumentException('Invalid key ID.');
        }
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 secret key is required.');
        }
        self::validateSnapshot($publishedSnapshot);
        $bytes = json_encode($publishedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($bytes) > self::MAX_PAYLOAD) {
            throw new InvalidArgumentException('Public catalog exceeds size limit.');
        }
        $signature = sodium_crypto_sign_detached(self::CONTEXT.$bytes, $secretKey);

        return [
            'protocol' => self::PROTOCOL,
            'keyId' => $keyId,
            'payloadBase64' => base64_encode($bytes),
            'signatureBase64' => base64_encode($signature),
        ];
    }

    private static function validateSnapshot(array $snapshot): void
    {
        self::fields($snapshot, ['schemaVersion', 'releaseId', 'publishedAt', 'items'], 'catalog');
        if (($snapshot['schemaVersion'] ?? null) !== '1.0' ||
            !is_string($snapshot['releaseId'] ?? null) ||
            preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D', $snapshot['releaseId']) !== 1) {
            throw new InvalidArgumentException('Invalid catalog schema or release ID.');
        }
        $date = $snapshot['publishedAt'] ?? null;
        if (!is_string($date) ||
            preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $date) !== 1) {
            throw new InvalidArgumentException('Invalid publishedAt.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $date, new DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d\TH:i:s\Z') !== $date) {
            throw new InvalidArgumentException('Invalid publishedAt calendar date.');
        }
        if (!is_array($snapshot['items'] ?? null) || !array_is_list($snapshot['items']) ||
            count($snapshot['items']) > 10000) {
            throw new InvalidArgumentException('Catalog items must be a bounded list.');
        }
        $seenId = [];
        $seenPath = [];
        foreach ($snapshot['items'] as $entry) {
            if (!is_array($entry) || !array_is_list($entry) === false) {
                // Individual entries must be associative (not lists).
                if (!is_array($entry) || array_is_list($entry)) {
                    throw new InvalidArgumentException('Catalog item must be an associative object.');
                }
            }
            self::fields($entry,
                ['id','slug','title','description','path','status','version','offerRef'], 'service');
            $id = $entry['id'] ?? null;
            $slug = $entry['slug'] ?? null;
            if (!is_string($id) || preg_match('/\A[a-z][a-z0-9_-]{5,79}\z/D', $id) !== 1 ||
                !is_string($slug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1 ||
                strlen($slug) < 2 || strlen($slug) > 80) {
                throw new InvalidArgumentException('Invalid public service identifier.');
            }
            self::plainText($entry['title'] ?? null, 15, 100, 'title');
            self::plainText($entry['description'] ?? null, 50, 180, 'description');
            $path = '/servicos/'.$slug.'/';
            if (($entry['path'] ?? null) !== $path || ($entry['status'] ?? null) !== 'published') {
                throw new InvalidArgumentException('Unpublished service or invalid path.');
            }
            if (!is_int($entry['version'] ?? null) || $entry['version'] < 1) {
                throw new InvalidArgumentException('Invalid service version.');
            }
            if (array_key_exists('offerRef', $entry) &&
                (!is_string($entry['offerRef']) ||
                    preg_match('/\Aoffer_[a-z0-9_-]{6,80}\z/D', $entry['offerRef']) !== 1)) {
                throw new InvalidArgumentException('Invalid public offer reference.');
            }
            if (isset($seenId[$id]) || isset($seenPath[$path])) {
                throw new InvalidArgumentException('Duplicate service entry.');
            }
            $seenId[$id] = true;
            $seenPath[$path] = true;
        }
    }

    private static function fields(array $value, array $allowed, string $context): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unexpected or private '.$context.' field.');
            }
        }
    }

    private static function plainText(mixed $value, int $min, int $max, string $label): void
    {
        if (!is_string($value) || mb_strlen($value, 'UTF-8') < $min ||
            mb_strlen($value, 'UTF-8') > $max ||
            preg_match('/[<>\x00-\x1F]/u', $value) === 1) {
            throw new InvalidArgumentException('Invalid public '.$label.'.');
        }
    }
}
