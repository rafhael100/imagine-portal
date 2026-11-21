<?php
declare(strict_types=1);
namespace App\Homologation;
use PDO;
use RuntimeException;

/** Immutable CI-only improvement suggestion; never Google Ads actions or real results. */
final class F423GoogleOfflineImprovementStore
{
    private const ACTION='audit_search_terms_offline_simulation';
    private const ORIGIN='ci_offline_google_ads_provider_fixture';

    public function __construct(private PDO $db,private CustomerPreviewStore $customer) {}

    private static function valid(string $owner,string $order,int $revision,string $digest): bool
    {
        return preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)===1
          && preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)===1
          && $revision>0 && preg_match('/\A[a-f0-9]{64}\z/D',$digest)===1;
    }

    /** Deterministic hash of the two *fictitious* metrics, never an Ads reading. */
    public static function evidence(array $samples): string
    {
        return hash('sha256',json_encode($samples,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    public static function fingerprint(string $owner,string $order,int $revision,
         string $digest,string $plan,string $draft,string $evidence):string
    {
        return hash('sha256',implode("\0",[$owner,$order,(string)$revision,$digest,'google_ads',
          '1','1',$plan,$draft,$evidence,self::ACTION,'ci_offline_improvement_only']));
    }

    public function read(string $owner,string $order,int $revision,string $digest,string $draftFingerprint):?array
    {
        if(!self::valid($owner,$order,$revision,$digest)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$draftFingerprint)!==1) return null;
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$revision,$digest,$draftFingerprint):?array {
            $q=$this->db->prepare("SELECT p.proposal_fingerprint,p.evidence_sha256,p.plan_fingerprint,
                p.draft_fingerprint,p.action,p.proposal_state
                FROM purchase_rehearsal.f423_google_offline_improvements p
                JOIN purchase_rehearsal.f423_google_internal_drafts d
                  ON d.customer_id=p.customer_id AND d.order_id=p.order_id
                 AND d.order_revision=p.order_revision AND d.provider=p.provider
                 AND d.plan_version=p.plan_version AND d.briefing_sha256=p.briefing_sha256
                 AND d.plan_fingerprint=p.plan_fingerprint AND d.draft_fingerprint=p.draft_fingerprint
                WHERE p.customer_id=? AND p.order_id=? AND p.order_revision=?
                  AND p.briefing_sha256=? AND p.provider='google_ads'
                  AND p.plan_version=1 AND p.proposal_version=1 AND p.draft_fingerprint=?
                  AND d.draft_state='ci_internal_unpublished'");
            $q->execute([$owner,$order,$revision,$digest,$draftFingerprint]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1||$rows[0]['proposal_state']!=='awaiting_separate_approval'
              ||$rows[0]['action']!==self::ACTION) return null;
            // Approval lives in a SECOND immutable ledger, never in the proposal row.
            $approvedSql=$this->db->prepare("SELECT a.approved_by FROM
              purchase_rehearsal.f423_google_improvement_approvals a
              JOIN purchase_rehearsal.f423_google_plan_approvals pa
                ON pa.customer_id=a.customer_id AND pa.order_id=a.order_id
               AND pa.order_revision=a.order_revision AND pa.provider=a.provider
               AND pa.plan_version=a.plan_version AND pa.briefing_sha256=a.briefing_sha256
               AND pa.approved_by=a.approved_by
              JOIN purchase_rehearsal.f423_briefing_reviews br
                ON br.customer_id=a.customer_id AND br.order_id=a.order_id
               AND br.order_revision=a.order_revision AND br.briefing_sha256=a.briefing_sha256
               AND br.approved_by=a.approved_by
              WHERE a.customer_id=? AND a.order_id=? AND a.order_revision=?
                AND a.briefing_sha256=? AND a.provider='google_ads' AND a.plan_version=1
                AND a.proposal_version=1 AND a.proposal_fingerprint=?");
            $approvedSql->execute([$owner,$order,$revision,$digest,$rows[0]['proposal_fingerprint']]);
            $approved=count($approvedSql->fetchAll(PDO::FETCH_ASSOC))===1;
            return ['state'=>'awaiting_separate_approval','proposal_version'=>1,
              'action'=>self::ACTION,'evidence_sha256'=>$rows[0]['evidence_sha256'],
              'proposal_fingerprint'=>$rows[0]['proposal_fingerprint'],
              'plan_fingerprint'=>$rows[0]['plan_fingerprint'],
              'draft_fingerprint'=>$rows[0]['draft_fingerprint'],
              'metric_origin'=>self::ORIGIN,'separate_approval_recorded'=>$approved,
               'approval_state'=>$approved?'synthetic_buyer_approved':'not_approved',
              'applied'=>false,'published'=>false,'operational_enabled'=>false];
        });
    }

    public function persist(string $owner,string $order,array $p):array
    {
        $expected=['schema','company_id','order_id','order_revision','briefing_sha256','provider',
         'plan_version','proposal_version','plan_fingerprint','draft_fingerprint',
         'evidence_sha256','proposal_fingerprint','action','state',
         'metric_origin','separate_approval_recorded','applied',
         'operational_enabled','billable'];
        $keys=array_keys($p);sort($keys);sort($expected);
        if($keys!==$expected || ($p['schema']??null)!=='f423_offline_improvement_ci_v1'
          ||($p['company_id']??null)!==$owner||($p['order_id']??null)!==$order
          ||!is_int($p['order_revision']??null)
          ||!is_string($p['briefing_sha256']??null)
          ||!self::valid($owner,$order,$p['order_revision'],$p['briefing_sha256'])
          ||($p['provider']??null)!=='google_ads'
          ||($p['plan_version']??null)!==1||($p['proposal_version']??null)!==1
          ||($p['action']??null)!==self::ACTION
          ||($p['state']??null)!=='awaiting_separate_approval'
          ||($p['metric_origin']??null)!==self::ORIGIN
          ||($p['separate_approval_recorded']??null)!==false
          ||($p['applied']??null)!==false
          ||($p['operational_enabled']??null)!==false||($p['billable']??null)!==false)
          throw new RuntimeException('Invalid CI proposal envelope');
        foreach(['plan_fingerprint','draft_fingerprint','evidence_sha256','proposal_fingerprint'] as $key)
          if(!is_string($p[$key]??null)||preg_match('/\A[a-f0-9]{64}\z/D',$p[$key])!==1)
             throw new RuntimeException('Invalid proposal SHA');

        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$p):array {
            $q=$this->db->prepare("SELECT revision,briefing,payment,payment_source,payment_hold,
                 product,offer_snapshot FROM purchase_rehearsal.customer_orders
                 WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($o)||$o['product']!=='performance'||$o['payment']!=='approved'
              ||$o['payment_source']!=='asaas_sandbox'
              ||in_array($o['payment_hold'],[true,'t','1',1],true)
              ||(int)$o['revision']!==$p['order_revision'])
              throw new RuntimeException('No current confirmed purchase');
            $briefing=json_decode((string)$o['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing)||!hash_equals($p['briefing_sha256'],
              hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR))))
              throw new RuntimeException('Briefing changed');
            $offer=json_decode((string)$o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if(($offer['media_by_channel_cents']??null)!==['google_ads'=>100000]
              ||($offer['activation_per_channel_cents']??null)!==49000)
              throw new RuntimeException('Not the same Google-only offer');
            $q=$this->db->prepare("SELECT d.draft_fingerprint
              FROM purchase_rehearsal.f423_google_internal_drafts d
              JOIN purchase_rehearsal.f423_google_plan_approvals a
                 ON a.customer_id=d.customer_id AND a.order_id=d.order_id
                AND a.order_revision=d.order_revision AND a.provider=d.provider
                AND a.plan_version=d.plan_version AND a.briefing_sha256=d.briefing_sha256
                AND a.plan_fingerprint=d.plan_fingerprint AND a.approved_by=d.approved_by
              WHERE d.customer_id=? AND d.order_id=? AND d.order_revision=?
                AND d.briefing_sha256=? AND d.provider='google_ads' AND d.plan_version=1
                AND d.plan_fingerprint=? AND d.draft_state='ci_internal_unpublished'");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],$p['plan_fingerprint']]);
            $draft=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($draft)!==1||!hash_equals($draft[0]['draft_fingerprint'],$p['draft_fingerprint']))
              throw new RuntimeException('Exact approved unpublished draft missing');
            $q=$this->db->prepare("SELECT sample_date::text sample_date,impressions,clicks,conversions,source
              FROM purchase_rehearsal.f423_google_monitoring_samples
              WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?
                AND provider='google_ads' AND plan_version=1
                AND plan_fingerprint=? AND draft_fingerprint=?
              ORDER BY sample_date");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],
              $p['plan_fingerprint'],$p['draft_fingerprint']]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==2) throw new RuntimeException('Both offline samples required');
            $normalized=[];
            foreach($rows as $r)
              $normalized[]=['sample_date'=>$r['sample_date'],'impressions'=>(int)$r['impressions'],
                'clicks'=>(int)$r['clicks'],'conversions'=>(int)$r['conversions'],'source'=>$r['source']];
            $expectedSamples=[
              ['sample_date'=>'2026-11-19','impressions'=>1000,'clicks'=>40,
               'conversions'=>2,'source'=>self::ORIGIN],
              ['sample_date'=>'2026-11-20','impressions'=>1200,'clicks'=>46,
               'conversions'=>2,'source'=>self::ORIGIN],
            ];
            if($normalized!==$expectedSamples
              ||!hash_equals(self::evidence($normalized),$p['evidence_sha256']))
              throw new RuntimeException('Offline evidence mismatch');
            $fingerprint=self::fingerprint($owner,$order,$p['order_revision'],$p['briefing_sha256'],
              $p['plan_fingerprint'],$p['draft_fingerprint'],$p['evidence_sha256']);
            if(!hash_equals($fingerprint,$p['proposal_fingerprint']))
              throw new RuntimeException('Proposal altered');
            $q=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_offline_improvements
              (customer_id,order_id,order_revision,briefing_sha256,provider,
               plan_version,proposal_version,plan_fingerprint,draft_fingerprint,
               evidence_sha256,proposal_fingerprint,action,proposal_state)
              VALUES(?,?,?,?,'google_ads',1,1,?,?,?,?,?,
               'awaiting_separate_approval') ON CONFLICT DO NOTHING");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],
              $p['plan_fingerprint'],$p['draft_fingerprint'],$p['evidence_sha256'],
              $p['proposal_fingerprint'],self::ACTION]);
            $q=$this->db->prepare("SELECT proposal_fingerprint,proposal_state
              FROM purchase_rehearsal.f423_google_offline_improvements
              WHERE customer_id=? AND order_id=? AND order_revision=?
                AND provider='google_ads' AND plan_version=1 AND proposal_version=1");
            $q->execute([$owner,$order,$p['order_revision']]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($saved)!==1||$saved[0]['proposal_state']!=='awaiting_separate_approval'
              ||!hash_equals($saved[0]['proposal_fingerprint'],$p['proposal_fingerprint']))
               throw new RuntimeException('Conflicting proposal replay');
            return ['persisted_proposals'=>1,'proposal_state'=>'awaiting_separate_approval',
              'separate_approval_recorded'=>false,'applied'=>false,
              'published'=>false,'billable'=>false,'operational_enabled'=>false];
        });
    }
    /** Explicit buyer approval of the exact OFFLINE proposal. No Ads permission or application. */
    public function approve(string $owner,string $order,string $buyer,int $revision,
      int $version,string $fingerprint):bool
    {
        if(!self::valid($owner,$order,$revision,$fingerprint)
          ||!preg_match('/\Ademo-[a-z0-9-]{3,40}\z/D',$buyer)
          ||$version!==1) return false;
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$buyer,$revision,$fingerprint):bool {
            $q=$this->db->prepare("SELECT revision,briefing,product,payment,payment_source,payment_hold,
              offer_snapshot FROM purchase_rehearsal.customer_orders
              WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($o)||$o['product']!=='performance'||$o['payment']!=='approved'
              ||$o['payment_source']!=='asaas_sandbox'
              ||in_array($o['payment_hold'],[true,'t','1',1],true)
              ||(int)$o['revision']!==$revision) return false;
            $briefing=json_decode((string)$o['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing))return false;
            $digest=hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR));
            $offer=json_decode((string)$o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if(($offer['media_by_channel_cents']??null)!==['google_ads'=>100000]
              ||($offer['activation_per_channel_cents']??null)!==49000) return false;
            $q=$this->db->prepare("SELECT b.approved_by
              FROM purchase_rehearsal.f423_briefing_reviews b
              JOIN purchase_rehearsal.f423_google_plan_approvals pa
                ON pa.customer_id=b.customer_id AND pa.order_id=b.order_id
               AND pa.order_revision=b.order_revision AND pa.briefing_sha256=b.briefing_sha256
               AND pa.provider='google_ads' AND pa.plan_version=1
               AND pa.approved_by=b.approved_by
              WHERE b.customer_id=? AND b.order_id=? AND b.order_revision=?
                AND b.briefing_sha256=?");
            $q->execute([$owner,$order,$revision,$digest]);
            if($q->fetchColumn()!==$buyer)return false;
            $q=$this->db->prepare("SELECT p.proposal_fingerprint,p.draft_fingerprint,p.plan_fingerprint
              FROM purchase_rehearsal.f423_google_offline_improvements p
              JOIN purchase_rehearsal.f423_google_internal_drafts d
                ON d.customer_id=p.customer_id AND d.order_id=p.order_id
               AND d.order_revision=p.order_revision AND d.briefing_sha256=p.briefing_sha256
               AND d.provider=p.provider AND d.plan_version=p.plan_version
               AND d.plan_fingerprint=p.plan_fingerprint AND d.draft_fingerprint=p.draft_fingerprint
              WHERE p.customer_id=? AND p.order_id=? AND p.order_revision=?
                AND p.briefing_sha256=? AND p.provider='google_ads'
                AND p.plan_version=1 AND p.proposal_version=1
                AND p.proposal_state='awaiting_separate_approval'
                AND d.draft_state='ci_internal_unpublished'");
            $q->execute([$owner,$order,$revision,$digest]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1 || !hash_equals($rows[0]['proposal_fingerprint'],$fingerprint))
              return false;
            $q=$this->db->prepare("SELECT count(*) FROM
              purchase_rehearsal.f423_google_monitoring_samples
              WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?
                AND provider='google_ads' AND plan_version=1
                AND plan_fingerprint=? AND draft_fingerprint=?");
            $q->execute([$owner,$order,$revision,$digest,
               $rows[0]['plan_fingerprint'],$rows[0]['draft_fingerprint']]);
            if((int)$q->fetchColumn()!==2)return false;
            $q=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_improvement_approvals
              (customer_id,order_id,order_revision,briefing_sha256,provider,
               plan_version,proposal_version,proposal_fingerprint,approved_by)
              VALUES(?,?,?,?,'google_ads',1,1,?,?) ON CONFLICT DO NOTHING");
            $q->execute([$owner,$order,$revision,$digest,$fingerprint,$buyer]);
            $q=$this->db->prepare("SELECT proposal_fingerprint,approved_by FROM
              purchase_rehearsal.f423_google_improvement_approvals
              WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?
                AND provider='google_ads' AND plan_version=1 AND proposal_version=1");
            $q->execute([$owner,$order,$revision,$digest]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            return count($saved)===1
              &&hash_equals($saved[0]['proposal_fingerprint'],$fingerprint)
              &&$saved[0]['approved_by']===$buyer;
        });
    }

}
