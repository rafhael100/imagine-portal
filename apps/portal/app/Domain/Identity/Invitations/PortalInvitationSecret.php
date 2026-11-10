<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use LogicException;
use WeakMap;

/**
 * Portal-only invitation token material; no persistence, login or HTTP.
 *
 * F109 prevents accidental disclosure by common object inspection/export:
 * no bearer or bearer-equivalent digest is stored as an INSTANCE PROPERTY.
 *
 * This is defense-in-depth for diagnostics, NOT a memory erasure guarantee:
 * a privileged process can read its own memory or use reflection to inspect
 * private static state. Never log token/digest or share with CRM providers.
 */
final class PortalInvitationSecret
{
    /** @var ?WeakMap<self, array{deliverySecret:?string,sha256:string}> */
    private static ?WeakMap $secretStorage = null;

    private function __construct(string $opaque)
    {
        self::$secretStorage ??= new WeakMap();
        self::$secretStorage[$this] = [
            'deliverySecret' => $opaque,
            'sha256' => hash('sha256', $opaque),
        ];
    }

    public static function mint(): self
    {
        $opaque = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new self($opaque);
    }

    /**
     * Return the digest for persistence in Portal-only infrastructure.
     * The digest remains bearer-equivalent while the invitation is pending.
     */
    public function digest(): string
    {
        return self::stateOf($this)['sha256'];
    }

    /**
     * Reveal only once to an already-approved trusted delivery boundary.
     * This is not permission to email customers or to authenticate a session.
     */
    public function takeDeliverySecretOnce(): ?string
    {
        $state = self::stateOf($this);
        $opaque = $state['deliverySecret'];
        $state['deliverySecret'] = null;
        self::$secretStorage[$this] = $state;

        return $opaque;
    }

    /** @return array{deliverySecret:?string,sha256:string} */
    private static function stateOf(self $key): array
    {
        if (self::$secretStorage === null
            || !isset(self::$secretStorage[$key])) {
            throw new LogicException('Invitation material is not available.');
        }

        return self::$secretStorage[$key];
    }

    public static function digestCandidate(string $candidate): ?string
    {
        if (strlen($candidate) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $candidate) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($candidate, '-_', '+/').'=', true);
        if (!is_string($raw)
            || strlen($raw) !== 32
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $candidate) {
            return null;
        }

        return hash('sha256', $candidate);
    }

    public static function matchesCandidate(string $candidate, string $expectedDigest): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) !== 1) {
            return false;
        }

        $actual = self::digestCandidate($candidate);

        return $actual !== null && hash_equals($expectedDigest, $actual);
    }

    public static function mayAuthenticateOrGrantAccess(): bool
    {
        return false;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['deliverySecret'=>'[redacted]', 'sha256'=>'[redacted]'];
    }

    public function __toString(): string
    {
        throw new LogicException('Invitation objects cannot be stringified.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Portal invitation secrets cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Portal invitation secrets cannot be restored.');
    }

    /** @param array<mixed> $data */
    public static function __set_state(array $data): self
    {
        throw new LogicException('Portal invitation secrets cannot be restored from exports.');
    }

    private function __clone()
    {
    }
}
