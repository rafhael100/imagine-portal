<?php
declare(strict_types=1);
namespace App\Homologation;

use PDO;
use RuntimeException;

/**
 * An unpublished INTERNAL CI draft candidate. Never an Ads API object, account
 * authorization, billing event or consent. Immutable, RLS-scoped and insert-only.
 */
final class F423GoogleCampaignDraftStore
{
    public function __construct(private PDO $db, private CustomerPreviewStore $customer) {}

    private static function valid(string $owner,string $order,int $revision,string $digest): bool
    {
        return preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)===1
          && preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)===1
          && $revision>0 && preg_match('/\A[a-f0-9]{64}\z/D',$digest)===1;
    }

    public function read(string $owner,string $order,int $revision,string $digest): ?array
    {
        if(!self::valid($owner,$order,$revision,$digest)) return null;
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$revision,$digest):?array {
            $q=$this->db->prepare("SELECT c.draft_state,c.draft_fingerprint,c.plan_fingerprint,
                c.approved_by FROM purchase_rehearsal.f423_google_internal_drafts c
                JOIN purchase_rehearsal.f423_google_plan_approvals a
                  ON a.customer_id=c.customer_id AND a.order_id=c.order_id
                  AND a.order_revision=c.order_revision AND a.provider=c.provider
                  AND a.plan_version=c.plan_version AND a.briefing_sha256=c.briefing_sha256
                  AND a.plan_fingerprint=c.plan_fingerprint AND a.approved_by=c.approved_by
                WHERE c.customer_id=? AND c.order_id=? AND c.order_revision=?
                  AND c.briefing_sha256=? AND c.provider='google_ads' AND c.plan_version=1");
            $q->execute([$owner,$order,$revision,$digest]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($saved)!==1 || $saved[0]['draft_state']!=='ci_internal_unpublished') return null;
            return [
              'state'=>'ci_internal_unpublished','provider'=>'google_ads',
              'draft_version'=>1,'plan_version'=>1,
              'plan_fingerprint'=>$saved[0]['plan_fingerprint'],
              'draft_fingerprint'=>$saved[0]['draft_fingerprint'],
              'published'=>false,'ads_connected'=>false,'operational_enabled'=>false,
              'metric_origin'=>'ci_synthetic_fixture_no_ads'
            ];
        });
    }

    /** Signed CI S2S only. Do not derive consent from the buyer's plan approval. */
    public function persist(string $owner,string $order,array $p): array
    {
        $keys=['schema','company_id','order_id','order_revision','briefing_sha256',
          'provider','plan_version','plan_fingerprint','draft_fingerprint','draft_state',
          'operational_enabled','ads_write_enabled','billable'];
        $actual=array_keys($p);sort($actual);sort($keys);
        if($actual!==$keys
          ||($p['schema']??null)!=='f423_google_internal_draft_ci_v1'
          ||($p['company_id']??null)!==$owner||($p['order_id']??null)!==$order
          ||!is_int($p['order_revision']??null)
          ||!is_string($p['briefing_sha256']??null)
          ||!self::valid($owner,$order,$p['order_revision'],$p['briefing_sha256'])
          ||($p['provider']??null)!=='google_ads'||($p['plan_version']??null)!==1
          ||!is_string($p['plan_fingerprint']??null)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$p['plan_fingerprint'])!==1
          ||!is_string($p['draft_fingerprint']??null)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$p['draft_fingerprint'])!==1
          ||($p['draft_state']??null)!=='ci_internal_unpublished'
          ||($p['operational_enabled']??null)!==false
          ||($p['ads_write_enabled']??null)!==false
          ||($p['billable']??null)!==false)
          throw new RuntimeException('Invalid unpublished draft envelope');

        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$p):array {
            $q=$this->db->prepare("SELECT revision,briefing,payment,payment_source,payment_hold,
                 product,offer_snapshot FROM purchase_rehearsal.customer_orders
                 WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($o)||$o['product']!=='performance'||$o['payment']!=='approved'
              ||$o['payment_source']!=='asaas_sandbox'
              ||in_array($o['payment_hold'],[true,'t','1',1],true)
              ||(int)$o['revision']!==$p['order_revision'])
              throw new RuntimeException('Purchase or revision invalid');
            $briefing=json_decode((string)$o['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing)||!hash_equals($p['briefing_sha256'],
                hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR))))
              throw new RuntimeException('Briefing mismatch');
            $offer=json_decode((string)$o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $channels=$offer['media_by_channel_cents']??null;
            if(!is_array($channels)||array_keys($channels)!==['google_ads']
              ||$channels['google_ads']!==100000
              ||($offer['activation_per_channel_cents']??null)!==49000)
              throw new RuntimeException('Not Google-only purchase');

            $q=$this->db->prepare("SELECT b.approved_by FROM purchase_rehearsal.f423_briefing_reviews b
              WHERE b.customer_id=? AND b.order_id=? AND b.order_revision=?
                AND b.briefing_sha256=?");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256']]);
            $buyer=$q->fetchColumn();
            if(!is_string($buyer)||$buyer==='') throw new RuntimeException('Briefing not approved');

            $q=$this->db->prepare("SELECT a.approved_by,p.plan_fingerprint
               FROM purchase_rehearsal.f423_google_plan_approvals a
               JOIN purchase_rehearsal.f423_google_diagnostic_plans p
                 ON a.customer_id=p.customer_id AND a.order_id=p.order_id
                AND a.order_revision=p.order_revision AND a.provider=p.provider
                AND a.plan_version=p.plan_version AND a.briefing_sha256=p.briefing_sha256
                AND a.plan_fingerprint=p.plan_fingerprint
               JOIN purchase_rehearsal.f423_performance_decisions d
                 ON d.customer_id=p.customer_id AND d.order_id=p.order_id
                AND d.order_revision=p.order_revision AND d.provider=p.provider
                AND d.briefing_sha256=p.briefing_sha256
                AND d.decision_fingerprint=p.decision_fingerprint
               WHERE a.customer_id=? AND a.order_id=? AND a.order_revision=?
                AND a.briefing_sha256=? AND a.provider='google_ads' AND a.plan_version=1
                AND p.plan_state='awaiting_separate_approval'
                AND d.decision_state='internal_review_only'");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256']]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1||$rows[0]['approved_by']!==$buyer
              ||!hash_equals($p['plan_fingerprint'],$rows[0]['plan_fingerprint']))
              throw new RuntimeException('Exact buyer approval missing');

            $expected=hash('sha256',implode("\0",[$owner,$order,
              (string)$p['order_revision'],$p['briefing_sha256'],'google_ads','1',
              $p['plan_fingerprint'],$buyer,'ci_internal_unpublished_v1']));
            if(!hash_equals($expected,$p['draft_fingerprint']))
              throw new RuntimeException('Unmatched draft fingerprint');
            $q=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_internal_drafts
              (customer_id,order_id,order_revision,briefing_sha256,provider,plan_version,
               plan_fingerprint,approved_by,draft_fingerprint,draft_state)
              VALUES(?,?,?,?,'google_ads',1,?,?,?,'ci_internal_unpublished')
              ON CONFLICT DO NOTHING");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],
              $p['plan_fingerprint'],$buyer,$p['draft_fingerprint']]);
            $q=$this->db->prepare("SELECT plan_fingerprint,approved_by,draft_fingerprint,draft_state
                FROM purchase_rehearsal.f423_google_internal_drafts
                WHERE customer_id=? AND order_id=? AND order_revision=?
                  AND provider='google_ads' AND plan_version=1");
            $q->execute([$owner,$order,$p['order_revision']]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($saved)!==1
              ||$saved[0]['approved_by']!==$buyer
              ||$saved[0]['draft_state']!=='ci_internal_unpublished'
              ||!hash_equals($saved[0]['plan_fingerprint'],$p['plan_fingerprint'])
              ||!hash_equals($saved[0]['draft_fingerprint'],$p['draft_fingerprint']))
              throw new RuntimeException('Draft replay conflict');
            return ['persisted_drafts'=>1,'draft_state'=>'ci_internal_unpublished',
              'operational_enabled'=>false,'published'=>false,'billable'=>false];
        });
    }
}
