<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\PortalAccess\Authority\PortalBoundaryVerdict;
use Tests\TestCase;

/**
 * PORTAL-F27: regression guard for the current closed Laravel bootstrap.
 * This does NOT certify all future endpoints, IAM, PHP autoload isolation
 * or the security of any external provider / database.
 */
final class PortalLabIsolationBootGateTest extends TestCase
{
    /** @return list<string> */
    private function entrypoints(): array
    {
        $files = [
            base_path('bootstrap/app.php'),
            base_path('bootstrap/providers.php'),
            base_path('public/index.php'),
        ];
        foreach (['routes/*.php', 'app/Providers/*.php', 'config/*.php'] as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $path) {
                $files[] = $path;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    public function test_no_lab_reader_is_wired_into_an_application_entrypoint(): void
    {
        $entrypoints = $this->entrypoints();
        self::assertGreaterThanOrEqual(6, count($entrypoints));
        foreach ($entrypoints as $file) {
            self::assertFileExists($file);
            $content = file_get_contents($file);
            self::assertIsString($content);
            foreach ([
                'App\\Labs\\',
                'SyntheticPgServiceReadAdapter',
                'F24LabSessionProof',
                'F24SyntheticAuthorizedReader',
                'F26AuthorityReadRehearsal',
                'F22_ENABLE_LAB',
                'F22_PG_PASSWORD_',
                'F24_PG_PASSWORD_',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $content,
                    'Laboratory dependency referenced from application entrypoint: '.$file,
                );
            }
        }
    }

    public function test_only_the_explicitly_allowed_health_route_is_registered(): void
    {
        $routes = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $routes[] = $route->uri();
            self::assertStringNotContainsString(
                'App\\Labs\\',
                $route->getActionName(),
                'Never bind the synthetic database readers into actual routes.',
            );
        }
        sort($routes, SORT_STRING);
        self::assertSame(['up'], $routes);
    }

    public function test_auth_payment_service_and_lab_endpoints_remain_404(): void
    {
        foreach ([
            '/login', '/logout', '/register', '/portal',
            '/portal/services', '/portal/contracts',
            '/api/portal/services', '/api/portal/session',
            '/api/f22', '/api/f24', '/api/f26',
            '/labs/f21', '/labs/f22', '/labs/f24', '/labs/f26',
            '/crm/sso', '/checkout', '/university/activate',
        ] as $endpoint) {
            $this->get($endpoint)->assertNotFound();
        }
    }

    public function test_normal_ci_phpunit_suites_exclude_lab_only_integration_tests(): void
    {
        $configuration = file_get_contents(base_path('phpunit.xml'));
        self::assertIsString($configuration);
        $xml = simplexml_load_string($configuration);
        self::assertNotFalse($xml);
        $names = [];
        $dirs = [];
        foreach ($xml->testsuites->testsuite as $suite) {
            $names[] = (string) $suite['name'];
            foreach ($suite->directory as $dir) {
                $dirs[] = (string) $dir;
            }
        }
        self::assertSame(['Unit', 'Feature'], $names);
        self::assertSame(['tests/Unit', 'tests/Feature'], $dirs);
        self::assertNotContains('tests/Integration', $dirs);
        self::assertDirectoryExists(base_path('tests/Integration'));
    }

    public function test_synthetic_candidate_and_prepared_page_never_authorize_real_actions(): void
    {
        $verdict = PortalBoundaryVerdict::syntheticCandidate();
        self::assertTrue($verdict->syntheticChecksPass);
        self::assertFalse($verdict->canAuthorizeHttp());

        $page = DemoServiceRelationPage::prepared(
            [[
                'reference' => 'DEMO-SVC-ONEPAGE',
                'service' => 'Onepage fictícia',
                'request_count' => 1,
                'contract_count' => 0,
            ]],
            1, 1, 0, null,
        );
        self::assertTrue($page->isPreparedPreview());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());

        foreach (['untrusted_db_session', 'unsafe_db_schema', 'revoked_lab_identity',
            'invalid_lab_identity_evidence', 'untrusted_lab_authority'] as $reason) {
            $denied = DemoServiceRelationPage::blocked($reason);
            self::assertSame([], $denied->rows);
            self::assertFalse($denied->isPreparedPreview());
            self::assertFalse($denied->authorizesServiceAccess());
        }
    }
}
