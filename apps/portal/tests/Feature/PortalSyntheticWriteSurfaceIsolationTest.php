<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Labs\F38\F38SyntheticWriteResult;
use App\Labs\F40\F40FakeRelayOutcome;
use App\Labs\F40\F40InMemoryFakeSink;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Tests\TestCase;

/**
 * F41 negative integration gate for currently *closed* Laravel bootstrap.
 * This does not certify a future production API, authentication, DB
 * ownership, policy semantics or external consumers.
 */
final class PortalSyntheticWriteSurfaceIsolationTest extends TestCase
{
    /** @return list<string> */
    private function bootReachableFiles(): array
    {
        $directories = [
            'routes',
            'bootstrap',
            'config',
            'app/Providers',
            'app/Http',
            'app/Console',
            'app/Jobs',
            'app/Listeners',
            'app/Observers',
            'database/seeders',
            'database/migrations',
        ];
        $files = [base_path('public/index.php'),base_path('composer.json')];

        foreach ($directories as $relative) {
            $directory=base_path($relative);
            if (!is_dir($directory)) {
                continue;
            }
            $it=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $entry) {
                if ($entry->isFile()
                    && in_array(strtolower($entry->getExtension()),['php','json'],true)) {
                    $files[]=$entry->getPathname();
                }
            }
        }
        sort($files,SORT_STRING);
        return array_values(array_unique($files));
    }

    public function test_no_synthetic_writers_or_relay_are_wired_into_bootstrap_providers_jobs_or_migrations(): void
    {
        $files=$this->bootReachableFiles();
        self::assertGreaterThanOrEqual(8,count($files));
        $forbidden=[
            'App\\Labs\\F38\\',
            'App\\Labs\\F39\\',
            'App\\Labs\\F40\\',
            'F38SyntheticRequestWriter',
            'F40SyntheticOutboxRelay',
            'F40InMemoryFakeSink',
            'F39_ENABLE_LAB',
            'F40_ENABLE_LAB',
            'f38.command_receipt',
            'f39.synthetic_request_outbox',
            'f40.fake_delivery_receipt',
            'f40.record_fake_delivery',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $source=file_get_contents($file);
            self::assertIsString($source);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,$source,
                    'Non-operational write/relay fixture leaked into Laravel boot surface: '.$file,
                );
            }
        }
    }

    public function test_no_http_or_api_endpoint_for_synthetic_request_writes_or_outbox_delivery(): void
    {
        foreach ([
            '/api/portal/requests',
            '/api/portal/request/DEMO-REQUEST-A',
            '/api/portal/requests/submit',
            '/api/f38','/api/f39','/api/f40',
            '/portal/request/DEMO-REQUEST-A/submit',
            '/portal/requests/DEMO-REQUEST-A/transition',
            '/portal/outbox',
            '/portal/notifications',
            '/portal/webhooks',
            '/labs/f38','/labs/f39','/labs/f40',
        ] as $url) {
            $this->postJson($url,[
                'organization'=>'org-a',
                'actor_kind'=>'imagine_team',
                'action'=>'submit',
            ])->assertNotFound();
            $this->get($url)->assertNotFound();
        }
    }

    public function test_dry_run_results_cannot_grant_access_or_claim_external_delivery(): void
    {
        $write=F38SyntheticWriteResult::blocked('source_unavailable');
        self::assertFalse($write->mayAuthorizeHttp());
        self::assertFalse($write->mayWriteProduction());
        self::assertFalse($write->mayCreateContractOrPayment());
        self::assertFalse($write->wasWrittenToDisposableFixture());

        $relay=new F40FakeRelayOutcome(true,'complete',0,0);
        self::assertTrue($relay->completed);
        self::assertFalse($relay->deliveredExternally());
        self::assertFalse($relay->authorizesCustomerAccess());

        $pending=new F40FakeRelayOutcome(false,'ack_pending',1,0);
        self::assertFalse($pending->completed);
        self::assertFalse($pending->deliveredExternally());
        self::assertFalse($pending->authorizesCustomerAccess());
    }

    public function test_fake_sink_can_only_record_opaque_demo_references_in_memory(): void
    {
        $sink=new F40InMemoryFakeSink('F39-DEMO-CMD-A-001');
        self::assertFalse($sink->simulate('F39-DEMO-CMD-A-001','{"test":1}'));
        self::assertSame(0,$sink->simulatedCount());
        self::assertTrue($sink->simulate('F39-DEMO-CMD-A-001','{"test":1}'));
        self::assertTrue($sink->simulate('F39-DEMO-CMD-A-001','{"test":1}'));
        self::assertFalse($sink->simulate('F39-DEMO-CMD-A-001','{"test":2}'));
        self::assertFalse($sink->simulate('https://example.org/hook','{}'));
        self::assertFalse($sink->simulate('../escape','{}'));
        self::assertSame(1,$sink->simulatedCount());
        self::assertSame(['F39-DEMO-CMD-A-001'],$sink->references());
    }
}
