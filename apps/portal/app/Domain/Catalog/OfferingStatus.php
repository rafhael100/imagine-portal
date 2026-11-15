<?php

namespace App\Domain\Catalog;

/** Intentionally no "for_sale", "active" or "published" state in P02-A. */
enum OfferingStatus: string
{
    case Draft = 'draft';
    case NotForSale = 'not_for_sale';
    case ReadyForReview = 'ready_for_review';
}
