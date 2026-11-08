<?php

declare(strict_types=1);

namespace App\Presentation;

/** Closed content groups, never delivery channels or access decisions. */
enum NoticeCategory: string
{
    case General = 'general';
    case Sites = 'sites';
    case Requests = 'requests';
    case Performance = 'performance';
    case MediaCredit = 'media_credit';
    case University = 'university';
    case Crm = 'crm';

    public static function fromCode(string $code): self
    {
        return self::tryFrom($code) ?? self::General;
    }

    public function label(): string
    {
        return match ($this) {
            self::General => 'Aviso geral ilustrativo',
            self::Sites => 'Sites Imagine',
            self::Requests => 'Solicitações fictícias',
            self::Performance => 'Performance AI',
            self::MediaCredit => 'Mídia · conceito futuro',
            self::University => 'Universidade Imagine',
            self::Crm => 'CRM Imagine',
        };
    }
}
