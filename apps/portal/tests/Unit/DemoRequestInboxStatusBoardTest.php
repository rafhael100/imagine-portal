<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\RequestInboxPreviewResult;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Presentation\DemoRequestInbox;
use App\Presentation\DemoRequestInboxStatusBoard;
use PHPUnit\Framework\TestCase;

final class DemoRequestInboxStatusBoardTest extends TestCase
{
    private function sample(string $reference, string $org, ServiceRequestStatus $status): ServiceRequestSnapshot
    {
        return new ServiceRequestSnapshot(
            $reference, new OrganizationId($org), 'DEMO-SVC-ONEPAGE', $status, 1,
        );
    }

    public function test_one_preview_produces_only_true_demonstration_counts(): void
    {
        self::assertSame([
            ['Em análise', '1'],
            ['Aguardando informações do cliente', '1'],
            ['Resolvida', '1'],
        ], DemoRequestInboxStatusBoard::rows());
        self::assertFalse(DemoRequestInboxStatusBoard::isOperational());
        self::assertFalse(DemoRequestInboxStatusBoard::authorizesRequestTransition());
    }

    public function test_rejected_or_partial_preview_cannot_claim_full_distribution(): void
    {
        $full = DemoRequestInbox::preview();
        self::assertCount(3, $full->items);
        self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
            RequestInboxPreviewResult::rejected('mixed_organization')
        ));
        self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
            RequestInboxPreviewResult::preview([$full->items[0]], 3, 1)
        ));
        self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
            RequestInboxPreviewResult::preview([$full->items[0]], 3, null)
        ));
        self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
            RequestInboxPreviewResult::preview(array_fill(0, 51, $full->items[0]), 51, null)
        ));
    }

    public function test_forged_foreign_organization_duplicate_or_invalid_item_fails_entire_summary(): void
    {
        $sample = $this->sample('DEMO-REQ-1', 'DEMO-ORG-AURORA', ServiceRequestStatus::Submitted);
        $foreign = $this->sample('DEMO-REQ-2', 'DEMO-ORG-OTHER', ServiceRequestStatus::Closed);
        foreach ([
            [$sample, $foreign],
            [$sample, $sample],
            [$sample, new \stdClass()],
        ] as $rows) {
            self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
                RequestInboxPreviewResult::preview($rows, count($rows), null)
            ));
        }
    }

    public function test_empty_valid_demo_has_no_invented_workload(): void
    {
        self::assertSame([], DemoRequestInboxStatusBoard::fromPreview(
            RequestInboxPreviewResult::preview([], 0, null)
        ));
    }
}
