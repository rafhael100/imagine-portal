<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * CI-only two-connection stale-writer proof.
 *
 * An owner role writes exclusively to the disposable PG18 fixture; this is
 * NOT a positive proof of RLS for an authorized runtime principal, consent,
 * provider account access, S2S authorization, or an operational Ads grant.
 */
final class NativePerformanceAccountMandateStaleWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The general no-database Laravel suites must skip this PG18-only
        // fixture, while the opted-in authority job still fails closed on
        // every environment or SQL-identity mismatch.
        if (getenv('ADMIN_PHASE4_PG_CI') !== '1') {
            $this->markTestSkipped('Only the disposable Phase8 PostgreSQL18 job runs stale writers');
        }
        foreach ([
            'GITHUB_ACTIONS' => 'true', 'CI' => 'true',
            'ADMIN_PHASE4_PG_CI' => '1', 'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432', 'DB_DATABASE' => 'performance_phase4_ci',
            'DB_USERNAME' => 'postgres',
        ] as $name => $expected) {
            if (getenv($name) !== $expected) {
                $this->fail('Only attested disposable PostgreSQL18 CI may run this test');
            }
        }
        if (DB::getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'performance_phase4_ci') {
            $this->fail('Unexpected Laravel SQL destination');
        }
        $identity = DB::selectOne(
            "SELECT current_database() AS db, current_user AS role, " .
            "current_setting('server_version_num')::int AS version"
        );
        $this->assertSame('performance_phase4_ci', $identity->db);
        $this->assertSame('postgres', $identity->role);
        $this->assertGreaterThanOrEqual(180000, (int) $identity->version);
    }

    private function independentOwnerConnection(): PDO
    {
        $pdo = new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=performance_phase4_ci',
            'postgres',
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        $identity = $pdo->query(
            "SELECT current_database() AS db, current_user AS role, " .
            "current_setting('server_version_num')::int AS version"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('performance_phase4_ci', $identity['db']);
        $this->assertSame('postgres', $identity['role']);
        $this->assertGreaterThanOrEqual(180000, (int) $identity['version']);
        return $pdo;
    }

    public function test_stale_revision_and_revocation_cannot_be_overwritten_between_connections(): void
    {
        $company = (string) Str::uuid();
        $meta = (string) Str::uuid();
        $google = (string) Str::uuid();
        $expires = time() + 600;

        DB::table('portal_performance_services')->insert([
            'company_id' => $company, 'contract_ref' => (string) Str::uuid(),
            'contract_version' => 1, 'company_active' => true,
            'status' => 'active', 'source_kind' => 'synthetic_persisted',
            'expires_at' => $expires,
        ]);
        foreach ([
            ['google_ads', $google, 'synthetic-consent-google-001', 'synthetic-vault-google-001'],
            ['meta_ads', $meta, 'synthetic-consent-meta-001', 'synthetic-vault-meta-001'],
        ] as [$provider, $id, $consent, $reference]) {
            DB::table('portal_performance_account_mandates')->insert([
                'id' => $id, 'company_id' => $company, 'provider' => $provider,
                'account_id' => '1234567890', 'service_principal' => 'imagine_portal_s2s',
                'operation' => 'reports.read', 'purpose' => 'ads_reporting_read',
                'consent_reference' => $consent, 'credential_ref' => $reference,
                'status' => 'candidate', 'source_kind' => 'synthetic_candidate',
                'operational_enabled' => false, 'revision' => 1,
                'expires_at' => $expires,
            ]);
        }

        $stale = $this->independentOwnerConnection();
        $fresh = $this->independentOwnerConnection();
        $read = $stale->prepare(
            'SELECT revision FROM portal_performance_account_mandates WHERE id=?'
        );

        // Writer A observes Meta revision 1 in an open transaction. Writer B
        // commits revision 2 first. A must fail its stale revision-2 replay;
        // only B's audited update is durable.
        $stale->beginTransaction();
        $read->execute([$meta]);
        $this->assertSame(1, (int) $read->fetchColumn());
        $fresh->beginTransaction();
        $rotate = $fresh->prepare(
            'UPDATE portal_performance_account_mandates ' .
            'SET credential_ref=?, revision=2 WHERE id=?'
        );
        $rotate->execute(['synthetic-vault-meta-002', $meta]);
        $this->assertSame(1, $rotate->rowCount());
        $fresh->commit();

        try {
            $replay = $stale->prepare(
                'UPDATE portal_performance_account_mandates ' .
                'SET credential_ref=?, revision=2 WHERE id=?'
            );
            $replay->execute(['synthetic-vault-meta-stale', $meta]);
            $this->fail('Stale writer replaced the committed Meta revision');
        } catch (PDOException $exception) {
            $this->assertStringContainsString(
                'Account mandate revision must increase', $exception->getMessage()
            );
        } finally {
            if ($stale->inTransaction()) {
                $stale->rollBack();
            }
        }

        // Writer A now observes the original Google mandate. B revokes it
        // before A writes. Even a newer revision cannot rewrite a revoked
        // mandate: the terminal trigger must reject the stale editor.
        $stale->beginTransaction();
        $read->execute([$google]);
        $this->assertSame(1, (int) $read->fetchColumn());
        $fresh->beginTransaction();
        $revoke = $fresh->prepare(
            'UPDATE portal_performance_account_mandates ' .
            "SET status='revoked', revoked_at=?, revision=2 WHERE id=?"
        );
        $revoke->execute([time(), $google]);
        $this->assertSame(1, $revoke->rowCount());
        $fresh->commit();

        try {
            $overwrite = $stale->prepare(
                'UPDATE portal_performance_account_mandates ' .
                'SET credential_ref=?, revision=3 WHERE id=?'
            );
            $overwrite->execute(['synthetic-vault-google-stale', $google]);
            $this->fail('Stale writer rewrote revoked Google mandate');
        } catch (PDOException $exception) {
            $this->assertStringContainsString(
                'Revoked account mandate is immutable', $exception->getMessage()
            );
        } finally {
            if ($stale->inTransaction()) {
                $stale->rollBack();
            }
        }

        // Independently read durable SQL rows and trigger-generated audit.
        $rows = DB::table('portal_performance_account_mandates')->where('company_id', $company);
        $events = DB::table('portal_performance_account_mandate_events')->where('company_id', $company);
        $this->assertSame('synthetic-vault-meta-002',
            (clone $rows)->where('id', $meta)->value('credential_ref'));
        $this->assertSame('candidate', (clone $rows)->where('id', $meta)->value('status'));
        $this->assertSame(2, (int) (clone $rows)->where('id', $meta)->value('revision'));
        $this->assertSame('revoked', (clone $rows)->where('id', $google)->value('status'));
        $this->assertSame('synthetic-vault-google-001',
            (clone $rows)->where('id', $google)->value('credential_ref'));
        $this->assertSame(2, (int) (clone $rows)->where('id', $google)->value('revision'));
        $this->assertSame(0, (clone $rows)->where('operational_enabled', true)->count());
        $this->assertSame(4, (clone $events)->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $meta)
            ->where('revision', 2)->where('event_kind', 'revised')->count());
        $this->assertSame(1, (clone $events)->where('mandate_id', $google)
            ->where('revision', 2)->where('event_kind', 'revoked')->count());
        $this->assertSame(0, (clone $events)->where('revision', 3)->count());
    }
}
