<?php

declare(strict_types=1);

/**
 * F6 — real HTTP + disposable PostgreSQL + TOTP, never a public router.
 * This file is outside public/ and NOT imported by the operational app.
 */
$origin = 'http://127.0.0.1:18947';
if (
    PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_HTTP_PG_LAB') !== '1'
    || getenv('APP_ENV') !== 'local'
    || getenv('DB_DATABASE') !== 'portal_lab'
    || getenv('DB_USERNAME') !== 'portal_lab_runtime'
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 18947
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:18947'
    || ! str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/_loopback/pg/')
) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require_once __DIR__.'/../client-auth/LabTotp.php';
require_once __DIR__.'/../client-auth/LabMembershipFence.php';
require_once __DIR__.'/../client-auth/LabPortalGateway.php';
require_once __DIR__.'/../client-auth/LabSecureLogin.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

if (! $app->environment('local') || PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

// The server has a DB runtime role without table ownership, DDL, or BYPASSRLS.
// No admin connection is configured in this HTTP process.
$config = config('database.connections.pgsql');
if (($config['database'] ?? '') !== 'portal_lab'
    || ($config['username'] ?? '') !== 'portal_lab_runtime') {
    http_response_code(404);
    exit;
}
config(['database.connections.portal_lab_runtime' => $config]);

use App\Labs\ClientAuth\LabSecureLogin;
use App\Labs\ClientAuth\LabPortalGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$requireOrigin = static function (Request $request) use ($origin): ?\Illuminate\Http\JsonResponse {
    // The origin check is defense in depth; Laravel's real web middleware
    // MUST still reject invalid/missing CSRF tokens independently.
    if ($request->header('Origin') !== $origin
        || $request->header('Sec-Fetch-Site') === 'cross-site') {
        return response()->json(['error' => 'origin_forbidden'], 403);
    }

    return null;
};
$deny = static fn (callable $action) => LabPortalGateway::denyOnFailure($action);

Route::middleware(['web'])->prefix('_loopback/pg')->group(static function () use ($requireOrigin, $deny): void {
    Route::get('csrf', static function (Request $request) {
        $request->session()->put('f6_initialized', true);

        return response()->json([
            'csrf_token' => $request->session()->token(),
            'mode' => 'pg_mfa_synthetic',
        ])->header('Cache-Control', 'no-store');
    });

    Route::post('login', static function (Request $request) use ($requireOrigin, $deny) {
        if ($reject = $requireOrigin($request)) {
            return $reject;
        }
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:256'],
            'totp' => ['required', 'regex:/\A[0-9]{6}\z/D'],
        ]);

        return $deny(static function () use ($request, $data) {
            $result = app(LabSecureLogin::class)->signIn(
                $data['email'], $data['password'], $data['totp'],
            );

            if ($result['token'] === null) {
                return response()->json([
                    'error' => $result['status'] === 429
                        ? 'too_many_attempts' : 'invalid_credentials',
                ], $result['status'])->header('Cache-Control', 'no-store');
            }

            $request->session()->regenerate();
            $request->session()->put('portal_lab_v2_token', $result['token']);

            return response()->json([
                'message' => 'synthetic_pg_mfa_session',
                'csrf_token' => $request->session()->token(),
            ])->header('Cache-Control', 'no-store');
        });
    });

    Route::get('organizations', static function (Request $request) use ($deny) {
        return $deny(static function () use ($request) {
            $actor = app(LabSecureLogin::class)->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }

            return response()->json([
                'organizations' => app(LabPortalGateway::class)->organizations($actor),
            ])->header('Cache-Control', 'no-store');
        });
    });

    Route::get('organizations/{org}/services', static function (Request $request, string $org) use ($deny) {
        return $deny(static function () use ($request, $org) {
            $actor = app(LabSecureLogin::class)->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }
            if (preg_match('/\A[a-z0-9._:-]{1,64}\z/D', $org) !== 1) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $services = app(LabPortalGateway::class)->services($actor, $org);
            return $services === null
                ? response()->json(['error' => 'not_found'], 404)
                : response()->json(['services' => $services])->header('Cache-Control', 'no-store');
        });
    });

    Route::post('organizations/{org}/requests', static function (Request $request, string $org) use ($requireOrigin, $deny) {
        if ($reject = $requireOrigin($request)) {
            return $reject;
        }
        $data = $request->validate([
            'service_id' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9._:-]+\z/D'],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/\A[a-zA-Z0-9-]+\z/D'],
        ]);

        return $deny(static function () use ($request, $org, $data) {
            $actor = app(LabSecureLogin::class)->actor($request);
            if ($actor === null) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }
            if (preg_match('/\A[a-z0-9._:-]{1,64}\z/D', $org) !== 1) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $outcome = app(LabPortalGateway::class)->submitIdempotentRequest(
                $actor, $org, $data['service_id'], $data['title'],
                $data['idempotency_key'],
            );
            if ($outcome === null) {
                return response()->json(['error' => 'not_found'], 404);
            }
            if ($outcome['outcome'] === 'conflict') {
                return response()->json(['error' => 'idempotency_conflict'], 409);
            }

            return response()->json([
                'request_id' => $outcome['id'],
                'replayed' => $outcome['outcome'] === 'replayed',
            ], $outcome['outcome'] === 'created' ? 201 : 200)
                ->header('Cache-Control', 'no-store');
        });
    });

    Route::post('logout', static function (Request $request) use ($requireOrigin, $deny) {
        if ($reject = $requireOrigin($request)) {
            return $reject;
        }

        return $deny(static function () use ($request) {
            app(LabSecureLogin::class)->revoke($request);

            return response()->json(['message' => 'synthetic_pg_mfa_logout'])
                ->header('Cache-Control', 'no-store');
        });
    });
});

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
