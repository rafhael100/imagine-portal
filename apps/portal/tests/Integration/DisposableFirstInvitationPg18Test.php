<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Identity\Security\PortalPasswordCredential;
use PDO;
use RuntimeException;
use Tests\Support\DisposableFirstInvitationBootstrap;
use Tests\TestCase;

final class DisposableFirstInvitationPg18Test extends TestCase
{
    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PG18 invitation laboratory required.');
        }
        $this->admin = self::connect('postgres', (string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',
            $this->admin->query('SELECT current_database()')->fetchColumn());
        self::assertSame(18, (int) $this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());
        $this->admin->exec('TRUNCATE public.portal_auth_sessions,
            public.portal_membership_grants, public.portal_memberships,
            public.portal_identities, public.portal_organizations,
            public.portal_auth_attempt_buckets CASCADE');
        $this->admin->exec('DELETE FROM portal_browser_private.proof_key');
    }

    private static function connect(string $role, string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $role,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    private function reserve(): array
    {
        return (new DisposableFirstInvitationBootstrap($this->admin))->reserve(
            'operator@example.invalid', 'example-synthetic', 'Example Synthetic'
        );
    }

    public function test_first_reservation_is_atomic_but_cannot_authenticate_or_grant_permissions(): void
    {
        $reserved = $this->reserve();
        foreach ($reserved as $id) {
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
                $id
            );
        }
        $person = $this->admin->query(
            "SELECT status, password_hash, email_verified_at,
                    mfa_secret_ciphertext, mfa_confirmed_at
             FROM public.portal_identities"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('invited', $person['status']);
        foreach (['password_hash', 'email_verified_at',
            'mfa_secret_ciphertext', 'mfa_confirmed_at'] as $field) {
            self::assertNull($person[$field], 'No real login proof may exist at bootstrap.');
        }

        $member = $this->admin->query(
            'SELECT role, status FROM public.portal_memberships'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('owner', $member['role']);
        self::assertSame('invited', $member['status']);
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_membership_grants'
        )->fetchColumn());
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_auth_sessions'
        )->fetchColumn());
        $material = $this->admin->prepare(
            'SELECT * FROM public.portal_browser_material(?,CAST(? AS uuid))'
        );
        $material->execute(['operator@example.invalid', $reserved['organization']]);
        self::assertFalse($material->fetch(PDO::FETCH_ASSOC));

        self::assertFalse(PortalPasswordCredential::verify(
            'synthetic-password-only', $person['password_hash']
        ));
    }

    public function test_second_reservation_is_refused_and_original_invitation_is_not_mutated(): void
    {
        $this->reserve();
        $before = (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn();
        self::assertSame(1, $before);
        try {
            $this->reserve();
            self::fail('A second first account was incorrectly created.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable invitation unavailable.', $e->getMessage());
        }
        foreach (['portal_identities', 'portal_organizations', 'portal_memberships'] as $table) {
            self::assertSame(1, (int) $this->admin->query(
                'SELECT count(*) FROM public.' . $table
            )->fetchColumn());
        }
    }

    public function test_any_preexisting_identity_table_data_fails_before_writes(): void
    {
        $this->admin->exec(
            "INSERT INTO public.portal_organizations (id,reference,display_name,status)
             VALUES(gen_random_uuid(),'existing-synthetic','Preexisting fixture','active')"
        );
        try {
            $this->reserve();
            self::fail('A prepopulated identity database was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable invitation unavailable.', $e->getMessage());
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
        self::assertSame(1, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_organizations'
        )->fetchColumn());
    }

    public function test_invalid_or_real_email_and_injection_attempts_never_write(): void
    {
        $helper = new DisposableFirstInvitationBootstrap($this->admin);
        foreach ([
            ['operator@example.com', 'example-synthetic', 'Example'],
            ['PERSON@example.invalid', 'example-synthetic', 'Example'],
            ['operator@example.invalid', 'Example-Synthetic', 'Example'],
            ['operator@example.invalid', "invalid';DROP TABLE x;--", 'Example'],
            ['operator@example.invalid', 'example-synthetic', "Bad\nName"],
        ] as [$email, $reference, $display]) {
            try {
                $helper->reserve($email, $reference, $display);
                self::fail('Unsafe operator invitation input was accepted.');
            } catch (RuntimeException $e) {
                self::assertSame('Disposable invitation preflight refused.', $e->getMessage());
            }
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
    }

    public function test_stale_database_signing_key_blocks_the_first_invitation(): void
    {
        $this->admin->exec(
            "INSERT INTO portal_browser_private.proof_key(secret) VALUES('" . str_repeat('a', 64) . "')"
        );
        try {
            $this->reserve();
            self::fail('A preexisting authentication signing key was ignored.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable invitation unavailable.', $e->getMessage());
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
    }

    public function test_parallel_first_invitation_waits_for_exclusive_lock_and_fails_closed(): void
    {
        $this->admin->beginTransaction();
        try {
            $this->admin->exec('LOCK TABLE public.portal_identities IN ACCESS EXCLUSIVE MODE');
            $competing = self::connect('postgres', (string) getenv('PGPASSWORD'));
            $competing->exec("SET lock_timeout TO '150ms'");
            try {
                (new DisposableFirstInvitationBootstrap($competing))->reserve(
                    'operator@example.invalid', 'another-synthetic', 'Competing fixture'
                );
                self::fail('A concurrent first account bypassed the lock.');
            } catch (RuntimeException $e) {
                self::assertSame('Disposable invitation unavailable.', $e->getMessage());
            }
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
        $this->reserve();
        self::assertSame(1, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
    }

    public function test_login_runtime_role_and_existing_external_transaction_are_refused(): void
    {
        $runtime = self::connect(
            'portal_browser_runtime', (string) getenv('PORTAL_BROWSER_RUNTIME_PASSWORD')
        );
        try {
            (new DisposableFirstInvitationBootstrap($runtime))->reserve(
                'operator@example.invalid', 'example-synthetic', 'Example'
            );
            self::fail('Application runtime role was accepted as a bootstrapper.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable invitation unavailable.', $e->getMessage());
        }

        $this->admin->beginTransaction();
        try {
            (new DisposableFirstInvitationBootstrap($this->admin))->reserve(
                'operator@example.invalid', 'example-synthetic', 'Example'
            );
            self::fail('Existing caller transaction was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame('Disposable invitation unavailable.', $e->getMessage());
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame(0, (int) $this->admin->query(
            'SELECT count(*) FROM public.portal_identities'
        )->fetchColumn());
    }
}
