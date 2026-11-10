<?php

declare(strict_types=1);

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Security\PortalTotpVerifier;

/**
 * PORTAL-F114: synthetic invited-member ONLY two-stage password/TOTP flow.
 *
 * This lives in labs/ and requires postgres superuser for fixture writes:
 * it is NOT an operational issuer, not a browser login, and cannot grant
 * Portal business rights. The typed session is ALWAYS invitation_only.
 * A trusted KMS, real throttling network boundary, non-superuser writer,
 * recovery policies, audit witness and independent security review are
 * still mandatory before any customer flow is allowed.
 */
final readonly class PortalF114InvitedSessionIssuer
{
    public function __construct(
        private PDO $identityDatabase,
        private PDO $throttleDatabase,
    ) {
    }

    private function localLaboratory(): bool
    {
        return PHP_SAPI === 'cli'
            && getenv('APP_ENV') === 'testing'
            && getenv('PORTAL_F114_PG_LAB') === '1'
            && getenv('PGHOST') === '127.0.0.1'
            && getenv('PGPORT') === '5432'
            && getenv('PGDATABASE') === 'portal_identity_lab'
            && !$this->identityDatabase->inTransaction()
            && !$this->throttleDatabase->inTransaction();
    }

    private function authenticTestConnections(): bool
    {
        $owner=$this->identityDatabase->query(
            'SELECT current_user,current_database()'
        )->fetch(PDO::FETCH_NUM);
        $throttler=$this->throttleDatabase->query(
            'SELECT current_user,current_database()'
        )->fetch(PDO::FETCH_NUM);

        return $owner === ['postgres','portal_identity_lab']
            && $throttler === ['portal_f77_throttler','portal_identity_lab'];
    }

    private function rateLimit(
        string $action,
        string $credentialScope,
        string $trustedSourceIp,
        string $serverPepper,
    ): bool {
        if (!in_array($action,['login','totp'],true)
            || filter_var($trustedSourceIp,FILTER_VALIDATE_IP)===false
            || strlen($serverPepper)<32 || strlen($serverPepper)>128) {
            return false;
        }

        $scope=hash_hmac('sha256',
            'portal-f114|invitation-only|'.$action.'|'.$credentialScope
            .'|'.$trustedSourceIp,
            $serverPepper,
        );
        $query=$this->throttleDatabase->prepare(
            'SELECT public.portal_f77_claim_auth_attempt(:scope,:action)'
        );
        $query->execute(['scope'=>$scope,'action'=>$action]);

        return in_array($query->fetchColumn(),[true,'t','1',1],true);
    }

    /**
     * Issue a pending-MFA purpose=invitation_only record after verified
     * first-party password. The returned reference is NOT authenticated.
     */
    public function begin(
        string $email,
        string $password,
        string $organizationId,
        string $trustedSourceIp,
        string $serverPepper,
    ): ?string {
        if (!$this->localLaboratory()
            || strlen($email)<3 || strlen($email)>254
            || $email!==strtolower($email)
            || preg_match('/\A[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\z/D',$email)!==1
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $organizationId
            )!==1) {
            return null;
        }

        try {
            if (!$this->authenticTestConnections()
                || !$this->rateLimit(
                    'login',$email.'|'.$organizationId,$trustedSourceIp,$serverPepper
                )) {
                return null;
            }

            $this->identityDatabase->beginTransaction();
            $stmt=$this->identityDatabase->prepare(
                "SELECT i.id AS actor_id, i.status AS identity_status,
                        i.password_hash,i.mfa_secret_ciphertext,
                        i.email_verified_at,i.mfa_confirmed_at,
                        i.security_revision AS identity_revision,
                        m.status AS membership_status,
                        m.membership_revision,
                        o.status AS org_status,
                        o.security_revision AS org_revision
                   FROM portal_identities i
                   JOIN portal_memberships m ON m.identity_id=i.id
                   JOIN portal_organizations o ON o.id=m.organization_id
                  WHERE i.email_normalized=:email AND o.id=:org
                  FOR UPDATE OF i,m,o"
            );
            $stmt->execute(['email'=>$email,'org'=>$organizationId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || $row['identity_status']!=='active'
                || $row['membership_status']!=='invited'
                || $row['org_status']!=='active'
                || $row['email_verified_at']===null
                || $row['mfa_confirmed_at']===null
                || !is_string($row['mfa_secret_ciphertext'])
                || !str_starts_with($row['mfa_secret_ciphertext'],'mfa-sb1:')
                || !PortalPasswordCredential::verify($password,$row['password_hash'])
                || (int)$row['identity_revision']<1
                || (int)$row['membership_revision']<1
                || (int)$row['org_revision']<1) {
                $this->identityDatabase->rollBack();
                return null;
            }

            $bearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $uuid=self::uuidV4();
            $insert=$this->identityDatabase->prepare(
                "INSERT INTO portal_auth_sessions (
                    id,session_sha256,identity_id,organization_id,status,
                    session_purpose,identity_revision,membership_revision,
                    organization_revision,issued_at,expires_at,created_at,updated_at
                 ) VALUES (
                    :id,:digest,:actor,:org,'pending_mfa',
                    'invitation_only',:identity_revision,:membership_revision,
                    :organization_revision,statement_timestamp(),
                    statement_timestamp()+interval '5 minutes',now(),now()
                 )"
            );
            $insert->execute([
                'id'=>$uuid,
                'digest'=>hash('sha256',$bearer),
                'actor'=>$row['actor_id'],
                'org'=>$organizationId,
                'identity_revision'=>$row['identity_revision'],
                'membership_revision'=>$row['membership_revision'],
                'organization_revision'=>$row['org_revision'],
            ]);
            if ($insert->rowCount()!==1) {
                $this->identityDatabase->rollBack();
                return null;
            }

            $this->identityDatabase->commit();
            return $bearer;
        } catch (Throwable) {
            if ($this->identityDatabase->inTransaction()) {
                $this->identityDatabase->rollBack();
            }
            return null;
        }
    }

    /**
     * Verify enrolled TOTP using row-locked server-side secret and monotonic
     * counter. An invited member's session remains invitation_only even after
     * MFA; it cannot be used as a business permission credential (F113).
     */
    public function complete(
        string $pendingBearer,
        string $serverEncryptionKey,
        string $totpCode,
        string $trustedSourceIp,
        string $serverPepper,
    ): bool {
        if (!$this->localLaboratory()
            || !extension_loaded('sodium')
            || strlen($serverEncryptionKey)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || strlen($pendingBearer)!==43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$pendingBearer)!==1
            || preg_match('/\A[0-9]{6}\z/D',$totpCode)!==1) {
            return false;
        }

        $decoded=base64_decode(strtr($pendingBearer,'-_','+/').'=',true);
        if (!is_string($decoded) || strlen($decoded)!==32
            || rtrim(strtr(base64_encode($decoded),'+/','-_'),'=')!==$pendingBearer) {
            return false;
        }
        $digest=hash('sha256',$pendingBearer);
        try {
            if (!$this->authenticTestConnections()
                || !$this->rateLimit('totp',$digest,$trustedSourceIp,$serverPepper)) {
                return false;
            }

            $this->identityDatabase->beginTransaction();
            $query=$this->identityDatabase->prepare(
                "SELECT s.id AS session_id,s.status AS session_status,
                        s.session_purpose,s.identity_id,
                        s.identity_revision AS session_identity_revision,
                        s.membership_revision AS session_membership_revision,
                        s.organization_revision AS session_org_revision,
                        floor(extract(epoch from s.issued_at))::bigint AS issued_epoch,
                        floor(extract(epoch from s.expires_at))::bigint AS expires_epoch,
                        i.status AS identity_status,i.security_revision,
                        i.email_verified_at,i.mfa_confirmed_at,
                        i.mfa_secret_ciphertext,i.mfa_last_used_counter,
                        m.status AS member_status,m.membership_revision,
                        o.status AS org_status,o.security_revision AS org_revision
                   FROM portal_auth_sessions s
                   JOIN portal_identities i ON i.id=s.identity_id
                   JOIN portal_memberships m
                     ON m.identity_id=s.identity_id
                    AND m.organization_id=s.organization_id
                   JOIN portal_organizations o ON o.id=s.organization_id
                  WHERE s.session_sha256=:digest
                  FOR UPDATE OF s,i,m,o"
            );
            $query->execute(['digest'=>$digest]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            $serverNow=(int)$this->identityDatabase->query(
                'SELECT floor(extract(epoch from clock_timestamp()))::bigint'
            )->fetchColumn();
            if (!is_array($row)
                || $row['session_status']!=='pending_mfa'
                || $row['session_purpose']!=='invitation_only'
                || $row['identity_status']!=='active'
                || $row['member_status']!=='invited'
                || $row['org_status']!=='active'
                || $row['email_verified_at']===null
                || $row['mfa_confirmed_at']===null
                || $row['session_org_revision']===null
                || (int)$row['session_identity_revision']!==(int)$row['security_revision']
                || (int)$row['session_membership_revision']!==(int)$row['membership_revision']
                || (int)$row['session_org_revision']!==(int)$row['org_revision']
                || $serverNow<(int)$row['issued_epoch']
                || $serverNow>=(int)$row['expires_epoch']
                || (int)$row['expires_epoch']-(int)$row['issued_epoch']>300
                || !is_string($row['mfa_secret_ciphertext'])
                || !str_starts_with($row['mfa_secret_ciphertext'],'mfa-sb1:')) {
                $this->identityDatabase->rollBack();
                return false;
            }

            $secret=PortalTotpSecretCipher::open(
                $row['mfa_secret_ciphertext'],$serverEncryptionKey
            );
            if ($secret===null) {
                $this->identityDatabase->rollBack();
                return false;
            }
            try {
                $counter=PortalTotpVerifier::matchCounter(
                    $secret,$totpCode,$serverNow,(int)$row['mfa_last_used_counter']
                );
            } finally {
                if (function_exists('sodium_memzero')) sodium_memzero($secret);
            }
            if ($counter===null) {
                $this->identityDatabase->rollBack();
                return false;
            }

            $counterWrite=$this->identityDatabase->prepare(
                "UPDATE portal_identities
                    SET mfa_last_used_counter=:counter,updated_at=now()
                  WHERE id=:actor AND status='active'
                    AND security_revision=:revision
                    AND mfa_last_used_counter<:counter_check"
            );
            $counterWrite->execute([
                'counter'=>$counter,
                'actor'=>$row['identity_id'],
                'revision'=>$row['security_revision'],
                'counter_check'=>$counter,
            ]);
            if ($counterWrite->rowCount()!==1) {
                $this->identityDatabase->rollBack();
                return false;
            }

            $sessionWrite=$this->identityDatabase->prepare(
                "UPDATE portal_auth_sessions
                    SET status='active',
                        mfa_verified_at=clock_timestamp(),updated_at=now()
                  WHERE id=:session_id
                    AND session_purpose='invitation_only'
                    AND status='pending_mfa'
                    AND identity_revision=:identity_revision
                    AND membership_revision=:membership_revision
                    AND organization_revision=:org_revision"
            );
            $sessionWrite->execute([
                'session_id'=>$row['session_id'],
                'identity_revision'=>$row['session_identity_revision'],
                'membership_revision'=>$row['session_membership_revision'],
                'org_revision'=>$row['session_org_revision'],
            ]);
            if ($sessionWrite->rowCount()!==1) {
                $this->identityDatabase->rollBack();
                return false;
            }

            $this->identityDatabase->commit();
            return true;
        } catch (Throwable) {
            if ($this->identityDatabase->inTransaction()) {
                $this->identityDatabase->rollBack();
            }
            return false;
        }
    }

    private static function uuidV4(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'
            .substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    public static function mayIssueCustomerSession(): bool
    {
        return false;
    }
}
