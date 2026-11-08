<?php

declare(strict_types=1);

// Loaded only by routes/web.php when APP_ENV=testing, PHP_SAPI=cli and
// PORTAL_CLIENT_LAB_ENABLED=1. Never exposed by a real browser server.
require_once __DIR__.'/LabPortalGateway.php';

use App\Labs\ClientAuth\LabPortalGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('/_lab/portal')->group(static function (): void {
    Route::post('/login', static function (Request $request, LabPortalGateway $gateway) {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:256'],
        ]);

        return LabPortalGateway::denyOnFailure(static function () use ($request, $gateway, $data) {
            $record = $gateway->authenticate($data['email'], $data['password']);
            if ($record === null) {
                return response()->json(['error' => 'invalid_credentials'], 401);
            }

            $request->session()->regenerate();
            $request->session()->put('portal_lab_actor', $record['id']);
            $request->session()->put('portal_lab_revision', $record['revision']);

            return response()->json(['message' => 'lab_session_created'], 200);
        });
    })->name('portal.lab.login');

    Route::post('/logout', static function (Request $request) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'lab_session_revoked']);
    })->name('portal.lab.logout');

    Route::get('/organizations', static function (Request $request, LabPortalGateway $gateway) {
        return LabPortalGateway::denyOnFailure(static function () use ($request, $gateway) {
            $actor = $gateway->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }

            return response()->json(['organizations' => $gateway->organizations($actor)]);
        });
    })->name('portal.lab.organizations');

    Route::get('/organizations/{organization}/services', static function (Request $request, string $organization, LabPortalGateway $gateway) {
        return LabPortalGateway::denyOnFailure(static function () use ($request, $organization, $gateway) {
            $actor = $gateway->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }

            if (preg_match('/\A[a-z0-9._:-]{1,64}\z/D', $organization) !== 1) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $services = $gateway->services($actor, $organization);
            if ($services === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->json(['services' => $services]);
        });
    })->name('portal.lab.services');


    Route::get('/organizations/{organization}/dashboard', static function (Request $request, string $organization, LabPortalGateway $gateway) {
        return LabPortalGateway::denyOnFailure(static function () use ($request, $organization, $gateway) {
            $actor = $gateway->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }

            if (preg_match('/\A[a-z0-9._:-]{1,64}\z/D', $organization) !== 1) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $organizations = $gateway->organizations($actor);
            $selected = null;
            foreach ($organizations as $candidate) {
                if ($candidate['id'] === $organization) {
                    $selected = $candidate;
                    break;
                }
            }

            if ($selected === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $services = $gateway->services($actor, $organization);
            if ($services === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->view('portal.lab.client-dashboard', [
                'organization' => $selected,
                'services' => $services,
            ], 200, [
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        });
    })->name('portal.lab.dashboard');
});
