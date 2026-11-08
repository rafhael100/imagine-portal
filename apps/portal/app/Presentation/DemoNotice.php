<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Plain fictional display text. No recipient, delivery, read state or event. */
final readonly class DemoNotice
{
    public function __construct(
        public string $reference,
        public NoticeCategory $category,
        public string $title,
        public string $body,
    ) {
        if (preg_match('/\Ademo_notice_[a-z0-9_]{1,48}\z/D', $reference) !== 1
            || trim($title) === '' || strlen($title) > 180
            || trim($body) === '' || strlen($body) > 1600) {
            throw new InvalidArgumentException('Expected bounded fictional notice text and reference.');
        }
    }
}
