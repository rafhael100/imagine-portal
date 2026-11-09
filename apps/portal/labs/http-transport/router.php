<?php

declare(strict_types=1);

/**
 * Portal F5 — throwaway HTTP transport harness. NOT a production router.
 *
 * Start only with: GITHUB_ACTIONS=true, APP_ENV=local,
 * PORTAL_HTTP_LOOPBACK_LAB=1 and PHP's CLI server bound to 127.0.0.1.
 * This file is OUTSIDE public/, with NO registered operational route.
 */
$host = $_SERVER['HTTP_HOST'] ?? '';
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$server = $_SERVER['SERVER_ADDR'] ?? '';

if (
    PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_HTTP_LOOPBACK_LAB') !== '1'
    || getenv('APP_ENV') !== 'local'
    || $server !== '127.0.0.1'
    || $remote !== '127.0.0.1'
    || ! in_array($host, ['127.0.0.1:18945', '127.0.0.1:18946'], true)
    || ! str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/_loopback/transport/')
) {
    http_response_code(404);
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

// Laravel's authentic HTTP pipeline must run outside APP_ENV=testing
// because CSRF middleware bypasses CSRF verification during PHPUnit.
$kernel->bootstrap();

if (! $app->environment('local')) {
    http_response_code(404);
    exit;
}

\Illuminate\Support\Facades\Route::middleware(['web'])
    ->prefix('_loopback/transport')
    ->group(static function (): void {
        \Illuminate\Support\Facades\Route::get('csrf', static function (\Illuminate\Http\Request $request) {
            $request->session()->put('lab_transport_initialized', true);

            return response()->json([
                'csrf_token' => $request->session()->token(),
                'mode' => 'loopback_only',
            ])->header('Cache-Control', 'no-store');
        });

        \Illuminate\Support\Facades\Route::get('state', static function (\Illuminate\Http\Request $request) {
            return response()->json([
                'authenticated' => $request->session()->get('lab_transport_actor') === 'synthetic-actor',
                'mode' => 'loopback_only',
            ])->header('Cache-Control', 'no-store');
        });

        \Illuminate\Support\Facades\Route::post('login', static function (\Illuminate\Http\Request $request) {
            $validated = $request->validate([
                'proof' => ['required', 'string', 'in:lab-only'],
            ]);

            // This is NOT authentication: proof is an artificial constant.
            // The exercise validates only session rotation + CSRF behavior.
            if ($validated['proof'] !== 'lab-only') {
                return response()->json(['error' => 'invalid'], 401);
            }

            $request->session()->regenerate();
            $request->session()->put('lab_transport_actor', 'synthetic-actor');

            return response()->json([
                'message' => 'synthetic_transport_session_rotated',
                'csrf_token' => $request->session()->token(),
            ])->header('Cache-Control', 'no-store');
        });

        \Illuminate\Support\Facades\Route::post('logout', static function (\Illuminate\Http\Request $request) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'synthetic_transport_session_invalidated',
            ])->header('Cache-Control', 'no-store');
        });
    });

$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
