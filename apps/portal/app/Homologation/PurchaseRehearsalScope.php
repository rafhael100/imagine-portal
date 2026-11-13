<?php

declare(strict_types=1);
namespace App\Homologation;

use Illuminate\Http\Request;

/** Must be bound to verified server-side identity before any online activation. */
interface PurchaseRehearsalScope
{
    public function organization(Request $request): ?string;
}
