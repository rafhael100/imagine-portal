<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;

/**
 * F372 non-operational grant observation. A grant preflight is never
 * resource ownership/authorization; online witness evidence is not GO.
 */
final readonly class FencedPortalGrantReadCandidate
{
    public function __construct(
        private PostgresPortalPermissionPreflight $permission,
        private OnlineWitnessFenceCandidate $fence,
    ) {}

    public function inspectCandidateGrant(string $reference, string $organization, string $permission): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $reference) !== 1
            || !$this->fence->matchesPinnedOrganization($organization)) {
            return [
                'schema' => 'imagine.portal.f371.online-witness-prepost.v1',
                'status' => 'CANDIDATE_SCOPE_DENIED',
                'canAuthorizeHttp' => false,
                'canAuthorizeBusiness' => false,
                'canIssueSession' => false,
                'canReissueCredential' => false,
                'canRecoverFromRestore' => false,
                'canDeploy' => false,
            ];
        }

        return $this->fence->inspectReadOnly(
            fn(): bool => $this->permission->hasCandidateGrant($reference,$organization,$permission)
        );
    }
}
