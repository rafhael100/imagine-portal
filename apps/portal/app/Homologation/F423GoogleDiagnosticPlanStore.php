<?php
declare(strict_types=1);
namespace App\Homologation;

use PDO;
use RuntimeException;

/**
 * Disposable CI plan candidate, never an Ads campaign, draft, real performance
 * metric, human plan approval or consent source. SQL is FORCE RLS and INSERT-only.
 */
final class F423GoogleDiagnosticPlanStore
{
    public function __construct(private PDO $db,private CustomerPreviewStore $customer) {}

    public function read(string $owner,string $order,int $revision,string $digest): ?array
    {
        if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
          ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)
          ||$revision<1||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)) return null;
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$revision,$digest):?array {
            $q=$this->db->prepare("SELECT p.plan_state,p.plan_version,p.diagnostic_version,p.metric_origin,
                p.sample_impressions,p.sample_clicks,p.sample_conversions,p.media_cents,p.proposed_action,
                p.plan_fingerprint FROM purchase_rehearsal.f423_google_diagnostic_plans p
                JOIN purchase_rehearsal.f423_performance_decisions d
                  ON d.customer_id=p.customer_id AND d.order_id=p.order_id
                 AND d.order_revision=p.order_revision AND d.provider=p.provider
                 AND d.briefing_sha256=p.briefing_sha256
                 AND d.decision_fingerprint=p.decision_fingerprint
                WHERE p.customer_id=? AND p.order_id=? AND p.order_revision=?
                  AND p.briefing_sha256=? AND p.provider='google_ads'
                  AND d.decision_state='internal_review_only'");
            $q->execute([$owner,$order,$revision,$digest]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1) return null;
            $row=$rows[0];
            $approval=$this->db->prepare("SELECT approved_by,plan_fingerprint
              FROM purchase_rehearsal.f423_google_plan_approvals
              WHERE customer_id=? AND order_id=? AND order_revision=?
               AND briefing_sha256=? AND provider='google_ads' AND plan_version=1");
            $approval->execute([$owner,$order,$revision,$digest]);
            $review=$approval->fetch(PDO::FETCH_ASSOC);
            $buyerApproved=is_array($review)
              && hash_equals($row['plan_fingerprint'],$review['plan_fingerprint']);
            return [
                'state'=>$row['plan_state'],'plan_version'=>(int)$row['plan_version'],
                'diagnostic_version'=>(int)$row['diagnostic_version'],
                'metric_origin'=>$row['metric_origin'],
                'sample_impressions'=>(int)$row['sample_impressions'],
                'sample_clicks'=>(int)$row['sample_clicks'],
                'sample_conversions'=>(int)$row['sample_conversions'],
                'media_cents'=>(int)$row['media_cents'],
                'proposed_action'=>$row['proposed_action'],
                'plan_fingerprint'=>$row['plan_fingerprint'],
                'buyer_plan_approved'=>$buyerApproved,
                'plan_approval_state'=>$buyerApproved?'synthetic_buyer_approved':'not_approved',
                'campaign_draft_created'=>false,
                'operational_enabled'=>false
            ];
        });
    }

    public function persist(string $owner,string $order,array $p):array
    {
        $expected=['schema','company_id','order_id','order_revision','briefing_sha256',
          'provider','diagnostic_version','plan_version','decision_fingerprint',
          'metric_origin','sample_impressions','sample_clicks','sample_conversions',
          'media_cents','proposed_action','plan_state','plan_fingerprint',
          'operational_enabled','campaign_draft_created','billable'];
        $keys=array_keys($p);sort($keys);sort($expected);
        if($keys!==$expected || ($p['schema']??null)!=='f423_google_diagnostic_plan_ci_v1'
          ||$p['company_id']!==$owner||$p['order_id']!==$order
          ||!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
          ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)
          ||!is_int($p['order_revision'])||$p['order_revision']<1
          ||!is_string($p['briefing_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['briefing_sha256'])
          ||$p['provider']!=='google_ads'
          ||$p['diagnostic_version']!==1||$p['plan_version']!==1
          ||!is_string($p['decision_fingerprint'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['decision_fingerprint'])
          ||$p['metric_origin']!=='ci_synthetic_fixture_no_ads'
          ||$p['sample_impressions']!==1000||$p['sample_clicks']!==40
          ||$p['sample_conversions']!==2||$p['media_cents']!==100000
          ||$p['proposed_action']!=='review_search_terms_only'
          ||$p['plan_state']!=='awaiting_separate_approval'
          ||!is_string($p['plan_fingerprint'])||!preg_match('/\A[a-f0-9]{64}\z/D',$p['plan_fingerprint'])
          ||$p['operational_enabled']!==false||$p['campaign_draft_created']!==false||$p['billable']!==false)
          throw new RuntimeException('Invalid synthetic plan contract');
        $fingerprint=hash('sha256',implode("\0",[$owner,$order,(string)$p['order_revision'],
            $p['briefing_sha256'],'google_ads',$p['decision_fingerprint'],'1','1',
            '1000','40','2','100000','review_search_terms_only']));
        if(!hash_equals($fingerprint,$p['plan_fingerprint']))
          throw new RuntimeException('Synthetic plan fingerprint mismatch');
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$p):array {
            $q=$this->db->prepare("SELECT revision,product,payment,payment_source,payment_hold,
                  briefing,offer_snapshot FROM purchase_rehearsal.customer_orders
                  WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||$row['product']!=='performance'
                ||$row['payment']!=='approved'||$row['payment_source']!=='asaas_sandbox'
                ||in_array($row['payment_hold'],[true,'t',1,'1'],true)
                ||(int)$row['revision']!==$p['order_revision']) throw new RuntimeException('Order not approved');
            $briefing=json_decode((string)$row['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing)||!hash_equals($p['briefing_sha256'],
                hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR))))
                throw new RuntimeException('Wrong briefing digest');
            $media=json_decode((string)$row['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $offer=$media['media_by_channel_cents']??null;
            if(!is_array($offer)||array_keys($offer)!==['google_ads']
              ||$offer['google_ads']!==100000||($media['activation_per_channel_cents']??null)!==49000)
                throw new RuntimeException('Not the exact Google-only offer');
            $q=$this->db->prepare("SELECT approved_by FROM purchase_rehearsal.f423_briefing_reviews
               WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256']]);
            if(!$q->fetchColumn()) throw new RuntimeException('Briefing not buyer approved');
            $q=$this->db->prepare("SELECT decision_fingerprint,decision_state,media_cents
              FROM purchase_rehearsal.f423_performance_decisions
              WHERE customer_id=? AND order_id=? AND order_revision=?
                AND briefing_sha256=? AND provider='google_ads'");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256']]);
            $decision=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($decision)||$decision['decision_state']!=='internal_review_only'
              ||(int)$decision['media_cents']!==100000
              ||!hash_equals($decision['decision_fingerprint'],$p['decision_fingerprint']))
              throw new RuntimeException('Unverified internal decision');
            $q=$this->db->prepare("SELECT count(*) FROM purchase_rehearsal.f423_performance_decisions
              WHERE customer_id=? AND order_id=? AND order_revision=?");
            $q->execute([$owner,$order,$p['order_revision']]);
            if((int)$q->fetchColumn()!==1) throw new RuntimeException('Not Google-only decision');
            $insert=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_diagnostic_plans
              (customer_id,order_id,order_revision,briefing_sha256,provider,
              diagnostic_version,plan_version,decision_fingerprint,metric_origin,
              sample_impressions,sample_clicks,sample_conversions,media_cents,
              proposed_action,plan_state,plan_fingerprint)
              VALUES(?,?,?,?,'google_ads',1,1,?,'ci_synthetic_fixture_no_ads',
              1000,40,2,100000,'review_search_terms_only','awaiting_separate_approval',?)
              ON CONFLICT DO NOTHING");
            $insert->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],
                $p['decision_fingerprint'],$p['plan_fingerprint']]);
            $q=$this->db->prepare("SELECT decision_fingerprint,plan_fingerprint,
              plan_state FROM purchase_rehearsal.f423_google_diagnostic_plans
              WHERE customer_id=? AND order_id=? AND order_revision=?
                AND briefing_sha256=? AND provider='google_ads' AND plan_version=1");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256']]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($saved)!==1||!hash_equals($saved[0]['decision_fingerprint'],$p['decision_fingerprint'])
              ||!hash_equals($saved[0]['plan_fingerprint'],$p['plan_fingerprint'])
              ||$saved[0]['plan_state']!=='awaiting_separate_approval')
              throw new RuntimeException('Conflicting plan replay');
            return ['persisted_plans'=>1,'plan_state'=>'awaiting_separate_approval',
              'campaign_drafts_created'=>0,'plan_approved'=>false];
        });
    }

    /** Distinct CI buyer review, bound to exact immutable plan SHA and order revision. */
    public function approve(string $owner,string $order,string $buyer,int $revision,int $version,string $fingerprint): bool
    {
        if(!preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
          ||!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)
          ||!preg_match('/\Ademo-[a-z0-9-]{3,40}\z/D',$buyer)
          ||$revision<1||$version!==1
          ||!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)) return false;
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$buyer,$revision,$fingerprint):bool {
            $q=$this->db->prepare("SELECT revision,briefing,product,payment,payment_source,payment_hold,
                  offer_snapshot FROM purchase_rehearsal.customer_orders
                  WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($o)||$o['product']!=='performance'||$o['payment']!=='approved'
              ||$o['payment_source']!=='asaas_sandbox'
              ||in_array($o['payment_hold'],[true,'t',1,'1'],true)
              ||(int)$o['revision']!==$revision) return false;
            $briefing=json_decode((string)$o['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing))return false;
            $digest=hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR));
            $offer=json_decode((string)$o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $channels=$offer['media_by_channel_cents']??null;
            if(!is_array($channels)||array_keys($channels)!==['google_ads']
              ||$channels['google_ads']!==100000
              ||($offer['activation_per_channel_cents']??null)!==49000) return false;
            $q=$this->db->prepare("SELECT approved_by FROM purchase_rehearsal.f423_briefing_reviews
                  WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?");
            $q->execute([$owner,$order,$revision,$digest]);
            if($q->fetchColumn()!==$buyer)return false;
            $q=$this->db->prepare("SELECT p.plan_fingerprint
                FROM purchase_rehearsal.f423_google_diagnostic_plans p
                JOIN purchase_rehearsal.f423_performance_decisions d
                  ON d.customer_id=p.customer_id AND d.order_id=p.order_id
                  AND d.order_revision=p.order_revision AND d.provider=p.provider
                  AND d.briefing_sha256=p.briefing_sha256
                  AND d.decision_fingerprint=p.decision_fingerprint
                WHERE p.customer_id=? AND p.order_id=? AND p.order_revision=?
                  AND p.briefing_sha256=? AND p.provider='google_ads'
                  AND p.plan_version=1 AND p.plan_state='awaiting_separate_approval'
                  AND d.decision_state='internal_review_only'");
            $q->execute([$owner,$order,$revision,$digest]);
            if($q->fetchColumn()!==$fingerprint)return false;
            $q=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_plan_approvals
               (customer_id,order_id,order_revision,briefing_sha256,provider,
                plan_version,plan_fingerprint,approved_by)
               VALUES(?,?,?,?,'google_ads',1,?,?)
               ON CONFLICT DO NOTHING");
            $q->execute([$owner,$order,$revision,$digest,$fingerprint,$buyer]);
            $q=$this->db->prepare("SELECT plan_fingerprint,approved_by
              FROM purchase_rehearsal.f423_google_plan_approvals
              WHERE customer_id=? AND order_id=? AND order_revision=?
               AND briefing_sha256=? AND provider='google_ads' AND plan_version=1");
            $q->execute([$owner,$order,$revision,$digest]);$saved=$q->fetchAll(PDO::FETCH_ASSOC);
            return count($saved)===1
                &&hash_equals($saved[0]['plan_fingerprint'],$fingerprint)
                &&$saved[0]['approved_by']===$buyer;
        });
    }
}
