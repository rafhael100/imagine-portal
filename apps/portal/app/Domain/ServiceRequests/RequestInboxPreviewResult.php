<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use InvalidArgumentException;

/**
 * Dry-run list projection only, never an authorization or customer-response DTO.
 * A rejected projection has NO partial data, even if some inputs were valid.
 */
final readonly class RequestInboxPreviewResult
{
    /**
     * @param list<ServiceRequestSnapshot> $items
     */
    private function __construct(
        public string $reason,
        public array $items,
        public int $total,
        public ?int $nextOffset,
    ) {
        if ($reason !== 'preview_ready' && ($items !== [] || $total !== 0 || $nextOffset !== null)) {
            throw new InvalidArgumentException('Rejected preview must carry no records.');
        }
        if ($reason === 'preview_ready' && ($total < count($items) || !array_is_list($items))) {
            throw new InvalidArgumentException('Invalid preview pagination.');
        }
    }

    /** @param list<ServiceRequestSnapshot> $items */
    public static function preview(array $items, int $total, ?int $nextOffset): self
    {
        return new self('preview_ready', $items, $total, $nextOffset);
    }

    public static function rejected(string $reason): self
    {
        if (!in_array($reason, [
            'invalid_preview_pagination', 'invalid_preview_input',
            'mixed_organization', 'duplicate_request_reference',
        ], true)) {
            throw new InvalidArgumentException('Unknown inbox preview rejection.');
        }

        return new self($reason, [], 0, null);
    }

    public function isPreparedPreview(): bool
    {
        return $this->reason === 'preview_ready';
    }

    public function mayExposeCustomerData(): bool
    {
        // Requires a separate, trusted backend reader, identity/RLS and audit.
        return false;
    }
}
