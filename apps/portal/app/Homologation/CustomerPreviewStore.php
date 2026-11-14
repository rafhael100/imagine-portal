<?php
declare(strict_types=1);
namespace App\Homologation;

use PDO;
use RuntimeException;

/**
 * Isolated, synthetic-only customer store.
 * The restricted company SQL login has no raw SELECT/UPDATE access to other
 * customers' orders without a DB-verifiable, transaction-local signed claim.
 * This is not production IAM or proof of a real payment.
 */
final class CustomerPreviewStore
{
    public function __construct(
        private PDO $db,
        #[\SensitiveParameter] private string $scopeKey
    ) {
        new RoleBoundTestPurchase($db);
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $scopeKey)) {
            throw new RuntimeException('Invalid synthetic ownership verifier configuration.');
        }
    }

    /**
     * A direct SQL session can SET a principal GUC but cannot forge its MAC.
     * SET LOCAL prevents a pooled/reused connection from inheriting an actor.
     * Admin claims are only constructed by the application on admin-only routes.
     */
    private function scoped(?string $owner, callable $operation): mixed
    {
        $principal = $owner ?? 'admin';
        if ($principal !== 'admin' && !preg_match('/\Ademo-[a-f0-9]{32}\z/D', $principal)) {
            throw new RuntimeException('Invalid synthetic customer identity.');
        }
        $key = hex2bin($this->scopeKey);
        if ($key === false) throw new RuntimeException('Invalid verifier key.');
        $mac = hash_hmac('sha256', 'imagine-owned-preview-v1:'.$principal, $key);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT set_config('imagine.preview_principal', ?, true),
                        set_config('imagine.preview_mac', ?, true)"
            );
            $stmt->execute([$principal, $mac]);
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Synthetic signup only; identity and customer hashes still require separate IAM hardening. */
    public function register(string $username, #[\SensitiveParameter] string $password, string $name, string $company): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT pg_advisory_xact_lock(724113)");
            if ((int)$this->db->query('SELECT count(*) FROM purchase_rehearsal.customers')->fetchColumn()>=100) {
                throw new RuntimeException('Limite de contas de teste atingido.');
            }
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customers(id,username,password_hash,name,company) VALUES (?,?,?,?,?) RETURNING *');
            $q->execute(['demo-'.bin2hex(random_bytes(16)),$username,password_hash($password,PASSWORD_DEFAULT),$name,$company]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();
            return $row;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function account(string $username): ?array
    {
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customers WHERE username=? AND enabled=true');
        $q->execute([$username]);
        return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function create(string $owner,string $key,string $product): array
    {
        return $this->scoped($owner, function () use ($owner,$key,$product): array {
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customer_orders(id,customer_id,request_key,product) VALUES (?,?,?,?) ON CONFLICT(customer_id,request_key) DO NOTHING');
            $q->execute(['TEST-'.bin2hex(random_bytes(16)),$owner,$key,$product]);
            $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE customer_id=? AND request_key=?');
            $q->execute([$owner,$key]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row||$row['product']!==$product) throw new RuntimeException('Idempotency conflict');
            return $this->result($row);
        });
    }

    public function read(string $owner,string $id): ?array
    {
        return $this->scoped($owner, function () use ($owner,$id): ?array {
            $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE customer_id=? AND id=?');
            $q->execute([$owner,$id]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            return $row?$this->result($row):null;
        });
    }

    public function payment(string $owner,string $id,int $revision,string $outcome): bool
    {
        return $this->scoped($owner, function () use ($owner,$id,$revision,$outcome): bool {
            $q=$this->db->prepare('UPDATE purchase_rehearsal.customer_orders SET payment=?,revision=revision+1 WHERE customer_id=? AND id=? AND revision=? AND briefing IS NULL');
            $q->execute([$outcome,$owner,$id,$revision]);
            return $q->rowCount()===1;
        });
    }

    public function briefing(string $owner,string $id,int $revision,array $briefing): bool
    {
        return $this->scoped($owner, function () use ($owner,$id,$revision,$briefing): bool {
            $q=$this->db->prepare("UPDATE purchase_rehearsal.customer_orders SET briefing=?::jsonb,revision=revision+1 WHERE customer_id=? AND id=? AND revision=? AND payment='approved' AND briefing IS NULL");
            $q->execute([json_encode($briefing,JSON_THROW_ON_ERROR),$owner,$id,$revision]);
            return $q->rowCount()===1;
        });
    }

    public function orders(?string $owner): array
    {
        return $this->scoped($owner, function () use ($owner): array {
            $sql='SELECT o.id,o.product,o.payment,o.revision,(o.briefing IS NOT NULL) AS briefing_saved,c.name AS customer_name,c.username FROM purchase_rehearsal.customer_orders o JOIN purchase_rehearsal.customers c ON c.id=o.customer_id';
            $q=$this->db->prepare($sql.($owner===null?'':' WHERE o.customer_id=?').' ORDER BY o.created_at DESC,o.id LIMIT 100');
            $q->execute($owner===null?[]:[$owner]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    private function result(array $row): array
    {
        $row['revision']=(int)$row['revision'];
        $row['briefing']=$row['briefing']===null?null:json_decode($row['briefing'],true,512,JSON_THROW_ON_ERROR);
        unset($row['customer_id'],$row['request_key']);
        return $row;
    }
}
