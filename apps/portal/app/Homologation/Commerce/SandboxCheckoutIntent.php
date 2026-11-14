<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;
use PDO;
use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;

/** Queues only synthetic Asaas sandbox work. Browser never controls the amount. */
final class SandboxCheckoutIntent
{
    public function __construct(private PDO $db) { new \App\Homologation\RoleBoundTestPurchase($db); }
    public function request(string $owner,string $id,int $revision): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock(724114)');
            $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE id=? AND customer_id=? FOR UPDATE'); $q->execute([$id,$owner]); $o=$q->fetch(PDO::FETCH_ASSOC);
            if (!$o||(int)$o['revision']!==$revision||$o['briefing']!==null) throw new RuntimeException('Order unavailable');
            $q=$this->db->prepare('SELECT order_id FROM purchase_rehearsal.sandbox_payment_intents WHERE order_id=?');$q->execute([$id]);
            if ($q->fetchColumn()!==false) {
                $q=$this->db->prepare('UPDATE purchase_rehearsal.sandbox_payment_intents SET sync_requested=true WHERE order_id=?');$q->execute([$id]);
                $this->db->commit();return;
            }
            if ($o['payment']!=='pending'||$o['payment_source']!=='manual_test'||$o['offer_snapshot']===null) throw new RuntimeException('Payment source conflict');
            if ((int)$this->db->query('SELECT count(*) FROM purchase_rehearsal.sandbox_payment_intents')->fetchColumn()>=50) throw new RuntimeException('Sandbox quota');
            $quote=json_decode($o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $amount=$quote['amount_now_cents']??null;
            if (!is_int($amount)||$amount<1||$amount>100000000||($quote['currency']??null)!=='BRL'||($quote['product']??null)!==$o['product']) throw new RuntimeException('Quote unavailable');
            $due=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.sandbox_payment_intents(order_id,amount_cents,due_date) VALUES (?,?,?)');$q->execute([$id,$amount,$due]);
            $q=$this->db->prepare("UPDATE purchase_rehearsal.customer_orders SET payment_source='asaas_sandbox',revision=revision+1 WHERE id=? AND customer_id=?");$q->execute([$id,$owner]);
            $this->db->commit();
        } catch (\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function read(string $owner,string $id): ?array
    {
        $q=$this->db->prepare('SELECT i.state,i.provider_status,i.invoice_url FROM purchase_rehearsal.sandbox_payment_intents i JOIN purchase_rehearsal.customer_orders o ON o.id=i.order_id WHERE o.id=? AND o.customer_id=?');$q->execute([$id,$owner]);
        $row=$q->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }
}
