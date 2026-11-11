<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\CommercialDeliveryReview\DeliveryReviewMetadataProjector;

/**
 * F129: hardcoded illustration only, never backed by the F125 SQL review view.
 * No connection, identifier from customers, CRM result, retry permission or
 * HTTP authorization is allowed in this preview.
 */
final class DemoCommercialDeliveryReview
{
    /** @return list<array{reference:string,service:string,reason:string,attempts:int,decision:string}> */
    public static function examples(): array
    {
        return [
            [
                'reference' => 'DEMO-AURORA-001',
                'submission_reference' => 'e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1',
                'reason_code' => 'unknown_outcome',
                'service' => 'Landing Page',
                'reason' => 'Resposta não confirmada',
                'attempts' => 1,
                'decision' => 'Conferência manual necessária',
            ],
            [
                'reference' => 'DEMO-AURORA-002',
                'submission_reference' => 'e2e2e2e2-e2e2-4e2e-8e2e-e2e2e2e2e2e2',
                'reason_code' => 'expired_dispatch_may_have_committed',
                'service' => 'Onepage',
                'reason' => 'Prazo de comunicação expirado após início',
                'attempts' => 2,
                'decision' => 'Conferir destino antes de qualquer reenvio',
            ],
            [
                'reference' => 'DEMO-AURORA-003',
                'submission_reference' => 'e3e3e3e3-e3e3-4e3e-8e3e-e3e3e3e3e3e3',
                'reason_code' => 'claim_attempt_limit_reached',
                'service' => 'Formulário de contato',
                'reason' => 'Limite de reservas atingido',
                'attempts' => 3,
                'decision' => 'Revisar histórico técnico',
            ],
        ];
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string}> */
    public static function rows(): array
    {
        return array_map(static fn (array $item): array => [
            $item['reference'],
            $item['service'],
            $item['reason'],
            (string) $item['attempts'],
            $item['decision'],
        ], self::examples());
    }

    /** @return list<array{0:string,1:string}> */
    public static function reasonSummaryRows(): array
    {
        $organization = '11111111-1111-4111-8111-111111111111';
        $samples = array_map(static fn (array $example): array => [
            'organization_reference' => $organization,
            'submission_reference' => $example['submission_reference'],
            'review_reason' => $example['reason_code'],
            'attempts' => $example['attempts'],
            'claim_version' => $example['attempts'],
            'requires_manual_review' => true,
            'automatic_retry_allowed' => false,
            'real_crm_persisted_verified' => false,
            'can_acknowledge_visitor' => false,
        ], self::examples());

        $projector = new DeliveryReviewMetadataProjector();
        $counts = $projector->counts($projector->project($samples, $organization));

        return [
            ['Resultado desconhecido', (string) $counts['unknown_outcome']],
            ['Possível envio sem confirmação', (string) $counts['expired_dispatch_may_have_committed']],
            ['Limite de reservas atingido', (string) $counts['claim_attempt_limit_reached']],
        ];
    }

    public static function isOperational(): bool
    {
        return false;
    }

    public static function canResend(): bool
    {
        return false;
    }

    public static function canConfirmCrmDelivery(): bool
    {
        return false;
    }
}
