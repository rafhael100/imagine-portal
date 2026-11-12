<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Unregistered HTTP transport. Requires web + PortalBrowserAuthGuard middleware.
 * Framework session stores opaque references server-side; none enter JSON/URLs.
 * Authentication state here NEVER authorizes a business resource or CRM.
 */
final readonly class PortalBrowserAuthController
{
    private const STATE = 'portal.browser_auth';

    public function __construct(private PortalBrowserAuthentication $identity)
    {
    }

    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function password(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        // The private development gateway can authenticate only the
        // organization explicitly configured server-side. Never trust a
        // browser-provided tenant UUID merely because it is well formed.
        $configuredOrganization = config('portal_development_auth.organization');
        if (!$this->only($input, ['email', 'password', 'organization'])
            || !is_string($input['email'] ?? null) || strlen($input['email']) > 254
            || filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false
            || !is_string($input['password'] ?? null) || strlen($input['password']) > 1024
            || $input['password'] === ''
            || !is_string($input['organization'] ?? null)
            || preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D', $input['organization']) !== 1
            || !is_string($configuredOrganization)
            || !hash_equals($configuredOrganization, $input['organization'])) {
            return $this->denied();
        }

        try {
            $old = $this->state($request);
            if ($old !== null && !$this->identity->revoke($old['reference'])) {
                $this->clear($request);
                return $this->unavailable();
            }
            $this->clear($request);
            $pending = $this->identity->beginPassword(
                strtolower($input['email']), $input['password'], $input['organization'], (string) $request->ip()
            );
            if (!$this->reference($pending)) {
                return $this->denied();
            }
            $request->session()->put(self::STATE, ['phase' => 'pending_mfa', 'reference' => $pending]);
            return response()->json(['next' => 'mfa'], 202);
        } catch (Throwable) {
            $this->clear($request);
            return $this->unavailable();
        }
    }

    public function mfa(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $state = $this->state($request);
        if (!$this->only($input, ['code']) || !is_string($input['code'] ?? null)
            || preg_match('/\\A[0-9]{6}\\z/D', $input['code']) !== 1
            || $state === null || $state['phase'] !== 'pending_mfa') {
            return $this->denied();
        }
        try {
            $active = $this->identity->completeMfa($state['reference'], $input['code'], (string) $request->ip());
            if ($active === null) {
                return $this->denied();
            }
            if (!$this->reference($active) || hash_equals($state['reference'], $active)) {
                $this->identity->revoke($state['reference']);
                $this->clear($request);
                return $this->unavailable();
            }
            $this->clear($request);
            $request->session()->put(self::STATE, ['phase' => 'business', 'reference' => $active]);
            return response()->json(['authenticated' => true]);
        } catch (Throwable) {
            $this->clear($request);
            return $this->unavailable();
        }
    }

    public function current(Request $request): JsonResponse
    {
        $state = $this->state($request);
        if ($state === null || $state['phase'] !== 'business') {
            return $this->denied();
        }
        try {
            if ($this->identity->isActiveBusinessSession($state['reference'])) {
                return response()->json(['authenticated' => true]);
            }
            // F294: a revoked membership, changed identity revision or
            // expired authentication is not only a local cookie problem.
            // Durably revoke the old SQL reference before clearing its
            // browser envelope so that restoration cannot revive it.
            $revoked = $this->identity->revoke($state['reference']);
            $this->clear($request);
            return $revoked ? $this->denied() : $this->unavailable();
        } catch (Throwable) {
            $this->clear($request);
            return $this->unavailable();
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $state = $this->state($request);
        $revoked = $state === null;
        try {
            if ($state !== null) {
                $revoked = $this->identity->revoke($state['reference']);
            }
        } catch (Throwable) {
            $revoked = false;
        } finally {
            $this->clear($request);
        }
        return $revoked ? response()->json(null, 204) : $this->unavailable();
    }

    /** @return array{phase:string,reference:string}|null */
    private function state(Request $request): ?array
    {
        $value = $request->session()->get(self::STATE);
        if (!is_array($value) || !in_array($value['phase'] ?? null, ['pending_mfa', 'business'], true)
            || !$this->reference($value['reference'] ?? null)) {
            return null;
        }
        return ['phase' => $value['phase'], 'reference' => $value['reference']];
    }

    private function reference(mixed $value): bool
    {
        return is_string($value) && preg_match('/\\A[0-9a-f]{64}\\z/D', $value) === 1;
    }

    private function only(array $input, array $allowed): bool
    {
        return array_diff(array_keys($input), $allowed) === [];
    }

    private function clear(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function denied(): JsonResponse
    {
        return response()->json(['error' => 'authentication_failed'], 401);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['error' => 'authentication_unavailable'], 503);
    }
}
