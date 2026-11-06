<?php

declare(strict_types=1);

namespace App\Presentation;

/** Presentation order only; never an entitlement, route or access decision. */
enum ClientSection: string
{
    case Overview = 'overview';
    case Services = 'services';
    case Available = 'available';
    case Contracts = 'contracts';
    case Modules = 'modules';
    case Journey = 'journey';
    case Requests = 'requests';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Visão geral',
            self::Services => 'Meus serviços Imagine',
            self::Available => 'Serviços disponíveis',
            self::Contracts => 'Contratos e assinaturas',
            self::Modules => 'Módulos Imagine',
            self::Journey => 'Jornada do cliente',
            self::Requests => 'Solicitações',
        };
    }
}
