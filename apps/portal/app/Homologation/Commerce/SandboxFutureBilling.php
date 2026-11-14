<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;
use PDO;
use RuntimeException;
final class SandboxFutureBilling
{
    public function __construct(private PDO $db) { new \App\Homologation\RoleBoundTestPurchase($db); }
    public function request(string $owner,string $id,int $revision):void
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock(724115)');
            $q=$this->db->prepare('SELECT o.* FROM purchase_rehearsal.customer_orders o JOIN purchase_rehearsal.sandbox_payment_intents i ON i.order_id=o.id WHERE o.id=? AND o.customer_id=? AND i.state=\'approved\' AND o.payment_source=\'asaas_sandbox\' FOR UPDATE OF o');$q->execute([$id,$owner]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if (!$o||(int)$o['revision']!==$revision) throw new RuntimeException('Order unavailable');
            $quote=json_decode($o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $schedule=BillingSchedule::project($quote,$o['offer_accepted_at'],1);
            if ((int)$this->db->query('SELECT count(*) FROM purchase_rehearsal.sandbox_future_billing')->fetchColumn()>147) throw new RuntimeException('Sandbox quota');
            foreach (array_slice($schedule,1) as $item) {
                $key=$item['kind']==='monthly'?'monthly':$item['key'];
                $q=$this->db->prepare('INSERT INTO purchase_rehearsal.sandbox_future_billing(order_id,charge_key,kind,amount_cents,due_date) VALUES (?,?,?,?,?) ON CONFLICT(order_id,charge_key) DO UPDATE SET sync_requested=true');
                $q->execute([$id,$key,$item['kind'],$item['amount_cents'],$item['due_date']]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function read(string $owner,string $id):array
    {
        $q=$this->db->prepare('SELECT f.charge_key,f.kind,f.amount_cents,f.due_date,f.state,f.provider_status,f.invoice_url FROM purchase_rehearsal.sandbox_future_billing f JOIN purchase_rehearsal.customer_orders o ON o.id=f.order_id WHERE o.id=? AND o.customer_id=? ORDER BY f.due_date,f.charge_key');$q->execute([$id,$owner]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
