<?php

namespace App\Domain\PortalAccess\Authority;

/**
 * F1 preview verdict. It deliberately never confers real authorization.
 * A synthetic positive test is NOT an allow token and cannot authorize HTTP.
 */
final readonly class PortalBoundaryVerdict
{
    private function __construct(public bool $syntheticChecksPass)
    {
    }

    public static function blocked(): self
    {
        return new self(false);
    }

    public static function syntheticCandidate(): self
    {
        return new self(true);
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }
}
