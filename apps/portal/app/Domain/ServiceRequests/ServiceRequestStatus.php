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
}
