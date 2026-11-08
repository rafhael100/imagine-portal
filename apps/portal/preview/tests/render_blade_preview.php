<?php

declare(strict_types=1);

/**
 * PVIEW-03: CLI-only static Blade export for synthetic browser QA.
 * Refuses non-runner destinations; does NOT start a server or create routes.
 * No database reads, authentication, accounts or financial operations.
 */
$portalRoot = dirname(__DIR__, 2);
$runner = getenv('RUNNER_TEMP');
$target = getenv('PVIEW_RENDER_FILE');

if (!is_string($runner) || $runner === '' || !is_dir($runner)
    || !is_string($target) || $target === '') {
    fwrite(STDERR, "[FAIL] PVIEW-03 requires a runner temp destination\n");
    exit(1);
}
$runnerReal = realpath($runner);
$targetParent = realpath(dirname($target));
if ($runnerReal === false || $targetParent === false
    || !str_starts_with($targetParent . DIRECTORY_SEPARATOR, $runnerReal . DIRECTORY_SEPARATOR)
    || pathinfo($target, PATHINFO_EXTENSION) !== 'html') {
    fwrite(STDERR, "[FAIL] PVIEW-03 refused a non-temporary destination\n");
    exit(1);
}
require $portalRoot . '/vendor/autoload.php';
$app = require $portalRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$html = view('portal.demo.pview-navigation')->render();
if (!str_contains($html, 'DEMONSTRAÇÃO OFFLINE / LARAVEL')
    || substr_count($html, 'data-screen=') !== 8
    || substr_count($html, 'data-nav=') !== 8
    || str_contains($html, '<form')
    || str_contains($html, '<script src')
    || str_contains($html, '<iframe')) {
    fwrite(STDERR, "[FAIL] PVIEW-03 unexpected rendered demonstration document\n");
    exit(1);
}
if (file_put_contents($target, $html, LOCK_EX) !== strlen($html)) {
    fwrite(STDERR, "[FAIL] PVIEW-03 failed to write HTML\n");
    exit(1);
}
fwrite(STDOUT, "[PASS PVIEW-BLADE-EXPORT] 8 fictional sections rendered into runner temp\n");
