<?php

declare(strict_types=1);

namespace App\Presentation;

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
                'service' => 'Landing Page',
                'reason' => 'Resposta não confirmada',
                'attempts' => 1,
                'decision' => 'Conferência manual necessária',
            ],
            [
                'reference' => 'DEMO-AURORA-002',
                'service' => 'Onepage',
                'reason' => 'Prazo de comunicação expirado após início',
                'attempts' => 2,
                'decision' => 'Conferir destino antes de qualquer reenvio',
            ],
            [
                'reference' => 'DEMO-AURORA-003',
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
