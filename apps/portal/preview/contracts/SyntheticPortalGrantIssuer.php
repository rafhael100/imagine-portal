<?php

declare(strict_types=1);

namespace Imagine\Protocol\Lab;

use InvalidArgumentException;

/**
 * Fake Portal issuer for contract testing ONLY.
 * Actor and service grants are hard-coded synthetic fixtures, NOT Portal auth.
 * Never register in Laravel providers, routes, queues or production services.
 */
final class SyntheticPortalGrantIssuer
{
    /** @var array<string,array{organization:string,role:string}> */
    private array $actors = [
        'actor-demo-owner-a' => ['organization' => 'org-demo-a', 'role' => 'issuer_demo'],
        'actor-demo-reader-a' => ['organization' => 'org-demo-a', 'role' => 'viewer_demo'],
        'actor-demo-owner-b' => ['organization' => 'org-demo-b', 'role' => 'issuer_demo'],
    ];
    /** @var array<string,array{organization:string,tenant:string,revision:int,active:bool}> */
    private array $services = [
        'svc-demo-a' => ['organization' => 'org-demo-a', 'tenant' => 'perf-demo-a', 'revision' => 3, 'active' => true],
        'svc-demo-b' => ['organization' => 'org-demo-b', 'tenant' => 'perf-demo-b', 'revision' => 1, 'active' => true],
    ];

    public function __construct(
        private readonly string $ephemeralTestKey,
        private readonly string $fixtureDigest
    ) {
        if (strlen($ephemeralTestKey) < 32
            || !preg_match('/^[0-9a-f]{64}$/D', $fixtureDigest)) {
            throw new InvalidArgumentException('Lab issuer requires ephemeral key and fixture digest.');
        }
    }

    /** @return array<string,mixed>|string */
    public function issueForLab(
        string $actor,
        string $organization,
        string $tenant,
        string $service,
        string $requestId,
        string $nonce,
        int $issuedAt,
        int $expiresAt
    ): array|string {
        $principal = $this->actors[$actor] ?? null;
        if ($principal === null) {
            return 'mock_actor_denied';
        }
        if ($principal['role'] !== 'issuer_demo') {
            return 'mock_scope_denied';
        }

        $grant = $this->services[$service] ?? null;
        if ($grant === null
            || $principal['organization'] !== $organization
            || $grant['organization'] !== $organization
            || $grant['tenant'] !== $tenant) {
            return 'mock_binding_denied';
        }
        if (!$grant['active']) {
            return 'mock_grant_revoked';
        }

        return SyntheticS2SProtocol::mintForLab(
            $organization, $tenant, $service, $requestId, $nonce,
            $issuedAt, $expiresAt, $grant['revision'],
            $this->fixtureDigest, $this->ephemeralTestKey
        );
    }

    /**
     * Test-only administrative operation. A real revoke would require Portal
     * authenticated authority, durable grants and atomic delivery/fence.
     */
    public function revokeForLab(string $service): string
    {
        if (!isset($this->services[$service])) {
            return 'not_found';
        }
        if (!$this->services[$service]['active']) {
            return 'already_revoked_demo';
        }
        $this->services[$service]['active'] = false;
        ++$this->services[$service]['revision'];
        return 'revoked_demo_only';
    }

    public function grantRevisionForLab(string $service): ?int
    {
        return $this->services[$service]['revision'] ?? null;
    }
}
