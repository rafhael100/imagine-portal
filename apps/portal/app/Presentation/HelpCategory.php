<?php

declare(strict_types=1);

namespace App\Presentation;

/** Content grouping only. Never access, service status or eligibility. */
enum HelpCategory: string
{
    case General = 'general';
    case Sites = 'sites';
    case Performance = 'performance';
    case Requests = 'requests';
    case Contracts = 'contracts';
    case Crm = 'crm';
    case University = 'university';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::General;
    }

    public function label(): string
    {
        return match ($this) {
            self::General => 'Orientação geral',
            self::Sites => 'Sites Imagine',
            self::Performance => 'Performance AI',
            self::Requests => 'Solicitações',
            self::Contracts => 'Contratos',
            self::Crm => 'CRM Imagine',
            self::University => 'Universidade Imagine',
        };
    }
}
