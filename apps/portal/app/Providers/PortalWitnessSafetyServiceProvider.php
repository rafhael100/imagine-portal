<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\RestoreSafety\DenyingPortalWitnessChallengeTransport;
use App\Domain\RestoreSafety\PortalWitnessChallengeTransport;
use Illuminate\Support\ServiceProvider;

/**
 * A missing external revocation witness MUST mean unavailable, not allowed.
 *
 * No HTTP transport is implemented or bound in this repository. This
 * provider never installs an authentication, serving or recovery grant.
 */
final class PortalWitnessSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            PortalWitnessChallengeTransport::class,
            DenyingPortalWitnessChallengeTransport::class,
        );
    }
}
