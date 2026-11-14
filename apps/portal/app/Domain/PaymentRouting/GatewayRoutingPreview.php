<?php

declare(strict_types=1);

namespace App\Domain\PaymentRouting;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Pure, offline fee-based routing preview. No API, HTTP, database, credentials,
 * checkout, webhooks, customer migration, or PSP charge execution.
 *
 * Only manually authorized commercial terms may be supplied by the caller.
 */
final class GatewayRoutingPreview
{
    private const PROVIDERS = ['asaas', 'c6'];
    private const METHODS = ['pix', 'boleto', 'credit_card', 'pix_automatico'];

    /**
     * @param array<string,mixed> $configuration
     * @return array{gateway:string,method:string,currency:string,mode:string,gross_cents:int,estimated_fee_cents:int,estimated_net_cents:int,settlement_days:int,fee_version:string}
     */
    public static function quote(array $configuration, string $method, int $grossCents): array
    {
        if (!in_array($method, self::METHODS, true)
            || $grossCents < 1 || $grossCents > 100_000_000) {
            throw new InvalidArgumentException('Unsupported payment method or amount');
        }

        $mode = $configuration['mode'] ?? null;
        $preferred = $configuration['preferred_gateway'] ?? null;
        if (!in_array($mode, ['manual', 'lowest_cost'], true)
            || !in_array($preferred, self::PROVIDERS, true)
            || ($configuration['currency'] ?? null) !== 'BRL'
            || !is_array($configuration['providers'] ?? null)) {
            throw new InvalidArgumentException('Incomplete routing policy');
        }

        $quotes = [];
        foreach (self::PROVIDERS as $gateway) {
            $candidate = $configuration['providers'][$gateway] ?? null;
            if (!is_array($candidate)) {
                continue;
            }
            // A missing or ambiguous switch never authorizes a gateway.
            if (($candidate['enabled'] ?? null) !== true
                || ($candidate['approved'] ?? null) !== true
                || ($candidate['healthy'] ?? null) !== true) {
                continue;
            }

            $feeVersion = $candidate['fee_version'] ?? null;
            $terms = $candidate['methods'][$method] ?? null;
            if (!is_string($feeVersion)
                || !preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $feeVersion)
                || !is_array($terms)
                || ($terms['enabled'] ?? null) !== true) {
                continue;
            }
            $fixed = $terms['fixed_cents'] ?? null;
            $basisPoints = $terms['variable_bps'] ?? null;
            $settlementDays = $terms['settlement_days'] ?? null;
            if (!is_int($fixed) || $fixed < 0 || $fixed > 1_000_000
                || !is_int($basisPoints) || $basisPoints < 0 || $basisPoints > 10_000
                || !is_int($settlementDays) || $settlementDays < 0 || $settlementDays > 90) {
                // Missing or unverified commercial terms must never receive routing.
                continue;
            }
            // Integer arithmetic and rounded-up percentage prevent floating point drift.
            $fee = $fixed + intdiv($grossCents * $basisPoints + 9_999, 10_000);
            // An amount that cannot cover the provider's quoted fee is not a
            // usable payment route. Fail closed rather than presenting a
            // negative net settlement or silently choosing a loss-making PSP.
            if ($fee >= $grossCents) {
                continue;
            }
            $quotes[$gateway] = [
                'gateway' => $gateway,
                'method' => $method,
                'currency' => 'BRL',
                'mode' => $mode,
                'gross_cents' => $grossCents,
                'estimated_fee_cents' => $fee,
                'estimated_net_cents' => $grossCents - $fee,
                'settlement_days' => $settlementDays,
                'fee_version' => $feeVersion,
            ];
        }

        if ($mode === 'manual') {
            if (!isset($quotes[$preferred])) {
                throw new RuntimeException('Preferred gateway not eligible');
            }
            return $quotes[$preferred];
        }
        if ($quotes === []) {
            throw new RuntimeException('No approved gateway available');
        }

        // Stable tie-break: fee, settlement days, preferred gateway, lexical.
        uasort($quotes, static function (array $a, array $b) use ($preferred): int {
            return ($a['estimated_fee_cents'] <=> $b['estimated_fee_cents'])
                ?: ($a['settlement_days'] <=> $b['settlement_days'])
                ?: ((int) ($a['gateway'] !== $preferred) <=> (int) ($b['gateway'] !== $preferred))
                ?: strcmp($a['gateway'], $b['gateway']);
        });
        return reset($quotes);
    }

    /**
     * Existing invoices, subscriptions, webhooks and refunds always stay pinned
     * to the original PSP. Never reroute ambiguous or in-flight writes.
     */
    public static function enforcePinnedGateway(string $original, string $requested): string
    {
        if (!in_array($original, self::PROVIDERS, true)
            || !in_array($requested, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unknown gateway');
        }
        if ($original !== $requested) {
            throw new LogicException('Existing financial record is pinned to its original gateway');
        }
        return $original;
    }
}
