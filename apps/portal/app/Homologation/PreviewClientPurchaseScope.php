<?php

declare(strict_types=1);

namespace App\Homologation;

use Illuminate\Http\Request;

/**
 * Only a signed-in synthetic CLIENT may access synthetic purchase data in
 * the isolated Portal preview. Never gives commercial authority, membership,
 * verified identity, payment or production HTTP authorization.
 *
 * The global Laravel provider still defaults to DENY, and this adapter is
 * instantiated exclusively by the private-purchase router.
 */
final class PreviewClientPurchaseScope implements PurchaseRehearsalScope
{
    public function __construct(private readonly PreviewProfiles $profiles) {}

    public function organization(Request $request): ?string
    {
        $profile = $this->profiles->current($request);
        if (!is_array($profile)
            || ($profile['role'] ?? null) !== 'client'
            || ($profile['id'] ?? null) !== 'cliente.teste') {
            return null;
        }
        return 'test-org-a';
    }

    public static function authorizesRealCustomer(): bool
    {
        return false;
    }
}
