<?php

declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;

/**
 * Inert, in-memory review contract. NOT an authority, writer, saved draft or
 * bearer capability. Organization is a proposed binding, never a verified one.
 * No provider/route binds this class. A future native authority must authorize
 * both the initial write and every read BEFORE accessing a protected draft.
 */
final readonly class OnepageQuoteJourneyPlan
{
    private function __construct(
        private string $organization,
        private string $requestHash,
        private array $selection,
        private string $catalogVersion,
        private string $quoteDigest,
    ) {}

    public static function prepare(array $selection, string $organization, string $requestKey): self
    {
        $selection = self::selection($selection);
        self::scope($organization, $requestKey);
        $quote = (new CompositeQuotePreview())->quote($selection);
        return new self($organization, hash('sha256', $requestKey), $selection,
            $quote['catalog_version'], self::digest($quote));
    }

    /** Recompute, do not trust stored/displayed amounts or silently reprice a plan. */
    public function resume(array $selection, string $organization, string $requestKey): array
    {
        $selection = self::selection($selection);
        self::scope($organization, $requestKey);
        if ($organization !== $this->organization
            || !hash_equals($this->requestHash, hash('sha256', $requestKey))
            || $selection !== $this->selection) {
            throw new InvalidArgumentException('ONEPAGE_PLAN_CONTEXT_MISMATCH');
        }
        $quote = (new CompositeQuotePreview())->quote($this->selection);
        if ($quote['catalog_version'] !== $this->catalogVersion
            || !hash_equals($this->quoteDigest, self::digest($quote))) {
            throw new InvalidArgumentException('ONEPAGE_PLAN_REVIEW_REQUIRED');
        }
        return [
            'contract_version' => 'onepage-quote-journey-plan-v1',
            // Stable proposed UNIQUE key: same request in another company differs.
            // This hash is not a secret, capability or proof of durable uniqueness.
            'proposed_idempotency_scope' => self::digest(['onepage-plan-v1', $organization, $this->requestHash]),
            'quote' => $quote,
            'binding_status' => 'PROPOSED_UNVERIFIED',
            'authority_verified' => false,
            'draft_persisted' => false,
            'persistence_allowed' => false,
            'order_created' => false,
            'payment_requested' => false,
            'site_provisioned' => false,
            'release_status' => 'HOLD_NATIVE_QUOTE_AUTHORITY_AND_MINIMUM_PRIVILEGE_STORE',
        ];
    }

    private static function selection(array $selection): array
    {
        $keys = array_keys($selection);
        sort($keys);
        if ($keys !== ['channels', 'site', 'tier']
            || $selection['site'] !== 'onepage' || $selection['channels'] !== []
            || !in_array($selection['tier'], ['minimum', 'recommended'], true)) {
            throw new InvalidArgumentException('ONEPAGE_ONLY_SELECTION_REQUIRED');
        }
        return ['site' => 'onepage', 'channels' => [], 'tier' => $selection['tier']];
    }

    private static function scope(string $organization, string $requestKey): void
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $organization)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $requestKey)) {
            throw new InvalidArgumentException('ONEPAGE_PLAN_CONTEXT_REQUIRED');
        }
    }

    private static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
