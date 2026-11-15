<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * Real PostgreSQL RLS-scoped lookup of an existing grant record; no grant
 * provisioning, Google API calls, session verification or display authority.
 */
final readonly class PgsqlRecordedGscGrantReviewSource implements RecordedGscGrantReviewSource
{
    public function __construct(private PDO $connection) {}

    public function inspect(
        array $serverResolvedContext,
        string $exactSiteUrl,
        int $trustedNow
    ): ?array {
        return (new GoogleSearchConsoleTenantGrantGateway($this->connection))
            ->inspectRecordedGrant($serverResolvedContext, $exactSiteUrl, $trustedNow);
    }
}
