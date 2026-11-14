<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use InvalidArgumentException;

/** New-contract calculation only. No PSP calls, legacy rewrites or automatic invoicing. */
final class PerformancePostpaidPolicy
{
    public const VERSION = 'performance-postpaid-170-per-channel-2026-11-14';
    public const ACTIVATION_CENTS = 49000;
    public const MONTHLY_MINIMUM_CENTS = 17000;

    /** Full-month active channels only; eligible scope must come from the server contract. */
    public static function fullMonth(array $contractedChannels, array $actualMedia): array
    {
        if ($contractedChannels === [] || !array_is_list($contractedChannels)
            || count($contractedChannels) > 20
            || count(array_unique($contractedChannels, SORT_REGULAR)) !== count($contractedChannels)) {
            throw new InvalidArgumentException('Invalid contracted channel scope');
        }
        foreach ($contractedChannels as $channel) {
            if (!is_string($channel) || !preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D', $channel)) {
                throw new InvalidArgumentException('Invalid channel identifier');
            }
        }
        $expected = $contractedChannels;
        $actual = array_keys($actualMedia);
        sort($expected); sort($actual);
        if ($expected !== $actual) throw new InvalidArgumentException('Missing or unexpected channel spend');
        $rows = []; $total = 0;
        foreach ($contractedChannels as $channel) {
            $spend = $actualMedia[$channel];
            if (!is_int($spend) || $spend < 0 || $spend > 100000000) {
                throw new InvalidArgumentException('Invalid actual spend');
            }
            $percentage = intdiv($spend * 2000 + 5000, 10000);
            $fee = max(self::MONTHLY_MINIMUM_CENTS, $percentage);
            $rows[$channel] = ['media_cents' => $spend, 'percentage_fee_cents' => $percentage,
                'minimum_fee_cents' => self::MONTHLY_MINIMUM_CENTS, 'management_fee_cents' => $fee,
                'minimum_applied' => $percentage < self::MONTHLY_MINIMUM_CENTS];
            $total += $fee;
        }
        return ['policy_version' => self::VERSION, 'channels' => $rows,
            'management_fee_cents' => $total, 'activation_total_cents' => count($rows) * self::ACTIVATION_CENTS,
            'activation_included_in_monthly_fee' => false, 'automatic_charge_enabled' => false];
    }
}
