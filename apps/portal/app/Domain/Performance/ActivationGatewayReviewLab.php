<?php
declare(strict_types=1);

namespace App\Domain\Performance;

use App\Domain\PaymentRouting\GatewayRoutingPreview;
use App\Domain\PaymentRouting\GatewayPolicyReview;
use App\Domain\PaymentRouting\PortalGatewayReviewExporter;
use LogicException;

/**
 * F413: synthetic preview of a NEW Performance activation checkout journey.
 *
 * There are no containers, HTTP requests, customer identity, PSP API keys,
 * subscription writes, external CRM calls, invoice issuers or DB connections.
 * This is NOT a payment transaction or customer-visible permission.
 */
final class ActivationGatewayReviewLab
{
    /**
     * Recalculate activation from a server-owned channel eligibility map,
     * route only the activation fee (NOT the client's media budget), and
     * produce the established signed CRM review payload with hard false
     * charge/activation flags.
     *
     * @param list<string> $channels
     * @param array<string,bool> $eligible
     * @param array<string,mixed> $paymentPolicy
     * @return array<string,mixed>
     */
    public static function preview(
        array $channels,
        array $eligible,
        array $paymentPolicy,
        string $paymentMethod,
        string $organizationReference,
        string $orderReference,
        int $policyRevision,
        #[\SensitiveParameter] string $syntheticSigningKey
    ): array {
        $activation = ActivationPostpaidPreview::activationQuote($channels, $eligible);
        if ($activation['checkout_enabled'] !== false
            || $activation['asaas_write_enabled'] !== false
            || $activation['ads_write_enabled'] !== false
            || $activation['media_paid_directly_to_platforms'] !== true
            || $activation['management_postpaid_on_verified_actual'] !== true) {
            throw new LogicException('Activation review must be closed');
        }

        // A caller cannot pair a versioned CRM envelope with different
        // gateway commercial terms. The candidate policy itself is read-only.
        GatewayPolicyReview::validate($paymentPolicy);
        if ($paymentPolicy['revision'] !== $policyRevision) {
            throw new LogicException('Gateway policy revision mismatch');
        }

        // Bank fees belong only to the one-time Imagine activation amount.
        // Media budgets and unverified management fees must never be charged
        // to the selected PSP by this flow.
        $grossCents = $activation['activation_due_now_cents'];
        $quote = GatewayRoutingPreview::quote($paymentPolicy, $paymentMethod, $grossCents);
        if ($quote['gross_cents'] !== $grossCents
            || $quote['estimated_fee_cents'] >= $grossCents
            || $quote['estimated_net_cents'] <= 0) {
            throw new LogicException('Invalid activation gateway quote');
        }

        $crmReview = PortalGatewayReviewExporter::export(
            $quote, $organizationReference, $orderReference,
            $policyRevision, $syntheticSigningKey
        );
        if ($crmReview['payload']['can_charge'] !== false
            || $crmReview['payload']['can_activate'] !== false
            || $crmReview['payload']['amount_cents'] !== $grossCents) {
            throw new LogicException('Activation and CRM review diverged');
        }
        return [
            'schema' => 'imagine.portal.performance.activation-routing-offline-v1',
            'purpose' => 'performance_activation_review_not_an_invoice',
            'channels' => $activation['channels'],
            'currency' => 'BRL',
            'activation_cents' => $grossCents,
            'management_fee_postpaid_basis_points' => 2000,
            'minimum_management_fee_cents' => ActivationPostpaidPreview::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS,
            'postpaid_policy_version' => ActivationPostpaidPreview::POLICY_VERSION,
            'media_paid_to_platforms_not_this_gateway' => true,
            'gateway_quote' => $quote,
            'crm_review' => $crmReview,
            'can_charge' => false,
            'can_activate' => false,
            'can_collect_personal_data' => false,
        ];
    }
}
