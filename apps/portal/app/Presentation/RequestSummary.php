<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** A read-only example, without actor, organization, permissions or mutations. */
final readonly class RequestSummary
{
    /** @param list<IllustrativeUpdate> $updates Supplied narrative order, not operational chronology. */
    public function __construct(
        public string $reference,
        public string $serviceReference,
        public string $title,
        public string $summary,
        public JourneyState $state,
        public array $updates = [],
    ) {
        if (preg_match('/\ADEMO-REQ-[A-Z0-9-]{1,40}\z/D', $reference) !== 1
            || preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $serviceReference) !== 1) {
            throw new InvalidArgumentException('Expected fictional request and service references.');
        }
        foreach ([$title, $summary] as $text) {
            if (trim($text) === '' || strlen($text) > 2000) {
                throw new InvalidArgumentException('Expected bounded presentation text.');
            }
        }
        if (!array_is_list($updates) || count($updates) > 20) {
            throw new InvalidArgumentException('Expected a bounded list of fictional updates.');
        }
        foreach ($updates as $update) {
            if (!$update instanceof IllustrativeUpdate) {
                throw new InvalidArgumentException('Expected an illustrative update.');
            }
        }
    }
}
