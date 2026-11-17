<?php
declare(strict_types=1);
require '/run/recovery-tools/AuthenticatedBundle.php';
try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('ADMIN_PHASE8_RECOVERY_CI') !== '1') throw new RuntimeException('ci_only');
    $action = $argv[1] ?? '';
    if ($action === 'seal' && count($argv) === 6) {
        Imagine\PerformanceRecovery\AuthenticatedBundle::seal($argv[2], $argv[3], $argv[4], $argv[5]);
    } elseif ($action === 'open' && count($argv) === 6) {
        Imagine\PerformanceRecovery\AuthenticatedBundle::open($argv[2], $argv[3], $argv[4], $argv[5]);
    } else throw new RuntimeException('action');
    echo "PHASE8_AUTHENTICATED_BUNDLE_OK\n";
} catch (Throwable $error) {
    // A constant diagnostic avoids exposing paths, raw archive contents or keys.
    fwrite(STDERR, "PHASE8_AUTHENTICATED_BUNDLE_DENIED\n");
    exit(1);
}
