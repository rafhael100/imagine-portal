<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * Read-only visual cards for the same *fictional* preparation projection.
 * The cards never infer checklist completion, ownership, ETA, or entitlement.
 */
final class DemoPreparationCards
{
    private const FIXTURES = [
        'DEMO-SVC-ONEPAGE' => [
            'product' => 'Sites Imagine · Onepage',
            'request' => 'DEMO-REQ-ONEPAGE',
            'next' => 'Revisar o roteiro e aprovar o conteúdo do exemplo antes de pensar em publicação.',
        ],
        'DEMO-SVC-LANDING' => [
            'product' => 'Sites Imagine · Landing Page',
            'request' => 'DEMO-REQ-LANDING',
            'next' => 'Conferir objetivo, formulário e consentimento do exemplo antes de qualquer captação.',
        ],
        'DEMO-SVC-PERFORMANCE' => [
            'product' => 'Performance AI',
            'request' => 'DEMO-REQ-PERFORMANCE',
            'next' => 'Validar contas, criativos e orçamento do exemplo antes de qualquer campanha.',
        ],
    ];

    /** @return list<array{product:string,service:string,service_state:string,request:string,request_state:string,requirements:list<string>,next:string}> */
    public static function cards(): array
    {
        return self::fromRows(DemoServicePreparation::rows());
    }

    /**
     * @param array<array-key,mixed> $rows
     * @return list<array{product:string,service:string,service_state:string,request:string,request_state:string,requirements:list<string>,next:string}>
     */
    public static function fromRows(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) !== count(self::FIXTURES)) {
            return [];
        }
        $states = array_map(
            static fn (JourneyState $state): string => $state->label(),
            JourneyState::cases(),
        );
        $cards = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 6
                || !is_string($row[1]) || !isset(self::FIXTURES[$row[1]])
                || isset($cards[$row[1]]) || !is_string($row[5])
                || $row[0] !== self::FIXTURES[$row[1]]['product']
                || $row[3] !== self::FIXTURES[$row[1]]['request']
                || !in_array($row[2], $states, true)
                || !in_array($row[4], $states, true)) {
                return [];
            }
            $requirements = array_map(
                static fn (string $text): string => trim($text),
                explode(';', $row[5]),
            );
            if (count($requirements) !== 3) {
                return [];
            }
            foreach ($requirements as $requirement) {
                if ($requirement === '' || mb_strlen($requirement, 'UTF-8') > 140) {
                    return [];
                }
            }
            $cards[$row[1]] = [
                'product' => $row[0],
                'service' => $row[1],
                'service_state' => $row[2],
                'request' => $row[3],
                'request_state' => $row[4],
                'requirements' => $requirements,
                'next' => self::FIXTURES[$row[1]]['next'],
            ];
        }
        // Validate the complete inventory before exposing any one service.
        if (count($cards) !== count(self::FIXTURES)) {
            return [];
        }

        $result = [];
        foreach (array_keys(self::FIXTURES) as $reference) {
            if (!isset($cards[$reference])) {
                return [];
            }
            $result[] = $cards[$reference];
        }
        return $result;
    }

    public static function authorizesServiceAction(): bool
    {
        return false;
    }
}
