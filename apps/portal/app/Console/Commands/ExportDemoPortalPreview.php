<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Presentation\DemoPortalStaticExporter;
use Illuminate\Console\Command;
use Throwable;

/** No HTTP, queue, customer data or remote side effects. */
final class ExportDemoPortalPreview extends Command
{
    protected $signature = 'portal:demo-export';

    protected $description = 'Export a local-only static HTML DEMO of the Imagine Portal';

    public function handle(DemoPortalStaticExporter $exporter): int
    {
        // Fixed private output location, no --output path injection or overwrite.
        $output = storage_path('app/portal-demo-preview');
        try {
            $pages = $exporter->export($output);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info('Exported ' . count($pages) . ' synthetic pages to: ' . $output);
        $this->warn('Internal local preview only; do not upload or expose these files publicly.');
        return self::SUCCESS;
    }
}
