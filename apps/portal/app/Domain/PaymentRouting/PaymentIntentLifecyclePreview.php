<?php

declare(strict_types=1);

namespace App\Domain\PaymentRouting;

use InvalidArgumentException;
use LogicException;

/**
 * Pure candidate transition guard. A real payment write requires a durable,
 * locked PostgreSQL intent, verified PSP readback, and authorized dispatcher.
 */
final class PaymentIntentLifecyclePreview
{
    private const PROVIDERS = ['asaas', 'c6'];
    private const METHODS = ['pix', 'boleto', 'credit_card', 'pix_automatico'];

    public static function open(string $organization, string $intentKey, string $gateway, string $method, int $amountCents): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{3,64}\z/D', $organization)
            || !preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/D', $intentKey)
            || !in_array($gateway, self::PROVIDERS, true)
            || !in_array($method, self::METHODS, true)
            || $amountCents < 1 || $amountCents > 100_000_000) {
            throw new InvalidArgumentException('Invalid immutable payment intent');
        }
        return [
            'organization' => $organization,
            'intent_key' => $intentKey,
            'gateway' => $gateway,
            'method' => $method,
            'amount_cents' => $amountCents,
            'status' => 'planned',
            'external_id' => null,
        ];
    }

    /**
     * Events are caller-authenticated facts, not raw webhook trust inputs.
     * Returns a NEW projection; never sends a provider write.
     */
    public static function advance(array $intent, string $event, string $gateway, ?string $externalId = null): array
    {
        $valid = ['planned', 'submitting', 'uncertain', 'pending', 'settled', 'failed', 'absent', 'cancelled'];
        $status = $intent['status'] ?? null;
        if (!in_array($status, $valid, true)
            || !in_array($intent['gateway'] ?? null, self::PROVIDERS, true)
            || $intent['gateway'] !== $gateway) {
            throw new LogicException('Unknown intent state or wrong provider');
        }
        if ($externalId !== null
            && !preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D', $externalId)) {
            throw new InvalidArgumentException('Invalid external reference');
        }

        $transitions = [
            'planned' => ['dispatch_started' => 'submitting', 'cancel_before_dispatch' => 'cancelled'],
            'submitting' => ['write_uncertain' => 'uncertain', 'created' => 'pending'],
            'uncertain' => ['reconciled_created' => 'pending', 'verified_absent' => 'absent'],
            'pending' => ['verified_paid' => 'settled', 'verified_failure' => 'failed'],
        ];
        $next = $transitions[$status][$event] ?? null;
        if ($next === null) {
            // Duplicate verified positive facts are idempotent only with the same reference.
            if (($status === 'pending' && in_array($event, ['created', 'reconciled_created'], true))
                || ($status === 'settled' && $event === 'verified_paid')) {
                if ($externalId !== null && $externalId === $intent['external_id']) {
                    return $intent;
                }
            }
            throw new LogicException('Forbidden payment intent transition');
        }
        if (in_array($event, ['created', 'reconciled_created', 'verified_paid'], true)
            && $externalId === null) {
            throw new InvalidArgumentException('Verified external PSP identifier required');
        }
        if ($status === 'pending'
            && $externalId !== $intent['external_id']) {
            throw new LogicException('PSP reference mismatch');
        }
        if (in_array($event, ['dispatch_started', 'write_uncertain', 'verified_absent', 'cancel_before_dispatch'], true)
            && $externalId !== null) {
            throw new InvalidArgumentException('External id cannot accompany this event');
        }
        if ($event === 'verified_failure' && $externalId !== $intent['external_id']) {
            throw new LogicException('PSP reference mismatch');
        }
        $updated = $intent;
        $updated['status'] = $next;
        if (in_array($event, ['created', 'reconciled_created'], true)) {
            $updated['external_id'] = $externalId;
        }
        return $updated;
    }

    /** Rerouting is never permitted for the same intent, including verified absence. */
    public static function routeExisting(array $intent, string $requestedGateway): void
    {
        if (!in_array($requestedGateway, self::PROVIDERS, true)
            || ($intent['gateway'] ?? null) !== $requestedGateway) {
            throw new LogicException('Cannot reroute an existing payment intent');
        }
    }
}
