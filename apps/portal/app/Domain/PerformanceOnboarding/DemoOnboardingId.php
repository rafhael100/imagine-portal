<?php

namespace App\Domain\PerformanceOnboarding;

use InvalidArgumentException;

/** Synthetic onboarding identifier, never a production tenant or service ID. */
final readonly class DemoOnboardingId
{
    public function __construct(public string $value)
    {
        if (preg_match('/\Ademo_[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid synthetic onboarding identifier.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
