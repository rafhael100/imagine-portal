<?php

declare(strict_types=1);

namespace App\Labs\F40;

use PDO;
use Throwable;

/**
 * F40: scan synthetic F39 outbox as TWO isolated fake SQL relay roles,
 * call an in-memory sink (NO external I/O), record a lab-only SQL receipt.
 *
 * Only the owner of the runner can supply these fake credentials and calls.
 * A successful receipt is not delivery evidence, an authorization decision,
 * external acknowledgement, or exactly-once processing.
 */
final readonly class F40SyntheticOutboxRelay
{
    private const MAX_PER_TENANT = 10;

    public function __construct(
        private PDO $tenantA,
        private PDO $tenantB,
        private F40InMemoryFakeSink $sink,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F38_ENABLE_LAB') !== 'SYNTHETIC_REQUEST_WRITE_ONLY'
            || getenv('F39_ENABLE_LAB') !== 'TRANSACTIONAL_OUTBOX_CI_ONLY'
            || getenv('F40_ENABLE_LAB') !== 'IN_MEMORY_FAKE_RELAY_ONLY') {
            throw new \LogicException('F40 cannot run outside disposable GitHub CI fixtures.');
        }
        foreach ([$tenantA,$tenantB] as $pdo) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
                throw new \InvalidArgumentException('F40 requires transient PostgreSQL 18 sessions.');
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        }
    }

    public function runOnce(): F40FakeRelayOutcome
    {
        $discovered=0;
        $recorded=0;
        $more=false;

        foreach ([
            ['org-a','f40_relay_a',$this->tenantA],
            ['org-b','f40_relay_b',$this->tenantB],
        ] as [$organization,$role,$pdo]) {
            $batch=$this->readPending($pdo,$organization,$role);
            if ($batch===null) {
                return new F40FakeRelayOutcome(
                    false,'source_unavailable',$discovered,$recorded,
                );
            }
            $discovered+=count($batch['rows']);
            $more=$more || $batch['more'];

            foreach ($batch['rows'] as $event) {
                if (!$this->sink->simulate($event['reference'],$event['payload'])) {
                    return new F40FakeRelayOutcome(
                        false,'sink_unavailable',$discovered,$recorded,
                    );
                }
                if (!$this->recordFakeReceipt($pdo,$event)) {
                    return new F40FakeRelayOutcome(
                        false,'ack_pending',$discovered,$recorded,
                    );
                }
                $recorded++;
            }
        }

        return new F40FakeRelayOutcome(
            !$more, $more?'bounded_backpressure':'complete',
            $discovered,$recorded,
        );
    }

    /**
     * @return array{rows:list<array{org:string,command:string,reference:string,payload:string}>,more:bool}|null
     */
    private function readPending(PDO $pdo,string $org,string $expectedRole): ?array
    {
        if ($pdo->inTransaction()) {
            return null;
        }

        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout='2000ms'");
            $auth=$pdo->query(
                "SELECT session_user AS login_role,current_user AS active_role,
                        (SELECT NOT rolsuper AND NOT rolbypassrls
                                    AND NOT rolinherit AND rolcanlogin
                           FROM pg_catalog.pg_roles WHERE rolname=current_user)
                         AS restricted"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($auth)
                || $auth['login_role']!==$expectedRole
                || $auth['active_role']!==$expectedRole
                || !$this->pgTrue($auth['restricted'] ?? null)) {
                $pdo->rollBack();
                return null;
            }

            // Negative drift test, not comprehensive policy attestation:
            // reject disabled RLS/extra policies before fake relay.
            $security=$pdo->query(
                "SELECT n.nspname,c.relname,c.relrowsecurity AS enabled,
                        c.relforcerowsecurity AS forced,
                        (SELECT count(*)::int FROM pg_catalog.pg_policy p
                           WHERE p.polrelid=c.oid) AS policy_count
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE (n.nspname='f39'
                          AND c.relname='synthetic_request_outbox'
                          AND c.relkind='r')
                     OR (n.nspname='f40'
                          AND c.relname='fake_delivery_receipt'
                          AND c.relkind='r')"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($security) || count($security)!==2) {
                $pdo->rollBack();
                return null;
            }
            $seen=[];
            foreach ($security as $row) {
                $name=($row['nspname'] ?? '').'.'.($row['relname'] ?? '');
                $expectedCount=match($name) {
                    'f39.synthetic_request_outbox'=>4,
                    'f40.fake_delivery_receipt'=>2,
                    default=>null,
                };
                if ($expectedCount===null || isset($seen[$name])
                    || !$this->pgTrue($row['enabled'] ?? null)
                    || !$this->pgTrue($row['forced'] ?? null)
                    || (int)($row['policy_count'] ?? -1)!==$expectedCount) {
                    $pdo->rollBack();
                    return null;
                }
                $seen[$name]=true;
            }

            $query=$pdo->prepare(
                'SELECT e.organization_id,e.command_id,e.outbox_reference,
                        e.event_type,e.payload::text AS serialized
                   FROM f39.synthetic_request_outbox e
                   LEFT JOIN f40.fake_delivery_receipt a
                     ON a.organization_id=e.organization_id
                    AND a.command_id=e.command_id
                  WHERE e.organization_id=:org AND a.command_id IS NULL
                  ORDER BY e.command_id
                  LIMIT 11'
            );
            $query->execute(['org'=>$org]);
            $rows=$query->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                $pdo->rollBack();
                return null;
            }

            $more=count($rows)>self::MAX_PER_TENANT;
            $rows=array_slice($rows,0,self::MAX_PER_TENANT);
            $result=[];
            foreach ($rows as $row) {
                $cmd=$row['command_id'] ?? null;
                $ref=$row['outbox_reference'] ?? null;
                $payload=$row['serialized'] ?? null;
                if (($row['organization_id'] ?? null)!==$org
                    || !is_string($cmd)
                    || preg_match('/\ADEMO-CMD-[A-Z0-9-]{1,64}\z/D',$cmd)!==1
                    || $ref!=='F39-'.$cmd
                    || $row['event_type']!=='service_request.status_changed.demo'
                    || !is_string($payload)
                    || strlen($payload)>4096) {
                    $pdo->rollBack();
                    return null;
                }
                $decoded=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($decoded)
                    || count($decoded)!==9
                    || ($decoded['schema'] ?? null)!=='f39.demo.v1'
                    || ($decoded['organization_id'] ?? null)!==$org
                    || ($decoded['command_id'] ?? null)!==$cmd
                    || !is_string($decoded['request_id'] ?? null)
                    || !is_string($decoded['service_reference'] ?? null)
                    || !is_string($decoded['action'] ?? null)
                    || !is_string($decoded['from_status'] ?? null)
                    || !is_string($decoded['to_status'] ?? null)
                    || !is_int($decoded['revision'] ?? null)) {
                    $pdo->rollBack();
                    return null;
                }
                $allowed=[
                    'schema','organization_id','request_id',
                    'service_reference','command_id','action',
                    'from_status','to_status','revision',
                ];
                $keys=array_keys($decoded);
                sort($keys);sort($allowed);
                if ($keys!==$allowed) {
                    $pdo->rollBack();
                    return null;
                }

                $result[]=[
                    'org'=>$org,
                    'command'=>$cmd,
                    'reference'=>$ref,
                    'payload'=>$payload,
                ];
            }
            $pdo->commit();
            return ['rows'=>$result,'more'=>$more];
        } catch (Throwable) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // A disconnected or invalid cursor is never an empty queue.
            }
            return null;
        }
    }

    /** @param array{org:string,command:string,reference:string,payload:string} $event */
    private function recordFakeReceipt(PDO $pdo,array $event): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }
        try {
            $stmt=$pdo->prepare(
                'SELECT f40.record_fake_delivery(
                    :org,:cmd,:reference,:sha,:simulated_at
                 ) AS result'
            );
            $stmt->execute([
                'org'=>$event['org'],
                'cmd'=>$event['command'],
                'reference'=>$event['reference'],
                'sha'=>hash('sha256',$event['payload']),
                'simulated_at'=>1800000300,
            ]);
            return in_array($stmt->fetchColumn(),['recorded','already_recorded'],true);
        } catch (Throwable) {
            return false;
        }
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value,[true,'t','true',1,'1'],true);
    }
}
