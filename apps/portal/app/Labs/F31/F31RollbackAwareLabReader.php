<?php

declare(strict_types=1);

namespace App\Labs\F31;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use Throwable;

/**
 * F31 test-only facade for the F24 signed synthetic session path.
 *
 * The separately booted read-only witness refuses stale proof revisions
 * even if the primary fixture's session/membership rows were restored.
 * Checks before and after primary read narrow but DO NOT eliminate the
 * race: a revocation after the second check could still be unobserved.
 */
final readonly class F31RollbackAwareLabReader
{
    public function __construct(
        private F24SyntheticAuthorizedReader $primary,
        private F31ExternalWitnessReader $witness,
        private string $ephemeralKey,
    ) {
        if (getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || strlen($ephemeralKey) !== 32) {
            throw new \LogicException('F31 never authorizes nonlaboratory traffic.');
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
        if (!is_string($fixtureToken)) {
            return DemoServiceRelationPage::blocked('invalid_lab_identity_evidence');
        }

        try {
            $proof = F24LabSessionProof::verifyFixture(
                $fixtureToken, $this->ephemeralKey, $now,
            );
            if ($proof === null
                || !$proof->selectedOrganization()->equals($organization)) {
                return DemoServiceRelationPage::blocked('invalid_lab_identity_evidence');
            }

            $before = $this->witness->observe($organization);
            if ($before === null) {
                return DemoServiceRelationPage::blocked('unavailable_external_witness');
            }
            if (!$before->accepts($proof)) {
                return DemoServiceRelationPage::blocked('stale_external_witness');
            }

            $preview = $this->primary->read(
                $organization, $fixtureToken, $now, $limit, $after,
            );
            if (!$preview->isPreparedPreview()) {
                return $preview; // preserve existing F24/F28/F29/F30 denials
            }

            // Fail closed if witness disappears, changes, or exceeds the
            // signed revisions while the read is being prepared.
            $afterFloor = $this->witness->observe($organization);
            if ($afterFloor === null) {
                return DemoServiceRelationPage::blocked('unavailable_external_witness');
            }
            if (!$before->matches($afterFloor)
                || !$afterFloor->accepts($proof)) {
                return DemoServiceRelationPage::blocked('stale_external_witness');
            }

            return $preview; // STILL CANNOT AUTHORIZE ANY REAL ACCESS.
        } catch (Throwable) {
            return DemoServiceRelationPage::blocked('unavailable_external_witness');
        }
    }
}
