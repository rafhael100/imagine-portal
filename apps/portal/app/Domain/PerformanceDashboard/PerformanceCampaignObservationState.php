<?php
declare(strict_types=1);

namespace App\Domain\PerformanceDashboard;

/**
 * Display-only vocabulary for F84's internal Performance observations.
 * Deliberately NOT DemoCampaignStage and NEVER provider-confirmed status.
 */
enum PerformanceCampaignObservationState: string
{
    case DraftDemo = 'draft_demo';
    case PausedDemo = 'paused_demo';
    case ArchivedDemo = 'archived_demo';

    public function label(): string
    {
        return match($this) {
            self::DraftDemo => 'Rascunho DEMO',
            self::PausedDemo => 'Pausada DEMO',
            self::ArchivedDemo => 'Arquivada DEMO',
        };
    }

    public function externallyConfirmed(): bool { return false; }
    public function canExecute(): bool { return false; }
}
