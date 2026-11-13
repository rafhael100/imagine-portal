<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Read-only preparation table over the original DEV's fictional service and
 * request fixtures. This is NOT a CRM sync, onboarding workflow or entitlement.
 */
final class DemoServicePreparation
{
    private const SERVICES = [
        'DEMO-SVC-ONEPAGE' => [
            'Sites Imagine · Onepage',
            'Briefing e identidade visual; conteúdo revisado; domínio pendente',
            'DEMO-REQ-ONEPAGE',
        ],
        'DEMO-SVC-LANDING' => [
            'Sites Imagine · Landing Page',
            'Objetivo e conteúdo; Formulário e Consentimento; domínio pendente',
            'DEMO-REQ-LANDING',
        ],
        'DEMO-SVC-PERFORMANCE' => [
            'Performance AI',
            'Objetivos e métricas; contas Google Ads e Meta; criativos e orçamento',
            'DEMO-REQ-PERFORMANCE',
        ],
    ];

    /** @return list<list<string>> */
    public static function rows(): array
    {
        return self::fromSources(
            DemoServiceRequests::services(),
            DemoServiceRequests::requests(),
        );
    }

    /**
     * @param array<array-key,mixed> $services
     * @param array<array-key,mixed> $requests
     * @return list<list<string>>
     */
    public static function fromSources(array $services, array $requests): array
    {
        if (!array_is_list($services) || !array_is_list($requests)
            || count($services) !== 3 || count($requests) !== 3) {
            return [];
        }

        $byService = [];
        foreach ($services as $service) {
            if (!$service instanceof ServiceFollowUp
                || !isset(self::SERVICES[$service->reference])
                || $service->product !== self::SERVICES[$service->reference][0]
                || isset($byService[$service->reference])) {
                return [];
            }
            $byService[$service->reference] = $service;
        }

        $byRequest = [];
        foreach ($requests as $request) {
            if (!$request instanceof RequestSummary
                || !isset(self::SERVICES[$request->serviceReference])
                || $request->reference !== self::SERVICES[$request->serviceReference][2]
                || isset($byRequest[$request->serviceReference])) {
                return [];
            }
            $byRequest[$request->serviceReference] = $request;
        }

        $rows = [];
        foreach (self::SERVICES as $reference => [$product, $requirements]) {
            if (!isset($byService[$reference], $byRequest[$reference])) {
                return [];
            }
            $service = $byService[$reference];
            $request = $byRequest[$reference];
            $rows[] = [
                $product,
                $service->reference,
                $service->state->label(),
                $request->reference,
                $request->state->label(),
                $requirements,
            ];
        }

        return $rows;
    }

    public static function authorizesPublication(): bool
    {
        return false;
    }

    public static function authorizesAds(): bool
    {
        return false;
    }

    public static function authorizesCustomerAccess(): bool
    {
        return false;
    }
}
