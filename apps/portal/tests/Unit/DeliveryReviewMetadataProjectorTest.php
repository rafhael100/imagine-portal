<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CommercialDeliveryReview\DeliveryReviewMetadataProjector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeliveryReviewMetadataProjectorTest extends TestCase
{
    private const ORG_A = '11111111-1111-4111-8111-111111111111';
    private const ORG_B = '99999999-9999-4999-8999-999999999999';
    private const SUBMISSION = 'e1e1e1e1-e1e1-4e1e-8e1e-e1e1e1e1e1e1';

    /** @return array<string,mixed> */
    private static function valid(array $changes = []): array
    {
        return array_replace([
            'organization_reference' => self::ORG_A,
            'submission_reference' => self::SUBMISSION,
            'review_reason' => 'unknown_outcome',
            'attempts' => '1',
            'claim_version' => '2',
            'requires_manual_review' => 't',
            'automatic_retry_allowed' => 'f',
            'real_crm_persisted_verified' => 'f',
            'can_acknowledge_visitor' => 'f',
        ], $changes);
    }

    public function test_valid_pg18_boolean_shapes_are_minimized_and_never_authorize_read_or_retry(): void
    {
        $subject = new DeliveryReviewMetadataProjector();
        $items = $subject->project([
            self::valid(['email_normalized' => 'never-export@example.invalid',
                'bearer' => 'private-fixture-value', 'payload' => '<script>']),
        ], self::ORG_A);

        self::assertCount(1, $items);
        self::assertSame([
            'reference' => self::SUBMISSION,
            'reason_code' => 'unknown_outcome',
            'reason_label' => 'Resultado desconhecido',
            'attempts' => 1,
            'claim_version' => 2,
            'requires_manual_review' => true,
            'can_retry' => false,
            'can_acknowledge_visitor' => false,
            'real_crm_verified' => false,
        ], $items[0]);
        self::assertSame([
            'unknown_outcome'=>1,
            'expired_dispatch_may_have_committed'=>0,
            'claim_attempt_limit_reached'=>0,
        ], $subject->counts($items));
        self::assertFalse(DeliveryReviewMetadataProjector::canAuthorizeQueueRead());
        self::assertStringNotContainsString('never-export', json_encode($items, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-fixture', json_encode($items, JSON_THROW_ON_ERROR));
    }

    public function test_distinct_known_states_count_without_guessing_acknowledgement(): void
    {
        $subject = new DeliveryReviewMetadataProjector();
        $result = $subject->project([
            self::valid(),
            self::valid([
                'submission_reference' => 'e2e2e2e2-e2e2-4e2e-8e2e-e2e2e2e2e2e2',
                'review_reason' => 'expired_dispatch_may_have_committed',
                'attempts' => 2,
                'claim_version' => 3,
                'requires_manual_review' => true,
                'automatic_retry_allowed' => false,
                'real_crm_persisted_verified' => false,
                'can_acknowledge_visitor' => false,
            ]),
            self::valid([
                'submission_reference' => 'e3e3e3e3-e3e3-4e3e-8e3e-e3e3e3e3e3e3',
                'review_reason' => 'claim_attempt_limit_reached',
                'attempts' => 3,
                'claim_version' => 3,
            ]),
        ], self::ORG_A);
        self::assertCount(3, $result);
        self::assertSame([1,1,1], array_values($subject->counts($result)));
        foreach ($result as $row) {
            self::assertFalse($row['can_retry']);
            self::assertFalse($row['real_crm_verified']);
            self::assertFalse($row['can_acknowledge_visitor']);
        }
    }

    public function test_mixed_tenants_or_duplicate_submissions_fail_the_complete_projection(): void
    {
        foreach ([
            [self::valid(), self::valid(['organization_reference'=>self::ORG_B,
                'submission_reference'=>'e4e4e4e4-e4e4-4e4e-8e4e-e4e4e4e4e4e4'])],
            [self::valid(), self::valid()],
        ] as $rows) {
            try {
                (new DeliveryReviewMetadataProjector())->project($rows, self::ORG_A);
                self::fail('Mixed tenant or duplicate should reject entire projection.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_flags_grants_and_unrecognized_reasons_cannot_be_sneaked_in(): void
    {
        foreach ([
            ['automatic_retry_allowed'=>'t'],
            ['can_acknowledge_visitor'=>true],
            ['real_crm_persisted_verified'=>true],
            ['requires_manual_review'=>false],
            ['review_reason'=>'delivered'],
            ['attempts'=>0],
            ['attempts'=>4],
            ['attempts'=>'01'],
            ['claim_version'=>'9999999999999999'],
            ['claim_version'=>0],
            ['submission_reference'=>'../crm'],
            ['submission_reference'=>null],
            ['organization_reference'=>self::ORG_B],
        ] as $mutation) {
            $this->expectRejected([self::valid($mutation)]);
        }
    }

    public function test_large_non_list_or_untrusted_scopes_refused(): void
    {
        $this->expectRejected(array_fill(0,101,self::valid()));
        $this->expectRejected(['wrong-key'=>self::valid()]);
        try {
            (new DeliveryReviewMetadataProjector())->project([self::valid()], self::ORG_B);
            self::fail('A tenant-A projection must not succeed for tenant B.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame([], (new DeliveryReviewMetadataProjector())->project([], self::ORG_A));
    }

    /** @param array<mixed> $rows */
    private function expectRejected(array $rows): void
    {
        try {
            (new DeliveryReviewMetadataProjector())->project($rows, self::ORG_A);
            self::fail('Untrusted review data must fail closed.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
