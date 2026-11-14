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
        // Fail closed if a migration or fixture accidentally removes SQL RLS.
        $guard = $db->query(
            "SELECT relrowsecurity, relforcerowsecurity
               FROM pg_class WHERE oid='purchase_rehearsal.customer_orders'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($guard)
            || !in_array($guard['relrowsecurity'] ?? null, [true, 't', 1, '1'], true)
            || !in_array($guard['relforcerowsecurity'] ?? null, [true, 't', 1, '1'], true)) {
            throw new RuntimeException('Customer orders require forced row-level security.');
        }
        $policy = $db->query(
            "SELECT 1 FROM pg_policies
             WHERE schemaname='purchase_rehearsal'
               AND tablename='customer_orders'
               AND policyname='customer_order_scope'
               AND qual LIKE '%preview_order_allowed%'
               AND with_check LIKE '%preview_order_allowed%'"
        )->fetchColumn();
        if ($policy === false) {
            throw new RuntimeException('Customer-scoped signed SQL policy is required.');
        }
        $identityGuard = $db->query(
            "SELECT relrowsecurity, relforcerowsecurity
             FROM pg_class WHERE oid='purchase_rehearsal.customers'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identityGuard)
            || !in_array($identityGuard['relrowsecurity'] ?? null, [true, 't', 1, '1'], true)
            || !in_array($identityGuard['relforcerowsecurity'] ?? null, [true, 't', 1, '1'], true)) {
            throw new RuntimeException('Synthetic identity rows require forced row-level security.');
        }
        $identityPolicy = $db->query(
            "SELECT 1 FROM pg_policies
             WHERE schemaname='purchase_rehearsal'
               AND tablename='customers'
               AND policyname='customer_preview_read_scope'
               AND qual LIKE '%preview_order_allowed%'"
        )->fetchColumn();
        if ($identityPolicy === false) throw new RuntimeException('Signed customer identity policy required.');
        $canReadSecret = $db->query(
            "SELECT has_table_privilege(current_user,'purchase_rehearsal.preview_scope_secret','SELECT')"
        )->fetchColumn();
        if (in_array($canReadSecret,[true,'t',1,'1'],true)) {
            throw new RuntimeException('Synthetic SQL login must not read the signing secret.');
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

    /** A scope is valid only for the verified server-side customer/admin principal. */
    public function withVerifiedScope(?string $owner, callable $operation): mixed
    {
        return $this->scoped($owner, $operation);
    }

    /** Synthetic signup only; identity and customer hashes still require separate IAM hardening. */
    public function register(string $username, #[\SensitiveParameter] string $password, string $name, string $company): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT pg_advisory_xact_lock(724113)");
            if ((int)$this->db->query('SELECT purchase_rehearsal.preview_customer_count()')->fetchColumn()>=100) {
                throw new RuntimeException('Limite de contas de teste atingido.');
            }
            $id='demo-'.bin2hex(random_bytes(16));
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customers(id,username,password_hash,name,company) VALUES (?,?,?,?,?)');
            $q->execute([$id,$username,password_hash($password,PASSWORD_DEFAULT),$name,$company]);
            $this->db->commit();
            // No RETURNING: an unauthenticated new account cannot SELECT identity rows.
            return ['id'=>$id,'username'=>$username,'name'=>$name,'company'=>$company];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function account(string $username): ?array
    {
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.preview_lookup_account(?)');
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
            $sql='SELECT o.id,o.product,o.payment,o.revision,o.work_status,(o.briefing IS NOT NULL) AS briefing_saved,c.name AS customer_name,c.username FROM purchase_rehearsal.customer_orders o JOIN purchase_rehearsal.customers c ON c.id=o.customer_id';
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
