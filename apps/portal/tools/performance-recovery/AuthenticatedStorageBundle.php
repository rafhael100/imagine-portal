<?php
declare(strict_types=1);

namespace Imagine\PerformanceRecovery;

/**
 * Offline, bounded Portal storage codec. The caller must stop writers, mount
 * the source read-only and prove that the destination is a new volume. This
 * class authenticates the complete tree and its binding to the exact paired
 * database ciphertext before writing into an existing, literally empty
 * UID/GID 33 directory. The caller must separately authenticate/open that V1
 * database archive. This is not a filesystem transaction and does not protect
 * against a concurrent writer.
 */
final class AuthenticatedStorageBundle
{
    public const MAGIC = "IMAGINE-PERFORMANCE-STORAGE-1\n";
    public const SCHEMA = 'imagine.performance.recovery.storage.bundle.v1';
    public const INVENTORY_SCHEMA = 'imagine.performance.recovery.storage.inventory.v1';
    private const PAIRED_MAGIC = "IMAGINE-PERFORMANCE-BUNDLE-1\n";
    private const MAX_FILE = 2 * 1024 * 1024;
    private const MAX_TOTAL = 8 * 1024 * 1024;
    private const MAX_ARCHIVE = 24 * 1024 * 1024;
    private const MAX_PAIRED = 96 * 1024 * 1024;
    private const MAX_ENTRIES = 2048;
    private const MAX_PATH = 512;
    private const MAX_DEPTH = 32;
    private const ERRORS = [
        'storage_runtime', 'storage_path', 'storage_scope', 'storage_type',
        'storage_owner', 'storage_mode', 'storage_link', 'storage_limit',
        'storage_read', 'storage_changed', 'storage_manifest', 'storage_key',
        'storage_paired_format', 'storage_archive_format', 'storage_authentication',
        'storage_binding', 'storage_tree', 'storage_digest', 'storage_destination',
        'storage_destination_not_empty', 'storage_output_exists', 'storage_write',
        'storage_operation',
    ];

    public static function seal(string $sourceDir, string $keyPath, string $manifestPath, string $pairedArchivePath, string $output): void
    {
        self::guard(static function () use ($sourceDir, $keyPath, $manifestPath, $pairedArchivePath, $output): void {
            self::runtime();
            self::directory($sourceDir);
            self::outputPath($output);
            foreach ([$keyPath, $manifestPath, $pairedArchivePath, $output] as $control) {
                if ($control === $sourceDir || str_starts_with($control, $sourceDir.'/')) self::fail('storage_scope');
            }
            $key = self::key($keyPath);
            try {
                $manifest = self::manifestFile($manifestPath);
                $paired = self::pairedDigest($pairedArchivePath);
                $tree = self::scan($sourceDir, true);
                $payload = self::json([
                    'schema' => self::SCHEMA, 'manifest' => $manifest, 'paired_sha256' => $paired,
                    'root_mode' => $tree['root_mode'], 'entries' => $tree['entries'],
                ]);
                if (strlen($payload) > self::MAX_ARCHIVE - strlen(self::MAGIC) - 40) self::fail('storage_limit');
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($payload, self::MAGIC, $nonce, $key);
                self::create($output, self::MAGIC.$nonce.$sealed, 0600);
            } finally {
                sodium_memzero($key);
            }
        });
    }

