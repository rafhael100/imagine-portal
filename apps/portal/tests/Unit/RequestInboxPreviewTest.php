<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\RequestInboxPreview;
use App\Domain\ServiceRequests\RequestInboxPreviewResult;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus as Status;
use App\Presentation\DemoRequestInbox;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RequestInboxPreviewTest extends TestCase
{
    private function request(string $id, string $org = 'portal-org-a', Status $status = Status::Submitted): ServiceRequestSnapshot
    {
        return new ServiceRequestSnapshot($id, new OrganizationId($org), 'service-onepage', $status, 0);
    }

    public function test_deterministic_sorted_preview_and_pagination_never_grant_customer_access(): void
    {
        $org = new OrganizationId('portal-org-a');
        $source = [$this->request('req-c'), $this->request('req-a'), $this->request('req-b')];
        $projection = new RequestInboxPreview();
        $first = $projection->project($source, $org, limit: 2);
        self::assertTrue($first->isPreparedPreview());
        self::assertSame('preview_ready', $first->reason);
        self::assertSame(['req-a', 'req-b'], array_map(static fn ($r) => $r->requestId, $first->items));
        self::assertSame(3, $first->total);
        self::assertSame(2, $first->nextOffset);
        self::assertFalse($first->mayExposeCustomerData());

        $second = $projection->project($source, $org, limit: 2, offset: $first->nextOffset);
        self::assertSame(['req-c'], array_map(static fn ($r) => $r->requestId, $second->items));
        self::assertSame(3, $second->total);
        self::assertNull($second->nextOffset);
        self::assertSame(['req-c', 'req-a', 'req-b'], array_map(static fn ($r) => $r->requestId, $source));
    }

    public function test_status_filter_is_fictional_and_does_not_change_source(): void
    {
        $source = [
            $this->request('req-3', status: Status::InReview),
            $this->request('req-1', status: Status::Submitted),
            $this->request('req-2', status: Status::InReview),
        ];
        $result = (new RequestInboxPreview())->project(
            $source, new OrganizationId('portal-org-a'), status: Status::InReview,
        );
        self::assertSame(['req-2', 'req-3'], array_map(static fn ($r) => $r->requestId, $result->items));
        self::assertSame(2, $result->total);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertSame(Status::Submitted, $source[1]->status);
    }

    public function test_mixed_tenants_are_rejected_atomically_without_partial_results(): void
    {
        $data = [
            $this->request('req-1'),
            $this->request('req-2', 'portal-org-b'),
            $this->request('req-3'),
        ];
        $result = (new RequestInboxPreview())->project($data, new OrganizationId('portal-org-a'));
        self::assertSame('mixed_organization', $result->reason);
        self::assertSame([], $result->items);
        self::assertSame(0, $result->total);
        self::assertNull($result->nextOffset);
        self::assertFalse($result->mayExposeCustomerData());
    }

    public function test_duplicates_are_denied_even_if_they_have_different_statuses(): void
    {
        $result = (new RequestInboxPreview())->project([
            $this->request('req-1', status: Status::Submitted),
            $this->request('req-1', status: Status::Closed),
        ], new OrganizationId('portal-org-a'));
        self::assertSame('duplicate_request_reference', $result->reason);
        self::assertSame([], $result->items);
    }

    public function test_bad_inputs_and_overlong_fixture_lists_fail_closed(): void
    {
        $org = new OrganizationId('portal-org-a');
        $preview = new RequestInboxPreview();
        foreach ([0, -1, 51, PHP_INT_MAX] as $limit) {
            $result = $preview->project([$this->request('req-a')], $org, limit: $limit);
            self::assertSame('invalid_preview_pagination', $result->reason);
            self::assertSame([], $result->items);
        }
        foreach ([-1, 10001] as $offset) {
            self::assertSame('invalid_preview_pagination',
                $preview->project([], $org, offset: $offset)->reason);
        }
        foreach ([['x' => $this->request('req-a')], [new \stdClass()], [null]] as $badInput) {
            $result = $preview->project($badInput, $org);
            self::assertSame('invalid_preview_input', $result->reason);
            self::assertSame([], $result->items);
        }
        $oversize = array_fill(0, 1001, $this->request('req-z'));
        self::assertSame('invalid_preview_input', $preview->project($oversize, $org)->reason);
        self::assertTrue($preview->project([], $org)->isPreparedPreview());
    }

    public function test_empty_or_out_of_range_pages_never_invent_fallback_records(): void
    {
        $org = new OrganizationId('portal-org-a');
        $result = (new RequestInboxPreview())->project([$this->request('req-a')], $org, offset: 30);
        self::assertSame([], $result->items);
        self::assertSame(1, $result->total);
        self::assertNull($result->nextOffset);
        self::assertFalse($result->mayExposeCustomerData());
    }

    public function test_demo_fixtures_stay_deterministic_and_not_connected_to_product_databases(): void
    {
        $demo = DemoRequestInbox::preview();
        self::assertTrue($demo->isPreparedPreview());
        self::assertSame(3, $demo->total);
        self::assertSame([
            'DEMO-REQ-LANDING', 'DEMO-REQ-ONEPAGE', 'DEMO-REQ-PERFORMANCE',
        ], array_column($demo->items, 'requestId'));
        self::assertCount(3, DemoRequestInbox::rows());
        foreach (DemoRequestInbox::rows() as $row) {
            self::assertSame(4, count($row));
            self::assertStringStartsWith('DEMO-REQ-', $row[0]);
            self::assertStringStartsWith('DEMO-SVC-', $row[1]);
            self::assertStringContainsString('fictícia', $row[3]);
        }
        self::assertFalse($demo->mayExposeCustomerData());
    }

    public function test_rejected_decision_never_contains_records_and_unknown_reason_is_invalid(): void
    {
        self::assertTrue((new \ReflectionClass(RequestInboxPreviewResult::class))->isReadOnly());
        $deny = RequestInboxPreviewResult::rejected('mixed_organization');
        self::assertFalse($deny->isPreparedPreview());
        self::assertFalse($deny->mayExposeCustomerData());
        self::assertSame([], $deny->items);

        $this->expectException(InvalidArgumentException::class);
        RequestInboxPreviewResult::rejected('authorized');
    }
}
