<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ServiceRequests\ServiceRequestStatus;
use RuntimeException;

/**
 * F22: test-only cross-project cards built from the SAME vetted PG18 row projections
 * used by the integrated Blade tables. No SQL, HTTP, grants or customer entitlement.
 */
final class DisposablePg18ServiceCards
{
    /**
     * @param list<list<string>> $preparationRows
     * @param list<list<string>> $requestRows
     * @return list<array{product:string,reference:string,preparation:string,progress:string,pending:string,request:string,request_status:string,request_revision:string,next_step:string}>
     */
    public static function fromRows(array $preparationRows, array $requestRows): array
    {
        // Check the two projection inventories before joining; no partial cards.
        $overview = DisposablePg18ShowcaseOverview::fromRows($preparationRows, $requestRows);
        if ($overview['projects'] !== $overview['requests']) {
            throw new RuntimeException('The synthetic cards require one request per project.');
        }
        $requestsByProject = [];
        $knownStates = array_map(
            static fn (ServiceRequestStatus $state): string => $state->label(),
            ServiceRequestStatus::cases(),
        );
        foreach ($requestRows as $row) {
            if (isset($requestsByProject[$row[1]])
                || !in_array($row[2], $knownStates, true)
                || preg_match('/\ARevisão fictícia ([1-9][0-9]{0,8})\z/uD', $row[3]) !== 1) {
                throw new RuntimeException('Invalid synthetic request history for project cards.');
            }
            $requestsByProject[$row[1]] = $row;
        }

        $cards = [];
        foreach ($preparationRows as $row) {
            [$product, $reference, $preparation, $progress, $pending] = $row;
            if (!isset($requestsByProject[$reference])
                || !in_array($product, [
                    'Sites Imagine · Onepage',
                    'Sites Imagine · Landing Page',
                    'Performance AI',
                ], true)
                || !in_array($preparation, [
                    'Rascunho de exemplo', 'Revisão interna fictícia',
                ], true)
                || preg_match('/\A([0-9]{1,2})\/([0-9]{1,2}) itens revisados\z/uD', $progress, $match) !== 1
                || ($pending !== 'Sem itens pendentes no exemplo'
                    && !str_starts_with($pending, 'Pendente no exemplo: '))) {
                throw new RuntimeException('Invalid preparation state for synthetic card.');
            }
            $done = (int) $match[1];
            $required = (int) $match[2];
            if (($done === $required) !== ($pending === 'Sem itens pendentes no exemplo')) {
                throw new RuntimeException('Conflicting checklist completion.');
            }
            $request = $requestsByProject[$reference];
            $cards[] = [
                'product' => $product,
                'reference' => $reference,
                'preparation' => $preparation,
                'progress' => $progress,
                'pending' => $pending,
                'request' => $request[0],
                'request_status' => $request[2],
                'request_revision' => $request[3],
                'next_step' => $done === $required
                    ? 'Aguardar revisão interna do exemplo; nenhuma publicação autorizada.'
                    : 'Conferir pendências da preparação fictícia; nenhuma ação de cliente habilitada.',
            ];
        }
        return $cards;
    }
}
