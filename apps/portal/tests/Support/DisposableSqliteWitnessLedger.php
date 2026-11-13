<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabExternalWitnessReader;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Durable *test fixture* in its OWN SQLite file. Demonstrates a separate
 * persistence domain in CI, NOT a physically independent witness in a
 * real deployment. SQLite DB, WAL, filesystem and backups can all roll back.
 *
 * Never connect this to serving, HTTP, real tenants, or the real Portal DB.
 */
final class DisposableSqliteWitnessLedger implements LabExternalWitnessReader
{
    private PDO $db;

    public function __construct(private readonly string $file)
    {
        try {
            $root = realpath(sys_get_temp_dir());
            $parent = realpath(dirname($file));
            if (getenv('APP_ENV') !== 'testing'
                || !extension_loaded('pdo_sqlite')
                || !is_string($root) || !is_string($parent)
                || !str_starts_with($parent, $root . '/portal-witness-sqlite-lab-')
                || !preg_match('/\A[a-zA-Z0-9_-]+\.sqlite\z/D', basename($file))
                || is_link($file)) {
                throw new RuntimeException('Disposable witness location refused.');
            }

            $this->db = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 1,
            ]);
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA synchronous=FULL');
            $this->db->exec('PRAGMA busy_timeout=200');
            $this->db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS witness_events(
    event_id TEXT PRIMARY KEY CHECK(length(event_id)=36),
    tenant TEXT NOT NULL,
    cluster TEXT NOT NULL,
    sequence INTEGER NOT NULL CHECK(sequence >= 1 AND sequence <= 1000000000),
    covered_backup INTEGER NOT NULL CHECK(covered_backup >= 1 AND covered_backup <= 1000000000),
    session_floor INTEGER NOT NULL CHECK(session_floor >= 1 AND session_floor <= 1000000000),
    membership_floor INTEGER NOT NULL CHECK(membership_floor >= 1 AND membership_floor <= 1000000000),
    UNIQUE(tenant, sequence)
);
CREATE TRIGGER IF NOT EXISTS witness_no_update
BEFORE UPDATE ON witness_events
BEGIN SELECT RAISE(ABORT,'witness_events immutable'); END;
CREATE TRIGGER IF NOT EXISTS witness_no_delete
BEFORE DELETE ON witness_events
BEGIN SELECT RAISE(ABORT,'witness_events immutable'); END;
SQL);
        } catch (Throwable) {
            throw new RuntimeException('Disposable SQLite witness unavailable.');
        }
    }

    /**
     * Atomic, idempotent append of a synthetic monotonic floor. The event
     * cannot be silently reassigned to another tenant or altered on retry.
     */
    public function publish(string $eventId, LabExternalWitnessFloor $floor): void
    {
        if (preg_match(
            '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',
            $eventId,
        ) !== 1) {
            throw new RuntimeException('Disposable witness event refused.');
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $old = $this->db->prepare('SELECT * FROM witness_events WHERE event_id=?');
                $old->execute([$eventId]);
                $seen = $old->fetch(PDO::FETCH_ASSOC);
                if (is_array($seen)) {
                    if (!$this->matchesRow($seen, $floor)) {
                        throw new RuntimeException('Duplicate witness event changed scope.');
                    }
                    $this->db->exec('COMMIT');
                    return;
                }

                $q = $this->db->prepare(
                    'SELECT * FROM witness_events WHERE tenant=? ORDER BY sequence DESC LIMIT 1'
                );
                $q->execute([$floor->organization->value]);
                $last = $q->fetch(PDO::FETCH_ASSOC);
                if ($last === false && $floor->sequence !== 1) {
                    throw new RuntimeException('Missing witness genesis.');
                }
                if (is_array($last) && (
                    $floor->witnessClusterId !== $last['cluster']
                    || $floor->sequence !== (int) $last['sequence'] + 1
                    || $floor->coveredBackupSequence < (int) $last['covered_backup']
                    || $floor->sessionFloor < (int) $last['session_floor']
                    || $floor->membershipFloor < (int) $last['membership_floor']
                )) {
                    throw new RuntimeException('Witness floor regression.');
                }
                $insert = $this->db->prepare(<<<'SQL'
INSERT INTO witness_events(
    event_id,tenant,cluster,sequence,covered_backup,
    session_floor,membership_floor
) VALUES(?,?,?,?,?,?,?)
SQL);
                $insert->execute([
                    $eventId, $floor->organization->value, $floor->witnessClusterId,
                    $floor->sequence, $floor->coveredBackupSequence,
                    $floor->sessionFloor, $floor->membershipFloor,
                ]);
                $this->db->exec('COMMIT');
            } catch (Throwable) {
                $this->db->exec('ROLLBACK');
                throw new RuntimeException('Disposable witness event unavailable.');
            }
        } catch (Throwable) {
            // No SQL exception or underlying path leaks through this fixture.
            throw new RuntimeException('Disposable witness event unavailable.');
        }
    }

    /**
     * A committed event lookup makes signed proof retries idempotent.
     * This is a TEST-only database lookup, not durable remote evidence.
     */
    public function event(string $eventId, OrganizationId $organization): ?LabExternalWitnessFloor
    {
        try {
            if (preg_match(
                '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',
                $eventId,
            ) !== 1) {
                return null;
            }
            $q = $this->db->prepare(
                'SELECT * FROM witness_events WHERE event_id=? AND tenant=? LIMIT 1'
            );
            $q->execute([$eventId, $organization->value]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            return is_array($row)
                ? new LabExternalWitnessFloor(
                    new OrganizationId($row['tenant']),
                    $row['cluster'], (int) $row['sequence'],
                    (int) $row['covered_backup'], (int) $row['session_floor'],
                    (int) $row['membership_floor'],
                ) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function observe(OrganizationId $organization): ?LabExternalWitnessFloor
    {
        try {
            $q = $this->db->prepare(
                'SELECT * FROM witness_events WHERE tenant=? ORDER BY sequence DESC LIMIT 1'
            );
            $q->execute([$organization->value]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            return is_array($row)
                ? new LabExternalWitnessFloor(
                    new OrganizationId($row['tenant']),
                    $row['cluster'], (int) $row['sequence'],
                    (int) $row['covered_backup'], (int) $row['session_floor'],
                    (int) $row['membership_floor'],
                ) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function matchesRow(array $row, LabExternalWitnessFloor $floor): bool
    {
        return $row['tenant'] === $floor->organization->value
            && $row['cluster'] === $floor->witnessClusterId
            && (int) $row['sequence'] === $floor->sequence
            && (int) $row['covered_backup'] === $floor->coveredBackupSequence
            && (int) $row['session_floor'] === $floor->sessionFloor
            && (int) $row['membership_floor'] === $floor->membershipFloor;
    }
}
