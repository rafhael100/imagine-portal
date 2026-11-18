<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * Trusted, immutable local snapshot of ONE installation invoice/charge.
 * This is constructed ONLY after resolving a real authenticated order, its
 * versioned offer and the PSP mapping from the authorized Portal database.
 * A client request, webhook JSON or Asaas response MUST NOT construct it.
 *
 * Installation and future monthly subscription cycles have separate ledgers.
 */
final readonly class AsaasTrustedPaymentBinding
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
        public string $paymentId,
        public string $customerId,
        public string $externalReference,
        public int $amountCents,
        public string $billingType,
        public string $purpose = 'installation',
    ) {
        foreach ([$tenantRef,$orderRef,$contractRef,$externalReference] as $value) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$value)!==1) {
                throw new InvalidArgumentException('Invalid local financial binding.');
            }
        }
        if ($contractRevision<1 ||
            !in_array($productCode,['onepage','landing_page'],true) ||
            preg_match('/\Apay_[A-Za-z0-9]{6,64}\z/D',$paymentId)!==1 ||
            preg_match('/\Acus_[A-Za-z0-9]{6,64}\z/D',$customerId)!==1 ||
            $amountCents<1 || $amountCents>999999999 ||
            !in_array($billingType,['PIX','CREDIT_CARD','BOLETO'],true) ||
            $purpose!=='installation') {
            throw new InvalidArgumentException('Untrusted or ineligible charge mapping.');
        }
    }
}
