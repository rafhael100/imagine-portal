<?php

declare(strict_types=1);

namespace App\Labs\F24;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F22\SyntheticPgServiceReadAdapter;
use PDO;

/**
 * F24 entrypoint accepts only ephemeral HMAC synthetic envelopes, checks
 * a PostgreSQL membership/session snapshot and DOES NOT authorize customers.
 *
 * This class is never bound into Laravel providers or HTTP routes.
 */
final readonly class F24SyntheticAuthorizedReader
{
    public function __construct(
        private PDO $pdo,
        private string $ephemeralLabKey,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || strlen($ephemeralLabKey) !== 32) {
            throw new \LogicException('F24 requires a disposable lab environment and key.');
        }
    }

    public function read(
        ?OrganizationId $organization,
        ?string $fixtureToken,
        int $now,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        if ($organization === null) {
            return DemoServiceRelationPage::blocked('missing_organization');
        }
        if ($fixtureToken === null) {
            return DemoServiceRelationPage::blocked('invalid_lab_identity_evidence');
        }
        $proof = F24LabSessionProof::verifyFixture(
            $fixtureToken, $this->ephemeralLabKey, $now,
        );
        if ($proof === null
            || !$proof->selectedOrganization()->equals($organization)) {
            return DemoServiceRelationPage::blocked('invalid_lab_identity_evidence');
        }
        return (new SyntheticPgServiceReadAdapter($this->pdo))->read(
            $organization, $limit, $after, $proof,
        );
    }
}
