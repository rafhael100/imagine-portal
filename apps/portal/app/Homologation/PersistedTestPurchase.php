<?php

declare(strict_types=1);

namespace App\Homologation;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

/** Persistent synthetic purchase rehearsal. No HTTP registration or business authority. */
final class PersistedTestPurchase
{
    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgumentException('PostgreSQL required.');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $name = $db->query('SELECT current_database()')->fetchColumn();
        if ($name !== 'portal_purchase_rehearsal') {
            throw new RuntimeException('Dedicated synthetic rehearsal database required.');
        }
    }

    public function create(string $tenant, string $key, string $product): array
    {
        $this->tenant($tenant);
        if (!in_array($product, ['onepage', 'landing-pages', 'performance'], true)
            || preg_match('/\ATEST-[a-zA-Z0-9-]{8,64}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid synthetic offer or request key.');
        }
        $id = 'TEST-'.bin2hex(random_bytes(16));
        return $this->transaction(function () use ($tenant, $key, $product, $id): array {
            $q = $this->db->prepare('INSERT INTO purchase_rehearsal.orders (id,tenant,request_key,product) VALUES (?,?,?,?) ON CONFLICT (tenant,request_key) DO NOTHING');
            $q->execute([$id,$tenant,$key,$product]);
            $q = $this->db->prepare('SELECT * FROM purchase_rehearsal.orders WHERE tenant=? AND request_key=? FOR UPDATE');
            $q->execute([$tenant,$key]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['product'] !== $product) throw new RuntimeException('Idempotency conflict.');
            return $this->result($row);
        });
    }

    public function read(string $tenant, string $id): array
    {
        $this->tenant($tenant);
        return $this->result($this->row($tenant,$id,false));
    }

    /** State is explicitly fake; no financial adapter exists. */
    public function payment(string $tenant, string $id, int $revision, string $outcome): array
    {
        $this->tenant($tenant);
        if (!in_array($outcome,['approved','pending','declined'],true)) throw new InvalidArgumentException('Unknown fake outcome.');
        return $this->transaction(function () use ($tenant,$id,$revision,$outcome): array {
            $row = $this->row($tenant,$id,true);
            if ((int)$row['revision'] !== $revision) throw new RuntimeException('Stale revision.');
            if ($row['briefing'] !== null) throw new RuntimeException('Briefing already submitted.');
            $q=$this->db->prepare('UPDATE purchase_rehearsal.orders SET payment=?,revision=revision+1 WHERE tenant=? AND id=? RETURNING *');
            $q->execute([$outcome,$tenant,$id]);
            return $this->result($q->fetch(PDO::FETCH_ASSOC));
        });
    }

    public function briefing(string $tenant, string $id, int $revision, array $brief): array
    {
        $this->tenant($tenant);
        $fields=['business','goal','audience','materials'];
        if (count($brief)!==4 || array_diff(array_keys($brief),$fields)!==[]) throw new InvalidArgumentException('Briefing fields required.');
        foreach ($fields as $field) {
            if (!is_string($brief[$field]??null) || trim($brief[$field])==='' || strlen($brief[$field])>800) throw new InvalidArgumentException('Invalid sample briefing.');
            $brief[$field]=trim($brief[$field]);
        }
        return $this->transaction(function () use ($tenant,$id,$revision,$brief): array {
            $row=$this->row($tenant,$id,true);
            if ((int)$row['revision']!==$revision) throw new RuntimeException('Stale revision.');
            if ($row['payment']!=='approved') throw new RuntimeException('Approved fake payment required.');
            $q=$this->db->prepare('UPDATE purchase_rehearsal.orders SET briefing=?::jsonb,revision=revision+1 WHERE tenant=? AND id=? RETURNING *');
            $q->execute([json_encode($brief,JSON_THROW_ON_ERROR),$tenant,$id]);
            return $this->result($q->fetch(PDO::FETCH_ASSOC));
        });
    }

    private function row(string $tenant,string $id,bool $lock): array
    {
        if (preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id)!==1) throw new RuntimeException('Order unavailable.');
        $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.orders WHERE tenant=? AND id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$tenant,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Order unavailable.');
        return $row;
    }

    private function tenant(string $tenant): void
    {
        if (!in_array($tenant,['test-org-a','test-org-b'],true)) throw new InvalidArgumentException('Synthetic organization required.');
    }

    private function result(array $row): array
    {
        return ['id'=>$row['id'],'product'=>$row['product'],'payment'=>$row['payment'],
            'revision'=>(int)$row['revision'],'briefing'=>$row['briefing']===null?null:json_decode($row['briefing'],true,512,JSON_THROW_ON_ERROR),
            'synthetic'=>true,'real_payment'=>false,'real_contract'=>false,'authorizes_http'=>false];
    }

    private function transaction(callable $operation): array
    {
        if ($this->db->inTransaction()) throw new RuntimeException('Independent transaction required.');
        $this->db->beginTransaction();
        try { $value=$operation(); $this->db->commit(); return $value; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
