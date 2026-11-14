<?php
declare(strict_types=1);

namespace App\Homologation;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Pure CI candidate for Asaas webhook ingress. NO ROUTE, NO API KEY, NO PSP
 * RECONCILIATION, NO CUSTOMER SESSION, NO PAYMENT/SERVICE AUTHORISATION.
 * Store only redacted identifiers and body digest; never a full webhook body.
 */
final class AsaasWebhookInboxCandidate
{
    public function __construct(
        private PDO $db,
        #[\SensitiveParameter] private string $authToken,
        private string $expectedAccountId
    ) {
        if (strlen($authToken) < 32 || strlen($authToken) > 255 ||
            preg_match('/\s/', $authToken) ||
            !preg_match('/\A[a-zA-Z0-9-]{5,128}\z/D', $expectedAccountId)) {
            throw new InvalidArgumentException('Invalid isolated webhook configuration');
        }
    }

    /** Returns recorded|duplicate only after the database transaction commits. */
    public function receive(?string $headerToken, string $body): string
    {
        if ($headerToken === null || !hash_equals($this->authToken, $headerToken)) {
            throw new InvalidArgumentException('Unauthenticated webhook');
        }
        if (strlen($body) < 2 || strlen($body) > 65536) {
            throw new InvalidArgumentException('Invalid webhook body size');
        }
        try {
            $data=json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid webhook JSON');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Invalid webhook object');
        }
        $eventId=$data['id']??null;
        $eventType=$data['event']??null;
        $accountId=$data['account']['id']??null;
        if (!is_string($eventId) ||
            !preg_match('/\A[\x21-\x7e]{1,160}\z/D', $eventId) ||
            !is_string($eventType) ||
            !preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/D', $eventType) ||
            !is_string($accountId) ||
            !hash_equals($this->expectedAccountId, $accountId)) {
            throw new InvalidArgumentException('Invalid account or event identity');
        }
        $paymentId=$data['payment']['id']??null;
        if (str_starts_with($eventType, 'PAYMENT_')) {
            if (!is_string($paymentId) ||
                !preg_match('/\A[\x21-\x7e]{1,128}\z/D', $paymentId)) {
                throw new InvalidArgumentException('Missing payment identity');
            }
        } else {
            $paymentId=null; // unknown categories never become payment evidence
        }
        $digest=hash('sha256', $body);
        $this->db->beginTransaction();
        try {
            $insert=$this->db->prepare(
                "INSERT INTO portal_asaas_ci.webhook_inbox(event_id,event_type,account_id,payment_id,payload_sha256,review_state)
                 VALUES(?,?,?,?,?,'pending_external_reconciliation') ON CONFLICT(event_id) DO NOTHING"
            );
            $insert->execute([$eventId,$eventType,$accountId,$paymentId,$digest]);
            $created=$insert->rowCount()===1;
            $select=$this->db->prepare(
                'SELECT payload_sha256,account_id,event_type,payment_id,review_state FROM portal_asaas_ci.webhook_inbox WHERE event_id=?'
            );
            $select->execute([$eventId]);
            $existing=$select->fetch(PDO::FETCH_ASSOC);
            if (!$existing || !hash_equals($existing['payload_sha256'],$digest) ||
                !hash_equals($existing['account_id'],$accountId) ||
                $existing['event_type']!==$eventType ||
                $existing['payment_id']!==$paymentId ||
                $existing['review_state']!=='pending_external_reconciliation') {
                throw new RuntimeException('Conflicting event replay or changed review state');
            }
            $this->db->commit();
            return $created?'recorded':'duplicate';
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Explicit negative capability until an independently reviewed PSP verifier exists. */
    public function approvesPayment(): bool { return false; }
    public function activatesService(): bool { return false; }
}
