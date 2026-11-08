<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** Plain display strings only; validation never makes text safe HTML or authentic data. */
final class PreviewText
{
    public static function validated(string $text, int $maxBytes): string
    {
        if (trim($text) === '' || strlen($text) > $maxBytes) {
            throw new InvalidArgumentException('Expected non-empty bounded preview text.');
        }

        return $text;
    }
}
