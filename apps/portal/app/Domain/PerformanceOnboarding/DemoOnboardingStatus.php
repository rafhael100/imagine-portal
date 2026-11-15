<?php

namespace App\Domain\PerformanceOnboarding;

/** No active/provisioned/paid/connected state is representable in P02-C. */
enum DemoOnboardingStatus: string
{
    case AwaitingMockReview = 'awaiting_mock_review';
}
