<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;

/**
 * Test-only display DTO. A successful status never authorizes publication,
 * grants, customer data, ad spend, billing, or another Imagine product.
 */
final readonly class DisposablePg18ProjectPreparationResult
{
    /** @param list<list<string>> $rows */
    private function __construct(
        public string $reason,
        public array $rows,
    ) {
        if (!in_array($reason,['preview_ready','invalid_preview_input'],true)
            || ($reason!=='preview_ready' && $rows!==[])
            || !array_is_list($rows) || count($rows)>10) {
            throw new InvalidArgumentException('Invalid test-only preparation projection.');
        }
        foreach($rows as $row) {
            if (!is_array($row) || count($row)!==5) {
                throw new InvalidArgumentException('Invalid preparation table row.');
            }
            foreach ($row as $text) {
                if (!is_string($text) || trim($text)==='' || strlen($text)>2000) {
                    throw new InvalidArgumentException('Invalid preparation cell.');
                }
            }
        }
    }

    /** @param list<list<string>> $rows */
    public static function prepared(array $rows): self
    {
        return new self('preview_ready',$rows);
    }

    public static function rejected(): self
    {
        return new self('invalid_preview_input',[]);
    }

    public function authorizesService(): bool { return false; }
    public function authorizesPublication(): bool { return false; }
    public function authorizesAdSpend(): bool { return false; }
    public function authorizesUniversityAccess(): bool { return false; }
}
