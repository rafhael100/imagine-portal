<?php
declare(strict_types=1);

// Explicit offline maintenance. No autoloader, Laravel bootstrap, provider,
// configuration file or compiled view is ever required/evaluated here.
// Preconditions: own private volumes, application writers stopped, trusted UID.
// PHP path checks cannot provide an atomic guarantee against a concurrent writer.
$stage = 'arguments';
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException('maintenance_io', 0, $severity); });

function directoryIdentity(string $path): array
{
    if (!str_starts_with($path, '/') || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) throw new RuntimeException('directory_path');
    $identities = [];
    $current = '';
    foreach (explode('/', substr($path, 1)) as $component) {
        if ($component === '') continue;
        $current .= '/'.$component;
        clearstatcache(true, $current);
        $stat = lstat($current);
        if (($stat['mode'] & 0170000) !== 0040000 || realpath($current) !== $current) throw new RuntimeException('directory_link_or_type');
        // A named Docker volume is allowed to have its own device number.
        $identities[$current] = ['dev'=>$stat['dev'], 'ino'=>$stat['ino'], 'mode'=>$stat['mode']];
    }
    return $identities;
}

function regularIdentity(string $path, string $directory): array
{
    if (dirname($path) !== $directory) throw new RuntimeException('outside_cache_root');
    clearstatcache(true, $path);
    $stat = lstat($path);
    if (($stat['mode'] & 0170000) !== 0100000 || realpath($path) !== $path) throw new RuntimeException('file_link_or_type');
    return ['dev'=>$stat['dev'], 'ino'=>$stat['ino'], 'mode'=>$stat['mode'], 'size'=>$stat['size'], 'mtime'=>$stat['mtime'], 'ctime'=>$stat['ctime']];
}

try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !in_array($argv[1], ['--check', '--apply'], true)) throw new RuntimeException('explicit_action_required');
    $root = dirname(__DIR__, 2);
    $script = $root.'/tools/performance-maintenance/clear-derived-cache.php';
    // No caller-selected root, environment override, relative script alias or
    // symlinked installation may redirect this operation to another tree.
    if (($_SERVER['SCRIPT_FILENAME'] ?? '') !== $script || ($argv[0] ?? '') !== $script) throw new RuntimeException('installed_script_required');
    $stage = 'preflight';
    directoryIdentity(dirname($script));
    regularIdentity($script, dirname($script));
    directoryIdentity($root.'/bootstrap');
    regularIdentity($root.'/bootstrap/app.php', $root.'/bootstrap');
    regularIdentity($root.'/artisan', $root);
    $roots = [$root.'/bootstrap/cache', $root.'/storage/framework/views'];
    $directorySnapshots = [];
    $candidates = [];
    foreach ($roots as $index=>$directory) {
        $directorySnapshots[$directory] = directoryIdentity($directory);
        if (!is_writable($directory)) throw new RuntimeException('cache_directory_not_writable');
        foreach (scandir($directory, SCANDIR_SORT_ASCENDING) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $directory.'/'.$name;
            clearstatcache(true, $path);
            $stat = lstat($path);
            // Refuse any immediate symlink, including an unselected filename.
            // Subdirectories are left untouched; nothing is walked recursively.
            if (($stat['mode'] & 0170000) === 0120000) throw new RuntimeException('cache_entry_symlink');
            $allowed = $index === 0
                ? in_array($name, ['packages.php','services.php','config.php','events.php'], true)
                    || (str_starts_with($name, 'routes') && str_ends_with($name, '.php'))
                : str_ends_with($name, '.php');
            if ($allowed) $candidates[$path] = ['directory'=>$directory, 'identity'=>regularIdentity($path, $directory)];
        }
    }
    // Complete both roots and every candidate before the first unlink. Clearing
    // PHP's stat/realpath cache is essential for the second observation.
    foreach ($directorySnapshots as $directory=>$identity) {
        if (directoryIdentity($directory) !== $identity) throw new RuntimeException('directory_changed');
    }
    foreach ($candidates as $path=>$candidate) {
        if (regularIdentity($path, $candidate['directory']) !== $candidate['identity']) throw new RuntimeException('candidate_changed');
    }
    $removed = 0;
    if ($argv[1] === '--apply') {
        $stage = 'remove';
        foreach ($candidates as $path=>$candidate) {
            if (directoryIdentity($candidate['directory']) !== $directorySnapshots[$candidate['directory']]
                || regularIdentity($path, $candidate['directory']) !== $candidate['identity']) throw new RuntimeException('candidate_changed');
            if (!unlink($path)) throw new RuntimeException('unlink_failed');
            $removed++;
        }
    }
    echo json_encode(['schema'=>'performance.derived-cache-maintenance.v1', 'mode'=>$argv[1], 'eligible_files'=>count($candidates), 'removed_files'=>$removed], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // No raw path, file content, provider name or exception message is emitted.
    fwrite(STDERR, 'PERFORMANCE_DERIVED_CACHE_DENIED stage='.$stage.PHP_EOL);
    exit(1);
}
