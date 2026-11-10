<?php

declare(strict_types=1);

use App\Domain\Identity\Invitations\PortalEmailVerificationSecret;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Security\PortalTotpVerifier;

/**
 * PORTAL-F116: disposable CLI-only first-time credential enrollment.
 *
 * This proof explicitly REQUIRES a previously CONSUMED email challenge
 * and proves ownership of a newly generated TOTP secret before moving
 * identity.status invited -> active. It NEVER activates a membership,
 * issues a session or grants permission. Its superuser fixture connection
 * must not be reused in a customer runtime.
 */
final readonly class PortalF116EmailPasswordTotpEnrollment
{
    public function __construct(
        private PDO $identityDb,
        private PDO $throttleDb,
    ) {
    }

    private function labReady(): bool
    {
        return PHP_SAPI === 'cli'
            && getenv('APP_ENV') === 'testing'
            && getenv('PORTAL_F116_PG_LAB') === '1'
            && getenv('PGHOST') === '127.0.0.1'
            && getenv('PGPORT') === '5432'
            && getenv('PGDATABASE') === 'portal_identity_lab'
            && !$this->identityDb->inTransaction()
            && !$this->throttleDb->inTransaction();
    }

    private function trustedFixtureConnections(): bool
    {
        return $this->identityDb->query(
            'SELECT current_user,current_database()'
        )->fetch(PDO::FETCH_NUM) === ['postgres','portal_identity_lab']
        && $this->throttleDb->query(
            'SELECT current_user,current_database()'
        )->fetch(PDO::FETCH_NUM) === ['portal_f77_throttler','portal_identity_lab'];
    }

    private static function validIdentityId(string $id): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $id,
        ) === 1;
    }

    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer=0;
        $bits=0;
        $encoded='';
        foreach (str_split($bytes) as $byte) {
            $buffer=($buffer<<8)|ord($byte);
            $bits+=8;
            while($bits>=5){
                $bits-=5;
                $encoded.=$alphabet[($buffer>>$bits)&31];
            }
            $buffer &= (1<<$bits)-1;
        }
        if($bits>0)$encoded.=$alphabet[($buffer<<(5-$bits))&31];

        return $encoded;
    }

    /**
     * Start ONLY for a verified invited identity holding a recently consumed
     * email token (<= 10m). The returned QR seed is delivery-only, once;
     * never log, transmit to analytics or put it in an untrusted URL.
     * No MFA confirmation or activation is performed by this method.
     */
    public function begin(
        string $verifiedEmailBearer,
        string $identityId,
        string $plainPassword,
        string $serverEncryptionKey,
    ): ?string {
        if (!$this->labReady()
            || !self::validIdentityId($identityId)
            || !extension_loaded('sodium')
            || strlen($serverEncryptionKey)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || ($digest=PortalEmailVerificationSecret::digestCandidate(
                $verifiedEmailBearer
            ))===null) {
            return null;
        }

        $hash=PortalPasswordCredential::makeHash($plainPassword);
        if($hash===null)return null;

        try {
            if (!$this->trustedFixtureConnections())return null;
            $this->identityDb->beginTransaction();
            $select=$this->identityDb->prepare(
                "SELECT i.status,i.security_revision,i.email_normalized,
                        i.email_verified_at,i.password_hash,
                        i.mfa_secret_ciphertext,i.mfa_confirmed_at,
                        i.mfa_last_used_counter,
                        c.status AS challenge_status,c.identity_revision AS challenge_revision,
                        c.email_sha256,c.consumed_at
                   FROM portal_identities i
                   JOIN portal_email_verification_challenges c
                     ON c.identity_id=i.id
                  WHERE i.id=:identity AND c.token_sha256=:digest
                  FOR UPDATE OF i,c"
            );
            $select->execute(['identity'=>$identityId,'digest'=>$digest]);
            $row=$select->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)
                || $row['status']!=='invited'
                || $row['challenge_status']!=='consumed'
                || $row['password_hash']!==null
                || $row['mfa_secret_ciphertext']!==null
                || $row['mfa_confirmed_at']!==null
                || (int)$row['mfa_last_used_counter']!==-1
                || $row['email_verified_at']===null
                || $row['consumed_at']===null
                || (int)$row['security_revision']<1
                || $row['security_revision']!==$row['challenge_revision']
                || !hash_equals(
                    hash('sha256',$row['email_normalized']),
                    $row['email_sha256']
                )) {
                $this->identityDb->rollBack();
                return null;
            }

            $fresh=$this->identityDb->prepare(
                "SELECT (c.consumed_at<=clock_timestamp()
                   AND c.consumed_at>=clock_timestamp()-interval '10 minutes'
                   AND i.email_verified_at=c.consumed_at) AS valid
                   FROM portal_identities i
                   JOIN portal_email_verification_challenges c
                     ON c.identity_id=i.id
                  WHERE i.id=:identity AND c.token_sha256=:digest"
            );
            $fresh->execute(['identity'=>$identityId,'digest'=>$digest]);
            if(!in_array($fresh->fetchColumn(),[true,1,'t','1'],true)){
                $this->identityDb->rollBack();
                return null;
            }

            $secret=self::base32(random_bytes(20));
            $sealed=PortalTotpSecretCipher::seal($secret,$serverEncryptionKey);
            if(!is_string($sealed)){
                $this->identityDb->rollBack();
                return null;
            }

            $update=$this->identityDb->prepare(
                "UPDATE portal_identities
                    SET password_hash=:hash,mfa_secret_ciphertext=:sealed,
                        updated_at=clock_timestamp()
                  WHERE id=:identity AND status='invited'
                    AND email_verified_at IS NOT NULL
                    AND password_hash IS NULL
                    AND mfa_secret_ciphertext IS NULL
                    AND mfa_confirmed_at IS NULL
                    AND mfa_last_used_counter=-1"
            );
            $update->execute([
                'hash'=>$hash,'sealed'=>$sealed,'identity'=>$identityId
            ]);
            if($update->rowCount()!==1){
                $this->identityDb->rollBack();
                return null;
            }
            $this->identityDb->commit();

            return $secret; // caller-only enrollment seed, never logged
        }catch(Throwable){
            if($this->identityDb->inTransaction())$this->identityDb->rollBack();
            return null;
        }
    }

    /**
     * Confirm the first TOTP using the ENROLLED encrypted identity secret.
     * Uses a fixed scope and F77 limit before decryption. A verified email
     * alone NEVER changes identity.status or enables membership access.
     */
    public function complete(
        string $verifiedEmailBearer,
        string $identityId,
        string $otpCode,
        string $serverEncryptionKey,
        string $trustedSourceIp,
        string $serverPepper,
    ): bool {
        if (!$this->labReady()
            || !self::validIdentityId($identityId)
            || ($digest=PortalEmailVerificationSecret::digestCandidate(
                $verifiedEmailBearer
            ))===null
            || !extension_loaded('sodium')
            || strlen($serverEncryptionKey)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || preg_match('/\A[0-9]{6}\z/D',$otpCode)!==1
            || filter_var($trustedSourceIp,FILTER_VALIDATE_IP)===false
            || strlen($serverPepper)<32
            || strlen($serverPepper)>128) {
            return false;
        }

        try {
            if(!$this->trustedFixtureConnections())return false;
            $scope=hash_hmac(
                'sha256','portal-f116|first-totp|'.$identityId.'|'.$trustedSourceIp,
                $serverPepper,
            );
            $limit=$this->throttleDb->prepare(
                'SELECT public.portal_f77_claim_auth_attempt(:scope,:action)'
            );
            $limit->execute(['scope'=>$scope,'action'=>'totp']);
            if(!in_array($limit->fetchColumn(),[true,'t','1',1],true)){
                return false;
            }

            $this->identityDb->beginTransaction();
            $query=$this->identityDb->prepare(
                "SELECT i.status,i.security_revision,i.email_normalized,
                        i.email_verified_at,i.password_hash,
                        i.mfa_secret_ciphertext,i.mfa_confirmed_at,
                        i.mfa_last_used_counter,
                        c.status AS challenge_status,c.identity_revision AS challenge_revision,
                        c.email_sha256,c.consumed_at
                   FROM portal_identities i
                   JOIN portal_email_verification_challenges c
                     ON c.identity_id=i.id
                  WHERE i.id=:identity AND c.token_sha256=:digest
                  FOR UPDATE OF i,c"
            );
            $query->execute(['identity'=>$identityId,'digest'=>$digest]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)
                || $row['status']!=='invited'
                || $row['challenge_status']!=='consumed'
                || $row['email_verified_at']===null
                || $row['password_hash']===null
                || !str_starts_with((string)$row['password_hash'],'$argon2id$')
                || !is_string($row['mfa_secret_ciphertext'])
                || $row['mfa_confirmed_at']!==null
                || (int)$row['mfa_last_used_counter']!==-1
                || $row['security_revision']!==$row['challenge_revision']
                || !hash_equals(
                    hash('sha256',$row['email_normalized']),
                    $row['email_sha256']
                )) {
                $this->identityDb->rollBack();
                return false;
            }

            $fresh=$this->identityDb->prepare(
                "SELECT (c.consumed_at<=clock_timestamp()
                   AND c.consumed_at>=clock_timestamp()-interval '10 minutes'
                   AND i.email_verified_at=c.consumed_at) AS valid
                   FROM portal_identities i
                   JOIN portal_email_verification_challenges c
                     ON c.identity_id=i.id
                  WHERE i.id=:identity AND c.token_sha256=:digest"
            );
            $fresh->execute(['identity'=>$identityId,'digest'=>$digest]);
            if(!in_array($fresh->fetchColumn(),[true,1,'t','1'],true)){
                $this->identityDb->rollBack();
                return false;
            }

            $secret=PortalTotpSecretCipher::open(
                $row['mfa_secret_ciphertext'],$serverEncryptionKey
            );
            if($secret===null){
                $this->identityDb->rollBack();
                return false;
            }
            $epoch=(int)$this->identityDb->query(
                'SELECT floor(extract(epoch from clock_timestamp()))::bigint'
            )->fetchColumn();
            try {
                $counter=PortalTotpVerifier::matchCounter(
                    $secret,$otpCode,$epoch,(int)$row['mfa_last_used_counter']
                );
            }finally {
                if(function_exists('sodium_memzero'))sodium_memzero($secret);
            }
            if($counter===null){
                $this->identityDb->rollBack();
                return false;
            }

            $save=$this->identityDb->prepare(
                "UPDATE portal_identities SET status='active',
                    mfa_confirmed_at=clock_timestamp(),
                    mfa_last_used_counter=:counter,
                    security_revision=security_revision+1,
                    updated_at=clock_timestamp()
                  WHERE id=:identity AND status='invited'
                    AND password_hash IS NOT NULL
                    AND mfa_secret_ciphertext IS NOT NULL
                    AND email_verified_at IS NOT NULL
                    AND mfa_confirmed_at IS NULL
                    AND security_revision=:revision
                    AND mfa_last_used_counter<:counter_check"
            );
            $save->execute([
                'counter'=>$counter,'identity'=>$identityId,
                'revision'=>$row['security_revision'],
                'counter_check'=>$counter,
            ]);
            if($save->rowCount()!==1){
                $this->identityDb->rollBack();
                return false;
            }
            $this->identityDb->commit();
            return true;
        }catch(Throwable){
            if($this->identityDb->inTransaction())$this->identityDb->rollBack();
            return false;
        }
    }

    public static function mayEnrollRealCustomers(): bool
    {
        return false;
    }
}
