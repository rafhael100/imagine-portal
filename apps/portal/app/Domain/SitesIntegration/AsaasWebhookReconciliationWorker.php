<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * F1-392 independent claim -> official-settlement observation -> leased ACK.
 *
 * No PSP webhook can authorize provision, contract, cash movement or a Site.
 * "observed_psp_received" is an audit observation, NOT an entitlement.
 * All future issuance MUST re-query the official PSP and revocation ledger.
 *
 * The account/payment lookup MUST be derived from a trusted tenant-scoped
 * immutable SQL binding; no user/webhook-created financial bindings allowed.
 */
final readonly class AsaasWebhookReconciliationWorker
{
    public function __construct(
        private PDO $db,
        private string $accountRef,
        private AsaasTrustedEventPaymentSource $bindings = new DenyingAsaasEventPaymentSource(),
        private PortalCurrentSettlementSource $officialSettlement = new DenyingPortalSettlementSource(),
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',$accountRef)!==1) {
            throw new DomainException('PSP reconciliation identity unavailable.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        try {
            $q=$db->query(
                "SELECT r.rolsuper,r.rolbypassrls,
                        c.relrowsecurity,c.relforcerowsecurity
                   FROM pg_roles r
                   JOIN pg_class c ON c.oid='portal_psp.asaas_inbox'::regclass
                  WHERE r.rolname=session_user AND current_user=session_user
                    AND session_user='asaas_reconcile_worker'"
            );
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) ||
                self::pgFlag($row['rolsuper']??null) ||
                self::pgFlag($row['rolbypassrls']??null) ||
                !self::pgFlag($row['relrowsecurity']??null) ||
                !self::pgFlag($row['relforcerowsecurity']??null)) {
                throw new DomainException('Restricted reconciler SQL login required.');
            }
        } catch (Throwable) {
            throw new DomainException('Restricted reconciler SQL login required.');
        }
    }

    /** @return array{processed:bool,outcome:string} Safe status only */
    public function processOne(): array
    {
        $lease=$this->claimNext();
        if ($lease===null) {
            return ['processed'=>false,'outcome'=>'empty'];
        }
        $outcome='retry_wait'; // never optimistically mark a payment paid
        try {
            $type=$lease['event_type'];
            if (in_array($type,[
                'PAYMENT_REFUNDED','PAYMENT_PARTIALLY_REFUNDED',
                'PAYMENT_REFUND_IN_PROGRESS','PAYMENT_CHARGEBACK_REQUESTED',
                'PAYMENT_CHARGEBACK_DISPUTE',
                'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
                'PAYMENT_DELETED','PAYMENT_RECEIVED_IN_CASH_UNDONE',
            ],true)) {
                // Escalate to human / external revocation worker. Never allow
                // a signed payment proof merely from a preceding RECEIVED.
                $outcome='manual_review';
            } elseif ($type==='PAYMENT_RECEIVED') {
                $bound=$this->bindings->findInstallationByAccountAndPaymentId(
                    $this->accountRef,$lease['payment_id']
                );
                if ($bound instanceof AsaasTrustedPaymentBinding &&
                    $bound->paymentId===$lease['payment_id'] &&
                    $bound->purpose==='installation') {
                    // This call MUST use AsaasVerifiedSettlementSource backed by
                    // a NEW official GET; default source returns null.
                    $proof=$this->officialSettlement->findCurrentSettledOrder(
                        $bound->orderRef
                    );
                    if ($proof instanceof PortalSettlementEvidence &&
                        $proof->tenantRef===$bound->tenantRef &&
                        $proof->orderRef===$bound->orderRef &&
                        $proof->contractRef===$bound->contractRef &&
                        $proof->contractRevision===$bound->contractRevision &&
                        $proof->productCode===$bound->productCode &&
                        $proof->settlementRef===$bound->paymentId &&
                        preg_match('/\A[a-f0-9]{64}\z/D',
                            $proof->settlementSha256)===1) {
                        $outcome='observed_psp_received';
                    }
                }
            }
        } catch (Throwable) {
            // Outage, stale binding, PSP response drift: leave retry/deny.
            $outcome='retry_wait';
        }
        if ($outcome==='retry_wait' && $lease['attempts']>=8) {
            $outcome='manual_review';
        }
        $this->finishWithLease($lease,$outcome);
        return ['processed'=>true,'outcome'=>$outcome];
    }

    /** @return array{event_id:string,event_type:string,payment_id:string,attempts:int,lease_token:string}|null */
    private function claimNext(): ?array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Reconciler requires independent SQL transaction.');
        }
        $uuid=self::randomUuid();
        try {
            $this->db->beginTransaction();
            $q=$this->db->prepare(
                "WITH pick AS (
                    SELECT account_ref,event_id
                      FROM portal_psp.asaas_inbox
                     WHERE account_ref=CAST(? AS uuid)
                       AND attempts<8
                       AND (
                          state='pending_reconciliation'
                          OR (state='retry_wait' AND retry_after<=clock_timestamp())
                          OR (state='claimed' AND lease_expires_at<clock_timestamp())
                       )
                     ORDER BY received_at,event_id
                     FOR UPDATE SKIP LOCKED
                     LIMIT 1
                  )
                  UPDATE portal_psp.asaas_inbox i
                     SET state='claimed',attempts=i.attempts+1,
                         lease_token=CAST(? AS uuid),
                         lease_expires_at=clock_timestamp()+INTERVAL '45 seconds',
                         retry_after=NULL
                    FROM pick
                   WHERE i.account_ref=pick.account_ref
                     AND i.event_id=pick.event_id
                   RETURNING i.event_id,i.event_type,i.payment_id,
                             i.attempts,i.lease_token::text AS lease_token"
            );
            $q->execute([$this->accountRef,$uuid]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            if ($row===false) return null;
            if (!is_array($row) ||
                ($row['lease_token']??null)!==$uuid ||
                !is_numeric($row['attempts']??null)) {
                throw new RuntimeException('Unexpected claim result.');
            }
            return [
                'event_id'=>(string)$row['event_id'],
                'event_type'=>(string)$row['event_type'],
                'payment_id'=>(string)$row['payment_id'],
                'attempts'=>(int)$row['attempts'],
                'lease_token'=>$uuid,
            ];
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw new RuntimeException('Webhook event claim unavailable.');
        }
    }

    /** @param array{event_id:string,event_type:string,payment_id:string,attempts:int,lease_token:string} $lease */
    private function finishWithLease(array $lease,string $outcome): void
    {
        if (!in_array($outcome,[
            'retry_wait','manual_review','observed_psp_received',
        ],true)) {
            throw new RuntimeException('Unexpected reconciliation outcome.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Reconciler ACK requires a new transaction.');
        }
        try {
            $this->db->beginTransaction();
            $q=$this->db->prepare(
                "UPDATE portal_psp.asaas_inbox
                    SET state=?,
                        lease_token=NULL,
                        lease_expires_at=NULL,
                        retry_after=CASE WHEN ?='retry_wait'
                          THEN clock_timestamp()+INTERVAL '120 seconds'
                          ELSE NULL END,
                        last_checked_at=clock_timestamp()
                  WHERE account_ref=CAST(? AS uuid)
                    AND event_id=?
                    AND state='claimed'
                    AND lease_token=CAST(? AS uuid)
                    AND lease_expires_at>clock_timestamp()"
            );
            $q->execute([
                $outcome,$outcome,$this->accountRef,$lease['event_id'],$lease['lease_token']
            ]);
            if ($q->rowCount()!==1) {
                throw new RuntimeException('Stale or lost webhook lease.');
            }
            $this->db->commit();
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            // Lease is now owned by timeout recovery; do not falsely ACK.
            throw new RuntimeException('Webhook ACK unavailable; lease must recover.');
        }
    }

    private static function randomUuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $s=bin2hex($bytes);
        return substr($s,0,8).'-'.substr($s,8,4).'-'.
            substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20,12);
    }

    private static function pgFlag(mixed $flag): bool
    {
        return in_array($flag,[true,'t',1,'1'],true);
    }
}
