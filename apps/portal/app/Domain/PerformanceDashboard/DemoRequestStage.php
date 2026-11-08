<?php

namespace App\Domain\PerformanceDashboard;

/** Read-only preview. No actual business approval action is enabled. */
enum DemoRequestStage: string
{
    case RequestedMock = 'requested_mock';
    case AwaitingMockReview = 'awaiting_mock_review';

    public function label(): string
    {
        return match ($this) {
            self::RequestedMock => 'Solicitação fictícia',
            self::AwaitingMockReview => 'Aprovação simulada pendente',
        };
    }
}
