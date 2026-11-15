<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * F107 inspection contract. The record is a conservative database review,
 * NEVER a Google or Portal authorization assertion.
 *
 * This interface is deliberately NOT bound in the Laravel service container.
 * Test doubles may only be used in isolated tests, never HTTP.
 */
interface RecordedGscGrantReviewSource
{
    public function inspect(
        array $serverResolvedContext,
        string $exactSiteUrl,
        int $trustedNow
    ): ?array;
}
