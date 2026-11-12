<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ServiceRequests\ServiceRequestAction;
use App\Domain\ServiceRequests\ServiceRequestActorKind;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Domain\ServiceRequests\ServiceRequestWorkflowPreview;
use LogicException;

/**
 * F143: explain the existing synthetic state machine as an offline chart.
 * This class does not fetch requests or authenticate an actor.
 */
final class DemoRequestWorkflowMap
{
    private static function actorLabel(ServiceRequestActorKind $actor): string
    {
        return match ($actor) {
            ServiceRequestActorKind::Customer => 'Cliente fictício',
            ServiceRequestActorKind::ImagineTeam => 'Equipe Imagine fictícia',
        };
    }

    private static function actionLabel(ServiceRequestAction $action): string
    {
        return match ($action) {
            ServiceRequestAction::Submit => 'Enviar exemplo',
            ServiceRequestAction::StartReview => 'Iniciar análise fictícia',
            ServiceRequestAction::RequestInformation => 'Solicitar informações fictícias',
            ServiceRequestAction::ProvideInformation => 'Complementar exemplo',
            ServiceRequestAction::Resolve => 'Marcar resolvida no exemplo',
            ServiceRequestAction::Close => 'Encerrar exemplo',
            ServiceRequestAction::Reopen => 'Reabrir exemplo',
        };
    }

    /** @return list<array{0:string,1:string,2:string,3:string}> */
    public static function rows(): array
    {
        $org = DemoRequestInbox::organization();
        $flow = new ServiceRequestWorkflowPreview();
        $rows = [];

        foreach (ServiceRequestStatus::cases() as $status) {
            $snapshot = new ServiceRequestSnapshot(
                'DEMO-REQ-WORKFLOW', $org, 'DEMO-SVC-ONEPAGE',
                $status, 1,
            );
            foreach (ServiceRequestActorKind::cases() as $actor) {
                foreach (ServiceRequestAction::cases() as $action) {
                    $proposal = $flow->evaluate($snapshot, $org, $actor, $action, 1);
                    if ($proposal->isExecutable()) {
                        throw new LogicException('Synthetic workflow must never authorize a write.');
                    }
                    if (!$proposal->isValidBusinessTransition()) {
                        continue;
                    }
                    if ($proposal->candidate === null
                        || $proposal->candidate->revision !== 2
                        || !$proposal->candidate->organizationId->equals($org)) {
                        throw new LogicException('Invalid synthetic transition projection.');
                    }
                    $rows[] = [
                        $status->label(),
                        self::actorLabel($actor),
                        self::actionLabel($action),
                        $proposal->candidate->status->label(),
                    ];
                }
            }
        }

        // Exactly seven known transitions; additions to the domain workflow
        // require conscious review before reflecting them in the UI.
        if (count($rows) !== 7) {
            throw new LogicException('The demonstrated workflow transition contract changed.');
        }

        return $rows;
    }

    public static function canExecute(): bool
    {
        return false;
    }
}
