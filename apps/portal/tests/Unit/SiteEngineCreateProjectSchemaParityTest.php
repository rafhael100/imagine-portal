<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteEngineContractVocabulary;
use App\Domain\SiteIntake\SiteEngineCreateProjectCandidateValidator;
use App\Domain\SiteIntake\SiteEngineCreateProjectDemoComposer;
use PHPUnit\Framework\TestCase;

/**
 * F55: local snapshot parity against imagine-sites pinned CreateProject v1.
 * No network fetch, schema compilation, production authorization or delivery.
 */
final class SiteEngineCreateProjectSchemaParityTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $path = dirname(__DIR__, 2) . '/labs/sites-intake-f55/create-project.schema.snapshot.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_pinned_snapshot_is_draft202012_closed_object_with_all_eight_required_fields(): void
    {
        $schema = self::schema();
        $keys = SiteEngineContractVocabulary::createProjectV1Keys();
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame($keys, $schema['required']);
        self::assertSame($keys, array_keys($schema['properties']));
        self::assertCount(8, $keys);
    }

    public function test_product_version_and_revision_are_equal_to_sites_contract(): void
    {
        $properties = self::schema()['properties'];
        self::assertSame('1', $properties['schemaVersion']['const']);
        self::assertSame('1', SiteEngineContractVocabulary::SITES_CREATE_PROJECT_V1);
        self::assertSame('integer', $properties['contractRevision']['type']);
        self::assertSame(1, $properties['contractRevision']['minimum']);
        self::assertSame(['onepage', 'landing_page'], $properties['productCode']['enum']);
        self::assertSame(
            'onepage',
            SiteEngineContractVocabulary::createProjectProductCode(PortfolioProduct::Onepage),
        );
        self::assertSame(
            'landing_page',
            SiteEngineContractVocabulary::createProjectProductCode(PortfolioProduct::LandingPage),
        );
    }

    public function test_reference_constraints_match_the_five_expected_fields(): void
    {
        $properties = self::schema()['properties'];
        $referenceKeys = ['operationId', 'tenantRef', 'orderRef', 'contractRef', 'idempotencyKey'];
        $pattern = '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$';

        foreach ($referenceKeys as $key) {
            self::assertSame('string', $properties[$key]['type']);
            self::assertSame($pattern, $properties[$key]['pattern']);
        }

        self::assertSame(5, count($referenceKeys));
        self::assertNotContains('actor', array_keys($properties));
        self::assertNotContains('authenticated', array_keys($properties));
        self::assertNotContains('publishable', array_keys($properties));
    }

    public function test_local_synthetic_composer_respects_pinned_upstream_types_and_enums(): void
    {
        $schema = self::schema();
        foreach ([PortfolioProduct::Onepage, PortfolioProduct::LandingPage] as $product) {
            $draft = new SiteBriefDraft(
                'DEMO-SITE-REQ-A1',
                new OrganizationId('org-a'),
                $product,
                'Exemplo fictício',
                'Público fictício',
                'Solicitar informação',
                1,
            );
            $json = (new SiteEngineCreateProjectDemoComposer())->compose($draft);
            self::assertTrue(SiteEngineCreateProjectCandidateValidator::inspect($json)['valid']);
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($schema['required'], array_keys($decoded));
            self::assertSame($schema['properties']['schemaVersion']['const'], $decoded['schemaVersion']);
            self::assertContains($decoded['productCode'], $schema['properties']['productCode']['enum']);
            self::assertGreaterThanOrEqual($schema['properties']['contractRevision']['minimum'], $decoded['contractRevision']);
            foreach (['operationId', 'tenantRef', 'orderRef', 'contractRef', 'idempotencyKey'] as $key) {
                $pattern = '~' . $schema['properties'][$key]['pattern'] . '~D';
                self::assertSame(1, preg_match($pattern, $decoded[$key]), 'Invalid reference ' . $key);
            }
        }
    }

    public function test_snapshot_is_not_live_authority_or_operational_message(): void
    {
        self::assertFalse(SiteEngineCreateProjectDemoComposer::mayDispatch());
        self::assertFalse(SiteEngineCreateProjectCandidateValidator::mayTransmit());
        self::assertFalse(SiteEngineContractVocabulary::mayInvokeOperationalSitesEngine());
    }
}
