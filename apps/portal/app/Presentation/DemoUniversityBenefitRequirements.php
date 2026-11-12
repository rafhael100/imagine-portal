<?php

declare(strict_types=1);

namespace App\Presentation;

/** Descriptive requirements only, not a University access check. */
final class DemoUniversityBenefitRequirements
{
    /** @return list<string> */
    public static function items(): array
    {
        return [
            'Existir pelo menos um serviço Imagine elegível e ativo, confirmado por fonte confiável.',
            'Confirmar a empresa e o vínculo do cliente antes de conceder qualquer benefício.',
            'Revalidar elegibilidade ao cancelar ou suspender o último serviço elegível.',
            'Não vender a Universidade Imagine isoladamente e não presumir login compartilhado com o CRM.',
        ];
    }
}
