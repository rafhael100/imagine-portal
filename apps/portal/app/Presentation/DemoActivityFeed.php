<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ClientOverview\DemoOverviewResult;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;

/**
 * Deterministic action-oriented display derived from the SAME consistent
 * fictional service/request/contract sources as the overview dashboard.
 *
 * Revision is NOT a timestamp or deadline. Never invent chronology or SLA.
 * If one reference, tenant or status is inconsistent, show no partial feed.
 */
final class DemoActivityFeed
{
    /** @return list<list<string>> */
    public static function rows(): array
    {
        return self::fromSources(
            DemoClientOverview::summary(),
            DemoRequestInbox::examples(),
            DemoRequestInbox::organization(),
        );
    }

    /**
     * @param array<array-key,mixed> $requests
     * @return list<list<string>>
     */
    public static function fromSources(
        DemoOverviewResult $summary,
        array $requests,
        OrganizationId $organization,
    ): array {
        if (!$summary->isConsistent()
            || !array_is_list($requests)
            || count($requests) === 0
            || count($requests) > 50
            || count($requests) !== $summary->requestCount) {
            return [];
        }

        $byService = [];
        foreach ($summary->rows as $record) {
            if (isset($byService[$record['reference']])
                || !str_starts_with($record['reference'], 'DEMO-SVC-')) {
                return [];
            }
            $byService[$record['reference']] = $record;
        }
        if (count($byService) !== count($requests)) {
            return [];
        }

        $seen = [];
        $activity = [];
        foreach ($requests as $request) {
            if (!$request instanceof ServiceRequestSnapshot
                || !$request->organizationId->equals($organization)
                || !str_starts_with($request->requestId, 'DEMO-REQ-')
                || isset($seen[$request->requestId])
                || !isset($byService[$request->serviceReference])
                || $byService[$request->serviceReference]['request_status']
                    !== $request->status->label()) {
                return [];
            }
            $seen[$request->requestId] = true;
            $record = $byService[$request->serviceReference];
            $activity[] = [
                'reference' => $request->requestId,
                'service' => $record['service'],
                'state' => $request->status->label(),
                'next' => self::fictionalNextStep($request->status),
                'revision' => $request->revision,
            ];
        }

        usort($activity, static fn (array $a, array $b): int =>
            ($b['revision'] <=> $a['revision'])
                ?: strcmp($a['reference'], $b['reference'])
        );

        return array_map(
            static fn (array $row): array => [
                $row['reference'],
                $row['service'],
                $row['state'],
                $row['next'],
                'Versão fictícia '.$row['revision'],
            ],
            $activity,
        );
    }

    private static function fictionalNextStep(ServiceRequestStatus $status): string
    {
        return match ($status) {
            ServiceRequestStatus::Draft => 'Preparar descrição de exemplo',
            ServiceRequestStatus::Submitted => 'Aguardar triagem ilustrativa',
            ServiceRequestStatus::InReview => 'Acompanhar revisão fictícia',
            ServiceRequestStatus::WaitingOnCustomer => 'Complementar informações de exemplo',
            ServiceRequestStatus::Resolved => 'Conferir resolução ilustrativa',
            ServiceRequestStatus::Closed => 'Nenhuma etapa ilustrativa pendente',
        };
    }

    public static function authorizesRequestAction(): bool
    {
        return false;
    }

    public static function claimsChronologicalActivity(): bool
    {
        return false;
    }
}
