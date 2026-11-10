<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use LogicException;
use WeakMap;

/**
 * F115: Portal-only email confirmation credential (NOT an invite/session).
 *
 * Digest has an explicit domain separator so an invitation/session token
 * cannot be reinterpreted as an email confirmation token. This class never
 * sends email, asserts mailbox ownership or grants access to the Portal.
 */
final class PortalEmailVerificationSecret
{
    private const DOMAIN = "IMAGINE-PORTAL-EMAIL-VERIFY:v1\0";

    /** @var ?WeakMap<self,array{deliverySecret:?string,digest:string}> */
    private static ?WeakMap $store = null;

    private function __construct(string $token)
    {
        self::$store ??= new WeakMap();
        self::$store[$this] = [
            'deliverySecret' => $token,
            'digest' => self::hashToken($token),
        ];
    }

    public static function mint(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    public function digest(): string
    {
        return $this->state()['digest'];
    }

    public function takeDeliverySecretOnce(): ?string
    {
        $state = $this->state();
        $delivery = $state['deliverySecret'];
        $state['deliverySecret'] = null;
        self::$store[$this] = $state;

        return $delivery;
    }

    /** @return array{deliverySecret:?string,digest:string} */
    private function state(): array
    {
        if (self::$store === null || !isset(self::$store[$this])) {
            throw new LogicException('Email challenge secret no longer available.');
        }

        return self::$store[$this];
    }

    public static function digestCandidate(string $candidate): ?string
    {
        if (strlen($candidate) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $candidate) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($candidate, '-_', '+/').'=', true);
        if (!is_string($raw) || strlen($raw) !== 32
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $candidate) {
            return null;
        }

        return self::hashToken($candidate);
    }

    public static function matchesCandidate(string $candidate, string $expectedDigest): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $expectedDigest) === 1
            && ($digest = self::digestCandidate($candidate)) !== null
            && hash_equals($expectedDigest, $digest);
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', self::DOMAIN.$token);
    }

    public static function mayConfirmRealEmailOrAuthenticate(): bool
    {
        return false;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['deliverySecret' => '[redacted]', 'digest' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Email confirmation secrets cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Email confirmation secrets cannot be restored.');
    }

    /** @param array<mixed> $data */
    public static function __set_state(array $data): self
    {
        throw new LogicException('Email confirmation secrets cannot be restored via export.');
    }

    public function __toString(): string
    {
        throw new LogicException('Email confirmation secrets cannot be converted to strings.');
    }

    private function __clone()
    {
    }
}
