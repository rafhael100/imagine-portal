<?php
declare(strict_types=1);

namespace App\Domain\Performance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F408: isolated, deterministic commercial preview for NEW Performance offers.
 * It is deliberately not wired into the v4 legacy catalog, checkout, PSP,
 * customers, invoice generation, monthly jobs or any controller.
 *
 * The full-month R$170 minimum per contracted channel is approved, but
 * real media spend and first partial-month rules still require evidenced
 * finance review before any operational invoice.
 */
final class ActivationPostpaidPreview
{
    public const ACTIVATION_PER_CHANNEL_CENTS = 49_000;
    public const MANAGEMENT_BASIS_POINTS = 2_000;
    public const MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS = 17_000;
    public const POLICY_VERSION = 'performance-postpaid-170-per-channel-2026-11-14';

    private const MIN_MEDIA = [
        'meta_ads' => 70_000,
        'google_ads' => 100_000,
        'chatgpt_ads' => 120_000,
    ];
    private const RECOMMENDED_MEDIA = [
        'meta_ads' => 100_000,
        'google_ads' => 150_000,
        'chatgpt_ads' => 180_000,
    ];

    /**
     * @param list<string> $channels Exactly one to three canonical channel IDs.
     * @param array<string,bool> $serverEligibility This is a server-only input.
     */
    public static function activationQuote(array $channels, array $serverEligibility): array
    {
        $selected = self::validateChannels($channels);
        $rows = [];
        foreach ($selected as $channel) {
            if (($serverEligibility[$channel] ?? null) !== true) {
                throw new InvalidArgumentException('Channel not commercially eligible');
            }
            $rows[] = [
                'channel' => $channel,
                'activation_cents' => self::ACTIVATION_PER_CHANNEL_CENTS,
                'minimum_management_fee_cents' => self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
                'minimum_media_cents' => self::MIN_MEDIA[$channel],
                'recommended_media_cents' => self::RECOMMENDED_MEDIA[$channel],
            ];
        }
        return [
            'schema' => 'imagine.portal.performance.activation-postpaid-candidate-v1',
            'currency' => 'BRL',
            'channels' => $selected,
            'items' => $rows,
            'activation_due_now_cents' => self::ACTIVATION_PER_CHANNEL_CENTS * count($selected),
            'management_basis_points' => self::MANAGEMENT_BASIS_POINTS,
            'postpaid_policy_version' => self::POLICY_VERSION,
            'management_postpaid_on_verified_actual' => true,
            'media_paid_directly_to_platforms' => true,
            'minimum_management_fee_cents' => self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
            'minimum_management_scope' => 'per_active_channel_full_month',
            'checkout_enabled' => false,
            'asaas_write_enabled' => false,
            'ads_write_enabled' => false,
            'is_contract_or_invoice' => false,
        ];
    }

    /**
     * First period begins on the activation day; later periods are calendar
     * months in Sao Paulo time. Full-month fee candidates apply the higher
     * of 20% of media or R$170 PER CHANNEL, not the maximum on the sum.
     * The first partial month requires explicit contract/finance review.
     * All values remain informational: NEVER actual invoice amounts.
     *
     * @param list<string> $channels
     * @param array<string,int> $actualMediaCents
     */
    public static function monthlyReview(
        array $channels,
        string $activationDate,
        string $period,
        array $actualMediaCents,
        string $syntheticEvidence
    ): array {
        $selected = self::validateChannels($channels);
        if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D', $period)
            || !preg_match('/\ASIM-[A-Z0-9][A-Z0-9-]{2,76}\z/D', $syntheticEvidence)) {
            throw new InvalidArgumentException('Synthetic evidence and calendar month required');
        }
        $tz = new DateTimeZone('America/Sao_Paulo');
        $activation = DateTimeImmutable::createFromFormat('!Y-m-d', $activationDate, $tz);
        if (!$activation || $activation->format('Y-m-d') !== $activationDate) {
            throw new InvalidArgumentException('Invalid activation date');
        }
        $firstOfMonth = DateTimeImmutable::createFromFormat('!Y-m-d', $period.'-01', $tz);
        if (!$firstOfMonth || $firstOfMonth->format('Y-m') !== $period
            || $firstOfMonth->format('Y-m') < $activation->format('Y-m')) {
            throw new InvalidArgumentException('Month precedes activation');
        }
        if (count($actualMediaCents) !== count($selected)
            || array_diff(array_keys($actualMediaCents), $selected) !== []
            || array_diff($selected, array_keys($actualMediaCents)) !== []) {
            throw new InvalidArgumentException('Media actuals must match every selected channel');
        }
        $sum = 0;
        $sumPercentage = 0;
        $sumFullMonthFee = 0;
        $rows = [];
        foreach ($selected as $channel) {
            $cents = $actualMediaCents[$channel];
            if (!is_int($cents) || $cents < 0 || $cents > 100_000_000) {
                throw new InvalidArgumentException('Invalid measured media amount');
            }
            $sum += $cents;
            $percentage = intdiv($cents * self::MANAGEMENT_BASIS_POINTS + 5_000, 10_000);
            $fee = max(self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS, $percentage);
            $sumPercentage += $percentage;
            $sumFullMonthFee += $fee;
            $rows[] = [
                'channel' => $channel,
                'synthetic_actual_media_cents' => $cents,
                'informational_twenty_percent_cents' => $percentage,
                'minimum_management_fee_cents' => self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
                'informational_full_month_management_fee_cents' => $fee,
                'minimum_applied' => $percentage < self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
            ];
        }
        $endOfMonth = $firstOfMonth->modify('last day of this month')->format('Y-m-d');
        $partialFirstMonth = $firstOfMonth->format('Y-m') === $activation->format('Y-m')
            && $activation->format('d') !== '01';
        return [
            'schema' => 'imagine.portal.performance.postpaid-review-offline-v1',
            'period' => $period,
            'start_date' => $firstOfMonth->format('Y-m') === $activation->format('Y-m')
                ? $activationDate : $firstOfMonth->format('Y-m-d'),
            'end_date' => $endOfMonth,
            'currency' => 'BRL',
            'channels' => $rows,
            'synthetic_evidence_ref' => $syntheticEvidence,
            'postpaid_policy_version' => self::POLICY_VERSION,
            'synthetic_actual_media_total_cents' => $sum,
            'informational_management_20pct_cents' => $sumPercentage,
            'minimum_management_fee_cents' => self::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
            'minimum_management_scope' => 'per_active_channel_full_month',
            'informational_full_month_management_fee_cents' => $sumFullMonthFee,
            'first_month_partial_contract_review_required' => $partialFirstMonth,
            'verified_platform_statements_required' => true,
            'finance_review_required' => true,
            'real_invoice_authorized' => false,
            'checkout_enabled' => false,
        ];
    }

    /** @return list<string> */
    private static function validateChannels(array $channels): array
    {
        if (!array_is_list($channels) || count($channels) < 1 || count($channels) > 3) {
            throw new InvalidArgumentException('One to three unique channels required');
        }
        foreach ($channels as $channel) {
            if (!is_string($channel) || !array_key_exists($channel, self::MIN_MEDIA)) {
                throw new InvalidArgumentException('Unknown channel');
            }
        }
        if (count(array_unique($channels)) !== count($channels)) {
            throw new InvalidArgumentException('Duplicate media channel');
        }
        sort($channels, SORT_STRING);
        return $channels;
    }
}
