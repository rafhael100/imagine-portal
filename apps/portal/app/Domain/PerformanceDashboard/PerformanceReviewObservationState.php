<?php
declare(strict_types=1);

namespace App\Domain\PerformanceDashboard;

/**
 * Display-only vocabulary for F84 review-journal observations.
 * Internal mock approval is NEVER authorization to publish/bill.
 */
enum PerformanceReviewObservationState: string
{
    case PendingHumanDemo = 'pending_human_demo';
    case ApprovedDemoNoExecution = 'approved_demo_no_execution';
    case RejectedDemo = 'rejected_demo';

    public function label(): string
    {
        return match($this) {
            self::PendingHumanDemo => 'Aguardando revisão DEMO',
            self::ApprovedDemoNoExecution => 'Revisão interna DEMO — sem execução',
            self::RejectedDemo => 'Solicitação recusada DEMO',
        };
    }

    public function authorizesExternalOperation(): bool { return false; }
    public function isRealApproval(): bool { return false; }
}
