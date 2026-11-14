<?php

declare(strict_types=1);

/**
 * Private preview routing: legacy shared-org rehearsal is CI-ONLY.
 * In private VPS staging, only the explicit customer/admin preview is reachable
 * (and the /up response is handled before this helper is loaded).
 *
 * This is a routing perimeter, not a substitute for Basic Auth, client IAM,
 * MFA, permissions or a public production checkout.
 */
final class PrivatePreviewRouteGate
{
    public static function allows(string $path, bool $ci): bool
    {
        if ($path === '/_preview/portal' ||
            str_starts_with($path, '/_preview/portal/')) {
            return true;
        }

        if ($path === '/_rehearsal/purchase' ||
            str_starts_with($path, '/_rehearsal/purchase/')) {
            return $ci;
        }

        return false;
    }
}
