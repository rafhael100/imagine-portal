<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Plain, untrusted text for an illustrative answer; no identity or operation. */
final readonly class HelpTopic
{
    public function __construct(
        public string $reference,
        public HelpCategory $category,
        public string $title,
        public string $guidance,
    ) {
        if (preg_match('/\Ademo_help_[a-z0-9_]{1,40}\z/D', $reference) !== 1
            || trim($title) === '' || strlen($title) > 240
            || trim($guidance) === '' || strlen($guidance) > 2000) {
            throw new InvalidArgumentException('Expected bounded fictional help content.');
        }
    }
}
