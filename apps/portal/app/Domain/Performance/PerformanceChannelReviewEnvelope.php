<?php
declare(strict_types=1);

namespace App\Domain\Performance;

use App\Domain\PaymentRouting\PortalGatewayReviewExporter;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * F415: offline-only signature that binds the exact selected advertising
 * channels to an existing signed, non-operational Portal -> CRM PSP review.
 *
 * This is NOT a customer contract, CRM message, payment mandate, invoice,
 * account permission, API token or replay-proof transaction authorization.
 */
final class PerformanceChannelReviewEnvelope
{
    private const SCHEMA = 'imagine.portal.performance.activation-channels.review.v1';
    private const CONTEXT = "IMAGINE-PERF-CHANNEL-REVIEW-OFFLINE-V1\n";
    private const TTL_SECONDS = 900;

    /**
     * Build a signed review snapshot only from a separately validated
     * ActivationGatewayReviewLab result and server-owned channel eligibility.
     * A UI-supplied total or channel authorization is never accepted.
     *
     * @param array<string,mixed> $review
     * @param array<string,bool> $serverEligibility
     * @return array{payload:array<string,mixed>,mac:string}
     */
    public static function issue(
        array $review,
        array $serverEligibility,
        string $organization,
        string $order,
        int $policyRevision,
        string $issuedAtUtc,
        #[\SensitiveParameter] string $key
    ): array {
        self::validateContext($organization, $order, $policyRevision, $key);
        $issued = self::utc($issuedAtUtc);
        $channels = $review['channels'] ?? null;
        if (!is_array($channels)) {
            throw new InvalidArgumentException('Canonical channels required');
        }
        $activation = ActivationPostpaidPreview::activationQuote($channels, $serverEligibility);
        $amount = $activation['activation_due_now_cents'];
        if (($review['schema'] ?? null) !== 'imagine.portal.performance.activation-routing-offline-v1'
            || ($review['purpose'] ?? null) !== 'performance_activation_review_not_an_invoice'
            || ($review['currency'] ?? null) !== 'BRL'
            || ($review['activation_cents'] ?? null) !== $amount
            || ($review['channels'] ?? null) !== $activation['channels']
            || ($review['management_fee_postpaid_basis_points'] ?? null) !== 2000
            || !array_key_exists('minimum_management_fee_cents', $review)
            || $review['minimum_management_fee_cents'] !== ActivationPostpaidPreview::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS
            || ($review['postpaid_policy_version'] ?? null) !== ActivationPostpaidPreview::POLICY_VERSION
            || ($review['media_paid_to_platforms_not_this_gateway'] ?? null) !== true
            || ($review['can_charge'] ?? null) !== false
            || ($review['can_activate'] ?? null) !== false
            || ($review['can_collect_personal_data'] ?? null) !== false
            || !is_array($review['gateway_quote'] ?? null)
            || !is_array($review['crm_review'] ?? null)) {
            throw new LogicException('Performance review is not a closed, eligible activation');
        }
        $gatewayQuote = $review['gateway_quote'];
        if (($gatewayQuote['gross_cents'] ?? null) !== $amount
            || ($gatewayQuote['currency'] ?? null) !== 'BRL') {
            throw new LogicException('PSP amount differs from channel activation');
        }
        $expectedCrm = PortalGatewayReviewExporter::export(
            $gatewayQuote, $organization, $order, $policyRevision, $key
        );
        $actualCrm = $review['crm_review'];
        if (!is_array($actualCrm['payload'] ?? null)
            || !is_string($actualCrm['mac'] ?? null)
            || !hash_equals($expectedCrm['mac'], $actualCrm['mac'])
            || self::canonical($expectedCrm['payload']) !== self::canonical($actualCrm['payload'])) {
            throw new LogicException('Source CRM review is not authentic for the selected organization and order');
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'organization_ref' => $organization,
            'order_ref' => $order,
            'policy_revision' => $policyRevision,
            'channels' => $activation['channels'],
            'activation_cents' => $amount,
            'gateway' => $gatewayQuote['gateway'],
            'method' => $gatewayQuote['method'],
            'crm_review_mac' => $expectedCrm['mac'],
            'issued_at' => $issuedAtUtc,
            'expires_at' => $issued->modify('+15 minutes')->format('Y-m-d\TH:i:s\Z'),
            'management_fee_postpaid_basis_points' => 2000,
            'minimum_management_fee_cents' => ActivationPostpaidPreview::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
            'postpaid_policy_version' => ActivationPostpaidPreview::POLICY_VERSION,
            'media_paid_directly_to_platforms' => true,
            'can_charge' => false,
            'can_activate' => false,
            'can_collect_personal_data' => false,
            'environment' => 'offline_review',
        ];
        return ['payload' => $payload, 'mac' => self::sign($payload, $key)];
    }

    /**
     * Verifies integrity and short time bounds for a *review*, not execution.
     * Without an authenticated DB order lock and unique use record this still
     * permits replay inside its short lifetime: never drive PSP writes with it.
     */
    public static function verify(
        array $envelope,
        array $originalReview,
        array $serverEligibility,
        string $expectedOrganization,
        string $expectedOrder,
        int $expectedRevision,
        string $nowUtc,
        #[\SensitiveParameter] string $key
    ): bool {
        try {
            $now = self::utc($nowUtc);
            $payload = $envelope['payload'] ?? null;
            $mac = $envelope['mac'] ?? null;
            if (!is_array($payload) || !is_string($mac)
                || !preg_match('/\A[a-f0-9]{64}\z/D', $mac)
                || !is_string($payload['issued_at'] ?? null)
                || !is_string($payload['expires_at'] ?? null)) {
                return false;
            }
            $issued = self::utc($payload['issued_at']);
            $expires = self::utc($payload['expires_at']);
            if ($expires->getTimestamp() - $issued->getTimestamp() !== self::TTL_SECONDS
                || $now < $issued || $now > $expires) {
                return false;
            }
            $expected = self::issue(
                $originalReview, $serverEligibility, $expectedOrganization,
                $expectedOrder, $expectedRevision, $payload['issued_at'], $key
            );
            return self::canonical($payload) === self::canonical($expected['payload'])
                && hash_equals($expected['mac'], $mac);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function validateContext(string $organization, string $order, int $revision, string $key): void
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D', $organization)
            || !preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D', $order)
            || $revision < 1 || $revision > 1_000_000
            || strlen($key) < 32 || strlen($key) > 128) {
            throw new InvalidArgumentException('Invalid financial review context');
        }
    }

    private static function utc(string $value): DateTimeImmutable
    {
        if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][0-9]Z\z/D', $value)) {
            throw new InvalidArgumentException('Strict UTC timestamp required');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Invalid UTC calendar date');
        }
        return $date;
    }

    private static function sign(array $payload, string $key): string
    {
        return hash_hmac('sha256', self::CONTEXT . json_encode(
            self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ), $key);
    }

    private static function canonical(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(
                static fn (mixed $value): mixed => is_array($value) ? self::canonical($value) : $value,
                $data
            );
        }
        ksort($data, SORT_STRING);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        unset($value);
        return $data;
    }
}
