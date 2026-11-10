<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use LogicException;

/**
 * F105: Portal-only invitation token material (no persistence or HTTP).
 *
 * This proves a crypto/token *format*, not an invitation issuer or a verified
 * Portal account. A single token is a delivery secret, not an authenticated
 * customer session, MFA verification, entitlement, or role grant.
 */
final class PortalInvitationSecret
{
    private ?string $deliverySecret;
    private readonly string $sha256;

    private function __construct(string $opaque)
    {
        $this->deliverySecret = $opaque;
        $this->sha256 = hash('sha256', $opaque);
    }

    public static function mint(): self
    {
        $opaque = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new self($opaque);
    }

    /**
     * An invitation repository stores only this digest (never the opaque).
     * The digest is bearer-equivalent while an invitation is pending:
     * do not put it in client-visible API output, logs, metrics or URLs.
     */
    public function digest(): string
    {
        return $this->sha256;
    }

    /**
     * Reveal at most once for an approved, separately implemented delivery.
     * Reading is not permission to send email, and PHP cannot guarantee that
     * a copied string is erased from memory after it leaves this object.
     */
    public function takeDeliverySecretOnce(): ?string
    {
        $opaque = $this->deliverySecret;
        $this->deliverySecret = null;

        return $opaque;
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

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['deliverySecret' => '[redacted]', 'sha256' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Portal invitation delivery secrets cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Portal invitation secrets cannot be restored from serialized input.');
    }

    private function __clone()
    {
    }
}
