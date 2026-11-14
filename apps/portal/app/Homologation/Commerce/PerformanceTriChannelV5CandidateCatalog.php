<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;
use RuntimeException;

/**
 * QUARANTINED v5 candidate: owner-approved media floors, not an active Portal
 * checkout catalog. Reads a frozen copy of Portal PR #770's proposal JSON.
 * Never registers offers, updates the v4 catalog, writes bills or calls PSP.
 */
final class PerformanceTriChannelV5CandidateCatalog
{
    private const CHANNELS = ['chatgpt_ads', 'google_ads', 'meta_ads'];
    private const MAX_MEDIA_CENTS = 100000000;
    private const FEE_BASIS_POINTS = 2000;
    private const SETTLEMENT = 'estimated_management_fee_upfront_with_monthly_actual_media_spend_adjustment';

    private readonly array $decision;
    private readonly string $sourceSha256;

    public function __construct()
    {
        $path = __DIR__ . '/performance-channel-minima-candidate-v5.json';
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Missing candidate price agreement.');
        }
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) ||
            $data['schema_version'] !== 'performance_channel_minima_proposal_v1' ||
            $data['status'] !== 'owner_approved_values_2026_11_14_pending_technical_catalog_migration' ||
            $data['commercial_minima_approved'] !== true ||
            $data['base_commercial_catalog_version'] !== 4 ||
            $data['management_fee_basis_points'] !== self::FEE_BASIS_POINTS ||
            $data['currency'] !== 'BRL' ||
            $data['reference_period'] !== 'monthly' ||
            $data['provider_charge_authorized'] !== false ||
            $data['asaas_charge_authorized'] !== false ||
            $data['checkout_enabled'] !== false ||
            $data['ad_write_enabled'] !== false ||
            $data['media_paid_directly_to_providers'] !== true) {
            throw new InvalidArgumentException('Unapproved candidate price source or live charge detected.');
        }
        foreach (['channel_minimums_cents', 'channel_recommended_cents'] as $key) {
            $values = $data[$key] ?? null;
            if (!is_array($values) || array_is_list($values)) {
                throw new InvalidArgumentException('Missing candidate channel scope.');
            }
            $channels = array_keys($values);
            sort($channels, SORT_STRING);
            if ($channels !== self::CHANNELS) {
                throw new InvalidArgumentException('Candidate must preserve exactly three approved channels.');
            }
            foreach ($values as $value) {
                if (!is_int($value) || $value < 1 || $value > self::MAX_MEDIA_CENTS) {
                    throw new InvalidArgumentException('Invalid integer media floor/reference.');
                }
            }
        }
        foreach (self::CHANNELS as $channel) {
            if ($data['channel_recommended_cents'][$channel] <
                $data['channel_minimums_cents'][$channel]) {
                throw new InvalidArgumentException('Recommendation cannot be below minimum.');
            }
        }
        $this->decision = $data;
        $this->sourceSha256 = hash('sha256', $raw);
    }

    public function version(): string
    {
        // Different commercial catalog source => different signed HMAC contract.
        return $this->sourceSha256;
    }

    /** @return array<string,int> */
    public function minima(): array
    {
        $out = $this->decision['channel_minimums_cents'];
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,int> */
    public function recommendations(): array
    {
        $out = $this->decision['channel_recommended_cents'];
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string,int> $media planned monthly media budgets in BRL cents
     * @return array<string,mixed> Same price primitives as existing v4, but
     *                        signed separately in a domain-separated v5 envelope.
     */
    public function quote(array $media): array
    {
        if (getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_PERF_TRICHANNEL_V5_LAB') !== '1') {
            throw new RuntimeException('Candidate contract cannot be used outside isolated testing.');
        }
        if ($media === [] || count($media) > 3 || array_is_list($media)) {
            throw new InvalidArgumentException('Choose one to three named channels.');
        }
        $minima = $this->minima();
        $keys = array_keys($media);
        sort($keys, SORT_STRING);
        if (array_diff($keys, self::CHANNELS) !== []) {
            throw new InvalidArgumentException('Unknown candidate Ads channel.');
        }
        ksort($media, SORT_STRING);
        $plannedTotal = 0;
        foreach ($media as $channel => $cents) {
            if (!is_int($cents) || $cents < $minima[$channel] ||
                $cents > self::MAX_MEDIA_CENTS) {
                throw new InvalidArgumentException('Below owner-approved media floor or above cap.');
            }
            $plannedTotal += $cents;
        }
        $fee = self::fee($plannedTotal);
        return [
            'catalog_version' => $this->version(),
            'product' => 'performance',
            'currency' => 'BRL',
            'gateway' => 'asaas',
            'real_charge_enabled' => false,
            'media_by_channel_cents' => $media,
            'media_paid_directly_to_platforms' => true,
            'management_fee_basis_points' => self::FEE_BASIS_POINTS,
            'estimated_management_fee_cents' => $fee,
            'reference_period' => 'monthly',
            'amount_now_cents' => $fee,
            'settlement_policy' => self::SETTLEMENT,
        ];
    }

    /** Mathematical illustration only. NOT actual spend evidence or a bill. */
    public function reconcile(array $quote, array $unverifiedSpends): array
    {
        if (getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_PERF_TRICHANNEL_V5_LAB') !== '1' ||
            !is_array($quote['media_by_channel_cents'] ?? null) ||
            !is_string($quote['catalog_version'] ?? null) ||
            !hash_equals($this->version(), $quote['catalog_version'])) {
            throw new InvalidArgumentException('Candidate reconciliation disabled or catalog mismatch.');
        }
        $expected = array_keys($quote['media_by_channel_cents']);
        $actual = array_keys($unverifiedSpends);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Unknown or missing channel spend.');
        }
        $verifiedQuote = $this->quote($quote['media_by_channel_cents']);
        if ($quote !== $verifiedQuote) {
            throw new InvalidArgumentException('Unrecognized or tampered candidate quote.');
        }
        $sum = 0;
        foreach ($unverifiedSpends as $spend) {
            if (!is_int($spend) || $spend < 0 || $spend > self::MAX_MEDIA_CENTS) {
                throw new InvalidArgumentException('Invalid synthetic spend.');
            }
            $sum += $spend;
        }
        $earned = self::fee($sum);
        return [
            'unverified_spend_cents' => $sum,
            'illustrative_management_fee_cents' => $earned,
            'illustrative_adjustment_cents' => $earned - $quote['estimated_management_fee_cents'],
            'quote_catalog_version' => $this->version(),
            'provider_spend_verified' => false,
            'finance_approved' => false,
            'portal_catalog_active' => false,
            'asaas_write_enabled' => false,
            'ads_write_enabled' => false,
        ];
    }

    private static function fee(int $cents): int
    {
        // Compute on TOTAL media, rounding once in BRL cents.
        return intdiv($cents * self::FEE_BASIS_POINTS + 5000, 10000);
    }
}
