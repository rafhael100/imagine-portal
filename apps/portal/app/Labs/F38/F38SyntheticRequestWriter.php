<?php

declare(strict_types=1);

namespace App\Labs\F38;

use App\Domain\ServiceRequests\DemoRequestCommand;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use App\Domain\ServiceRequests\ServiceRequestWorkflowPreview;
use PDO;
use Throwable;

/**
 * F38: one tenant-bound, transactionally idempotent PG18 WRITE rehearsal.
 *
 * Has no route, controller, auth provider or customer credentials. ActorKind
 * is still synthetic/untrusted, so a successful fake DB write is NOT a
 * production authorization. NEVER deploy this adapter.
 */
final readonly class F38SyntheticRequestWriter
{
    public function __construct(
        private PDO $pdo,
        private ServiceRequestWorkflowPreview $workflow = new ServiceRequestWorkflowPreview(),
    ) {
        if (getenv('F38_ENABLE_LAB') !== 'SYNTHETIC_REQUEST_WRITE_ONLY'
            || getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \LogicException('F38 accepts only ephemeral PG18 synthetic writes.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    public function apply(DemoRequestCommand $command): F38SyntheticWriteResult
    {
        if ($this->pdo->inTransaction()) {
            return F38SyntheticWriteResult::blocked('source_unavailable');
        }
        if (!in_array($command->organizationId->value,['org-a','org-b'],true)
            || $command->occurredAt < 1800000000
            || $command->occurredAt > 1800003600
            || !str_starts_with($command->requestId,'DEMO-REQUEST-')) {
            return F38SyntheticWriteResult::blocked('missing_synthetic_request');
        }

        try {
            $this->pdo->beginTransaction();
            // READ COMMITTED and FOR UPDATE serialize writers for the
            // same synthetic request. No pseudo-authority is inferred.
            $this->pdo->exec("SET LOCAL lock_timeout='400ms'");
            $this->pdo->exec("SET LOCAL statement_timeout='2000ms'");

            $expectedRole = $command->organizationId->value === 'org-a'
                ? 'f38_writer_a' : 'f38_writer_b';
            $identity = $this->pdo->query(
                "SELECT session_user AS login_role,current_user AS active_role,
                        (SELECT NOT rolsuper AND NOT rolbypassrls AND
                                      NOT rolinherit AND rolcanlogin
                           FROM pg_catalog.pg_roles WHERE rolname=current_user)
                         AS restricted"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['login_role'] !== $expectedRole
                || $identity['active_role'] !== $expectedRole
                || !$this->pgTrue($identity['restricted'] ?? null)) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('untrusted_db_role');
            }

            $meta = $this->pdo->query(
                "SELECT c.relname, c.relrowsecurity AS enabled,
                        c.relforcerowsecurity AS forced
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f38'
                    AND c.relname IN ('service_request','command_receipt')
                    AND c.relkind='r'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($meta) || count($meta)!==2) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('unsafe_synthetic_schema');
            }
            $names=[];
            foreach ($meta as $object) {
                if (!in_array($object['relname'] ?? null,
                        ['service_request','command_receipt'],true)
                    || isset($names[$object['relname']])
                    || !$this->pgTrue($object['enabled'] ?? null)
                    || !$this->pgTrue($object['forced'] ?? null)) {
                    $this->pdo->rollBack();
                    return F38SyntheticWriteResult::blocked('unsafe_synthetic_schema');
                }
                $names[$object['relname']]=true;
            }

            $stmt = $this->pdo->prepare(
                'SELECT request_id,organization_id,service_reference,
                        current_status,revision
                   FROM f38.service_request
                  WHERE organization_id=:org AND request_id=:request
                  FOR UPDATE'
            );
            $stmt->execute([
                'org'=>$command->organizationId->value,
                'request'=>$command->requestId,
            ]);
            $request=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request)) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('missing_synthetic_request');
            }

            // A repeated command must be considered BEFORE stale revision,
            // so network retries preserve the ORIGINAL committed response.
            $receiptQuery=$this->pdo->prepare(
                'SELECT request_id,payload_fingerprint,result_status,result_revision
                   FROM f38.command_receipt
                  WHERE organization_id=:org AND command_id=:command
                  LIMIT 2'
            );
            $receiptQuery->execute([
                'org'=>$command->organizationId->value,
                'command'=>$command->commandId,
            ]);
            $receipts=$receiptQuery->fetchAll(PDO::FETCH_ASSOC);
            if (count($receipts)>1) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('unsafe_synthetic_schema');
            }
            if ($receipts!==[]) {
                $row=$receipts[0];
                if ($row['request_id'] !== $command->requestId
                    || !hash_equals($row['payload_fingerprint'],$command->fingerprint())) {
                    $this->pdo->rollBack();
                    return F38SyntheticWriteResult::blocked('idempotency_conflict');
                }
                $status=ServiceRequestStatus::tryFrom($row['result_status']);
                if ($status===null || !is_numeric($row['result_revision'])) {
                    $this->pdo->rollBack();
                    return F38SyntheticWriteResult::blocked('unsafe_synthetic_schema');
                }
                $original=new ServiceRequestSnapshot(
                    $command->requestId,
                    $command->organizationId,
                    $request['service_reference'],
                    $status,
                    (int)$row['result_revision'],
                );
                $this->pdo->commit();
                return new F38SyntheticWriteResult('replayed_synthetic_sql',$original);
            }

            if ((int)$request['revision'] !== $command->expectedRevision) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('stale_revision');
            }
            $status=ServiceRequestStatus::tryFrom($request['current_status']);
            if ($status===null) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('unsafe_synthetic_schema');
            }
            $current=new ServiceRequestSnapshot(
                $command->requestId,
                $command->organizationId,
                $request['service_reference'],
                $status,
                (int)$request['revision'],
            );
            $preview=$this->workflow->evaluate(
                $current,$command->organizationId,$command->actorKind,
                $command->action,$command->expectedRevision,
            );
            if (!$preview->isValidBusinessTransition()
                || $preview->isExecutable()
                || $preview->candidate===null) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('transition_not_permitted');
            }

            $next=$preview->candidate;
            $update=$this->pdo->prepare(
                'UPDATE f38.service_request
                    SET current_status=:status,revision=:new_rev,updated_at=:at
                  WHERE organization_id=:org AND request_id=:request
                    AND revision=:old_rev
                  RETURNING revision'
            );
            $update->execute([
                'status'=>$next->status->value,
                'new_rev'=>$next->revision,
                'at'=>$command->occurredAt,
                'org'=>$command->organizationId->value,
                'request'=>$command->requestId,
                'old_rev'=>$current->revision,
            ]);
            $modified=$update->fetchAll(PDO::FETCH_ASSOC);
            if (count($modified)!==1
                || (int)$modified[0]['revision']!==$next->revision) {
                $this->pdo->rollBack();
                return F38SyntheticWriteResult::blocked('stale_revision');
            }

            $insert=$this->pdo->prepare(
                'INSERT INTO f38.command_receipt
                 (organization_id,request_id,command_id,payload_fingerprint,
                  action,actor_kind,previous_revision,result_revision,
                  result_status,occurred_at)
                 VALUES
                 (:org,:request,:command,:fingerprint,:action,:actor,
                  :previous,:revision,:status,:at)'
            );
            $insert->execute([
                'org'=>$command->organizationId->value,
                'request'=>$command->requestId,
                'command'=>$command->commandId,
                'fingerprint'=>$command->fingerprint(),
                'action'=>$command->action->value,
                'actor'=>$command->actorKind->value,
                'previous'=>$current->revision,
                'revision'=>$next->revision,
                'status'=>$next->status->value,
                'at'=>$command->occurredAt,
            ]);
            $this->pdo->commit();
            return new F38SyntheticWriteResult('applied_synthetic_sql',$next);
        } catch (Throwable) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // DB connection failure is denied, never retried as admin.
            }
            return F38SyntheticWriteResult::blocked('source_unavailable');
        }
    }

    private function pgTrue(mixed $val): bool
    {
        return in_array($val,[true,'t','true',1,'1'],true);
    }
}
