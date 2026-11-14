<?php

declare(strict_types=1);

namespace App\Domain\PaymentRouting;

use InvalidArgumentException;
use JsonException;

/**
 * Portal-side producer of CRM-BILL-MULTIPSP-01 review payloads.
 * This is an offline-only wire contract, not authorization to charge.
 */
final class PortalGatewayReviewExporter
{
    public static function export(
        array $quote,
        string $organization,
        string $order,
        int $revision,
        #[\SensitiveParameter] string $signingKey,
    ): array {
        if (strlen($signingKey) < 32 || strlen($signingKey) > 128
            || !self::token($organization) || !self::token($order)
            || $revision < 1 || $revision > 1_000_000
            || !in_array($quote['gateway'] ?? null, ['asaas', 'c6'], true)
            || !in_array($quote['method'] ?? null, ['pix', 'boleto', 'credit_card', 'pix_automatico'], true)
            || ($quote['currency'] ?? null) !== 'BRL'
            || !is_int($quote['gross_cents'] ?? null) || $quote['gross_cents'] < 1
            || $quote['gross_cents'] > 100_000_000
            || !is_int($quote['estimated_fee_cents'] ?? null) || $quote['estimated_fee_cents'] < 0
            || $quote['estimated_fee_cents'] > 100_000_000
            || !self::token($quote['fee_version'] ?? null)) {
            throw new InvalidArgumentException('Invalid offline routing quotation');
        }
        $payload = [
            'schema_version' => 'imagine.payment-routing.review.v1',
            'organization_ref' => $organization,
            'order_ref' => $order,
            'gateway' => $quote['gateway'],
            'method' => $quote['method'],
            'currency' => 'BRL',
            'amount_cents' => $quote['gross_cents'],
            'estimated_fee_cents' => $quote['estimated_fee_cents'],
            'fee_version' => $quote['fee_version'],
            'policy_revision' => $revision,
            'can_charge' => false,
            'can_activate' => false,
            'environment' => 'offline_review',
        ];

        $toSign = $payload;
        ksort($toSign, SORT_STRING);
        try {
            $serialized = json_encode($toSign, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('Cannot encode review payload');
        }
        return [
            'payload' => $payload,
            'mac' => hash_hmac('sha256', "IMAGINE-GATEWAY-OFFLINE-REVIEW-V1\n".$serialized, $signingKey),
        ];
    }

    private static function token(mixed $s): bool
    {
        return is_string($s) && preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D', $s) === 1;
    }
}
