<?php

declare(strict_types=1);

namespace App\Labs\F26;

use App\Domain\Authorization\Permission;
use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use Throwable;

/**
 * NON-OPERATIONAL orchestration experiment, not an auth gateway.
 *
 * Connects existing F1 synthetic PortalAuthorityBoundary (never authorizes
 * HTTP) to F24 test-only signed fixture, membership/RLS and PG read.
 * No provider/controller route binding, tokens or real identities.
 *
 * A synthetic-positive F1 verdict is never trusted for customer access.
 * It only permits a test-only read from the disposable F21/F24 database.
 */
final readonly class F26AuthorityReadRehearsal
{
    public function __construct(
        private PortalAuthorityBoundary $boundary,
        private PortalSessionAuthority $sessions,
        private F24SyntheticAuthorizedReader $pgLabReader,
        private string $ephemeralLabKey,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || strlen($ephemeralLabKey) !== 32) {
            throw new \LogicException('F26 only permits disposable fixtures and ephemeral key.');
        }
    }

    public function read(
        ?string $opaqueSessionReference,
        ?OrganizationId $organization,
        ?string $opaqueResourceReference,
        ?string $syntheticFixtureToken,
        int $now,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        if ($organization === null) {
            return DemoServiceRelationPage::blocked('missing_organization');
        }
        if (!is_string($opaqueSessionReference)
            || preg_match('/\ADEMO-SESSION-[A-Z0-9-]{1,40}\z/D', $opaqueSessionReference) !== 1
            || !is_string($opaqueResourceReference)
            || preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $opaqueResourceReference) !== 1
            || !is_string($syntheticFixtureToken) || $now <= 0) {
            return DemoServiceRelationPage::blocked('untrusted_lab_authority');
        }

        $proof = F24LabSessionProof::verifyFixture(
            $syntheticFixtureToken, $this->ephemeralLabKey, $now,
        );
        if ($proof === null
            || !$proof->selectedOrganization()->equals($organization)
            || !hash_equals($proof->syntheticSessionId(), $opaqueSessionReference)) {
            return DemoServiceRelationPage::blocked('untrusted_lab_authority');
        }

        try {
            // This F1 source is still a fake: it might change between the two
            // calls below. F24 SQL revision/RLS checks remain mandatory.
            $snapshot = $this->sessions->find($opaqueSessionReference);
            if ($snapshot === null
                || !hash_equals($snapshot->actorId->value, $proof->syntheticSubjectId())
                || !$snapshot->passesSyntheticFreshnessChecks($now)) {
                return DemoServiceRelationPage::blocked('untrusted_lab_authority');
            }
            $verdict = $this->boundary->inspect(
                $opaqueSessionReference,
                $organization,
                $opaqueResourceReference,
                Permission::ViewServices->value,
                $now,
            );

            // Refuse silent promotion if a future F1 refactor ever turns a
            // preview verdict into an actual HTTP authority.
            if (!$verdict->syntheticChecksPass || $verdict->canAuthorizeHttp()) {
                return DemoServiceRelationPage::blocked('untrusted_lab_authority');
            }

            return $this->pgLabReader->read(
                $organization, $syntheticFixtureToken, $now, $limit, $after,
            );
        } catch (Throwable) {
            // Never leak session, membership, source or DB error details.
            return DemoServiceRelationPage::blocked('untrusted_lab_authority');
        }
    }
}
