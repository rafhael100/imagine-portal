<?php
declare(strict_types=1);
namespace App\Domain\Commerce;

interface OnepageOrderStore extends OnepageOrderReader
{
    public function createDraft(string $businessReference, string $requestKey, array $selection): array;
    public function resumeDraft(string $businessReference, string $draftId): array;
    public function review(string $businessReference, string $quoteId): ?array;
    public function accept(string $businessReference, string $requestKey, string $quoteId, array $acceptance): array;
}
