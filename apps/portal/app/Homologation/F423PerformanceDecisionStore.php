<?php
declare(strict_types=1);

namespace App\Homologation;

use PDO;
use RuntimeException;

/**
 * ONLY F423 disposable CI. This records a Performance policy decision, NOT
 * campaign drafts, OAuth/account access, financial authority or consent.
 * The actual Portal buyer's approved revision/digest is rechecked under lock.
 */
final class F423PerformanceDecisionStore
{
    public function __construct(private PDO $db, private CustomerPreviewStore $customer) {}

    public function read(string $owner, string $orderId, int $revision, string $digest): array
    {
        if (!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
            || !preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderId)
            || $revision < 1 || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)) return [];
        return $this->customer->withVerifiedScope($owner,function() use ($owner,$orderId,$revision,$digest): array {
            $q=$this->db->prepare("SELECT provider,decision_state,decision_reason,media_cents,source_order_alias,account_fingerprint,decision_fingerprint
                FROM purchase_rehearsal.f423_performance_decisions
                WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=? ORDER BY provider");
            $q->execute([$owner,$orderId,$revision,$digest]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            $out=[];
            foreach($rows as $row) $out[$row['provider']]=[
                'state'=>$row['decision_state'],'reason'=>$row['decision_reason'],
                'media_cents'=>(int)$row['media_cents'],
                'source_order_alias'=>$row['source_order_alias'],
                'account_fingerprint'=>$row['account_fingerprint'],
                'fingerprint'=>$row['decision_fingerprint']
            ];
            return $out;
        });
    }

    public function persist(string $owner, string $orderId, array $payload): array
    {
        $expected=['company_id','order_id','source_order_alias','order_revision','briefing_sha256','channels','operational_enabled','ads_write_enabled','billable'];
        $keys=array_keys($payload);sort($keys);sort($expected);
        if($keys!==$expected || $payload['company_id']!==$owner || $payload['order_id']!==$orderId
            || !preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
            || !preg_match('/\ATEST-[a-f0-9]{32}\z/D',$orderId)
            || !is_int($payload['order_revision']) || $payload['order_revision']<1
            || !is_string($payload['briefing_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D',$payload['briefing_sha256'])
            || !is_string($payload['source_order_alias']) || !preg_match('/\Aorder_[a-f0-9]{32}\z/D',$payload['source_order_alias'])
            || $payload['source_order_alias']!=='order_'.substr(hash('sha256',"f423-alias-v1\0".$owner."\0".$orderId),0,32)
            || $payload['operational_enabled']!==false || $payload['ads_write_enabled']!==false || $payload['billable']!==false
            || !is_array($payload['channels'])) throw new RuntimeException('Invalid laboratory decision contract');
        $providers=array_keys($payload['channels']);sort($providers);
        // Exact lab offer sets only: existing Google+Meta or newly approved
        // Google-only order. Do not infer Meta or open arbitrary providers.
        if($providers!==['google_ads'] && $providers!==['google_ads','meta_ads'])
            throw new RuntimeException('Missing or unknown laboratory channel');

        return $this->customer->withVerifiedScope($owner,function() use ($owner,$orderId,$payload,$providers): array {
            $q=$this->db->prepare("SELECT revision,product,payment,payment_source,payment_hold,briefing,offer_snapshot
                  FROM purchase_rehearsal.customer_orders WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($order) || $order['product']!=='performance'
                || $order['payment']!=='approved' || $order['payment_source']!=='asaas_sandbox'
                || in_array($order['payment_hold'],[true,'t',1,'1'],true)
                || (int)$order['revision']!==$payload['order_revision']
                || !is_string($order['briefing'])) throw new RuntimeException('Purchase or revision not valid');
            $briefing=json_decode($order['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing) || !hash_equals($payload['briefing_sha256'],
                hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Briefing digest changed');
            }
            $q=$this->db->prepare("SELECT approved_by FROM purchase_rehearsal.f423_briefing_reviews
                WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?");
            $q->execute([$owner,$orderId,$payload['order_revision'],$payload['briefing_sha256']]);
            if(!is_string($q->fetchColumn() ?: null)) throw new RuntimeException('Buyer has not approved exact briefing');
            $q=$this->db->prepare("SELECT 1 FROM purchase_rehearsal.sandbox_payment_intents
                WHERE order_id=? AND state='approved' LIMIT 1");
            $q->execute([$orderId]);
            if($q->fetchColumn()===false) throw new RuntimeException('Fake payment not confirmed in SQL');
            $offer=json_decode((string)$order['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $media=$offer['media_by_channel_cents']??null;
            // PostgreSQL jsonb does not preserve object insertion/key order.
            // Match the *exact* provider set, then check strict integer amounts.
            $mediaKeys=is_array($media)?array_keys($media):[];
            sort($mediaKeys,SORT_STRING);
            if(!is_array($media) || ($offer['activation_per_channel_cents']??null)!==49000
                || $mediaKeys!==$providers
                || ($offer['activation_total_cents']??null)!==49000*count($providers)
                || ($offer['amount_now_cents']??null)!==49000*count($providers)) {
                throw new RuntimeException('Different synthetic offer');
            }
            $expectedMedia=['google_ads'=>100000,'meta_ads'=>70000];
            foreach($providers as $provider) {
                if(!is_int($media[$provider]) || $media[$provider]!==$expectedMedia[$provider])
                    throw new RuntimeException('Different synthetic channel budget');
            }

            $insert=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_performance_decisions
                (customer_id,order_id,order_revision,briefing_sha256,provider,
                 media_cents,source_order_alias,account_fingerprint,decision_state,decision_reason,decision_fingerprint)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING");
            foreach($providers as $provider) {
                $row=$payload['channels'][$provider];
                if(!is_array($row)) throw new RuntimeException('Invalid channel');
                $keys=array_keys($row);$expected=['state','reason','media_cents','account_fingerprint','fingerprint'];
                sort($keys);sort($expected);
                if($keys!==$expected
                    || !in_array($row['state'],['blocked','internal_review_only'],true)
                    || ($row['state']==='blocked'
                        ? $row['reason']!=='mandate_or_s2s_denied'
                        : $row['reason']!=='synthetic_gate_accepted_for_internal_review')
                    || !is_int($row['media_cents']) || $row['media_cents']!==$media[$provider]
                    || !is_string($row['account_fingerprint']) || !preg_match('/\A[a-f0-9]{64}\z/D',$row['account_fingerprint'])
                    // Only the two test-owned accounts are valid in this CI-only
                    // seam; a correctly signed swapped account must still fail.
                    || $row['account_fingerprint']!==hash('sha256','f423-account-reference-v1:'
                        .($provider==='google_ads'?'1234567890':'98765432109876'))
                    || !is_string($row['fingerprint']) || !preg_match('/\A[a-f0-9]{64}\z/D',$row['fingerprint'])
                    || $row['fingerprint']!==hash('sha256',implode("\0",[
                        $owner,$orderId,(string)$payload['order_revision'],$payload['briefing_sha256'],
                        $provider,(string)$row['media_cents'],$row['account_fingerprint'],$row['state'],$row['reason']
                    ]))) throw new RuntimeException('Unexpected channel decision');
                $insert->execute([$owner,$orderId,$payload['order_revision'],$payload['briefing_sha256'],
                    $provider,$row['media_cents'],$payload['source_order_alias'],$row['account_fingerprint'],
                    $row['state'],$row['reason'],$row['fingerprint']]);
            }
            $q=$this->db->prepare("SELECT provider,media_cents,source_order_alias,account_fingerprint,
                    decision_state,decision_reason,decision_fingerprint FROM purchase_rehearsal.f423_performance_decisions
                    WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?");
            $q->execute([$owner,$orderId,$payload['order_revision'],$payload['briefing_sha256']]);
            $stored=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($stored)!==count($providers)) throw new RuntimeException('Incomplete persisted decision');
            $ready=0;
            foreach($stored as $saved) {
                if($saved['decision_state']==='internal_review_only') ++$ready;
                $row=$payload['channels'][$saved['provider']]??null;
                if(!is_array($row) || (int)$saved['media_cents']!==$row['media_cents']
                    || $saved['source_order_alias']!==$payload['source_order_alias']
                    || $saved['account_fingerprint']!==$row['account_fingerprint']
                    || $saved['decision_state']!==$row['state']
                    || $saved['decision_reason']!==$row['reason']
                    || $saved['decision_fingerprint']!==$row['fingerprint'])
                    throw new RuntimeException('Conflicting interprocess replay');
            }
            return ['persisted_channels'=>count($providers),'ready_channels_count'=>$ready,'campaign_drafts_created'=>0];
        });
    }
}
