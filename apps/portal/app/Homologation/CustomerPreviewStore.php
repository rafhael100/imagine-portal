<?php
declare(strict_types=1);
namespace App\Homologation;
use PDO;
use RuntimeException;

/** Dedicated synthetic DB only; no Portal/CRM production identities. */
final class CustomerPreviewStore
{
    public function __construct(private PDO $db) { new RoleBoundTestPurchase($db); }
    public function register(string $username, #[\SensitiveParameter] string $password, string $name, string $company): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT pg_advisory_xact_lock(724113)");
            if ((int)$this->db->query('SELECT count(*) FROM purchase_rehearsal.customers')->fetchColumn()>=100) throw new RuntimeException('Limite de contas de teste atingido.');
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customers(id,username,password_hash,name,company) VALUES (?,?,?,?,?) RETURNING *');
            $q->execute(['demo-'.bin2hex(random_bytes(16)),$username,password_hash($password,PASSWORD_DEFAULT),$name,$company]);
            $row=$q->fetch(PDO::FETCH_ASSOC); $this->db->commit(); return $row;
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function account(string $username): ?array
    {
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customers WHERE username=? AND enabled=true'); $q->execute([$username]);
        return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function create(string $owner,string $key,string $product): array
    {
        $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customer_orders(id,customer_id,request_key,product) VALUES (?,?,?,?) ON CONFLICT(customer_id,request_key) DO NOTHING');
        $q->execute(['TEST-'.bin2hex(random_bytes(16)),$owner,$key,$product]);
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE customer_id=? AND request_key=?'); $q->execute([$owner,$key]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row||$row['product']!==$product) throw new RuntimeException('Idempotency conflict');
        return $this->result($row);
    }
    public function read(string $owner,string $id): ?array
    {
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE customer_id=? AND id=?'); $q->execute([$owner,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC); return $row?$this->result($row):null;
    }
    public function payment(string $owner,string $id,int $revision,string $outcome): bool
    {
        $q=$this->db->prepare('UPDATE purchase_rehearsal.customer_orders SET payment=?,revision=revision+1 WHERE customer_id=? AND id=? AND revision=? AND briefing IS NULL');
        $q->execute([$outcome,$owner,$id,$revision]); return $q->rowCount()===1;
    }
    public function briefing(string $owner,string $id,int $revision,array $briefing): bool
    {
        $q=$this->db->prepare("UPDATE purchase_rehearsal.customer_orders SET briefing=?::jsonb,revision=revision+1 WHERE customer_id=? AND id=? AND revision=? AND payment='approved' AND briefing IS NULL");
        $q->execute([json_encode($briefing,JSON_THROW_ON_ERROR),$owner,$id,$revision]); return $q->rowCount()===1;
    }
    public function orders(?string $owner): array
    {
        $sql='SELECT o.id,o.product,o.payment,o.revision,o.work_status,(o.briefing IS NOT NULL) AS briefing_saved,c.name AS customer_name,c.username FROM purchase_rehearsal.customer_orders o JOIN purchase_rehearsal.customers c ON c.id=o.customer_id';
        $q=$this->db->prepare($sql.($owner===null?'':' WHERE o.customer_id=?').' ORDER BY o.created_at DESC,o.id LIMIT 100');
        $q->execute($owner===null?[]:[$owner]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function customers(): array { return $this->db->query('SELECT name,company,username,enabled FROM purchase_rehearsal.customers ORDER BY username LIMIT 100')->fetchAll(PDO::FETCH_ASSOC); }
    private function result(array $row): array
    {
        $row['revision']=(int)$row['revision'];
        $row['briefing']=$row['briefing']===null?null:json_decode($row['briefing'],true,512,JSON_THROW_ON_ERROR);
        unset($row['customer_id'],$row['request_key']); return $row;
    }
}
