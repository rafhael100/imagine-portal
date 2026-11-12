<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

/**
 * Workflow vocabulary only: no implication that a request is persisted.
 */
enum ServiceRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case WaitingOnCustomer = 'waiting_on_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviada',
            self::InReview => 'Em análise',
            self::WaitingOnCustomer => 'Aguardando informações do cliente',
            self::Resolved => 'Resolvida',
            self::Closed => 'Encerrada',
        };
    }
}
