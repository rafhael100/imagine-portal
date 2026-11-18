<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Infrastructure\Identity\PortalBrowserSecretFile;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use WeakMap;

/** A separate execute-only credential for the same private native identity DB. */
final class OnepagePrivateRuntime
{
    private static ?WeakMap $values = null;

    private function __construct(#[SensitiveParameter] array $values)
    {
        self::$values ??= new WeakMap();
        self::$values[$this] = $values;
    }

    public static function load(string $path, PortalBrowserSecretFile $identity, string $expectedOrigin): self
    {
        $fail = static fn () => throw new RuntimeException('Private Onepage configuration required.');
        if (!function_exists('posix_geteuid') || !str_starts_with($path, '/')) $fail();
        clearstatcache(true, $path);
        $before = @lstat($path); $parent = @lstat(dirname($path));
        if (!is_array($before) || !is_array($parent)
            || ($before['mode'] & 0170000) !== 0100000 || ($parent['mode'] & 0170000) !== 0040000
            || ($before['mode'] & 0077) !== 0 || ($parent['mode'] & 0022) !== 0
            || $before['uid'] !== posix_geteuid() || $before['nlink'] !== 1
            || $before['size'] < 1 || $before['size'] > 4096) $fail();
        for ($ancestor = dirname($path);;) {
            $entry = @lstat($ancestor);
            if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0040000
                || (($entry['mode'] & 0022) !== 0 && ($entry['mode'] & 01000) === 0)) $fail();
            $next = dirname($ancestor); if ($next === $ancestor) break; $ancestor = $next;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) $fail();
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']
                || ($opened['mode'] & 0077) !== 0 || $opened['uid'] !== posix_geteuid() || $opened['nlink'] !== 1) $fail();
            $raw = stream_get_contents($handle, 4097);
        } finally { fclose($handle); }
        if (!is_string($raw) || strlen($raw) > 4096) $fail();
        try { $v = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { $fail(); }
        $keys = ['origin', 'host', 'port', 'database', 'password'];
        if (!is_array($v) || count($v) !== count($keys) || array_diff(array_keys($v), $keys) !== []) $fail();
        foreach ($keys as $key) if (!array_key_exists($key, $v)) $fail();
        foreach (['origin', 'host', 'database', 'password'] as $key) if (!is_string($v[$key])) $fail();
        if ($expectedOrigin !== 'https://portal-dev.imaginecomunica.com.br' || $v['origin'] !== $expectedOrigin
            || $v['host'] !== '127.0.0.1' || $identity->value('host') !== '127.0.0.1'
            || $v['origin'] !== $identity->value('origin')
            || !is_int($v['port']) || $v['port'] < 1 || $v['port'] > 65535
            || $v['port'] !== $identity->value('port') || $v['database'] !== $identity->value('database')
            || preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $v['database']) !== 1
            || strlen($v['password']) < 16 || strlen($v['password']) > 128 || str_contains($v['password'], "\0")) $fail();
        foreach (['identity_password', 'envelope_password', 'proof_key', 'totp_key', 'scope_pepper'] as $key) {
            if (hash_equals((string) $identity->value($key), $v['password'])) $fail();
        }
        foreach ([hex2bin((string) $identity->value('proof_key')), hex2bin((string) $identity->value('scope_pepper')),
            base64_decode((string) $identity->value('totp_key'), true)] as $key) {
            if (is_string($key) && hash_equals($key, $v['password'])) $fail();
        }
        return new self($v);
    }

    public function databaseName(): string { return $this->configuration()['database']; }

    public function connect(): PDO
    {
        $v = $this->configuration();
        try {
            return new PDO('pgsql:host=127.0.0.1;port='.$v['port'].';dbname='.$v['database']
                .';connect_timeout=3;sslmode=disable'
                .";options='--statement_timeout=5000 --lock_timeout=2000 --idle_in_transaction_session_timeout=30000'",
                'portal_onepage_runtime', $v['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false]);
        } catch (Throwable) { throw new RuntimeException('Native Onepage database unavailable.'); }
    }

    private function configuration(): array
    {
        if (self::$values === null || !isset(self::$values[$this])) throw new RuntimeException('Private Onepage configuration required.');
        return self::$values[$this];
    }

    public function __debugInfo(): array { return ['configuration' => '[redacted]']; }
    public function __serialize(): array { throw new RuntimeException('Onepage runtime cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new RuntimeException('Onepage runtime cannot be reconstructed.'); }
    public function __wakeup(): void { throw new RuntimeException('Onepage runtime cannot be reconstructed.'); }
    public static function __set_state(array $data): self { throw new RuntimeException('Onepage runtime cannot be reconstructed.'); }
    private function __clone(): void {}
}
