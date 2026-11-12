<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/**
 * Commercial relationship only, not a checkout/eligibility/authorization rule.
 */
enum PortfolioKind: string
{
    case IndependentService = 'independent_service';
    case IndependentAccount = 'independent_account';
    case IncludedBenefit = 'included_benefit';

    public function label(): string
    {
        return match ($this) {
            self::IndependentService => 'Serviço independente',
            self::IndependentAccount => 'Produto independente com acesso próprio',
            self::IncludedBenefit => 'Benefício para clientes elegíveis',
        };
    }
}
