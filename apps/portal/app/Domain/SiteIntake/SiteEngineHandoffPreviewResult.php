<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;

/** Non-authoritative result of validating a fake Portal→Sites handoff. */
final readonly class SiteEngineHandoffPreviewResult
{
    private const REASONS=[
        'preview_ready',
        'content_mismatch',
        'no_demo_review',
        'not_approved_demo',
        'ambiguous_history',
    ];

    public function __construct(
        public string $reason,
        public ?SiteEngineHandoffPreviewEnvelope $envelope,
    ) {
        if (!in_array($reason,self::REASONS,true)
            || (($reason==='preview_ready')!==($envelope!==null))) {
            throw new InvalidArgumentException('Invalid fake Sites handoff result.');
        }
    }

    public static function blocked(string $reason): self
    {
        return new self($reason,null);
    }

    public function hasPreviewEnvelope(): bool
    {
        return $this->envelope!==null;
    }

    public function isTransportAuthorized(): bool
    {
        return false;
    }

    public function isCustomerApprovalVerified(): bool
    {
        return false;
    }

    public function mayPublishSite(): bool
    {
        return false;
    }
}
