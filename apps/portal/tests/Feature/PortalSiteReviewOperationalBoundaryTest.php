<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteReviewOutcome;
use App\Labs\F44\F44SyntheticReviewWriteResult;
use App\Labs\F45\F45EditorialConsistencyResult;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * F46 regression gate: F43–F45 fake site approval and release consistency
 * MUST remain unreachable from the Laravel operational HTTP/bootstrap surface.
 * Negative checks cannot replace a formal security review.
 */
final class PortalSiteReviewOperationalBoundaryTest extends TestCase
{
    /** @return list<string> */
    private function bootFiles(): array
    {
        $files=[base_path('public/index.php'),base_path('composer.json')];
        foreach ([
            'routes','bootstrap','config','app/Providers','app/Http',
            'app/Jobs','app/Console','app/Listeners','app/Observers',
            'database/migrations','database/seeders',
        ] as $directory) {
            $root=base_path($directory);
            if (!is_dir($root)) {
                continue;
            }
            $it=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile()
                    && in_array(strtolower($file->getExtension()),['php','json'],true)) {
                    $files[]=$file->getPathname();
                }
            }
        }
        sort($files,SORT_STRING);
        return array_values(array_unique($files));
    }

    public function test_fake_site_review_or_release_reader_cannot_be_registered_in_bootstrap_or_migrations(): void
    {
        $files=$this->bootFiles();
        self::assertGreaterThanOrEqual(8,count($files));
        $needles=[
            'App\\Labs\\F44\\',
            'App\\Labs\\F45\\',
            'F44SyntheticSiteReviewWriter',
            'F45SyntheticReleaseConsistencyReader',
            'F44SyntheticReviewWriteResult',
            'F45EditorialConsistencyResult',
            'DemoSiteReviewJournal',
            'SiteReviewCommand',
            'F44_ENABLE_LAB',
            'F45_ENABLE_LAB',
            'f44.site_review',
            'f44.review_receipt',
            'f45_consistency_reader',
            'consistent_approved_demo',
        ];
        foreach ($files as $path) {
            self::assertFileExists($path);
            $contents=file_get_contents($path);
            self::assertIsString($contents);
            foreach ($needles as $needle) {
                self::assertStringNotContainsString(
                    $needle,$contents,
                    'Unapproved fake site review is reachable from bootstrap: '.$path,
                );
            }
        }
    }

    public function test_approval_publication_and_content_preview_endpoints_remain_404(): void
    {
        $routes=[
            '/api/portal/sites/DEMO-SITE-REQ-A1/review',
            '/api/portal/sites/DEMO-SITE-REQ-A1/approve',
            '/api/portal/sites/DEMO-SITE-REQ-A1/publish',
            '/api/portal/sites/DEMO-SITE-REQ-A1/release',
            '/portal/sites/DEMO-SITE-REQ-A1/approve',
            '/portal/sites/DEMO-SITE-REQ-A1/publish',
            '/portal/sites/DEMO-SITE-REQ-A1/preview',
            '/portal/sites/approvals',
            '/portal/sites/publication',
            '/labs/f43/approve',
            '/labs/f44/review',
            '/labs/f45/consistency',
        ];
        foreach ($routes as $uri) {
            $this->postJson($uri,[
                'actor'=>'DEMO-REVIEWER-A',
                'organization_id'=>'org-a',
                'decision'=>'approve',
                'content_sha256'=>str_repeat('a',64),
            ])->assertNotFound();
            $this->get($uri)->assertNotFound();
        }
    }

    public function test_even_approved_fake_sql_and_consistency_results_always_forbid_real_publication(): void
    {
        $sql=new F44SyntheticReviewWriteResult(
            'applied_synthetic_sql','approved_demo',1,
        );
        self::assertTrue($sql->isSimulatedApproval());
        self::assertTrue($sql->wasWrittenOnlyToDisposableFixture());
        self::assertFalse($sql->authorizesPublishing());
        self::assertFalse($sql->isVerifiedConsent());
        self::assertFalse($sql->authorizesContractOrCharge());

        $check=new F45EditorialConsistencyResult('consistent_approved_demo');
        self::assertTrue($check->isConsistentInLab());
        self::assertFalse($check->mayPublishSite());
        self::assertFalse($check->isVerifiedClientApproval());
        self::assertFalse($check->mayAuthorizeContractOrCharge());

        $editorial=new SiteReviewOutcome('approved_in_memory','approved_demo',1);
        self::assertTrue($editorial->isSimulatedApproval());
        self::assertFalse($editorial->mayPublishSite());
        self::assertFalse($editorial->isVerifiedClientConsent());
        self::assertFalse($editorial->mayActivateContract());
    }

    public function test_unsubmitted_fake_sites_copy_candidate_cannot_be_published_or_charged(): void
    {
        $candidate=new SiteContentCandidate(
            new SiteBriefDraft(
                'DEMO-SITE-REQ-A1',
                new OrganizationId('org-a'),
                PortfolioProduct::LandingPage,
                'Explicar benefícios de uma solução para pequenas empresas.',
                'Público de negócios locais',
                'Solicitar uma proposta inicial',
                0,
            ),
            1,
            'Uma landing page para demonstrar os serviços',
            'Uma descrição totalmente fictícia para revisar a disposição de conteúdos de um site sem clientes reais.',
            'Solicitar mais informações',
        );
        self::assertSame(64,strlen($candidate->fingerprint));
        self::assertFalse($candidate->canPublish());
        self::assertFalse($candidate->canCharge());
        self::assertFalse($candidate->brief->canSubmitToPortal());
        self::assertFalse($candidate->brief->canPublishSite());
        self::assertFalse($candidate->brief->canChargeOrContract());
    }
}
