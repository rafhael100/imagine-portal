<?php

declare(strict_types=1);

/**
 * Standalone verifier for the exported *synthetic* offline CI artifact.
 * Does not connect to a database, bootstrap Laravel or authorize deployment.
 *
 * Usage: php bin/verify-pg18-showcase-package.php path/to/showcase
 */
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php verify-pg18-showcase-package.php DIRECTORY\n");
    exit(2);
}

try {
    $directory = $argv[1];
    if (!is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('Invalid showcase directory.');
    }
    $entries = scandir($directory);
    if ($entries === false || array_values(array_diff($entries, ['.', '..'])) !== ['index.html', 'manifest.json']) {
        throw new RuntimeException('Unexpected showcase file inventory.');
    }

    foreach (['index.html', 'manifest.json'] as $filename) {
        if (!is_file($directory.'/'.$filename) || is_link($directory.'/'.$filename)) {
            throw new RuntimeException('Invalid showcase file type.');
        }
    }
    $rawManifest = file_get_contents($directory.'/manifest.json');
    $html = file_get_contents($directory.'/index.html');
    if (!is_string($rawManifest) || !is_string($html) || $html === ''
        || strlen($html) > 2000000 || strlen($rawManifest) > 8192) {
        throw new RuntimeException('Empty or oversized showcase package.');
    }
    $manifest = json_decode($rawManifest, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)
        || array_keys($manifest) !== [
            'schema', 'synthetic_only', 'production_approved',
            'customer_access_approved', 'source', 'projects',
            'requests', 'html_sha256',
        ]
        || $manifest['schema'] !== 'imagine.portal.pg18-integrated-showcase.v1'
        || $manifest['synthetic_only'] !== true
        || $manifest['production_approved'] !== false
        || $manifest['customer_access_approved'] !== false
        || $manifest['source'] !== 'disposable_postgresql_18'
        || $manifest['projects'] !== 3
        || $manifest['requests'] !== 3
        || !is_string($manifest['html_sha256'])
        || preg_match('/\\A[a-f0-9]{64}\\z/D', $manifest['html_sha256']) !== 1
        || !hash_equals($manifest['html_sha256'], hash('sha256', $html))) {
        throw new RuntimeException('Invalid manifest or HTML digest.');
    }

    foreach ([
        'LABORATÓRIO DESCARTÁVEL', 'DEMO-PROJECT-ONEPAGE',
        'DEMO-PROJECT-LANDING', 'DEMO-PROJECT-PERFORMANCE',
        'DEMO-REQ-ONEPAGE', 'DEMO-REQ-LANDING', 'DEMO-REQ-PERFORMANCE',
        'noindex,nofollow',
    ] as $required) {
        if (!str_contains($html, $required)) {
            throw new RuntimeException('Missing required synthetic display marker.');
        }
    }
    if (substr_count($html, '<table>') !== 2
        || substr_count($html, 'role="region"') !== 2
        || substr_count($html, 'tabindex="0"') !== 2
        || substr_count($html, 'data:image/png;base64,') !== 1
        || preg_match('~<\\s*(script|form|input|iframe|button|object|embed|link)\\b~i', $html) === 1
        || preg_match('~https?://|mailto:|javascript:~i', $html) === 1) {
        throw new RuntimeException('Unsafe or incomplete offline HTML.');
    }

    echo "Verified synthetic-only offline showcase: ".hash('sha256', $html)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Synthetic showcase verification failed: ".$error->getMessage()."\n");
    exit(1);
}
