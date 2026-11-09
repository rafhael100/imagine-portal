<?php

declare(strict_types=1);

namespace App\Labs\F40;

/**
 * Synchronous in-memory simulation. Absolutely no network, webhook,
 * email, customer-data output, subprocess, queue or external call.
 */
final class F40InMemoryFakeSink
{
    /** @var array<string,string> */
    private array $received = [];

    private bool $failureUsed = false;

    public function __construct(private readonly ?string $failOnceReference = null)
    {
    }

    public function simulate(string $reference, string $payload): bool
    {
        if (preg_match('/\AF39-DEMO-CMD-[A-Z0-9-]{1,64}\z/D',$reference)!==1) {
            return false;
        }
        $digest=hash('sha256',$payload);
        if (isset($this->received[$reference])) {
            return hash_equals($this->received[$reference],$digest);
        }

        if ($this->failOnceReference===$reference && !$this->failureUsed) {
            $this->failureUsed=true;
            return false;
        }

        $this->received[$reference]=$digest;
        return true;
    }

    public function simulatedCount(): int
    {
        return count($this->received);
    }

    /** @return list<string> */
    public function references(): array
    {
        return array_keys($this->received);
    }
}
