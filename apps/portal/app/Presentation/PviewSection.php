<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * PVIEW-02: static navigation metadata only, NOT authentication or entitlement.
 * Do not use this enum to authorize any HTTP request or assert active products.
 */
enum PviewSection: string
{
    case Overview = 'overview';
    case Services = 'services';
    case Catalog = 'catalog';
    case Requests = 'requests';
    case Contracts = 'contracts';
    case Performance = 'performance';
    case University = 'university';
    case Notices = 'notices';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Visão geral',
            self::Services => 'Meus serviços',
            self::Catalog => 'Serviços disponíveis',
            self::Requests => 'Solicitações',
            self::Contracts => 'Contratos',
            self::Performance => 'Performance AI',
            self::University => 'Universidade Imagine',
            self::Notices => 'Central de avisos',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Overview => 'Resumo ilustrativo do espaço da Empresa Aurora fictícia.',
            self::Services => 'Referências a produtos distintos, sem prova de contratação.',
            self::Catalog => 'Catálogo conceitual, sem oferta, preço ou checkout.',
            self::Requests => 'Exemplos sem abertura, envio ou acompanhamento de chamados reais.',
            self::Contracts => 'Sem contratos, pagamentos ou dados financeiros conectados.',
            self::Performance => 'Sem campanhas, anúncios, métricas ou contas de mídia conectadas.',
            self::University => 'Benefício futuro sujeito a elegibilidade de serviços ativos.',
            self::Notices => 'Avisos fictícios, sem destinatário, envio ou leitura persistente.',
        };
    }
}
