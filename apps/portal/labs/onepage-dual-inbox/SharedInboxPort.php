<?php
declare(strict_types=1);
namespace PrivateOnepageIngress;

/** Candidate only. No connection factory, activation, credentials or installer. */
final readonly class SharedInboxPort implements InboxPort
{
    public function __construct(
        private \PDO $db,
        private \Closure $attest,
        private bool $enabled = false,
    ) {}

    public function captureCommitted(ApprovedCase $case, array $event): string
    {
        if (!$this->enabled) return 'unarmed';
        if (($this->attest)($this->db) !== true || $this->db->inTransaction()
            || $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \RuntimeException('CAPTURE_GATE_REFUSED');
        }
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        if (!$this->db->beginTransaction()) throw new \RuntimeException('BEGIN_REFUSED');
        try {
            $this->db->exec("SET LOCAL lock_timeout='2s'");
            $this->db->exec("SET LOCAL statement_timeout='3s'");
            $q = $this->db->prepare('SELECT onepage_ingress_lab.capture_shared(?,?,?,?,?,?,?,?,?)');
            $q->execute([$case->account, $case->order, $case->payment, $case->revision,
                $case->environment, $event['event_id'], $event['event_type'],
                $event['payment_id'], $event['payload_sha256']]);
            $out = $q->fetchColumn();
            if (!in_array($out, ['recorded','replay','conflict','unarmed'], true)) {
                throw new \RuntimeException('CAPTURE_RESULT_REFUSED');
            }
            if (!$this->db->commit()) throw new \RuntimeException('COMMIT_UNCONFIRMED');
            return $out;
        } catch (\Throwable) {
            try { if ($this->db->inTransaction()) $this->db->rollBack(); } catch (\Throwable) {}
            throw new \RuntimeException('CAPTURE_UNCONFIRMED');
        }
    }
}
