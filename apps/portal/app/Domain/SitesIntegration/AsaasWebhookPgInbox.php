<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Durable, authenticated Asaas webhook INBOX (not a settlement authority).
 *
 * Pure service, not bound to a route/provider. Never creates charges,
 * activates a Site, signs an entitlement or trusts PAYMENT_RECEIVED as
 * proof of independent settlement. A separate worker must re-query PSP.
 *
 * PostgreSQL must grant this role only INSERT + SELECT on the private
 * scoped inbox, with FORCE RLS. All values use parameterized statements.
 */
final readonly class AsaasWebhookPgInbox
{
    public function __construct(
        private PDO $db,
        private string $providerAccountRef,
        private string $webhookAuthToken,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            strlen($webhookAuthToken)<32 ||
            strlen($webhookAuthToken)>255 ||
            preg_match('/[\x00-\x20\x7f]/',$webhookAuthToken)===1 ||
            preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',$providerAccountRef)!==1) {
            throw new DomainException('PSP webhook identity unavailable.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);

        // Reject DB-owner/superuser sessions and any table that has not
        // enforced row-level security. No 'trust me' from the HTTP request.
        $q=$db->query(
            "SELECT rol.rolsuper, rol.rolbypassrls, r.relrowsecurity, r.relforcerowsecurity
             FROM pg_roles rol JOIN pg_class r ON r.oid='portal_psp.asaas_inbox'::regclass
             WHERE rol.rolname=session_user AND current_user=session_user"
        );
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) ||
            self::pgFlag($row['rolsuper']??null) ||
            self::pgFlag($row['rolbypassrls']??null) ||
            !self::pgFlag($row['relrowsecurity']??null) ||
            !self::pgFlag($row['relforcerowsecurity']??null)) {
            throw new DomainException('Restricted forced-RLS webhook database login required.');
        }
    }

    /** @return array{received: true, duplicate: bool} */
    public function capture(string $httpMethod,string $contentType,
                            string $providedToken,string $rawBody):array
    {
        if ($httpMethod!=='POST' ||
            !hash_equals($this->webhookAuthToken,$providedToken) ||
            !preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/iD',trim($contentType)) ||
            strlen($rawBody)<2 || strlen($rawBody)>32768) {
            throw new DomainException('Webhook transport not accepted.');
        }
        try {
            $event=json_decode($rawBody,true,12,JSON_THROW_ON_ERROR);
            if (!is_array($event) || array_is_list($event) ||
                !is_string($event['id']??null) ||
                preg_match('/\Aevt_[A-Za-z0-9_&-]{8,128}\z/D',$event['id'])!==1 ||
                !is_string($event['event']??null) ||
                preg_match('/\APAYMENT_[A-Z_]{3,64}\z/D',$event['event'])!==1 ||
                (!is_array($event['account']??null) ||
                 array_is_list($event['account']) ||
                 ($event['account']['id']??null)!==$this->providerAccountRef) ||
                !is_array($event['payment']??null) ||
                array_is_list($event['payment']) ||
                !is_string($event['payment']['id']??null) ||
                preg_match('/\Apay_[A-Za-z0-9_]{6,64}\z/D',$event['payment']['id'])!==1) {
                throw new DomainException('Webhook event shape not accepted.');
            }
            // Asaas V3 includes account.id in the event root. Never accept
            // another account under a valid-but-misrouted webhook token.
            $digest=hash('sha256',$rawBody);
            if ($this->db->inTransaction()) {
                throw new RuntimeException('Webhook capture requires an independent transaction.');
            }
            $this->db->beginTransaction();
            try {
                $statement=$this->db->prepare(
                    'INSERT INTO portal_psp.asaas_inbox
                      (account_ref,event_id,event_type,payment_id,payload_sha256)
                     VALUES (?,?,?,?,?) ON CONFLICT (account_ref,event_id) DO NOTHING'
                );
                $statement->execute([
                    $this->providerAccountRef,$event['id'],$event['event'],
                    $event['payment']['id'],$digest,
                ]);
                $new=$statement->rowCount()===1;
                if (!$new) {
                    $get=$this->db->prepare(
                        'SELECT event_type,payment_id,payload_sha256
                           FROM portal_psp.asaas_inbox
                          WHERE account_ref=? AND event_id=?'
                    );
                    $get->execute([$this->providerAccountRef,$event['id']]);
                    $saved=$get->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($saved) ||
                        $saved['event_type']!==$event['event'] ||
                        $saved['payment_id']!==$event['payment']['id'] ||
                        !hash_equals((string)$saved['payload_sha256'],$digest)) {
                        throw new DomainException('Conflicting duplicate webhook ID.');
                    }
                }
                // Acknowledge ONLY after transaction COMMIT; even a perfectly
                // authenticated PAYMENT_RECEIVED remains pending reconciliation.
                $this->db->commit();
                return ['received'=>true,'duplicate'=>!$new];
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        } catch (Throwable) {
            // Deliberately do not expose input JSON, event IDs, token,
            // secret, SQL query text or PSP account data in errors.
            throw new DomainException('Webhook not persisted.');
        }
    }

    private static function pgFlag(mixed $flag):bool
    {
        return in_array($flag,[true,'t',1,'1'],true);
    }
}
