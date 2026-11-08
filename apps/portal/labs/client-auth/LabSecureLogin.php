<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AUTH-F2 disposable PostgreSQL exercise — NOT a production login provider.
 *
 * The secret is a plaintext synthetic fixture; MFA enrollment, key custody,
 * session cookie durability, CSRF in real HTTP, distributed abuse handling,
 * concurrency with grant revocation and disaster recovery remain unproven.
 */
final class LabSecureLogin
{
    private function db(): ConnectionInterface
    {
        if (! app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || config('database.connections.portal_lab_runtime') === null) {
            throw new \RuntimeException('Refusing the auth lab outside isolated tests.');
        }

        return DB::connection('portal_lab_runtime');
    }

    /**
     * @return array{status:int,token:?string}
     * The token is transferred ONLY into the local Laravel test session.
     * The database stores a SHA-256 digest, never this bearer token.
     */
    public function signIn(string $email, string $password, string $otp): array
    {
        $db = $this->db();
        $now = time();
        $normalized = mb_strtolower(trim($email));
        $principal = hash('sha256', $normalized);

        return $db->transaction(static function () use ($db, $now, $principal, $normalized, $password, $otp): array {
            // Insert-if-missing plus FOR UPDATE serializes attempts at a
            // normalized principal within this one PostgreSQL server.
            $db->table('lab_portal_login_limits')->insertOrIgnore([
                'principal_digest' => $principal,
                'failures' => 0,
                'locked_until' => 0,
            ]);
            $limit = $db->table('lab_portal_login_limits')
                ->where('principal_digest', $principal)
                ->lockForUpdate()->first();

            if ($limit === null) {
                throw new \RuntimeException('Missing synthetic login limiter state.');
            }
            if ((int) $limit->locked_until > $now) {
                return ['status' => 429, 'token' => null];
            }

            $user = $db->table('lab_portal_users')
                ->where('email', $normalized)
                ->where('status', 'active')
                ->first(['id', 'password_hash', 'session_revision']);

            $passwordValid = $user !== null && Hash::check($password, $user->password_hash);
            $factor = $passwordValid
                ? $db->table('lab_portal_auth_factors')->where('user_id', $user->id)->lockForUpdate()->first()
                : null;

            $step = $factor !== null
                ? LabTotp::verifiedNewStep(
                    $factor->secret_hex, $otp, $now, (int) $factor->last_step,
                )
                : null;

            if (! $passwordValid || $step === null) {
                $fails = (int) $limit->failures + 1;
                $db->table('lab_portal_login_limits')
                    ->where('principal_digest', $principal)->update([
                        'failures' => $fails,
                        'locked_until' => $fails >= 3 ? $now + 60 : 0,
                    ]);

                return ['status' => $fails >= 3 ? 429 : 401, 'token' => null];
            }

            $db->table('lab_portal_auth_factors')
                ->where('user_id', $user->id)->update(['last_step' => $step]);
            $db->table('lab_portal_login_limits')
                ->where('principal_digest', $principal)->update([
                    'failures' => 0, 'locked_until' => 0,
                ]);

            $bearer = bin2hex(random_bytes(32));
            $db->table('lab_portal_auth_sessions')->insert([
                'token_digest' => hash('sha256', $bearer),
                'user_id' => $user->id,
                'account_revision' => (int) $user->session_revision,
                'expires_at' => $now + 1200,
                'mfa_verified' => true,
                'revoked' => false,
            ]);

            return ['status' => 200, 'token' => $bearer];
        }, 3);
    }

    public function actor(Request $request): ?string
    {
        $token = $request->session()->get('portal_lab_v2_token');
        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/D', $token) !== 1) {
            return null;
        }

        $row = $this->db()->table('lab_portal_auth_sessions as s')
            ->join('lab_portal_users as u', 'u.id', '=', 's.user_id')
            ->where('s.token_digest', hash('sha256', $token))
            ->where('s.revoked', false)
            ->where('s.mfa_verified', true)
            ->where('s.expires_at', '>', time())
            ->where('u.status', 'active')
            ->whereColumn('s.account_revision', 'u.session_revision')
            ->first(['u.id']);

        return $row?->id;
    }

    public function revoke(Request $request): void
    {
        $token = $request->session()->get('portal_lab_v2_token');
        if (is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token) === 1) {
            $this->db()->table('lab_portal_auth_sessions')
                ->where('token_digest', hash('sha256', $token))
                ->update(['revoked' => true]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
