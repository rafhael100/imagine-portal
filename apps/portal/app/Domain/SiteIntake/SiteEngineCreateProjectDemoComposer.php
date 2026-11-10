<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;
use LogicException;

/**
 * F53: deterministic, metadata-only CreateProject v1 DEMO fixture.
 *
 * The inputs come exclusively from SiteBriefDraft (synthetic F42).
 * This is not a production command builder: no authentic contract,
 * tenant proof, customer acceptance, payment, network or publication.
 * Values are never sent to the Sites engine.
 */
final class SiteEngineCreateProjectDemoComposer
{
    public function compose(SiteBriefDraft $brief, int $contractRevision = 1): string
    {
        if ($contractRevision < 1 || $contractRevision > 100000) {
            throw new InvalidArgumentException('Only synthetic contract revisions are supported.');
        }

        $productCode = SiteEngineContractVocabulary::createProjectProductCode($brief->product);
        if ($productCode === null) {
            throw new InvalidArgumentException('Unsupported synthetic Sites product.');
        }

        $fingerprint = hash('sha256', implode('|', [
            $brief->organizationId->value,
            $brief->reference,
            (string) $brief->revision,
            (string) $contractRevision,
            $productCode,
        ]));

        // Only metadata that is already constrained to DEMO inputs.
        // Briefing free text, site contents and customer identifiers are omitted.
        $payload = [
            'schemaVersion' => SiteEngineContractVocabulary::SITES_CREATE_PROJECT_V1,
            'operationId' => 'DEMO-OP-' . substr($fingerprint, 0, 24),
            'tenantRef' => 'DEMO-TENANT-' . $brief->organizationId->value,
            'orderRef' => 'DEMO-ORDER-' . $brief->reference,
            'contractRef' => 'DEMO-CONTRACT-' . $brief->reference,
            'contractRevision' => $contractRevision,
            'productCode' => $productCode,
            'idempotencyKey' => 'DEMO-IDEM-' . $fingerprint,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (!SiteEngineCreateProjectCandidateValidator::inspect($json)['valid']) {
            throw new LogicException('Synthetic CreateProject fixture is incompatible with the local candidate contract.');
        }

        return $json;
    }

    public static function mayDispatch(): bool { return false; }
    public static function mayCreateRealOrder(): bool { return false; }
    public static function provesContractEligibility(): bool { return false; }
    public static function mayPublish(): bool { return false; }
}
