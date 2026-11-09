<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;

/** Safe non-operational Sites intake validation result. */
final readonly class SiteBriefPreviewResult
{
    private const REASONS = [
        'preview_ready',
        'unexpected_fields',
        'mixed_organization',
        'invalid_reference',
        'unsupported_product',
        'invalid_revision',
        'invalid_content',
        'content_out_of_bounds',
        'unsafe_content',
    ];

    public function __construct(
        public string $reason,
        public ?SiteBriefDraft $draft,
    ) {
        if (!in_array($reason,self::REASONS,true)
            || (($reason==='preview_ready') !== ($draft!==null))) {
            throw new InvalidArgumentException('Invalid Sites briefing preview result.');
        }
    }

    public static function blocked(string $reason): self
    {
        return new self($reason,null);
    }

    public function isPreview(): bool
    {
        return $this->draft!==null;
    }

    public function maySubmitRequest(): bool
    {
        return false;
    }

    public function mayWritePersonalData(): bool
    {
        return false;
    }

    public function mayPublishOrCharge(): bool
    {
        return false;
    }
}
