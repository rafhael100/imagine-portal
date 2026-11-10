<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

enum ServiceRequestAction: string
{
    case Submit = 'submit';
    case StartReview = 'start_review';
    case RequestInformation = 'request_information';
    case ProvideInformation = 'provide_information';
    case Resolve = 'resolve';
    case Close = 'close';
    case Reopen = 'reopen';
}
