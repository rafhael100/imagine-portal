<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Security\PortalEmailProof;
use PDO;
use PDOException;
use Tests\TestCase;

final class PortalEmailProofLedgerPg18Test extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const EMAIL = 'operator@example.invalid';

    private PDO $admin;
    private string $proofDigest;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PostgreSQL 18 email ledger lab required.');
        }
        $this->admin = self::connect('postgres', (string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab', $this->admin->query(
            'SELECT current_database()'
        )->fetchColumn());
        self::assertSame(18, (int) $this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());
        $this->admin->exec('TRUNCATE public.portal_email_proof_challenges,
            public.portal_auth_sessions, public.portal_membership_grants,
            public.portal_memberships, public.portal_identities,
            public.portal_organizations, public.portal_auth_attempt_buckets CASCADE');

        $person = $this->admin->prepare(
            "INSERT INTO public.portal_identities(id,email_normalized,status,security_revision)
             VALUES(CAST(? AS uuid),?,'invited',1)"
        );
        $person->execute([self::ID,self::EMAIL]);
        $org = $this->admin->prepare(
            "INSERT INTO public.portal_organizations(id,reference,display_name,status)
             VALUES(CAST(? AS uuid),'proof-lab','Proof Lab','active')"
        );
        $org->execute([self::ORG]);
        $member = $this->admin->prepare(
            "INSERT INTO public.portal_memberships
                (id,identity_id,organization_id,role,status)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),'owner','invited')"
        );
        $member->execute([self::MEMBER,self::ID,self::ORG]);
        $proof = PortalEmailProof::issue(
            self::ID,self::ORG,self::EMAIL,1,random_bytes(32),
        );
        self::assertIsArray($proof);
        $this->proofDigest = $proof['digest'];
    }

    private static function connect(string $role, string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $role,$password,[
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function issue(string $clock = 'current'): void
    {
        $timestamps = $clock === 'expired'
            ? "clock_timestamp()-interval '20 minutes',
               clock_timestamp()-interval '11 minutes'"
            : "clock_timestamp(),clock_timestamp()+interval '9 minutes'";
        $q = $this->admin->prepare(
            "INSERT INTO public.portal_email_proof_challenges
               (identity_id,organization_id,email_normalized,
                identity_revision,proof_digest,issued_at,expires_at)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,1,?,$timestamps)"
        );
        $q->execute([self::ID,self::ORG,self::EMAIL,$this->proofDigest]);
    }

    private static function rejected(PDO $db, string $sql, array $parameters = [],
                                     string $state = '42501'): void
    {
        try {
            $q = $db->prepare($sql);
            $q->execute($parameters);
        } catch (PDOException $error) {
            self::assertSame($state, (string) $error->getCode());
            return;
        }
        self::fail('Unsafe PostgreSQL proof ledger mutation was accepted.');
    }

    public function test_default_deny_for_browser_runtime_and_forced_rls_without_policies(): void
    {
        $this->issue();
        $metadata = $this->admin->query(
            "SELECT relrowsecurity,relforcerowsecurity
             FROM pg_class WHERE oid='public.portal_email_proof_challenges'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertContains($metadata['relrowsecurity'], ['t', true, 1, '1']);
        self::assertContains($metadata['relforcerowsecurity'], ['t', true, 1, '1']);
        self::assertSame(0,(int) $this->admin->query(
            "SELECT count(*) FROM pg_policies
             WHERE schemaname='public' AND tablename='portal_email_proof_challenges'"
        )->fetchColumn());

        $runtime = self::connect(
            'portal_browser_runtime', (string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD')
        );
        self::rejected($runtime, 'SELECT * FROM public.portal_email_proof_challenges');
        self::rejected($runtime,
            "INSERT INTO public.portal_email_proof_challenges
             (identity_id,organization_id,email_normalized,
              identity_revision,proof_digest,issued_at,expires_at)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,1,?,
                    clock_timestamp(),clock_timestamp()+interval '9 minutes')",
            [self::ID,self::ORG,self::EMAIL,$this->proofDigest]
        );
        self::rejected($runtime,
            "UPDATE public.portal_email_proof_challenges
             SET status='consumed',consumed_at=clock_timestamp()");
    }

    public function test_finalization_is_one_use_and_challenge_scope_is_immutable(): void
    {
        $this->issue();
        foreach ([
            "UPDATE public.portal_email_proof_challenges SET email_normalized='other@example.invalid'",
            "UPDATE public.portal_email_proof_challenges SET identity_revision=2",
            "UPDATE public.portal_email_proof_challenges SET proof_digest='".str_repeat('f',64)."'",
            "UPDATE public.portal_email_proof_challenges SET expires_at=expires_at+interval '1 minute'",
        ] as $sql) {
            self::rejected($this->admin,$sql);
        }
        $this->admin->exec(
            "UPDATE public.portal_email_proof_challenges
             SET status='consumed',consumed_at=clock_timestamp()"
        );
        $state = $this->admin->query(
            'SELECT status,consumed_at,attempts FROM public.portal_email_proof_challenges'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('consumed',$state['status']);
        self::assertNotNull($state['consumed_at']);
        self::assertSame(0,(int) $state['attempts']);
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges SET status='blocked',attempts=5");
    }

    public function test_five_failed_attempts_lock_the_proof_and_no_reset_is_possible(): void
    {
        $this->issue();
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges SET attempts=2");
        for ($n=1; $n<=5; $n++) {
            $status=$n===5?'blocked':'pending';
            $this->admin->prepare(
                'UPDATE public.portal_email_proof_challenges
                 SET attempts=attempts+1,status=?'
            )->execute([$status]);
            self::assertSame($n,(int) $this->admin->query(
                'SELECT attempts FROM public.portal_email_proof_challenges'
            )->fetchColumn());
        }
        self::assertSame('blocked',$this->admin->query(
            'SELECT status FROM public.portal_email_proof_challenges'
        )->fetchColumn());
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges
             SET status='consumed',consumed_at=clock_timestamp()");
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges SET attempts=0");
    }

    public function test_expired_challenge_never_consumes_or_extends(): void
    {
        $this->issue('expired');
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges
             SET status='consumed',consumed_at=clock_timestamp()");
        self::rejected($this->admin,
            "UPDATE public.portal_email_proof_challenges
             SET expires_at=clock_timestamp()+interval '9 minutes'");
        self::assertSame('pending',$this->admin->query(
            'SELECT status FROM public.portal_email_proof_challenges'
        )->fetchColumn());
    }

    public function test_scope_requires_existing_membership_and_has_one_challenge_per_identity(): void
    {
        $this->issue();
        self::rejected($this->admin,
            "INSERT INTO public.portal_email_proof_challenges
             (identity_id,organization_id,email_normalized,identity_revision,
              proof_digest,issued_at,expires_at)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,1,?,
                    clock_timestamp(),clock_timestamp()+interval '9 minutes')",
            [self::ID,self::ORG,self::EMAIL,$this->proofDigest], '23505'
        );
        self::rejected($this->admin,
            'DELETE FROM public.portal_memberships WHERE id=CAST(? AS uuid)',
            [self::MEMBER], '23503'
        );
        self::assertSame(1,(int) $this->admin->query(
            'SELECT count(*) FROM public.portal_email_proof_challenges'
        )->fetchColumn());
    }
}
