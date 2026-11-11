<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Presentation\DemoPortalArtifactManifest;
use Illuminate\Console\Command;
use Throwable;

/**
 * F135: inspect only the application's fixed local synthetic export directory.
 * No --path input, network, upload, deployment or customer record access.
 */
final class VerifyDemoPortalPreview extends Command
{
    protected $signature = 'portal:demo-verify';

    protected $description = 'Verify hashes and inventory of the private synthetic Portal preview';

    public function handle(DemoPortalArtifactManifest $manifest): int
    {
        try {
            $manifest->verify(storage_path('app/portal-demo-preview'));
        } catch (Throwable $error) {
            $this->error('Private synthetic preview integrity check failed.');
            return self::FAILURE;
        }

        $this->info('Verified nine synthetic HTML preview pages. No publication or customer access.');
        return self::SUCCESS;
    }
}
