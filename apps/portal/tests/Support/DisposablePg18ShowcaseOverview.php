<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Read-only summary of the *already projected* disposable PostgreSQL 18
 * showcase. No database, HTTP, authorization, or customer input.
 */
final class DisposablePg18ShowcaseOverview
{
    /**
     * @param list<list<string>> $preparationRows
     * @param list<list<string>> $requestRows
     * @return array{projects:int,requests:int,checkpoints_reviewed:int,checkpoints_total:int}
     */
    public static function fromRows(array $preparationRows, array $requestRows): array
    {
        if ($preparationRows === [] || count($preparationRows) > 10
            || count($requestRows) > 50) {
            throw new RuntimeException('Invalid synthetic showcase inventory.');
        }

        $projects = [];
        $reviewed = 0;
        $total = 0;
        foreach ($preparationRows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 5
                || !is_string($row[1]) || $row[1] === ''
                || isset($projects[$row[1]])
                || !is_string($row[3])
                || preg_match('/\\A([0-9]{1,2})\\/([0-9]{1,2}) itens revisados\\z/uD', $row[3], $matches) !== 1) {
                throw new RuntimeException('Malformed or duplicated synthetic project.');
            }
            $done = (int) $matches[1];
            $required = (int) $matches[2];
            if ($required < 1 || $required > 4 || $done > $required
                || !is_string($row[4])
                || ($row[4] === 'Sem itens pendentes no exemplo') !== ($done === $required)) {
                throw new RuntimeException('Inconsistent synthetic checkpoint progress.');
            }
            $projects[$row[1]] = true;
            $reviewed += $done;
            $total += $required;
        }

        $seenRequests = [];
        foreach ($requestRows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 4
                || !is_string($row[0]) || $row[0] === ''
                || !is_string($row[1]) || !isset($projects[$row[1]])
                || isset($seenRequests[$row[0]])) {
                throw new RuntimeException('Synthetic request does not match a unique project.');
            }
            $seenRequests[$row[0]] = true;
        }

        if ($total > 40) {
            throw new RuntimeException('Synthetic checkpoint inventory exceeds cap.');
        }

        return [
            'projects' => count($projects),
            'requests' => count($seenRequests),
            'checkpoints_reviewed' => $reviewed,
            'checkpoints_total' => $total,
        ];
    }
}
