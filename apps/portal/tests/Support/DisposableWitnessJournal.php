<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabExternalWitnessReader;
use RuntimeException;
use Throwable;

/**
 * Disposable test-only append journal. It is NOT a production witness:
 * its key and file can be restored together, and no serving path uses it.
 */
final class DisposableWitnessJournal implements LabExternalWitnessReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $key,
    ) {
        if (strlen($key) !== 32) {
            throw new RuntimeException('Invalid disposable witness key.');
        }
    }

    public function append(LabExternalWitnessFloor $floor): void
    {
        $handle = @fopen($this->path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Disposable witness unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Disposable witness lock unavailable.');
            }
            $records = $this->read($handle);
            $previous = $records === [] ? null : $records[array_key_last($records)];
            if ($previous === null) {
                if ($floor->sequence !== 1) {
                    throw new RuntimeException('Witness genesis sequence missing.');
                }
            } elseif (!$floor->organization->equals($previous['floor']->organization)
                || $floor->witnessClusterId !== $previous['floor']->witnessClusterId
                || $floor->sequence !== $previous['floor']->sequence + 1
                || $floor->coveredBackupSequence < $previous['floor']->coveredBackupSequence
                || $floor->sessionFloor < $previous['floor']->sessionFloor
                || $floor->membershipFloor < $previous['floor']->membershipFloor) {
                throw new RuntimeException('Witness monotonicity violation.');
            }
            $prevHash = $previous['mac'] ?? str_repeat('0', 64);
            $row = [
                'tenant' => $floor->organization->value,
                'cluster' => $floor->witnessClusterId,
                'sequence' => $floor->sequence,
                'coveredBackupSequence' => $floor->coveredBackupSequence,
                'sessionFloor' => $floor->sessionFloor,
                'membershipFloor' => $floor->membershipFloor,
                'previous' => $prevHash,
            ];
            $row['mac'] = hash_hmac('sha256', json_encode($row, JSON_THROW_ON_ERROR), $this->key);
            $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $line) !== strlen($line)
                || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Witness journal write failed.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function observe(OrganizationId $organization): ?LabExternalWitnessFloor
    {
        // An absent witness is a normal fail-closed test case, not an I/O
        // warning. Do not let phpunit mask a missing authority as a warning.
        if (!is_file($this->path)) {
            return null;
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $records = $this->read($handle);
            $last = $records === [] ? null : $records[array_key_last($records)];
            return $last !== null && $last['floor']->organization->equals($organization)
                ? $last['floor'] : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<array{floor:LabExternalWitnessFloor,mac:string}> */
    private function read(mixed $handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle, 131073);
        if (!is_string($contents) || strlen($contents) > 131072
            || ($contents !== '' && !str_ends_with($contents, "\n"))) {
            throw new RuntimeException('Witness journal damaged.');
        }
        if ($contents === '') {
            return [];
        }
        $lines = explode("\n", substr($contents, 0, -1));
        $records = [];
        $previousHash = str_repeat('0', 64);
        $previousFloor = null;
        foreach ($lines as $line) {
            try {
                $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($row)
                    || array_keys($row) !== [
                        'tenant', 'cluster', 'sequence', 'coveredBackupSequence',
                        'sessionFloor', 'membershipFloor', 'previous', 'mac',
                    ] || !is_string($row['previous']) || !is_string($row['mac'])
                    || !hash_equals($previousHash, $row['previous'])
                    || !hash_equals(
                        hash_hmac('sha256', json_encode(array_slice($row, 0, 7, true), JSON_THROW_ON_ERROR), $this->key),
                        $row['mac']
                    )) {
                    throw new RuntimeException('Witness journal integrity failed.');
                }
                $floor = new LabExternalWitnessFloor(
                    new OrganizationId($row['tenant']),
                    $row['cluster'],
                    $row['sequence'],
                    $row['coveredBackupSequence'],
                    $row['sessionFloor'],
                    $row['membershipFloor']
                );
                if (($previousFloor === null && $floor->sequence !== 1)
                    || ($previousFloor !== null
                        && (!$floor->organization->equals($previousFloor->organization)
                            || $floor->witnessClusterId !== $previousFloor->witnessClusterId
                            || $floor->sequence !== $previousFloor->sequence + 1
                            || $floor->coveredBackupSequence < $previousFloor->coveredBackupSequence
                            || $floor->sessionFloor < $previousFloor->sessionFloor
                            || $floor->membershipFloor < $previousFloor->membershipFloor))) {
                    throw new RuntimeException('Witness journal monotonicity failed.');
                }
                $records[] = ['floor' => $floor, 'mac' => $row['mac']];
                $previousFloor = $floor;
                $previousHash = $row['mac'];
            } catch (Throwable) {
                throw new RuntimeException('Witness journal invalid.');
            }
        }
        return $records;
    }
}
