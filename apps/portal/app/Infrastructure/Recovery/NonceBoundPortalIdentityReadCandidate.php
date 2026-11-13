<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;

/**
 * F381: DO NOT BIND TO HTTP. This negative-only observation combines actual
 * PostgreSQL session/grant readers with two fresh signed HTTPS challenges.
 * A diagnostic true is never a login/permission/resource authorization.
 */
final readonly class NonceBoundPortalIdentityReadCandidate implements PortalWitnessOnlyIdentityRead
{
    public function __construct(
        private PortalBrowserAuthentication $sessions,
        private PostgresPortalPermissionPreflight $permissions,
        private NonceBoundHttpsWitnessReadCandidate $witness,
        private string $serverPinnedOrganization,
    ) {}

    public function inspectBusinessSession(string $reference): array
    {
        if (!self::validReference($reference)) {
            return self::denied('INVALID_CANDIDATE_REFERENCE');
        }
        return $this->witness->inspectReadOnly(
            fn(): bool => $this->sessions->isActiveBusinessSession($reference),
        );
    }

    public function inspectTenantPermission(
        string $reference,
        string $organization,
        string $permission,
    ): array {
        if (!self::validReference($reference)
            || !hash_equals($this->serverPinnedOrganization, $organization)
            || !in_array($permission, [
                'services.view','services.manage','billing.view','members.manage',
            ], true)) {
            return self::denied('CANDIDATE_SCOPE_OR_PERMISSION_DENIED');
        }
        return $this->witness->inspectReadOnly(
            fn(): bool => $this->permissions->hasCandidateGrant(
                $reference, $organization, $permission,
            ),
        );
    }

    private static function validReference(string $ref): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D',$ref)===1;
    }

    private static function denied(string $status): array
    {
        return [
            'schema'=>'imagine.portal.f381.challenged-identity-observation.v1',
            'status'=>$status,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
        ];
    }
}
