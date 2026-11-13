<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Security\PortalEmailProof;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use PDO;
use RuntimeException;
use Tests\Support\DisposableFirstEnrollment;
use Tests\Support\DisposableFirstInvitationBootstrap;
use Tests\TestCase;

final class DisposableFirstEnrollmentPg18Test extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private PDO $admin;
    private DisposableFirstEnrollment $enrollment;
    private string $emailKey;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PG18 enrollment laboratory required.');
        }
        $this->admin = self::connect('postgres', (string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab', $this->admin->query(
            'SELECT current_database()'
        )->fetchColumn());
        self::assertSame(18, (int) $this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());
        $this->admin->exec('TRUNCATE public.portal_auth_sessions,
            public.portal_membership_grants, public.portal_memberships,
            public.portal_identities, public.portal_organizations,
            public.portal_auth_attempt_buckets CASCADE');
        $this->admin->exec('DELETE FROM portal_browser_private.proof_key');
        $this->admin->exec('DROP TABLE IF EXISTS portal_browser_private.lab_initial_email_proofs');
        $this->admin->exec(<<<'SQL'
CREATE TABLE portal_browser_private.lab_initial_email_proofs (
    identity_id uuid PRIMARY KEY REFERENCES public.portal_identities(id) ON DELETE RESTRICT,
    organization_id uuid NOT NULL REFERENCES public.portal_organizations(id) ON DELETE RESTRICT,
    identity_revision bigint NOT NULL CHECK(identity_revision > 0),
    proof_digest char(64) NOT NULL CHECK(proof_digest ~ '^[a-f0-9]{64}$'),
    expires_at timestamptz NOT NULL,
    consumed_at timestamptz,
    attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5)
)
SQL);
        $this->emailKey = random_bytes(32);
        $this->enrollment = new DisposableFirstEnrollment($this->admin, $this->emailKey);
    }

    protected function tearDown(): void
    {
        $this->admin->exec('DROP TABLE IF EXISTS portal_browser_private.lab_initial_email_proofs');
        parent::tearDown();
    }

    private static function connect(string $role, string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $role,$password,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    /** @return array{identity:string,organization:string,membership:string} */
    private function invite(): array
    {
        return (new DisposableFirstInvitationBootstrap($this->admin))->reserve(
            'operator@example.invalid', 'enrollment-synthetic', 'Enrollment Synthetic'
        );
    }

    private function otp(): string
    {
        $counter = (int) $this->admin->query(
            'SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint'
        )->fetchColumn();
        $hmac = hash_hmac('sha1',
            pack('N2', intdiv($counter,4294967296), $counter%4294967296),
            '12345678901234567890', true);
        $offset = ord($hmac[19]) & 15;
        $value = unpack('N',substr($hmac,$offset,4))[1] & 0x7fffffff;
        return str_pad((string) ($value%1000000),6,'0',STR_PAD_LEFT);
    }

    private function firstTwoSteps(array $ids): void
    {
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        self::assertTrue($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertTrue($this->enrollment->setFirstPassword(
            $ids['identity'], 'synthetic-strong-lab-password'
        ));
    }

    public function test_initial_invitation_never_activates_without_all_three_proofs(): void
    {
        $ids = $this->invite();
        self::assertFalse($this->enrollment->setFirstPassword(
            $ids['identity'], 'synthetic-strong-lab-password'
        ));
        self::assertFalse($this->enrollment->confirmMfaAndActivate(
            $ids['identity'], $ids['organization'], self::SECRET,
            $this->otp(), random_bytes(32)
        ));
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertFalse($this->enrollment->confirmEmail(
            $ids['identity'], str_repeat('a',64)
        ));
        self::assertFalse($this->enrollment->confirmEmail(
            $ids['identity'], 'bad'
        ));
        $person = $this->admin->query(
            'SELECT status,email_verified_at,password_hash,mfa_confirmed_at
             FROM public.portal_identities'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('invited', $person['status']);
        self::assertNull($person['email_verified_at']);
        self::assertNull($person['password_hash']);
        self::assertNull($person['mfa_confirmed_at']);
    }

    public function test_email_receipt_is_single_use_and_expired_or_wrong_proofs_are_refused(): void
    {
        $ids = $this->invite();
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        try {
            $this->enrollment->issueEmailProof($ids['identity']);
            self::fail('Issued a second email proof for one identity.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        $persisted = $this->admin->query(
            'SELECT proof_digest FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn();
        self::assertNotSame(hash('sha256',$token), trim($persisted));
        self::assertTrue(PortalEmailProof::matches(
            $token, trim((string) $persisted), $ids['identity'],
            $ids['organization'], 'operator@example.invalid', 1, $this->emailKey,
        ));
        self::assertNotSame($token,trim($persisted));
        $this->admin->exec(
            "UPDATE portal_browser_private.lab_initial_email_proofs
             SET expires_at=clock_timestamp()-interval '1 second'"
        );
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'],$token));
        self::assertNull($this->admin->query(
            'SELECT email_verified_at FROM public.portal_identities'
        )->fetchColumn());
        $this->admin->exec(
            "UPDATE portal_browser_private.lab_initial_email_proofs
             SET expires_at=clock_timestamp()+interval '1 minute'"
        );
        self::assertTrue($this->enrollment->confirmEmail($ids['identity'],$token));
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'],$token));
        self::assertFalse($this->enrollment->confirmEmail(
            $ids['identity'],str_repeat('b',64)
        ));
    }

    public function test_five_wrong_email_proofs_lock_out_even_a_later_correct_receipt(): void
    {
        $ids = $this->invite();
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertFalse($this->enrollment->confirmEmail(
                $ids['identity'], str_repeat('f', 64)
            ));
            self::assertSame($attempt, (int) $this->admin->query(
                'SELECT attempts FROM portal_browser_private.lab_initial_email_proofs'
            )->fetchColumn());
        }
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertSame(5, (int) $this->admin->query(
            'SELECT attempts FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
        self::assertNull($this->admin->query(
            'SELECT email_verified_at FROM public.portal_identities'
        )->fetchColumn());
        self::assertFalse($this->enrollment->setFirstPassword(
            $ids['identity'], 'synthetic-strong-lab-password'
        ));
    }

    public function test_password_and_mfa_are_bound_to_verified_invite_and_do_not_give_business_grants(): void
    {
        $ids = $this->invite();
        $this->firstTwoSteps($ids);
        $row = $this->admin->query(
            'SELECT password_hash,status,mfa_secret_ciphertext
             FROM public.portal_identities'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('invited',$row['status']);
        self::assertNull($row['mfa_secret_ciphertext']);
        self::assertTrue(PortalPasswordCredential::verify(
            'synthetic-strong-lab-password', $row['password_hash']
        ));
        self::assertFalse($this->enrollment->setFirstPassword(
            $ids['identity'], 'synthetic-other-password'
        ));
        self::assertFalse($this->enrollment->confirmMfaAndActivate(
            $ids['identity'],'33333333-3333-4333-8333-333333333333',
            self::SECRET,$this->otp(),random_bytes(32)
        ));
        self::assertFalse($this->enrollment->confirmMfaAndActivate(
            $ids['identity'],$ids['organization'],self::SECRET,'000BAD',random_bytes(32)
        ));
        $key=random_bytes(32);
        self::assertTrue($this->enrollment->confirmMfaAndActivate(
            $ids['identity'],$ids['organization'],self::SECRET,$this->otp(),$key
        ));
        $person=$this->admin->query(
            'SELECT status,mfa_secret_ciphertext,mfa_last_used_counter,
                    mfa_confirmed_at,email_verified_at,security_revision
             FROM public.portal_identities'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('active',$person['status']);
        self::assertNotNull($person['mfa_confirmed_at']);
        self::assertNotNull($person['email_verified_at']);
        self::assertGreaterThan(1,(int) $person['security_revision']);
        self::assertGreaterThan(0,(int) $person['mfa_last_used_counter']);
        self::assertSame(self::SECRET,PortalTotpSecretCipher::open(
            $person['mfa_secret_ciphertext'],$key
        ));
        self::assertSame(['owner','active'],array_values($this->admin->query(
            'SELECT role,status FROM public.portal_memberships'
        )->fetch(PDO::FETCH_ASSOC)));
        self::assertSame(0,(int) $this->admin->query(
            'SELECT count(*) FROM public.portal_membership_grants'
        )->fetchColumn());
        self::assertSame(0,(int) $this->admin->query(
            'SELECT count(*) FROM public.portal_auth_sessions'
        )->fetchColumn());
        self::assertFalse($this->enrollment->confirmMfaAndActivate(
            $ids['identity'],$ids['organization'],self::SECRET,$this->otp(),$key
        ));
        self::assertFalse($this->enrollment->setFirstPassword(
            $ids['identity'], 'synthetic-new-password'
        ));
    }

    public function test_forged_membership_grant_blocks_activation_without_partial_changes(): void
    {
        $ids=$this->invite();
        $this->firstTwoSteps($ids);
        $this->admin->prepare(
            "INSERT INTO public.portal_membership_grants
                (id,membership_id,permission_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),'services.view')"
        )->execute([$ids['membership']]);
        self::assertFalse($this->enrollment->confirmMfaAndActivate(
            $ids['identity'],$ids['organization'],self::SECRET,$this->otp(),random_bytes(32)
        ));
        self::assertSame('invited',$this->admin->query(
            'SELECT status FROM public.portal_identities'
        )->fetchColumn());
        self::assertSame('invited',$this->admin->query(
            'SELECT status FROM public.portal_memberships'
        )->fetchColumn());
    }

    public function test_copied_proof_tenant_scope_cannot_verify_even_with_original_token(): void
    {
        $ids = $this->invite();
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        $this->admin->exec(
            "INSERT INTO public.portal_organizations
             (id,reference,display_name,status)
             VALUES('44444444-4444-4444-8444-444444444444',
                    'copied-synthetic','Copied Synthetic','active')"
        );
        $this->admin->exec(
            "UPDATE portal_browser_private.lab_initial_email_proofs
             SET organization_id='44444444-4444-4444-8444-444444444444'"
        );
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertNull($this->admin->query(
            'SELECT email_verified_at FROM public.portal_identities'
        )->fetchColumn());
        self::assertSame(1, (int) $this->admin->query(
            'SELECT attempts FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
        $this->admin->prepare(
            'UPDATE portal_browser_private.lab_initial_email_proofs SET organization_id=CAST(? AS uuid)'
        )->execute([$ids['organization']]);
        self::assertTrue($this->enrollment->confirmEmail($ids['identity'], $token));
    }

    public function test_issued_proof_rejects_changed_identity_revision(): void
    {
        $ids = $this->invite();
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        $this->admin->exec(
            'UPDATE public.portal_identities SET security_revision=security_revision+1'
        );
        self::assertFalse($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertNull($this->admin->query(
            'SELECT email_verified_at FROM public.portal_identities'
        )->fetchColumn());
        self::assertSame(1, (int) $this->admin->query(
            'SELECT attempts FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
    }

    public function test_rotated_server_key_refuses_replayed_email_proof(): void
    {
        $ids = $this->invite();
        $token = $this->enrollment->issueEmailProof($ids['identity']);
        $otherKey = new DisposableFirstEnrollment($this->admin, random_bytes(32));
        self::assertFalse($otherKey->confirmEmail($ids['identity'], $token));
        self::assertTrue($this->enrollment->confirmEmail($ids['identity'], $token));
        self::assertSame(1, (int) $this->admin->query(
            'SELECT attempts FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
    }

    public function test_multiple_memberships_block_first_operator_email_issuance(): void
    {
        $ids = $this->invite();
        $this->admin->exec(
            "INSERT INTO public.portal_organizations
             (id,reference,display_name,status)
             VALUES('44444444-4444-4444-8444-444444444444',
                    'second-synthetic','Second Synthetic','active')"
        );
        $this->admin->prepare(
            "INSERT INTO public.portal_memberships
             (id,identity_id,organization_id,role,status)
             VALUES(gen_random_uuid(),CAST(? AS uuid),
                    '44444444-4444-4444-8444-444444444444','member','invited')"
        )->execute([$ids['identity']]);
        try {
            $this->enrollment->issueEmailProof($ids['identity']);
            self::fail('Ambiguous tenant relationship was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable enrollment unavailable.', $e->getMessage());
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
    }

    public function test_runtime_role_cannot_issue_email_or_enroll_operators(): void
    {
        $ids=$this->invite();
        $runtime=self::connect('portal_browser_runtime',
            (string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $lab=new DisposableFirstEnrollment($runtime,$this->emailKey);
        try {
            $lab->issueEmailProof($ids['identity']);
            self::fail('Runtime role gained offline enrollment authority.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable enrollment unavailable.',$e->getMessage());
        }
        self::assertSame(0,(int) $this->admin->query(
            'SELECT count(*) FROM portal_browser_private.lab_initial_email_proofs'
        )->fetchColumn());
    }

    public function test_caller_transaction_or_non_synthetic_password_fails_closed(): void
    {
        $ids=$this->invite();
        $this->admin->beginTransaction();
        try {
            $this->enrollment->issueEmailProof($ids['identity']);
            self::fail('Accepted caller-owned enrollment transaction.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable enrollment unavailable.',$e->getMessage());
        } finally {
            $this->admin->rollBack();
        }
        $token=$this->enrollment->issueEmailProof($ids['identity']);
        self::assertTrue($this->enrollment->confirmEmail($ids['identity'],$token));
        self::assertFalse($this->enrollment->setFirstPassword(
            $ids['identity'],'actual-user-password'
        ));
        self::assertSame('invited',$this->admin->query(
            'SELECT status FROM public.portal_identities'
        )->fetchColumn());
    }
}
