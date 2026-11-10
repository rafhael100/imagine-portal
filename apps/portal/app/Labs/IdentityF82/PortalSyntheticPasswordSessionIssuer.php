<?php
declare(strict_types=1);

namespace App\Labs\IdentityF82;

use App\Domain\Identity\Security\PortalPasswordCredential;
use PDO;
use Throwable;

/**
 * F82: CLI/PG18 disposable test only. The bearer returned here is NOT an
 * authenticated user: MFA, HTTPS cookies, consent, KMS, external anti-rollback
 * and independent AppSec/DBA gates still prevent operational deployment.
 */
final class PortalSyntheticPasswordSessionIssuer
{
    public function __construct(
        private readonly PDO $identityDb,
        private readonly PDO $throttleDb,
    ) {}

    public function begin(
        string $email,
        string $password,
        string $organizationId,
        string $trustedSourceIp,
        string $serverScopePepper,
    ): ?string {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F82_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->identityDb->inTransaction()
            || $this->throttleDb->inTransaction()
            || strlen($email) < 3 || strlen($email) > 254
            || $email !== strtolower($email)
            || preg_match('/\A[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\z/D', $email) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $organizationId) !== 1
            || filter_var($trustedSourceIp, FILTER_VALIDATE_IP) === false
            || strlen($serverScopePepper) < 32) {
            return null;
        }

        try {
            $identityRole = $this->identityDb->query(
                'SELECT current_database() AS db, current_user AS role'
            )->fetch(PDO::FETCH_ASSOC);
            $throttleRole = $this->throttleDb->query(
                'SELECT current_database() AS db, current_user AS role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identityRole) || !is_array($throttleRole)
                || $identityRole['db'] !== 'portal_identity_lab'
                || $throttleRole['db'] !== 'portal_identity_lab'
                || $identityRole['role'] !== 'postgres'
                || $throttleRole['role'] !== 'portal_f77_throttler') {
                return null;
            }

            // Rate-limit BEFORE credential evaluation. In production an
            // independently authenticated reverse proxy must supply sourceIp.
            $scope = hash_hmac('sha256',
                'portal-f82|login|'.$email.'|'.$organizationId.'|'.$trustedSourceIp,
                $serverScopePepper);
            $claim = $this->throttleDb->prepare(
                'SELECT public.portal_f77_claim_auth_attempt(:scope,:action)'
            );
            $claim->execute(['scope' => $scope, 'action' => 'login']);
            if (!in_array($claim->fetchColumn(), [true, 1, '1', 't'], true)) {
                return null;
            }

            $this->identityDb->beginTransaction();
            $query = $this->identityDb->prepare(
                "SELECT i.id AS identity_id, i.status AS identity_status,
                        i.password_hash, i.email_verified_at,
                        i.mfa_confirmed_at, i.mfa_secret_ciphertext,
                        i.security_revision AS identity_revision,
                        m.status AS membership_status, m.membership_revision,
                        o.status AS organization_status, o.security_revision AS organization_revision
                   FROM portal_identities AS i
                   JOIN portal_memberships AS m ON m.identity_id=i.id
                   JOIN portal_organizations AS o ON o.id=m.organization_id
                  WHERE i.email_normalized=:email AND o.id=:organization
                  FOR UPDATE OF i,m,o"
            );
            $query->execute(['email'=>$email,'organization'=>$organizationId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || $row['identity_status'] !== 'active'
                || $row['membership_status'] !== 'active'
                || $row['organization_status'] !== 'active'
                || $row['email_verified_at'] === null
                || $row['mfa_confirmed_at'] === null
                || !is_string($row['mfa_secret_ciphertext'])
                || !str_starts_with($row['mfa_secret_ciphertext'], 'mfa-sb1:')
                || !PortalPasswordCredential::verify($password, $row['password_hash'])
                || (int)$row['identity_revision'] < 1
                || (int)$row['membership_revision'] < 1
                || (int)$row['organization_revision'] < 1) {
                $this->identityDb->rollBack();
                return null;
            }

            $bearer = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $uuid = self::uuidV4();
            $insert = $this->identityDb->prepare(
                "INSERT INTO portal_auth_sessions(
                    id,session_sha256,identity_id,organization_id,status,
                    identity_revision,membership_revision,organization_revision,
                    issued_at,expires_at,created_at,updated_at
                ) VALUES(
                    :id,:sha,:identity,:org,'pending_mfa',
                    :identity_revision,:membership_revision,:org_revision,
                    now(),now()+interval '5 minutes',now(),now()
                )"
            );
            $insert->execute([
                'id'=>$uuid,
                'sha'=>hash('sha256',$bearer),
                'identity'=>$row['identity_id'],
                'org'=>$organizationId,
                'identity_revision'=>$row['identity_revision'],
                'membership_revision'=>$row['membership_revision'],
                'org_revision'=>$row['organization_revision'],
            ]);
            if ($insert->rowCount() !== 1) {
                $this->identityDb->rollBack();
                return null;
            }

            $this->identityDb->commit();
            return $bearer; // one-time synthetic bearer, never stored plaintext
        } catch (Throwable) {
            if ($this->identityDb->inTransaction()) {
                $this->identityDb->rollBack();
            }
            return null;
        }
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'
            .substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    public static function mayIssueCustomerSession(): bool { return false; }
}
