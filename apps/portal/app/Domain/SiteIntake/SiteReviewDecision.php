<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/** F43 fake editorial feedback, NOT a real client action. */
enum SiteReviewDecision: string
{
    case Approve = 'approve';
    case RequestChanges = 'request_changes';
    case Reject = 'reject';
}
