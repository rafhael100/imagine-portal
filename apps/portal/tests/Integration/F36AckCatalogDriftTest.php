<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F33\F33SyntheticRevocationEvent;
use App\Labs\F35\F35WitnessAckStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F36 negative drift probes on the disposable witness SQL instance ONLY.
 * All mutation tests restore the original fixture catalog in finally.
 */
final class F36AckCatalogDriftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY'
            || getenv('F35_ENABLE_LAB') !== 'ACK_ONLY_DISPOSABLE_PG18'
            || getenv('F36_ENABLE_LAB') !== 'ACK_CATALOG_DRIFT_ONLY') {
            self::markTestSkipped('F36 only accepts two ephemeral PostgreSQL 18 CI instances.');
        }
    }

    private function db(string $role): PDO
    {
        $roles = [
            'admin' => ['postgres', getenv('F31_PG_PASSWORD_ADMIN')],
            'ack' => ['f35_ack_worker', getenv('F35_PG_PASSWORD_ACK')],
        ];
        $creds = $roles[$role] ?? null;
        $port = getenv('F31_PG_PORT');
        if ($creds === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_PORT') === getenv('F22_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($creds[1])
            || preg_match('/\A[a-f0-9]{64}\z/D', $creds[1]) !== 1) {
            throw new RuntimeException('Non-disposable witness DB rejected.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $creds[0], $creds[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function event(): F33SyntheticRevocationEvent
    {
        return new F33SyntheticRevocationEvent(
            new OrganizationId('org-a'), 'F33-F36-NOT-PUBLISHED',
            'session', 'DEMO-SESSION-A', 2, 1, 1800000031,
        );
    }

    private function store(): F35WitnessAckStore
    {
        return new F35WitnessAckStore($this->db('ack'));
    }

    private function assertRejected(): void
    {
        self::assertNull($this->store()->isAcknowledged($this->event()));
        self::assertNull($this->store()->record($this->event()));
    }

    private function assertRecovered(): void
    {
        self::assertFalse(
            $this->store()->isAcknowledged($this->event()),
            'The catalog was not restored to a readable F35 witness fixture.',
        );
    }

    public function test_expected_catalog_allows_only_a_confirmed_missing_ack(): void
    {
        $this->assertRecovered();
        self::assertNull($this->store()->record($this->event()));
    }

    public function test_disabling_rls_prevents_ack_state_from_being_trusted(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f35.applied_event_ack DISABLE ROW LEVEL SECURITY');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('ALTER TABLE f35.applied_event_ack ENABLE ROW LEVEL SECURITY');
        }
        $this->assertRecovered();
    }

    public function test_removing_force_rls_is_rejected(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f35.applied_event_ack NO FORCE ROW LEVEL SECURITY');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('ALTER TABLE f35.applied_event_ack FORCE ROW LEVEL SECURITY');
        }
        $this->assertRecovered();
    }

    public function test_replacing_policy_predicate_with_true_is_rejected(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER POLICY f35_ack_reader ON f35.applied_event_ack USING (TRUE)');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec(
                "ALTER POLICY f35_ack_reader ON f35.applied_event_ack
                 USING (session_user='f35_ack_worker')"
            );
        }
        $this->assertRecovered();
    }

    public function test_policy_expanded_to_public_is_rejected(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER POLICY f35_ack_reader ON f35.applied_event_ack TO PUBLIC');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('ALTER POLICY f35_ack_reader ON f35.applied_event_ack TO f35_ack_worker');
        }
        $this->assertRecovered();
    }

    public function test_extra_permissive_policy_is_not_ignored(): void
    {
        $admin=$this->db('admin');
        $admin->exec(
            'CREATE POLICY f36_extra_ack_read ON f35.applied_event_ack
             FOR SELECT TO f35_ack_worker USING (TRUE)'
        );
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('DROP POLICY f36_extra_ack_read ON f35.applied_event_ack');
        }
        $this->assertRecovered();
    }

    public function test_unreviewed_write_grant_is_rejected_even_if_rls_denies_rows(): void
    {
        $admin=$this->db('admin');
        $admin->exec('GRANT UPDATE ON f35.applied_event_ack TO f35_ack_worker');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('REVOKE UPDATE ON f35.applied_event_ack FROM f35_ack_worker');
        }
        $this->assertRecovered();
    }

    public function test_public_select_grant_on_ack_is_rejected(): void
    {
        $admin=$this->db('admin');
        $admin->exec('GRANT SELECT ON f35.applied_event_ack TO PUBLIC');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('REVOKE SELECT ON f35.applied_event_ack FROM PUBLIC');
        }
        $this->assertRecovered();
    }

    public function test_ack_writer_inherit_role_drift_is_rejected(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER ROLE f35_ack_worker INHERIT');
        try {
            $this->assertRejected();
        } finally {
            $admin->exec('ALTER ROLE f35_ack_worker NOINHERIT');
        }
        $this->assertRecovered();
    }

    public function test_ack_table_owner_transfer_requires_restoring_explicit_grants(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f35.applied_event_ack OWNER TO f35_ack_worker');
        try {
            $this->assertRejected();
        } finally {
            // PostgreSQL ownership transfer can remove the former
            // owner's explicit grant: restore both owner AND SELECT.
            $admin->exec('ALTER TABLE f35.applied_event_ack OWNER TO postgres');
            $admin->exec('GRANT SELECT ON f35.applied_event_ack TO f35_ack_worker');
        }
        $this->assertRecovered();
    }
}
