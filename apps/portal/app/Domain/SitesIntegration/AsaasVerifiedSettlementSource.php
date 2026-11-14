<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use Throwable;

/**
 * Official *current* Asaas payment reconciliation for the Sites release
 * boundary. Never trusts a webhook, local "paid" flag or browser value.
 *
 * It reads an independently trusted local order/payment binding, then does
 * a fresh read of the full official PSP payment by pinned ID and matches
 * ALL critical fields. No optimistic fallback or cached success.
 *
 * The trusted binding source MUST enforce unique mapping of PSP payment ID,
 * order+contract+revision, versioned offer and tenant authorization.
 * It is intentionally not implemented in this candidate.
 */
final readonly class AsaasVerifiedSettlementSource implements PortalCurrentSettlementSource
{
    public function __construct(
        private AsaasTrustedPaymentBindingSource $bindings = new AsaasDenyingBindingSource(),
        private AsaasOfficialPaymentReader $payments = new AsaasDenyingPaymentReader(),
    ) {}

    public function findCurrentSettledOrder(string $orderRef): ?PortalSettlementEvidence
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$orderRef)!==1) {
            return null;
        }
        try {
            $bind=$this->bindings->findInstallationPayment($orderRef);
            if (!$bind instanceof AsaasTrustedPaymentBinding ||
                $bind->orderRef!==$orderRef || $bind->purpose!=='installation') {
                return null;
            }
            // Must be a GET /v3/payments/{id} backed by an authorized
            // account. A webhook event or a DB payment flag is NOT evidence.
            $current=$this->payments->fetchPayment($bind->paymentId);
            if (!is_array($current) ||
                ($current['id']??null)!==$bind->paymentId ||
                ($current['customer']??null)!==$bind->customerId ||
                ($current['externalReference']??null)!==$bind->externalReference ||
                ($current['billingType']??null)!==$bind->billingType ||
                ($current['status']??null)!=='RECEIVED' ||
                ($current['deleted']??false)!==false ||
                (isset($current['refundedDate']) && $current['refundedDate']!==null) ||
                (isset($current['refunds']) && $current['refunds']!==null && $current['refunds']!==[]) ||
                (isset($current['chargeback']) && $current['chargeback']!==null && $current['chargeback']!==false) ||
                (isset($current['currency']) && $current['currency']!=='BRL') ||
                (isset($current['subscription']) && $current['subscription']!==null)) {
                // A recurring cycle, refund, chargeback, partial/CONFIRMED,
                // cancelled or mismatched financial record must not fund
                // an initial Sites installation.
                return null;
            }
            $actualCents=self::strictCents($current['value']??null);
            if ($actualCents===null || $actualCents!==$bind->amountCents) {
                return null;
            }
            $receipt=[
                'schema'=>'imagine.asaas.installation-settlement.v1',
                'provider'=>'asaas',
                'tenant'=>$bind->tenantRef,
                'order'=>$bind->orderRef,
                'contract'=>$bind->contractRef,
                'revision'=>$bind->contractRevision,
                'product'=>$bind->productCode,
                'payment'=>$bind->paymentId,
                'customer'=>$bind->customerId,
                'reference'=>$bind->externalReference,
                'billing'=>$bind->billingType,
                'currency'=>'BRL',
                'amountCents'=>$actualCents,
                'status'=>'RECEIVED',
            ];
            return new PortalSettlementEvidence(
                $bind->tenantRef,$bind->orderRef,$bind->contractRef,
                $bind->contractRevision,$bind->productCode,
                $bind->paymentId,
                hash('sha256',json_encode(
                    $receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
                )),
            );
        } catch (Throwable) {
            // Outages, JSON/network errors, schema drift and database errors
            // all fail closed. No provider response or secret is logged.
            return null;
        }
    }

    /** @return int|null Exact BRL cents, never silently round a fraction. */
    private static function strictCents(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount>=1 && $amount<=9999999 ? $amount*100 : null;
        }
        if (is_float($amount)) {
            if (!is_finite($amount) || $amount<=0 || $amount>9999999) {
                return null;
            }
            $scaled=$amount*100;
            $nearest=round($scaled);
            if (abs($scaled-$nearest)>0.0000001) return null;
            return (int)$nearest;
        }
        if (!is_string($amount) ||
            preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?\z/D',$amount)!==1) {
            return null;
        }
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        $cents=((int)$whole)*100+(int)str_pad($fraction,2,'0',STR_PAD_RIGHT);
        return $cents>=1 && $cents<=999999999 ? $cents : null;
    }
}
