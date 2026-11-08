<?php

declare(strict_types=1);

namespace App\Presentation;

/** Closed in-document anchors. Separate from ClientSection and all access policies. */
enum PreviewArea: string
{
    case Overview = 'inicio';
    case Services = 'servicos';
    case Performance = 'performance';
    case Requests = 'solicitacoes';
    case University = 'universidade';
    case Finance = 'financeiro';

    public static function resolve(self|string $anchor): self
    {
        return $anchor instanceof self ? $anchor : (self::tryFrom($anchor) ?? self::Overview);
    }

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Visão geral',
            self::Services => 'Meus serviços',
            self::Performance => 'Performance AI',
            self::Requests => 'Solicitações',
            self::University => 'Universidade',
            self::Finance => 'Financeiro',
        };
    }
}