    public static function restore(string $archive, string $keyPath, string $expectedManifestPath, string $pairedArchivePath, string $destination): void
    {
        self::guard(static function () use ($archive, $keyPath, $expectedManifestPath, $pairedArchivePath, $destination): void {
            self::runtime();
            $target = self::emptyDestination($destination);
            $expected = self::manifestFile($expectedManifestPath);
            $paired = self::pairedDigest($pairedArchivePath);
            $key = self::key($keyPath);
            try {
                $sealed = self::read($archive, self::MAX_ARCHIVE);
                $offset = strlen(self::MAGIC);
                if (!str_starts_with($sealed, self::MAGIC) || strlen($sealed) < $offset + 40) self::fail('storage_archive_format');
                $nonce = substr($sealed, $offset, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($sealed, $offset + strlen($nonce)), self::MAGIC, $nonce, $key);
                unset($sealed);
                if (!is_string($plain)) self::fail('storage_authentication');
            } finally {
                sodium_memzero($key);
            }
            // Bound JSON structure before allocating decoded arrays. A valid
            // authentication tag alone does not make a supplied tree safe.
            self::jsonStructure($plain, self::MAX_ENTRIES + 3, 32768);
            $bundle = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($bundle) || self::json($bundle) !== $plain) self::fail('storage_archive_format');
            unset($plain);
            if (array_keys($bundle) !== ['schema', 'manifest', 'paired_sha256', 'root_mode', 'entries']
                || $bundle['schema'] !== self::SCHEMA || $bundle['manifest'] !== $expected
                || !is_string($bundle['paired_sha256']) || !hash_equals($paired, $bundle['paired_sha256'])) self::fail('storage_binding');
            $tree = self::validatedTree($bundle['root_mode'], $bundle['entries']);
            unset($bundle);

            // Every byte, path, type, mode, digest, parent and size has passed
            // preflight. Recheck the destination before the first mutation.
            $current = self::emptyDestination($destination);
            if (!self::sameNode($target, $current)) self::fail('storage_changed');
            $old = umask(0077);
            try {
                foreach ($tree['entries'] as $entry) {
                    $path = $destination.'/'.$entry['path'];
                    self::directory(dirname($path));
                    if ($entry['type'] === 'directory') {
                        if (!@mkdir($path, 0700)) self::fail('storage_write');
                        self::directory($path);
                    } else {
                        self::create($path, $entry['bytes'], $entry['mode']);
                    }
                }
                // Apply restrictive directory modes only after descendants
                // exist. Every target was created exclusively in this run.
                foreach (array_reverse($tree['entries']) as $entry) {
                    if ($entry['type'] === 'directory') self::setMode($destination.'/'.$entry['path'], $entry['mode'], true);
                }
                self::setMode($destination, $tree['root_mode'], true);
            } finally {
                umask($old);
            }
            // An I/O failure intentionally leaves a failed, partial NEW volume.
            // Never erase it, merge into it or silently retry over its contents.
        });
    }

    public static function inventory(string $directory): array
    {
        return self::guard(static function () use ($directory): array {
            self::runtime();
            $tree = self::scan($directory, false);
            return ['schema' => self::INVENTORY_SCHEMA] + $tree + ['tree_sha256' => hash('sha256', self::json($tree))];
        });
    }

    private static function runtime(): void
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getegid')
            || posix_geteuid() !== 33 || posix_getegid() !== 33
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) self::fail('storage_runtime');
    }

    private static function scan(string $directory, bool $contents): array
    {
        $root = self::directory($directory);
        $entries = []; $total = 0; $seen = [];
        $walk = static function (string $absolute, string $relative) use (&$walk, &$entries, &$total, &$seen, $root, $contents): void {
            $before = self::directory($absolute);
            if ($before['dev'] !== $root['dev']) self::fail('storage_scope');
            $node = $before['dev'].':'.$before['ino'];
            if (isset($seen[$node])) self::fail('storage_link');
            $seen[$node] = true;
            $stream = @opendir($absolute);
            if ($stream === false) self::fail('storage_read');
            try {
                while (($name = readdir($stream)) !== false) {
                    if ($name === '.' || $name === '..') continue;
                    if (count($entries) >= self::MAX_ENTRIES) self::fail('storage_limit');
                    $path = $relative === '' ? $name : $relative.'/'.$name;
                    self::relative($path);
                    $target = $absolute.'/'.$name;
                    $stat = self::node($target);
                    if ($stat['dev'] !== $root['dev']) self::fail('storage_scope');
                    self::owner($stat);
                    $kind = $stat['mode'] & 0170000;
                    if ($kind === 0040000) {
                        self::mode($stat['mode'] & 07777, true);
                        $entry = ['path' => $path, 'type' => 'directory', 'mode' => $stat['mode'] & 0777, 'size' => 0, 'sha256' => null];
                        if ($contents) $entry['data'] = null;
                        $entries[] = $entry;
                        $walk($target, $path);
                    } elseif ($kind === 0100000) {
                        if ($stat['nlink'] !== 1) self::fail('storage_link');
                        self::mode($stat['mode'] & 07777, false);
                        $node = $stat['dev'].':'.$stat['ino'];
                        if (isset($seen[$node])) self::fail('storage_link');
                        $seen[$node] = true;
                        if ($stat['size'] < 0 || $stat['size'] > self::MAX_FILE || $total + $stat['size'] > self::MAX_TOTAL) self::fail('storage_limit');
                        $bytes = self::read($target, self::MAX_FILE, $stat);
                        $total += strlen($bytes);
                        $entry = ['path' => $path, 'type' => 'file', 'mode' => $stat['mode'] & 0777, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
                        if ($contents) $entry['data'] = base64_encode($bytes);
                        $entries[] = $entry;
                        unset($bytes);
                    } else {
                        self::fail($kind === 0120000 ? 'storage_link' : 'storage_type');
                    }
                }
            } finally {
                closedir($stream);
            }
            if (!self::sameNode($before, self::node($absolute))) self::fail('storage_changed');
        };
        $walk($directory, '');
        usort($entries, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return ['root_mode' => $root['mode'] & 0777, 'entries' => $entries, 'total_bytes' => $total];
    }

    private static function validatedTree(mixed $rootMode, mixed $entries): array
    {
        self::mode($rootMode, true);
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > self::MAX_ENTRIES) self::fail('storage_tree');
        $ready = []; $types = []; $previous = null; $total = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['path', 'type', 'mode', 'size', 'sha256', 'data']) self::fail('storage_tree');
            self::relative($entry['path']);
            $path = $entry['path'];
            if ($previous !== null && strcmp($previous, $path) >= 0) self::fail('storage_tree');
            $previous = $path;
            $parent = dirname($path);
            if ($parent !== '.' && ($types[$parent] ?? null) !== 'directory') self::fail('storage_tree');
            if (!in_array($entry['type'], ['directory', 'file'], true)) self::fail('storage_type');
            $isDirectory = $entry['type'] === 'directory';
            self::mode($entry['mode'], $isDirectory);
            if (!is_int($entry['size']) || $entry['size'] < 0) self::fail('storage_tree');
            $bytes = null;
            if ($isDirectory) {
                if ($entry['size'] !== 0 || $entry['sha256'] !== null || $entry['data'] !== null) self::fail('storage_tree');
            } else {
                if ($entry['size'] > self::MAX_FILE || $total + $entry['size'] > self::MAX_TOTAL) self::fail('storage_limit');
                if (!is_string($entry['sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']) !== 1
                    || !is_string($entry['data']) || strlen($entry['data']) !== 4 * intdiv($entry['size'] + 2, 3)) self::fail('storage_digest');
                $bytes = base64_decode($entry['data'], true);
                if (!is_string($bytes) || strlen($bytes) !== $entry['size'] || base64_encode($bytes) !== $entry['data']
                    || !hash_equals($entry['sha256'], hash('sha256', $bytes))) self::fail('storage_digest');
                $total += $entry['size'];
            }
            $types[$path] = $entry['type'];
            $ready[] = ['path' => $path, 'type' => $entry['type'], 'mode' => $entry['mode'], 'bytes' => $bytes];
        }
        return ['root_mode' => $rootMode, 'entries' => $ready];
    }

    private static function relative(mixed $path): void
    {
        if (!is_string($path) || $path === '' || strlen($path) > self::MAX_PATH
            || preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1f\x7f\\\\]/u', $path) === 1) self::fail('storage_path');
        $parts = explode('/', $path);
        if (count($parts) > self::MAX_DEPTH) self::fail('storage_limit');
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) self::fail('storage_path');
        }
    }

    private static function mode(mixed $mode, bool $directory): void
    {
        if (!is_int($mode) || $mode < 0 || $mode > 0777 || ($mode & 0022) !== 0
            || ($directory ? ($mode & 0500) !== 0500 : ($mode & 0400) !== 0400)) self::fail('storage_mode');
        // Compiled Blade files can legitimately have ordinary execute bits.
        // Preserve them as metadata; the codec never executes file contents.
        // Group/other write and setuid/setgid/sticky bits remain forbidden.
    }

    private static function owner(array $stat): void
    {
        if ($stat['uid'] !== 33 || $stat['gid'] !== 33) self::fail('storage_owner');
    }

    private static function canonical(string $path): void
    {
        if ($path === '/' || !str_starts_with($path, '/') || str_contains($path, "\0") || @realpath($path) !== $path) self::fail('storage_path');
    }

    private static function node(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) self::fail('storage_read');
        return $stat;
    }

    private static function directory(string $path): array
    {
        self::canonical($path);
        $stat = self::node($path);
        if (($stat['mode'] & 0170000) !== 0040000) self::fail('storage_type');
        self::owner($stat);
        self::mode($stat['mode'] & 07777, true);
        return $stat;
    }

    private static function emptyDestination(string $path): array
    {
        $stat = self::directory($path);
        if (($stat['mode'] & 0700) !== 0700 || !is_writable($path)) self::fail('storage_destination');
        $stream = @opendir($path);
        if ($stream === false) self::fail('storage_destination');
        try {
            while (($name = readdir($stream)) !== false) {
                if ($name !== '.' && $name !== '..') self::fail('storage_destination_not_empty');
            }
        } finally {
            closedir($stream);
        }
        return $stat;
    }

    private static function input(string $path, int $limit): array
    {
        self::canonical($path);
        $stat = self::node($path);
        if (($stat['mode'] & 0170000) !== 0100000) self::fail('storage_type');
        if ($stat['nlink'] !== 1) self::fail('storage_link');
        if ($stat['size'] < 0 || $stat['size'] > $limit) self::fail('storage_limit');
        return $stat;
    }

    private static function sameNode(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) return false;
        }
        return true;
    }

    private static function read(string $path, int $limit, ?array $expected = null): string
    {
        $before = self::input($path, $limit);
        if ($expected !== null && !self::sameNode($expected, $before)) self::fail('storage_changed');
        $stream = @fopen($path, 'rb');
        if ($stream === false) self::fail('storage_read');
        try {
            $opened = @fstat($stream);
            if (!is_array($opened) || !self::sameNode($before, $opened)) self::fail('storage_changed');
            $bytes = '';
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if (!is_string($chunk) || ($chunk === '' && !feof($stream))) self::fail('storage_read');
                if (strlen($bytes) + strlen($chunk) > $limit) self::fail('storage_limit');
                $bytes .= $chunk;
            }
            $after = @fstat($stream);
            if (!is_array($after) || !self::sameNode($before, $after) || strlen($bytes) !== $before['size']
                || !self::sameNode($before, self::node($path))) self::fail('storage_changed');
            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private static function pairedDigest(string $path): string
    {
        $before = self::input($path, self::MAX_PAIRED);
        if ($before['size'] < strlen(self::PAIRED_MAGIC) + 40) self::fail('storage_paired_format');
        $stream = @fopen($path, 'rb');
        if ($stream === false) self::fail('storage_read');
        try {
            $opened = @fstat($stream);
            if (!is_array($opened) || !self::sameNode($before, $opened)) self::fail('storage_changed');
            $hash = hash_init('sha256'); $size = 0;
            while (!feof($stream)) {
                $chunk = @fread($stream, 65536);
                if (!is_string($chunk) || ($chunk === '' && !feof($stream))) self::fail('storage_read');
                if ($size === 0 && !str_starts_with($chunk, self::PAIRED_MAGIC)) self::fail('storage_paired_format');
                $size += strlen($chunk);
                if ($size > self::MAX_PAIRED) self::fail('storage_limit');
                hash_update($hash, $chunk);
            }
            $after = @fstat($stream);
            if (!is_array($after) || !self::sameNode($before, $after) || $size !== $before['size']
                || !self::sameNode($before, self::node($path))) self::fail('storage_changed');
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private static function manifestFile(string $path): array
    {
        $bytes = self::read($path, 16384);
        self::jsonStructure($bytes, 1, 32);
        $manifest = json_decode($bytes, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_keys($manifest) !== ['generation', 'portal_sha', 'engine_sha', 'compose_sha256']) self::fail('storage_manifest');
        foreach (['generation' => 32, 'portal_sha' => 40, 'engine_sha' => 40, 'compose_sha256' => 64] as $field => $length) {
            if (!is_string($manifest[$field]) || preg_match('/\A[a-f0-9]{'.$length.'}\z/D', $manifest[$field]) !== 1) self::fail('storage_manifest');
        }
        // Only insignificant whitespace may differ in the control manifest.
        // Fields contain hexadecimal strings, so escaped/duplicate keys or
        // values cannot be silently collapsed by json_decode.
        if (preg_replace('/\s+/', '', $bytes) !== self::json($manifest)) self::fail('storage_manifest');
        return $manifest;
    }

    private static function key(string $path): string
    {
        $stat = self::input($path, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        if (!in_array($stat['mode'] & 07777, [0400, 0600], true)) self::fail('storage_key');
        $key = self::read($path, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $stat);
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) self::fail('storage_key');
        return $key;
    }

    private static function jsonStructure(string $json, int $containers, int $punctuation): void
    {
        $quoted = false; $escaped = false; $opened = 0; $tokens = 0;
        for ($index = 0, $size = strlen($json); $index < $size; $index++) {
            $char = $json[$index];
            if ($quoted) {
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\') { $escaped = true; continue; }
                if ($char === '"') $quoted = false;
                continue;
            }
            if ($char === '"') { $quoted = true; continue; }
            if (($char === '{' || $char === '[') && ++$opened > $containers) self::fail('storage_limit');
            if (($char === ',' || $char === ':') && ++$tokens > $punctuation) self::fail('storage_limit');
        }
    }

    private static function outputPath(string $path): void
    {
        $parent = dirname($path); $name = basename($path);
        self::canonical($parent);
        self::relative($name);
        if ($path !== $parent.'/'.$name || !is_dir($parent)) self::fail('storage_path');
        clearstatcache(true, $path);
        if (@lstat($path) !== false) self::fail('storage_output_exists');
    }

    private static function create(string $path, string $bytes, int $mode): void
    {
        self::outputPath($path);
        $old = umask(0077);
        try {
            $stream = @fopen($path, 'xb');
            if ($stream === false) self::fail('storage_write');
            try {
                $opened = @fstat($stream);
                if (!is_array($opened)) self::fail('storage_write');
                self::owner($opened);
                if (($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) self::fail('storage_type');
                $offset = 0; $size = strlen($bytes);
                while ($offset < $size) {
                    $written = @fwrite($stream, substr($bytes, $offset, 65536));
                    if (!is_int($written) || $written <= 0) self::fail('storage_write');
                    $offset += $written;
                }
                if (!@fflush($stream) || !@fsync($stream)) self::fail('storage_write');
                $after = @fstat($stream);
                if (!is_array($after) || $after['size'] !== $size || $after['dev'] !== $opened['dev'] || $after['ino'] !== $opened['ino']) self::fail('storage_write');
            } finally {
                fclose($stream);
            }
            self::setMode($path, $mode, false);
        } finally {
            umask($old);
        }
    }

    private static function setMode(string $path, int $mode, bool $directory): void
    {
        self::mode($mode, $directory);
        if (!@chmod($path, $mode)) self::fail('storage_write');
        $stat = self::node($path);
        self::owner($stat);
        if (($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)
            || ($stat['mode'] & 07777) !== $mode || (!$directory && $stat['nlink'] !== 1)) self::fail('storage_write');
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function fail(string $code): never
    {
        throw new \RuntimeException($code);
    }

    private static function guard(\Closure $operation): mixed
    {
        try { return $operation(); }
        catch (\Throwable $error) {
            $code = $error instanceof \RuntimeException && in_array($error->getMessage(), self::ERRORS, true)
                ? $error->getMessage() : 'storage_operation';
            // No previous exception: callers receive a fixed code, never a
            // filesystem path, decoder message, file content or key material.
            throw new \RuntimeException($code);
        }
    }
}
