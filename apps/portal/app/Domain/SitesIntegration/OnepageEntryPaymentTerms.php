<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\BillingSchedule;
use App\Homologation\Commerce\CompositeQuotePreview;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Projection of the already accepted native order, never an authority source.
 * The accepted terms must explicitly carry this payment policy. An illustrative
 * quote, a caller-provided amount or this helper alone cannot authorize a charge.
 */
final class OnepageEntryPaymentTerms
{
    public const POLICY_VERSION = 'onepage-d0-d90-v1';

    public static function requiredPolicy(ApprovedOfferCatalog $catalog): array
    {
        $quote = $catalog->quote('onepage');
        return [
            'version' => self::POLICY_VERSION,
            'currency' => 'BRL',
            'setup_total_cents' => $quote['setup_total_cents'],
            'setup_charges' => $quote['setup_charges'],
            'monthly' => $quote['monthly'],
            'entry_installment_key' => 'setup-1',
            'briefing_after_entry_confirmation' => true,
            'publication_requires_separate_acceptance' => true,
        ];
    }

    /** @return list<array{key:string,kind:string,due_date:string,amount_cents:int}>|null */
    public static function schedule(OnepageAcceptedOrder $order): ?array
    {
        try {
            foreach ([$order->id, $order->quoteId, $order->companyId, $order->buyerId] as $id) {
                if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)) return null;
            }
            foreach ([$order->catalogVersion, $order->offerDigest, $order->termsDigest, $order->contractDigest] as $digest) {
                if (!preg_match('/\A[a-f0-9]{64}\z/D', $digest)) return null;
            }
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $order->contractRef)
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $order->companyReference)
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $order->publicationRef)
                || $order->contractRevision < 1
                || hash('sha256', $order->contractText) !== $order->contractDigest) return null;
            $format = 'Y-m-d\TH:i:s.u\Z';
            $accepted = DateTimeImmutable::createFromFormat('!'.$format, $order->acceptedAt, new DateTimeZone('UTC'));
            if (!$accepted || $accepted->format($format) !== $order->acceptedAt) return null;

            $catalog = new ApprovedOfferCatalog();
            $quote = $order->quoteSnapshot;
            if ($order->catalogVersion !== $catalog->version()
                || ($quote['catalog_version'] ?? null) !== $order->catalogVersion
                || ($quote['site'] ?? null) !== 'onepage' || ($quote['channels'] ?? null) !== []) return null;
            $expectedQuote = (new CompositeQuotePreview($catalog))->quote([
                'site' => 'onepage', 'channels' => [], 'tier' => $quote['media_tier'] ?? null,
            ]);
            if (self::canonical($quote) !== self::canonical($expectedQuote)
                || self::canonical($order->terms['onepage_payment_policy'] ?? null)
                    !== self::canonical(self::requiredPolicy($catalog))) return null;

            // offerDigest and termsDigest come from PostgreSQL's immutable JSONB
            // snapshot. Do not pretend json_encode() reproduces JSONB::text.
            $schedule = BillingSchedule::project($catalog->quote('onepage'), $accepted->format(DATE_ATOM), 1);
            $setup = array_values(array_filter($schedule, static fn(array $row): bool => $row['kind'] === 'setup'));
            return array_column($setup, 'key') === ['setup-1', 'setup-2', 'setup-3'] ? $setup : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stable reference shared with the native SQL binding producer. No browser
     * identity or payment status is encoded. Each setup line has a distinct
     * reference even when its amount is the same. This does not create a charge.
     */
    public static function reference(
        OnepageAcceptedOrder $order,
        string $sandboxAccount,
        string $customerId,
        string $lineKey = 'setup-1',
    ): ?string {
        $schedule = self::schedule($order);
        if ($schedule === null
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $sandboxAccount)
            || !preg_match('/\Acus_[A-Za-z0-9]{6,64}\z/D', $customerId)) return null;
        foreach ($schedule as $line) {
            if ($line['key'] !== $lineKey) continue;
            $parts = [
                'imagine.onepage.setup-reference.v1',
                $order->id, $order->quoteId, $order->companyId, $order->companyReference, $order->buyerId,
                $order->publicationRef,
                $order->contractRef, (string) $order->contractRevision,
                $order->catalogVersion, $order->offerDigest, $order->termsDigest,
                $order->contractDigest, $order->acceptedAt,
                $sandboxAccount, 'sandbox', $customerId,
                $line['key'], $line['due_date'], (string) $line['amount_cents'], 'BRL',
            ];
            return 'imagine:onepage:'.$line['key'].':'.hash('sha256', implode("\n", $parts));
        }
        return null;
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(self::canonical(...), $value);
    }
}
