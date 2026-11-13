<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;

/**
 * F372 review-only adapter over the existing, real Portal session reader.
 * It cannot issue/revoke a session, grant business access or return an
 * authorization boolean. Real Laravel HTTP/container binding is forbidden.
 */
final readonly class FencedPortalSessionReadCandidate
{
    public function __construct(
        private PortalBrowserAuthentication $authentication,
        private OnlineWitnessFenceCandidate $fence,
    ) {}

    /** Never use the observed status as an access-control decision. */
    public function inspectSession(string $reference): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $reference) !== 1) {
            return self::denied();
        }

        return $this->fence->inspectReadOnly(
            fn(): bool => $this->authentication->isActiveBusinessSession($reference)
        );
    }

    private static function denied(): array
    {
        return [
            'schema' => 'imagine.portal.f371.online-witness-prepost.v1',
            'status' => 'INVALID_CANDIDATE_SESSION_REFERENCE',
            'canAuthorizeHttp' => false,
            'canAuthorizeBusiness' => false,
            'canIssueSession' => false,
            'canReissueCredential' => false,
            'canRecoverFromRestore' => false,
            'canDeploy' => false,
        ];
    }
}
