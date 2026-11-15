<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * F134: empty organization-scoped admin readiness page.
 * The route is unreachable without a real Laravel admin session, recent MFA
 * and an independently persisted active seo.view organization grant.
 * No client data is read or inferred here.
 */
final class PortalAdminSeoScopeController
{
    public function show():View
    {
        return view('portal-admin.seo-scope');
    }
}
