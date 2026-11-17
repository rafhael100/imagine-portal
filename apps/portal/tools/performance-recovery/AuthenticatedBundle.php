<?php
declare(strict_types=1);

namespace Imagine\PerformanceRecovery;

/**
 * A bounded, authenticated pair of PostgreSQL custom dumps. This codec neither
 * contacts a database nor authorizes a restore. Custody/current-revocation gates
 * belong outside the archive; an authenticated old archive is still old.
 */
final class AuthenticatedBundle
{
    private const MAGIC = "IMAGINE-PERFORMANCE-BUNDLE-1\n";
    private const SCHEMA = 'imagine.performance.recovery.bundle.v1';
    private const MAX_DUMP = 32 * 1024 * 1024;
    private const NAMES = ['portal.dump', 'performance.dump'];

    public static function seal(string $directory, string $keyPath, string $manifestPath, string $output): void
    {
        $key = self::key($keyPath);
        $manifest = json_decode(self::read($manifestPath, 16384), true, 16, JSON_THROW_ON_ERROR);
        self::manifest($manifest);
        $files = [];
        foreach (self::NAMES as $name) {
            $bytes = self::read($directory.'/'.$name, self::MAX_DUMP);
            if (!str_starts_with($bytes, 'PGDMP')) throw new \RuntimeException('dump_format');
            $files[$name] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'data' => base64_encode($bytes)];
        }
        $payload = json_encode(['schema' => self::SCHEMA, 'manifest' => $manifest, 'files' => $files], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, self::MAGIC, $nonce, $key);
        sodium_memzero($key);
        self::create($output, self::MAGIC.$nonce.$sealed);
    }

    public static function open(string $archive, string $keyPath, string $expectedManifestPath, string $destination): void
    {
        if (file_exists($destination) || is_link($destination)) throw new \RuntimeException('fresh_destination_required');
        $key = self::key($keyPath);
        $sealed = self::read($archive, 96 * 1024 * 1024);
        $offset = strlen(self::MAGIC);
        if (!str_starts_with($sealed, self::MAGIC) || strlen($sealed) < $offset + 40) throw new \RuntimeException('archive_format');
        $nonce = substr($sealed, $offset, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($sealed, $offset + strlen($nonce)), self::MAGIC, $nonce, $key);
        sodium_memzero($key);
        if (!is_string($plain)) throw new \RuntimeException('archive_authentication');
        $bundle = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        $expected = json_decode(self::read($expectedManifestPath, 16384), true, 16, JSON_THROW_ON_ERROR);
        self::manifest($expected);
        if (($bundle['schema'] ?? null) !== self::SCHEMA || ($bundle['manifest'] ?? null) !== $expected
            || array_keys($bundle['files'] ?? []) !== self::NAMES) throw new \RuntimeException('archive_binding');
        $decoded = [];
        foreach (self::NAMES as $name) {
            $entry = $bundle['files'][$name];
            $bytes = base64_decode($entry['data'] ?? '', true);
            if (!is_string($bytes) || strlen($bytes) > self::MAX_DUMP || !str_starts_with($bytes, 'PGDMP')
                || strlen($bytes) !== ($entry['size'] ?? null)
                || !hash_equals(hash('sha256', $bytes), (string) ($entry['sha256'] ?? ''))) throw new \RuntimeException('archive_digest');
            $decoded[$name] = $bytes;
        }
        // Authenticate BOTH dumps and their source/generation binding before
        // creating even the first plaintext output. No partial unauthenticated
        // bytes are ever piped into pg_restore.
        if (!mkdir($destination, 0700)) throw new \RuntimeException('destination_create');
        try {
            foreach ($decoded as $name => $bytes) self::create($destination.'/'.$name, $bytes);
        } catch (\Throwable $error) {
            foreach (self::NAMES as $name) if (is_file($destination.'/'.$name)) unlink($destination.'/'.$name);
            rmdir($destination);
            throw $error;
        }
    }

    private static function manifest(mixed $manifest): void
    {
        if (!is_array($manifest) || array_keys($manifest) !== ['generation', 'portal_sha', 'engine_sha', 'compose_sha256']
            || !is_string($manifest['generation']) || !preg_match('/\A[a-f0-9]{32}\z/D', $manifest['generation'])
            || !preg_match('/\A[a-f0-9]{40}\z/D', (string) $manifest['portal_sha'])
            || !preg_match('/\A[a-f0-9]{40}\z/D', (string) $manifest['engine_sha'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', (string) $manifest['compose_sha256'])) throw new \RuntimeException('manifest');
    }

    private static function key(string $path): string
    {
        $key = self::read($path, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES || (fileperms($path) & 0077) !== 0) throw new \RuntimeException('key_file');
        return $key;
    }

    private static function read(string $path, int $limit): string
    {
        if (!is_file($path) || is_link($path) || filesize($path) > $limit) throw new \RuntimeException('input_file');
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > $limit) throw new \RuntimeException('input_read');
        return $bytes;
    }

    private static function create(string $path, string $bytes): void
    {
        $old = umask(0077);
        try {
            $stream = fopen($path, 'xb');
            if ($stream === false) throw new \RuntimeException('output_exists');
            try {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($stream, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('output_write');
                    $offset += $written;
                }
            } finally { fclose($stream); }
        } finally { umask($old); }
    }
}
