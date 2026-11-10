<?php

namespace App\Providers;

use App\Domain\Identity\Invitations\DenyingPortalInvitationIdentitySource;
use App\Domain\Identity\Invitations\PortalInvitationIdentitySource;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use Illuminate\Support\ServiceProvider;

/**
 * PORTAL-F98: explicitly bind every currently defined identity authority to
 * a single DENY-ALL implementation. This is a staging/boot safety default,
 * NOT a trusted login/session/membership/resource adapter.
 *
 * A real authority must be introduced only with separately reviewed, trusted
 * Portal-specific identity and tenancy infrastructure, transactional
 * revocation controls, RLS, and independent security approval.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DenyingPortalAuthorities::class);

        // F110: the invitation preflight NEVER trusts CRM/HTTP inputs or a
        // caller-built identity snapshot. A real Portal issuer is not wired.
        $this->app->singleton(DenyingPortalInvitationIdentitySource::class);
        $this->app->bind(
            PortalInvitationIdentitySource::class,
            static fn ($app): DenyingPortalInvitationIdentitySource =>
                $app->make(DenyingPortalInvitationIdentitySource::class),
        );

        // Even if an internal application component resolves these interfaces
        // before the actual trust adapters exist, it receives a source that
        // cannot issue a session, resolve a membership, or establish ownership.
        $deny = static fn ($app): DenyingPortalAuthorities =>
            $app->make(DenyingPortalAuthorities::class);

        $this->app->bind(PortalSessionAuthority::class, $deny);
        $this->app->bind(PortalMembershipAuthority::class, $deny);
        $this->app->bind(PortalResourceAuthority::class, $deny);
    }

    public function boot(): void
    {
        // No routes, synthetic authorities, customers, queues or APIs.
    }
}
