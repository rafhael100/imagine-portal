<?php

declare(strict_types=1);

namespace PortalTestTools\P20;

use Closure;
use RuntimeException;
use Throwable;

/** Local test tooling only; never an application authorization boundary. */
final class DisposablePreparation
{
    public const DATABASE = 'portal_automation_p20_ci';

    public static function require(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('P20_DISPOSABLE_TARGET_REJECTED');
        }
    }

    public static function configuration(array $snapshot, int $port): array
    {
        self::require($port >= 1024 && $port <= 65535);
        self::require(($snapshot['environment'] ?? null) === 'testing');
        self::require(($snapshot['default'] ?? null) === 'pgsql');
        self::require(($snapshot['ci'] ?? null) === 'true' && ($snapshot['p20'] ?? null) === '1');
        $config = $snapshot['connection'] ?? [];
        self::require(is_array($config));
        // Reject URL overrides, read/write routing, alternate DSNs and connector options.
        $allowed = ['driver', 'url', 'host', 'port', 'database', 'username', 'password',
            'charset', 'prefix', 'prefix_indexes', 'search_path', 'sslmode'];
        self::require(array_diff(array_keys($config), $allowed) === []);
        self::require(($config['url'] ?? null) === null || $config['url'] === '');
        self::require(($config['driver'] ?? null) === 'pgsql');
        self::require(($config['host'] ?? null) === '127.0.0.1');
        self::require(($config['port'] ?? null) === $port || ($config['port'] ?? null) === (string) $port);
        self::require(($config['database'] ?? null) === self::DATABASE);
        self::require(($config['username'] ?? null) === 'postgres');
        self::require(is_string($config['password'] ?? null) && $config['password'] !== '');
        self::require(($config['charset'] ?? null) === 'utf8');
        self::require(($config['prefix'] ?? null) === '' && ($config['search_path'] ?? null) === 'public');
        self::require(in_array($config['sslmode'] ?? null, ['prefer', 'disable'], true));
        unset($config['url']); // Laravel removes an empty URL before building the connection.
        return $config;
    }

    /** Accept only a single IPv4 loopback host, with optional full host mask. */
    public static function loopback(mixed $address): bool
    {
        return $address === '127.0.0.1' || $address === '127.0.0.1/32';
    }

    public static function controlIdentity(int $exitCode, string $output): string
    {
        self::require($exitCode === 0);
        self::require(preg_match_all('/^Database system identifier:\s+([0-9]+)\s*$/m', $output, $matches) === 1);
        return $matches[1][0];
    }

    public static function localEvidence(string $directory, string $controlBinary, int $port): array
    {
        self::require($directory !== '' && realpath($directory) === $directory && is_dir($directory));
        self::require(realpath($controlBinary) === $controlBinary && is_executable($controlBinary));
        self::require(basename($controlBinary) === 'pg_controldata');
        $pidFile = $directory.'/postmaster.pid';
        self::require(is_file($pidFile) && !is_link($pidFile));
        $lines = explode("\n", file_get_contents($pidFile));
        self::require(count($lines) >= 8 && ctype_digit($lines[0]) && ctype_digit($lines[2]));
        self::require((int) $lines[0] > 1 && (int) $lines[2] > 0);
        self::require($lines[1] === $directory && $lines[3] === (string) $port);
        self::require(trim($lines[5]) === '127.0.0.1' && trim($lines[7]) === 'ready');
        self::require(function_exists('posix_kill') && posix_kill((int) $lines[0], 0));
        $command = file_get_contents('/proc/'.$lines[0].'/cmdline');
        $args = explode("\0", $command);
        self::require(basename($args[0]) === 'postgres');
        $dataIndex = array_search('-D', $args, true);
        self::require($dataIndex !== false && ($args[$dataIndex + 1] ?? null) === $directory);
        $process = proc_open([$controlBinary, '-D', $directory], [0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), ['LC_ALL' => 'C']));
        self::require(is_resource($process));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $systemId = self::controlIdentity(proc_close($process), $output);
        return ['directory' => $directory, 'pid' => (int) $lines[0], 'started' => (int) $lines[2],
            'port' => $port, 'system_identifier' => $systemId];
    }

    private static function evidence(mixed $evidence, int $port): void
    {
        self::require(is_array($evidence) && array_keys($evidence) === ['directory', 'pid', 'started', 'port', 'system_identifier']);
        self::require(is_string($evidence['directory']) && str_starts_with($evidence['directory'], '/'));
        self::require(is_int($evidence['pid']) && $evidence['pid'] > 1 && is_int($evidence['started']) && $evidence['started'] > 0);
        self::require($evidence['port'] === $port && is_string($evidence['system_identifier']) && ctype_digit($evidence['system_identifier']));
    }

    private static function proof(mixed $proof): void
    {
        self::require(is_array($proof) && array_keys($proof) === ['schema', 'environment', 'connection', 'database', 'address', 'cluster']);
        self::require($proof['schema'] === 'P20_DISPOSABLE_TARGET_V1' && $proof['environment'] === 'testing');
        self::require($proof['connection'] === 'pgsql' && $proof['database'] === self::DATABASE && $proof['address'] === '127.0.0.1');
        self::require(is_array($proof['cluster']) && is_int($proof['cluster']['port'] ?? null));
        self::require($proof['cluster']['port'] >= 1024 && $proof['cluster']['port'] <= 65535);
        self::evidence($proof['cluster'], $proof['cluster']['port']);
    }

    /** The query callback is never reached until configuration and local evidence pass. */
    public static function run(Closure $snapshot, Closure $local, Closure $query, Closure $next, int $port): array
    {
        $original = $snapshot();
        self::configuration($original, $port);
        $evidence = $local();
        self::evidence($evidence, $port);
        $sql = $query();
        self::require(is_array($sql));
        self::require(($sql['database'] ?? null) === self::DATABASE && self::loopback($sql['address'] ?? null));
        self::require(($sql['port'] ?? null) === $port && ($sql['directory'] ?? null) === $evidence['directory']);
        self::require(($sql['started'] ?? null) === $evidence['started']);
        self::require(($sql['system_identifier'] ?? null) === $evidence['system_identifier']);
        $proof = ['schema' => 'P20_DISPOSABLE_TARGET_V1', 'environment' => 'testing', 'connection' => 'pgsql',
            'database' => self::DATABASE, 'address' => '127.0.0.1', 'cluster' => $evidence];
        // A missing/malformed structured proof cannot release the following stage.
        self::proof($proof);
        $encoded = json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::require(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR) === $proof);
        self::require($snapshot() === $original && $local() === $evidence);
        self::require(fwrite(STDOUT, $encoded.PHP_EOL) === strlen($encoded.PHP_EOL));
        self::require($next($proof) === 0);
        return ['schema' => 'P20_PREPARATION_RESULT_V1', 'completed' => true, 'target' => $proof];
    }

    /** Also catches exit(0)/fatal termination after Laravel installs its handlers. */
    public static function entry(Closure $main): never
    {
        $completed = false;
        register_shutdown_function(static function () use (&$completed): void {
            if (!$completed) {
                fwrite(STDERR, "P20_PREPARATION_INCOMPLETE\n");
                exit(1);
            }
        });
        try {
            $result = $main();
            self::require(is_array($result) && ($result['schema'] ?? null) === 'P20_PREPARATION_RESULT_V1'
                && ($result['completed'] ?? null) === true && is_array($result['target'] ?? null));
            self::proof($result['target']);
            $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
            self::require(fwrite(STDOUT, $encoded) === strlen($encoded));
            $completed = true;
            exit(0);
        } catch (Throwable $error) {
            // Do not leak connection strings, credentials or exception messages.
            fwrite(STDERR, "P20_PREPARATION_DENIED ".basename($error->getTrace()[0]['file'] ?? $error->getFile()).":".($error->getTrace()[0]['line'] ?? $error->getLine())."\n");
            exit(1);
        }
    }
}
