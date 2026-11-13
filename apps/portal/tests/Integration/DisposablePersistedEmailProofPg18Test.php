<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Security\PortalEmailProof;
use PDO;
use RuntimeException;
use Tests\Support\DisposablePersistedEmailProofLab;
use Tests\TestCase;

/** No customer e-mail, operator, SMTP, session or operational authority. */
final class DisposablePersistedEmailProofPg18Test extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const SECOND_ORG = '44444444-4444-4444-8444-444444444444';
    private const EMAIL = 'operator@example.invalid';

    private PDO $admin;
    private string $key;
    private DisposablePersistedEmailProofLab $lab;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PG18 proof ledger laboratory required.');
        }
        $this->admin = self::connect('postgres', (string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab', $this->admin->query(
            'SELECT current_database()'
        )->fetchColumn());
        self::assertSame(18, (int) $this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());
        $this->admin->exec(
            'TRUNCATE public.portal_email_proof_challenges,
             public.portal_auth_sessions,public.portal_membership_grants,
             public.portal_memberships,public.portal_identities,
             public.portal_organizations,public.portal_auth_attempt_buckets CASCADE'
        );
        $this->admin->prepare(
            "INSERT INTO public.portal_identities(id,email_normalized,status,security_revision)
             VALUES(CAST(? AS uuid),?,'invited',1)"
        )->execute([self::ID,self::EMAIL]);
        $this->admin->prepare(
            "INSERT INTO public.portal_organizations(id,reference,display_name,status)
             VALUES(CAST(? AS uuid),'persisted-proof-lab','Proof fixture','active')"
        )->execute([self::ORG]);
        $this->admin->prepare(
            "INSERT INTO public.portal_memberships
             (id,identity_id,organization_id,role,status)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),'owner','invited')"
        )->execute([self::MEMBER,self::ID,self::ORG]);

        $this->key = random_bytes(32);
        $this->lab = new DisposablePersistedEmailProofLab($this->admin,$this->key);
    }

    private static function connect(string $role,string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $role,$password,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_EMULATE_PREPARES=>false]
        );
    }

    private function identity(): array
    {
        return $this->admin->query(
            'SELECT status,email_verified_at,password_hash,mfa_confirmed_at,security_revision
             FROM public.portal_identities'
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function state(): array
    {
        return $this->admin->query(
            'SELECT status,attempts,consumed_at,proof_digest,identity_revision
             FROM public.portal_email_proof_challenges'
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function expectRefusal(callable $callback): void
    {
        try {
            $callback();
            self::fail('Unsafe synthetic email ledger operation was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Disposable persisted email proof unavailable.',
                $exception->getMessage()
            );
        }
    }

    public function test_real_persisted_digest_is_one_use_and_does_not_grant_any_resource(): void
    {
        $token=$this->lab->issue(self::ID,self::ORG);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$token);
        $state=$this->state();
        self::assertSame('pending',$state['status']);
        self::assertSame(0,(int) $state['attempts']);
        self::assertNotSame($token,trim($state['proof_digest']));
        self::assertNotSame(hash('sha256',$token),trim($state['proof_digest']));
        self::assertTrue(PortalEmailProof::matches(
            $token,trim($state['proof_digest']),self::ID,self::ORG,self::EMAIL,1,$this->key,
        ));
        $this->expectRefusal(fn()=> $this->lab->issue(self::ID,self::ORG));
        self::assertTrue($this->lab->confirm(self::ID,self::ORG,$token));
        self::assertFalse($this->lab->confirm(self::ID,self::ORG,$token));
        $now=$this->state();
        self::assertSame('consumed',$now['status']);
        self::assertNotNull($now['consumed_at']);
        self::assertSame(0,(int) $now['attempts']);
        $person=$this->identity();
        self::assertSame('invited',$person['status']);
        self::assertNotNull($person['email_verified_at']);
        self::assertNull($person['password_hash']);
        self::assertNull($person['mfa_confirmed_at']);
        self::assertSame(0,(int) $this->admin->query(
            'SELECT count(*) FROM public.portal_auth_sessions'
        )->fetchColumn());
        self::assertSame(0,(int) $this->admin->query(
            'SELECT count(*) FROM public.portal_membership_grants'
        )->fetchColumn());
    }

    public function test_five_bad_proofs_force_permanent_block_without_partial_email_verification(): void
    {
        $token=$this->lab->issue(self::ID,self::ORG);
        for ($i=1;$i<=5;$i++) {
            self::assertFalse($this->lab->confirm(self::ID,self::ORG,str_repeat('f',64)));
            $state=$this->state();
            self::assertSame($i,(int)$state['attempts']);
            self::assertSame($i===5?'blocked':'pending',$state['status']);
        }
        self::assertFalse($this->lab->confirm(self::ID,self::ORG,$token));
        self::assertSame(5,(int)$this->state()['attempts']);
        self::assertNull($this->identity()['email_verified_at']);
        $this->expectRefusal(fn()=> $this->lab->issue(self::ID,self::ORG));
    }

    public function test_rotation_of_secret_rejects_proof_and_counts_attempt(): void
    {
        $token=$this->lab->issue(self::ID,self::ORG);
        $wrong=new DisposablePersistedEmailProofLab($this->admin,random_bytes(32));
        self::assertFalse($wrong->confirm(self::ID,self::ORG,$token));
        self::assertSame(1,(int)$this->state()['attempts']);
        self::assertTrue($this->lab->confirm(self::ID,self::ORG,$token));
    }

    public function test_changed_identity_revision_invalidates_previous_proof(): void
    {
        $token=$this->lab->issue(self::ID,self::ORG);
        $this->admin->exec(
            'UPDATE public.portal_identities SET security_revision=security_revision+1'
        );
        self::assertFalse($this->lab->confirm(self::ID,self::ORG,$token));
        self::assertSame(1,(int)$this->state()['attempts']);
        self::assertNull($this->identity()['email_verified_at']);
    }

    public function test_second_organization_or_another_membership_cannot_steal_proof(): void
    {
        $token=$this->lab->issue(self::ID,self::ORG);
        self::assertFalse($this->lab->confirm(self::ID,self::SECOND_ORG,$token));
        $this->admin->exec(
            "INSERT INTO public.portal_organizations(id,reference,display_name,status)
             VALUES('44444444-4444-4444-8444-444444444444',
                    'other-fixture','Other fixture','active')"
        );
        $this->admin->prepare(
            "INSERT INTO public.portal_memberships
                (id,identity_id,organization_id,role,status)
             VALUES(gen_random_uuid(),CAST(? AS uuid),
                    '44444444-4444-4444-8444-444444444444','member','invited')"
        )->execute([self::ID]);
        self::assertFalse($this->lab->confirm(self::ID,self::ORG,$token));
        self::assertNull($this->identity()['email_verified_at']);
        self::assertSame(0,(int)$this->state()['attempts']);
    }

    public function test_expired_preexisting_digest_never_promotes_email(): void
    {
        $pair=PortalEmailProof::issue(self::ID,self::ORG,self::EMAIL,1,$this->key);
        self::assertIsArray($pair);
        $this->admin->prepare(
            "INSERT INTO public.portal_email_proof_challenges
                 (identity_id,organization_id,email_normalized,identity_revision,
                  proof_digest,issued_at,expires_at)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,1,?,
                    clock_timestamp()-interval '20 minutes',
                    clock_timestamp()-interval '11 minutes')"
        )->execute([self::ID,self::ORG,self::EMAIL,$pair['digest']]);
        self::assertFalse($this->lab->confirm(self::ID,self::ORG,$pair['token']));
        self::assertSame('pending',$this->state()['status']);
        self::assertNull($this->identity()['email_verified_at']);
    }

    public function test_runtime_role_and_caller_owned_transaction_are_refused(): void
    {
        $runtime=self::connect('portal_browser_runtime',
            (string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        $other=new DisposablePersistedEmailProofLab($runtime,$this->key);
        $this->expectRefusal(fn()=> $other->issue(self::ID,self::ORG));
        $this->admin->beginTransaction();
        try {
            $this->expectRefusal(fn()=> $this->lab->issue(self::ID,self::ORG));
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame(0,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_email_proof_challenges'
        )->fetchColumn());
    }

    public function test_concurrent_identity_lock_timeout_cannot_issue_second_proof(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->admin->exec(
                'LOCK TABLE public.portal_identities IN ACCESS EXCLUSIVE MODE'
            );
            $competing=self::connect('postgres',(string)getenv('PGPASSWORD'));
            $competing->exec("SET lock_timeout TO '150ms'");
            $second=new DisposablePersistedEmailProofLab($competing,$this->key);
            $this->expectRefusal(fn()=> $second->issue(self::ID,self::ORG));
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame(0,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_email_proof_challenges'
        )->fetchColumn());
        $this->lab->issue(self::ID,self::ORG);
        self::assertSame(1,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_email_proof_challenges'
        )->fetchColumn());
    }
}
