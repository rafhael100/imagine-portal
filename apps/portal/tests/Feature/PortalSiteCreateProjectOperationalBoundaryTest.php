<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteEngineContractVocabulary;
use App\Domain\SiteIntake\SiteEngineCreateProjectCandidateValidator;
use App\Domain\SiteIntake\SiteEngineCreateProjectDemoComposer;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * PORTAL-F54: prevent the offline CreateProject laboratory from becoming
 * an HTTP route, job, provider, queue or migration without a new review.
 * This is a negative regression gate, NOT AppSec certification.
 */
final class PortalSiteCreateProjectOperationalBoundaryTest extends TestCase
{
    /** @return list<string> */
    private function entrypointFiles(): array
    {
        $files = [base_path('public/index.php'), base_path('composer.json')];
        foreach ([
            'routes', 'bootstrap', 'config', 'app/Providers', 'app/Http',
            'app/Jobs', 'app/Console', 'app/Listeners', 'app/Observers',
            'database/migrations', 'database/seeders',
        ] as $directory) {
            $root = base_path($directory);
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() &&
                    in_array(strtolower($file->getExtension()), ['php', 'json'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files, SORT_STRING);
        return array_values(array_unique($files));
    }

    public function test_offline_composer_and_validator_are_not_wired_to_operational_entries(): void
    {
        $files = $this->entrypointFiles();
        self::assertGreaterThanOrEqual(8, count($files));
        $forbidden = [
            'SiteEngineCreateProjectDemoComposer',
            'SiteEngineCreateProjectCandidateValidator',
            'SiteEngineContractVocabulary',
            'candidate_shape_valid_only',
            'DEMO-IDEM-',
            'DEMO-TENANT-',
            'PORTAL_SITES_DEMO_CREATE',
        ];

        foreach ($files as $path) {
            self::assertFileExists($path);
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $contents,
                    'Unapproved CreateProject DEMO reference in operational entrypoint: ' . $path,
                );
            }
        }
    }

    public function test_candidate_createproject_routes_are_unavailable_for_all_common_methods(): void
    {
        $paths = [
            '/portal/sites/create-project',
            '/api/portal/sites/create-project',
            '/portal/sites/DEMO-SITE-REQ-A1/create-project',
            '/api/portal/sites/DEMO-SITE-REQ-A1/create-project',
            '/portal/sites/DEMO-SITE-REQ-A1/send',
            '/api/portal/sites/DEMO-SITE-REQ-A1/send',
            '/labs/sites-intake-f53/create-project',
        ];
        foreach ($paths as $path) {
            $payload = [
                'schemaVersion' => '1',
                'tenantRef' => 'DEMO-TENANT-org-a',
                'actor' => 'DEMO-REVIEWER-A',
                'authentication' => 'true',
            ];

            $this->get($path)->assertNotFound();
            $this->postJson($path, $payload)->assertNotFound();
            $this->putJson($path, $payload)->assertNotFound();
            $this->deleteJson($path, $payload)->assertNotFound();
        }
    }

    public function test_even_valid_local_fixture_never_grants_dispatch_or_approval(): void
    {
        $brief = new SiteBriefDraft(
            'DEMO-SITE-REQ-A1',
            new OrganizationId('org-a'),
            PortfolioProduct::Onepage,
            'Only synthetic text',
            'Synthetic audience',
            'Synthetic call to action',
            1,
        );
        $json = (new SiteEngineCreateProjectDemoComposer())->compose($brief);
        self::assertTrue(SiteEngineCreateProjectCandidateValidator::inspect($json)['valid']);
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayDispatch());
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayCreateRealOrder());
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayPublish());
        self::assertFalse(SiteEngineCreateProjectCandidateValidator::mayTransmit());
        self::assertFalse(SiteEngineCreateProjectCandidateValidator::isAuthenticated());
        self::assertFalse(SiteEngineCreateProjectCandidateValidator::isCustomerApprovalVerified());
        self::assertFalse(SiteEngineCreateProjectCandidateValidator::mayPublish());
        self::assertFalse(SiteEngineContractVocabulary::mayInvokeOperationalSitesEngine());
        self::assertFalse(SiteEngineContractVocabulary::mayAcceptCustomerApproval());
        self::assertFalse(SiteEngineContractVocabulary::mayRequestPublication());
    }
}
