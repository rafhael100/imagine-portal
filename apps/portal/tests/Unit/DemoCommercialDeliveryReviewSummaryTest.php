<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\DemoCommercialDeliveryReview;
use PHPUnit\Framework\TestCase;

final class DemoCommercialDeliveryReviewSummaryTest extends TestCase
{
    public function test_three_fictitious_review_reasons_map_to_an_unprivileged_summary(): void
    {
        self::assertSame([
            ['Resultado desconhecido', '1'],
            ['Possível envio sem confirmação', '1'],
            ['Limite de reservas atingido', '1'],
        ], DemoCommercialDeliveryReview::reasonSummaryRows());

        self::assertCount(3, DemoCommercialDeliveryReview::rows());
        self::assertFalse(DemoCommercialDeliveryReview::isOperational());
        self::assertFalse(DemoCommercialDeliveryReview::canResend());
        self::assertFalse(DemoCommercialDeliveryReview::canConfirmCrmDelivery());
    }
}
