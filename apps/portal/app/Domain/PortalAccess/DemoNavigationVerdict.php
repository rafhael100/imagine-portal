<?php
namespace App\Domain\PortalAccess;

/** No verdict issued by this class can authorize a real HTTP request. */
final readonly class DemoNavigationVerdict
{
    private function __construct(
        public bool $wouldShowSyntheticPreview,
        public ?DemoAccessBlocker $blocker,
    ) {
    }

    public static function preview(): self
    {
        return new self(true, null);
    }

    public static function blocked(DemoAccessBlocker $reason): self
    {
        return new self(false, $reason);
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }
}
