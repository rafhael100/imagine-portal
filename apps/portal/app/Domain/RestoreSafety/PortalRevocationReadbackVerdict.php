<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

/**
 * Diagnostic for two verified observations after a signed publication claim.
 * A candidate is NEVER proof of durable revocation or permission to serve.
 */
final readonly class PortalRevocationReadbackVerdict
{
    private function __construct(
        public bool $syntheticChecksPassed,
        public string $reason,
    ) {
    }

    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }

    public static function candidate(): self
    {
        return new self(true, 'synthetic_publication_readback_only');
    }

    public function canConfirmDurableRevocation(): bool
    {
        return false;
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }

    public function canReleaseServing(): bool
    {
        return false;
    }

    public function canReissueSessions(): bool
    {
        return false;
    }
}
