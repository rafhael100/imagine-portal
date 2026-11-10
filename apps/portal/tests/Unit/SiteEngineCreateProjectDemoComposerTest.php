<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteEngineCreateProjectCandidateValidator;
use App\Domain\SiteIntake\SiteEngineCreateProjectDemoComposer;
use PHPUnit\Framework\TestCase;

final class SiteEngineCreateProjectDemoComposerTest extends TestCase
{
    private static function brief(
        string $organization = 'org-a',
        PortfolioProduct $product = PortfolioProduct::Onepage,
        int $revision = 1,
        string $objective = 'DEMO local'
    ): SiteBriefDraft {
        return new SiteBriefDraft(
            'DEMO-SITE-REQ-A1',
            new OrganizationId($organization),
            $product,
            $objective,
            'Público sintético',
            'Ação sintética',
            $revision,
        );
    }

    public function test_compose_onepage_in_exact_existing_sites_schema_order(): void
    {
        $json = (new SiteEngineCreateProjectDemoComposer())->compose(self::brief());
        self::assertSame(
            ['valid' => true, 'reason' => 'candidate_shape_valid_only'],
            SiteEngineCreateProjectCandidateValidator::inspect($json),
        );
        $fields = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([
            'schemaVersion', 'operationId', 'tenantRef', 'orderRef',
            'contractRef', 'contractRevision', 'productCode', 'idempotencyKey',
        ], array_keys($fields));
        self::assertSame('onepage', $fields['productCode']);
        self::assertSame('DEMO-TENANT-org-a', $fields['tenantRef']);
        self::assertSame('DEMO-ORDER-DEMO-SITE-REQ-A1', $fields['orderRef']);
        self::assertSame(1, $fields['contractRevision']);
    }

    public function test_compose_landing_page_maps_to_engine_code(): void
    {
        $json = (new SiteEngineCreateProjectDemoComposer())->compose(
            self::brief(product: PortfolioProduct::LandingPage),
        );
        self::assertSame('landing_page', json_decode($json, true, 16, JSON_THROW_ON_ERROR)['productCode']);
        self::assertTrue(SiteEngineCreateProjectCandidateValidator::inspect($json)['valid']);
    }

    public function test_fixture_is_deterministic_and_changes_with_revisions(): void
    {
        $composer = new SiteEngineCreateProjectDemoComposer();
        $a = $composer->compose(self::brief(), 1);
        self::assertSame($a, $composer->compose(self::brief(), 1));
        $b = $composer->compose(self::brief(revision: 2), 1);
        $c = $composer->compose(self::brief(), 2);
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame(
            json_decode($a, true, 16, JSON_THROW_ON_ERROR)['idempotencyKey'],
            json_decode($b, true, 16, JSON_THROW_ON_ERROR)['idempotencyKey'],
        );
    }

    public function test_tenant_identity_changes_demo_fingerprint_and_tenant_ref(): void
    {
        $composer = new SiteEngineCreateProjectDemoComposer();
        $a = json_decode($composer->compose(self::brief('org-a')), true, 16, JSON_THROW_ON_ERROR);
        $b = json_decode($composer->compose(self::brief('org-b')), true, 16, JSON_THROW_ON_ERROR);
        self::assertNotSame($a['tenantRef'], $b['tenantRef']);
        self::assertNotSame($a['operationId'], $b['operationId']);
        self::assertNotSame($a['idempotencyKey'], $b['idempotencyKey']);
    }

    public function test_briefing_free_text_must_never_be_serialized_into_command(): void
    {
        $marker = 'SENSITIVE_FREE_TEXT_MUST_NOT_LEAK';
        $json = (new SiteEngineCreateProjectDemoComposer())->compose(
            self::brief(objective: $marker),
        );
        self::assertStringNotContainsString($marker, $json);
        self::assertSame(8, count(json_decode($json, true, 16, JSON_THROW_ON_ERROR)));
    }

    public function test_contract_revision_must_be_positive_and_bounded(): void
    {
        $composer = new SiteEngineCreateProjectDemoComposer();
        foreach ([-1, 0, 100001] as $bad) {
            try {
                $composer->compose(self::brief(), $bad);
                self::fail('Expected invalid synthetic revision to be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('synthetic', $e->getMessage());
            }
        }
    }

    public function test_no_demo_build_grants_transmission_order_authority_or_publication(): void
    {
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayDispatch());
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayCreateRealOrder());
        self::assertFalse(SiteEngineCreateProjectDemoComposer::provesContractEligibility());
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayPublish());
    }
}
