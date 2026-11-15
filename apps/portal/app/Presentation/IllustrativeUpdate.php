<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Ordered narrative only; not an audit event, timestamp or operation. */
final readonly class IllustrativeUpdate
{
    public function __construct(
        public string $label,
        public string $title,
        public string $summary,
    ) {
        foreach ([$label, $title, $summary] as $text) {
            if (trim($text) === '' || strlen($text) > 2000) {
                throw new InvalidArgumentException('Expected bounded fictional update text.');
            }
        }
    }
}
