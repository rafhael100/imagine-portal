<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DisposablePreparationTest extends TestCase
{
    public static function refusals(): iterable
    {
        foreach (['production', 'wrong-default', 'no-ci', 'no-p20', 'remote', 'hostname',
            'multi-host', 'cidr-config', 'bad-port', 'other-port', 'wrong-db', 'url',
            'read-routing', 'dsn-override', 'options', 'wrong-driver', 'missing-local',
            'control-failed', 'control-empty', 'control-duplicate', 'exception', 'fatal',
            'exit-zero', 'missing-result', 'empty-proof'] as $case) {
            yield $case => [$case, false];
        }
        foreach (['sql-db', 'sql-addr', 'sql-subnet', 'sql-port', 'sql-dir', 'sql-start',
            'sql-id', 'sql-exception', 'missing-sql', 'config-changed', 'cluster-changed'] as $case) {
            yield $case => [$case, true];
        }
    }

    #[DataProvider('refusals')]
    public function test_refusal_exits_nonzero_without_reaching_sentinel(string $case, bool $queried): void
    {
        $sentinel = sys_get_temp_dir().'/p20-sentinel-'.bin2hex(random_bytes(8));
        try {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/p20/preparation-process.php', $case, $sentinel]);
            $process->setTimeout(10);
            $process->run();
            self::assertNotSame(0, $process->getExitCode());
            self::assertFileDoesNotExist($sentinel);
            self::assertSame($queried, file_exists($sentinel.'.query'));
            self::assertStringNotContainsString('SYNTHETIC_SECRET', $process->getOutput().$process->getErrorOutput());
        } finally {
            foreach ([$sentinel, $sentinel.'.query'] as $path) {
                if (is_file($path)) { unlink($path); }
            }
        }
    }

    public static function validAddresses(): iterable
    {
        yield 'host mask' => ['valid'];
        yield 'plain' => ['valid-plain'];
    }

    #[DataProvider('validAddresses')]
    public function test_valid_host_releases_sentinel_with_structured_proof(string $case): void
    {
        $sentinel = sys_get_temp_dir().'/p20-sentinel-'.bin2hex(random_bytes(8));
        try {
            $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/p20/preparation-process.php', $case, $sentinel]);
            $process->setTimeout(10);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
            $proof = json_decode(file_get_contents($sentinel), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('P20_DISPOSABLE_TARGET_V1', $proof['schema']);
            self::assertSame('127.0.0.1', $proof['address']);
            self::assertSame('portal_automation_p20_ci', $proof['database']);
            $lines = explode("\n", trim($process->getOutput()));
            $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($result['completed']);
            self::assertSame($proof, $result['target']);
        } finally {
            foreach ([$sentinel, $sentinel.'.query'] as $path) {
                if (is_file($path)) { unlink($path); }
            }
        }
    }
}
