<?php
declare(strict_types=1);
namespace App\Homologation;
use PDO;
use RuntimeException;

/** Persistent operations for synthetic customer orders, never production fulfilment. */
final class CustomerWorkPreview
{
    public function __construct(private PDO $db, private CustomerPreviewStore $owners) { new RoleBoundTestPurchase($db); }
    public function read(?string $owner,string $id): ?array
    {
        return $this->owners->withVerifiedScope($owner, function () use ($owner,$id): ?array {
        $q=$this->db->prepare('SELECT o.*,c.name AS customer_name,c.company FROM purchase_rehearsal.customer_orders o JOIN purchase_rehearsal.customers c ON c.id=o.customer_id WHERE o.id=?'.($owner===null?'':' AND o.customer_id=?'));
        $q->execute($owner===null?[$id]:[$id,$owner]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['briefing']=$row['briefing']===null?null:json_decode($row['briefing'],true,512,JSON_THROW_ON_ERROR);
        $q=$this->db->prepare('SELECT actor_kind,action,note,created_at FROM purchase_rehearsal.customer_order_events WHERE order_id=? ORDER BY revision LIMIT 200');
        $q->execute([$id]); $row['events']=$q->fetchAll(PDO::FETCH_ASSOC);
        unset($row['customer_id'],$row['request_key']); return $row;
        });
    }
    public function act(?string $owner,string $id,int $revision,string $action,string $note): bool
    {
        try {
            return $this->owners->withVerifiedScope($owner, function () use ($owner,$id,$revision,$action,$note): bool {
            $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE id=?'.($owner===null?'':' AND customer_id=?').' FOR UPDATE');
            $q->execute($owner===null?[$id]:[$id,$owner]); $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row||(int)$row['revision']!==$revision) throw new RuntimeException('Unavailable or stale order');
            $state=$row['work_status']; $target=$state; $delivery=$row['delivery_note'];
            if ($action==='message') {
                if (trim($note)==='') throw new RuntimeException('Message required');
            } elseif ($owner===null&&$action==='start'&&in_array($state,['queued','changes_requested'],true)&&$row['briefing']!==null&&$row['payment']==='approved') {
                $target='in_production';
            } elseif ($owner===null&&$action==='deliver'&&$state==='in_production'&&trim($note)!=='') {
                $target='awaiting_approval'; $delivery=$note;
            } elseif ($owner!==null&&$action==='approve'&&$state==='awaiting_approval') {
                $target='completed';
            } elseif ($owner!==null&&$action==='request_changes'&&$state==='awaiting_approval'&&trim($note)!=='') {
                $target='changes_requested';
            } else throw new RuntimeException('Invalid transition');
            if (strlen($note)>4000) throw new RuntimeException('Message too long');
            $q=$this->db->prepare('UPDATE purchase_rehearsal.customer_orders SET work_status=?,delivery_note=?,revision=revision+1 WHERE id=? AND revision=?');
            $q->execute([$target,$delivery,$id,$revision]);
            if ($q->rowCount()!==1) throw new RuntimeException('Stale order');
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.customer_order_events(order_id,revision,actor_kind,action,note) VALUES (?,?,?,?,?)');
            $q->execute([$id,$revision+1,$owner===null?'admin':'client',$action,trim($note)]);
            return true;
            });
        } catch (\Throwable) { return false; }
    }
}
