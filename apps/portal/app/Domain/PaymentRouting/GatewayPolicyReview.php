<?php

declare(strict_types=1);

namespace App\Domain\PaymentRouting;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Produces a reviewable, immutable policy revision. NO writes, HTTP, bank API,
 * storage, login/authorization decisions, or production activation.
 */
final class GatewayPolicyReview
{
    private const GATEWAYS = ['asaas', 'c6'];
    private const METHODS = ['pix', 'boleto', 'credit_card', 'pix_automatico'];
    private const PROOFS = [
        'contract_approved',
        'sandbox_homologated',
        'security_approved',
        'finance_approved',
        'production_access_authorized',
    ];

    public static function review(array $previous, array $candidate, int $expectedRevision, string $actor, string $at, array $externalProofs = []): array
    {
        self::validate($previous);
        self::validate($candidate);
        if ($expectedRevision !== $previous['revision'] || $candidate['revision'] !== $expectedRevision + 1) {
            throw new LogicException('Stale or non-sequential policy revision');
        }
        if (!preg_match('/\A[A-Za-z0-9._@:-]{3,96}\z/D', $actor)) {
            throw new InvalidArgumentException('Operator evidence identifier required');
        }
        try {
            $when = new DateTimeImmutable($at);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid review timestamp');
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $at)
            || $when->format('U') === false) {
            throw new InvalidArgumentException('RFC3339 review timestamp required');
        }
        // The revision is *required* to increment; including it in a no-op
        // comparison would make the check unreachable. Compare the effective
        // policy, while retaining revision in the signed audit hashes below.
        $previousTerms = $previous;
        $candidateTerms = $candidate;
        unset($previousTerms['revision'], $candidateTerms['revision']);
        if (self::digest($previousTerms) === self::digest($candidateTerms)) {
            throw new LogicException('No effective policy change to review');
        }
        if ($candidate['providers']['c6']['enabled']) {
            foreach (self::PROOFS as $proof) {
                $reference = $externalProofs[$proof] ?? null;
                if (!is_string($reference)
                    || !preg_match('/\A[A-Za-z0-9._:-]{6,128}\z/D', $reference)) {
                    throw new LogicException('C6 integration requires independently verified approval references');
                }
            }
        }
        return [
            'revision' => $candidate['revision'],
            'previous_sha256' => self::digest($previous),
            'candidate_sha256' => self::digest($candidate),
            'operator_reference' => $actor,
            'reviewed_at' => $at,
            'operation' => 'policy_review_candidate',
            'activation_allowed' => false,
        ];
    }

    public static function validate(array $policy): void
    {
        self::keys($policy, ['revision', 'mode', 'currency', 'preferred_gateway', 'providers']);
        if (!is_int($policy['revision']) || $policy['revision'] < 1 || $policy['revision'] > 1000000
            || !in_array($policy['mode'], ['manual', 'lowest_cost'], true)
            || $policy['currency'] !== 'BRL'
            || !in_array($policy['preferred_gateway'], self::GATEWAYS, true)
            || !is_array($policy['providers'])) {
            throw new InvalidArgumentException('Invalid gateway policy');
        }
        self::keys($policy['providers'], self::GATEWAYS);
        $enabledCount = 0;
        foreach (self::GATEWAYS as $name) {
            $provider = $policy['providers'][$name];
            if (!is_array($provider)) {
                throw new InvalidArgumentException('Invalid gateway provider');
            }
            self::keys($provider, ['enabled', 'approved', 'healthy', 'fee_version', 'methods']);
            foreach (['enabled', 'approved', 'healthy'] as $flag) {
                if (!is_bool($provider[$flag])) {
                    throw new InvalidArgumentException('Gateway flags must be boolean');
                }
            }
            if (!is_string($provider['fee_version'])
                || !preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $provider['fee_version'])
                || !is_array($provider['methods'])) {
                throw new InvalidArgumentException('Gateway fee schedule incomplete');
            }
            foreach ($provider['methods'] as $method => $terms) {
                if (!in_array($method, self::METHODS, true) || !is_array($terms)) {
                    throw new InvalidArgumentException('Unknown method or malformed terms');
                }
                self::keys($terms, ['enabled', 'fixed_cents', 'variable_bps', 'settlement_days']);
                if (!is_bool($terms['enabled'])
                    || !is_int($terms['fixed_cents']) || $terms['fixed_cents'] < 0 || $terms['fixed_cents'] > 1_000_000
                    || !is_int($terms['variable_bps']) || $terms['variable_bps'] < 0 || $terms['variable_bps'] > 10_000
                    || !is_int($terms['settlement_days']) || $terms['settlement_days'] < 0 || $terms['settlement_days'] > 90) {
                    throw new InvalidArgumentException('Invalid commercial method terms');
                }
            }
            if ($provider['enabled']) {
                $enabledCount++;
                if (!$provider['approved'] || !$provider['healthy']
                    || !array_filter($provider['methods'], static fn (array $item): bool => $item['enabled'])) {
                    throw new InvalidArgumentException('Enabled provider must be approved, healthy and support a method');
                }
            }
        }
        if ($enabledCount === 0) {
            throw new InvalidArgumentException('At least one approved gateway must remain enabled');
        }
        if ($policy['mode'] === 'manual' && !$policy['providers'][$policy['preferred_gateway']]['enabled']) {
            throw new InvalidArgumentException('Manual gateway is disabled');
        }
    }

    private static function keys(array $data, array $expected): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Unexpected or missing policy keys');
        }
    }

    private static function digest(array $payload): string
    {
        return hash('sha256', json_encode(self::sorted($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function sorted(array $value): array
    {
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sorted($item);
            }
        }
        unset($item);
        return $value;
    }
}
