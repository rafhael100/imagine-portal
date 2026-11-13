<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Domain\ClientOverview\DemoOverviewResult;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;

/**
 * Read-only service setup checklist over the reconciled fictional summary.
 *
 * Never turns a fixture into an active site, approved campaign, billing
 * event or entitlement. Failure in any linked source hides ALL rows.
 */
final class DemoServicePreparation
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
        $activity = DemoActivityFeed::fromSources($summary, $requests, $organization);
        if (!$summary->isConsistent() || count($activity) !== 3) {
            return [];
        }

        // These are documented product-specific *requirements*, not claims
        // that any customer already supplied documents or accounts.
        $requirements = [
            'Sites Imagine · Onepage' => [
                'Roteiro, textos e elementos de marca',
                'Validação editorial fictícia',
                'Domínio e publicação não configurados',
            ],
            'Sites Imagine · Landing Page' => [
                'Objetivo de conversão e conteúdo',
                'Revisão fictícia de formulário e consentimento',
                'Domínio e captação não configurados',
            ],
            'Performance AI' => [
                'Objetivos e indicadores de campanha',
                'Contas Google Ads e Meta não conectadas',
                'Criativos e orçamento não autorizados',
            ],
        ];

        $seen = [];
        $rows = [];
        foreach ($activity as $item) {
            [$request, $service, $status, $next] = $item;
            if (!array_key_exists($service, $requirements) || isset($seen[$service])) {
                return [];
            }
            $seen[$service] = true;
            $rows[] = [
                $service,
                $request,
                $status,
                $next,
                implode('; ', $requirements[$service]),
            ];
        }
        return count($seen) === count($requirements) ? $rows : [];
    }

    /** @return list<string> */
    public static function safeguards(): array
    {
        return [
            'Sem identidade Portal ativa e MFA validado, não conceder acesso a recursos de cliente.',
            'CRM Imagine possui login e sessão próprios; não reutilizar a sessão do Portal.',
            'Universidade Imagine é benefício de serviço elegível ativo, nunca assinatura avulsa.',
            'Site, domínio, campanha e formulário não são publicados por esta prévia.',
        ];
    }

    public static function authorizesSitePublication(): bool
    {
        return false;
    }

    public static function authorizesAdCampaign(): bool
    {
        return false;
    }

    public static function authorizesUniversityAccess(): bool
    {
        return false;
    }
}
