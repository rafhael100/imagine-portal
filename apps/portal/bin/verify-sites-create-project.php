<?php

declare(strict_types=1);

use App\Domain\SiteIntake\SiteEngineSchemaDriftGate;

// Intentionally CLI-only. Never install as a Laravel route/job or execute
// against remote URLs, customer data, or production Sites services.
require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php bin/verify-sites-create-project.php /local/checkout/imagine-sites/contracts/portal-sites/v1/create-project.schema.json\n");
    exit(2);
}

$snapshot = dirname(__DIR__) . '/labs/sites-intake-f55/create-project.schema.snapshot.json';
$outcome = (new SiteEngineSchemaDriftGate())->compare($argv[1], $snapshot);
fwrite($outcome['matched'] ? STDOUT : STDERR, json_encode($outcome, JSON_THROW_ON_ERROR) . PHP_EOL);
exit($outcome['matched'] ? 0 : 1);
