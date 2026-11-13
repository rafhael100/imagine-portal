<?php

declare(strict_types=1);

// Run only through CI or an explicitly opted-in local developer shell.
// Never expose via routes, cron, or a customer-facing controller.
if (PHP_SAPI !== 'cli'
    || (getenv('GITHUB_ACTIONS') !== 'true'
        && getenv('PORTAL_ALLOW_LOCAL_STATIC_PREVIEW') !== '1')) {
    fwrite(STDERR, "Portable static preview is disabled outside an authorized local/CI CLI.\n");
    exit(64);
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Portable preview may run only in local/testing.\n");
    exit(65);
}

try {
    $target = storage_path('app/portal-demo-static');
    $pages = (new \App\Presentation\PortablePortalStaticPreview())->export($target);
    fwrite(STDOUT, "Exported ".count($pages)." fictional pages to private offline preview.\n");
    fwrite(STDOUT, "Do not publish this artifact as an authenticated customer Portal.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Portable offline preview refused: ".$exception->getMessage()."\n");
    exit(65);
}
