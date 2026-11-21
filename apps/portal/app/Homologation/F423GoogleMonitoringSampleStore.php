<?php
declare(strict_types=1);
namespace App\Homologation;
use PDO;
use RuntimeException;

/**
 * Two immutable, explicitly fictitious dated samples from OFFLINE CI provider
 * simulation. Never Google API metrics, financial authority or campaign data.
 */
final class F423GoogleMonitoringSampleStore
{
    public function __construct(private PDO $db, private CustomerPreviewStore $customer) {}

    private const EXPECTED=[
      ['sample_date'=>'2026-11-19','impressions'=>1000,'clicks'=>40,
       'conversions'=>2,'source'=>'ci_offline_google_ads_provider_fixture'],
      ['sample_date'=>'2026-11-20','impressions'=>1200,'clicks'=>46,
       'conversions'=>2,'source'=>'ci_offline_google_ads_provider_fixture'],
    ];
    private static function valid(string $owner,string $order,int $revision,string $digest):bool
    {
        return preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)===1
          && preg_match('/\ATEST-[a-f0-9]{32}\z/D',$order)===1
          && $revision>0&&preg_match('/\A[a-f0-9]{64}\z/D',$digest)===1;
    }

    public function read(string $owner,string $order,int $revision,string $digest,string $draftFingerprint):array
    {
        if(!self::valid($owner,$order,$revision,$digest)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$draftFingerprint)!==1) return [];
        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$revision,$digest,$draftFingerprint):array {
            $q=$this->db->prepare("SELECT m.sample_date::text sample_date,m.impressions,m.clicks,m.conversions,m.source
                FROM purchase_rehearsal.f423_google_monitoring_samples m
                JOIN purchase_rehearsal.f423_google_internal_drafts d
                 ON d.customer_id=m.customer_id AND d.order_id=m.order_id
                AND d.order_revision=m.order_revision AND d.provider=m.provider
                AND d.plan_version=m.plan_version AND d.briefing_sha256=m.briefing_sha256
                AND d.plan_fingerprint=m.plan_fingerprint
                AND d.draft_fingerprint=m.draft_fingerprint
                WHERE m.customer_id=? AND m.order_id=? AND m.order_revision=?
                 AND m.briefing_sha256=? AND m.provider='google_ads'
                 AND m.plan_version=1 AND m.draft_fingerprint=?
                 AND d.draft_state='ci_internal_unpublished'
                ORDER BY m.sample_date");
            $q->execute([$owner,$order,$revision,$digest,$draftFingerprint]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==2) return [];
            $result=[];
            foreach($rows as $r){
                $result[]=['sample_date'=>$r['sample_date'],
                  'impressions'=>(int)$r['impressions'],'clicks'=>(int)$r['clicks'],
                  'conversions'=>(int)$r['conversions'],'source'=>$r['source']];
            }
            return $result===self::EXPECTED ? $result : [];
        });
    }

    /** Single signed S2S packet, all-or-nothing transaction, exact replay only. */
    public function persist(string $owner,string $order,array $p):array
    {
        $expected=['schema','company_id','order_id','order_revision','briefing_sha256',
           'provider','plan_version','plan_fingerprint','draft_fingerprint','samples',
           'operational_enabled','ads_write_enabled','billable'];
        $actual=array_keys($p);sort($expected);sort($actual);
        if($actual!==$expected||($p['schema']??null)!=='f423_google_monitoring_ci_v1'
          ||($p['company_id']??null)!==$owner||($p['order_id']??null)!==$order
          ||!is_int($p['order_revision']??null)||!is_string($p['briefing_sha256']??null)
          ||!self::valid($owner,$order,$p['order_revision'],$p['briefing_sha256'])
          ||($p['provider']??null)!=='google_ads'||($p['plan_version']??null)!==1
          ||!is_string($p['plan_fingerprint']??null)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$p['plan_fingerprint'])!==1
          ||!is_string($p['draft_fingerprint']??null)
          ||preg_match('/\A[a-f0-9]{64}\z/D',$p['draft_fingerprint'])!==1
          ||($p['samples']??null)!==self::EXPECTED
          ||($p['operational_enabled']??null)!==false
          ||($p['ads_write_enabled']??null)!==false
          ||($p['billable']??null)!==false)
          throw new RuntimeException('Invalid offline monitoring contract');

        return $this->customer->withVerifiedScope($owner,function() use($owner,$order,$p):array {
            $q=$this->db->prepare("SELECT revision,briefing,payment,payment_source,payment_hold,
                product,offer_snapshot FROM purchase_rehearsal.customer_orders
                WHERE customer_id=? AND id=? FOR UPDATE");
            $q->execute([$owner,$order]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($o)||$o['product']!=='performance'||$o['payment']!=='approved'
              ||$o['payment_source']!=='asaas_sandbox'
              ||in_array($o['payment_hold'],[true,'t','1',1],true)
              ||(int)$o['revision']!==$p['order_revision'])
              throw new RuntimeException('Purchase/revision not valid');
            $briefing=json_decode((string)$o['briefing'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($briefing)||!hash_equals($p['briefing_sha256'],
              hash('sha256',json_encode($briefing,JSON_THROW_ON_ERROR))))
              throw new RuntimeException('Briefing not current');
            $offer=json_decode((string)$o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if(($offer['media_by_channel_cents']??null)!==['google_ads'=>100000]
              ||($offer['activation_per_channel_cents']??null)!==49000)
              throw new RuntimeException('Another purchase');
            $q=$this->db->prepare("SELECT d.draft_fingerprint
                FROM purchase_rehearsal.f423_google_internal_drafts d
                JOIN purchase_rehearsal.f423_google_plan_approvals a
                 ON a.customer_id=d.customer_id AND a.order_id=d.order_id
                AND a.order_revision=d.order_revision AND a.briefing_sha256=d.briefing_sha256
                AND a.provider=d.provider AND a.plan_version=d.plan_version
                AND a.plan_fingerprint=d.plan_fingerprint AND a.approved_by=d.approved_by
                WHERE d.customer_id=? AND d.order_id=? AND d.order_revision=?
                  AND d.briefing_sha256=? AND d.provider='google_ads'
                  AND d.plan_version=1 AND d.plan_fingerprint=? AND d.draft_state='ci_internal_unpublished'");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],$p['plan_fingerprint']]);
            $saved=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($saved)!==1||!hash_equals($saved[0]['draft_fingerprint'],$p['draft_fingerprint']))
              throw new RuntimeException('Approved unpublished draft missing');
            $insert=$this->db->prepare("INSERT INTO purchase_rehearsal.f423_google_monitoring_samples
               (customer_id,order_id,order_revision,briefing_sha256,provider,plan_version,
                plan_fingerprint,draft_fingerprint,sample_date,impressions,clicks,conversions,source)
               VALUES(?,?,?,?,'google_ads',1,?,?,CAST(? AS date),?,?,?,'ci_offline_google_ads_provider_fixture')
               ON CONFLICT DO NOTHING");
            foreach(self::EXPECTED as $m) {
                $insert->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],
                  $p['plan_fingerprint'],$p['draft_fingerprint'],$m['sample_date'],
                  $m['impressions'],$m['clicks'],$m['conversions']]);
            }
            $q=$this->db->prepare("SELECT sample_date::text sample_date,impressions,clicks,conversions,source
              FROM purchase_rehearsal.f423_google_monitoring_samples
              WHERE customer_id=? AND order_id=? AND order_revision=? AND briefing_sha256=?
                AND provider='google_ads' AND plan_version=1 AND draft_fingerprint=?
              ORDER BY sample_date");
            $q->execute([$owner,$order,$p['order_revision'],$p['briefing_sha256'],$p['draft_fingerprint']]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==2) throw new RuntimeException('Incomplete sample replay');
            foreach($rows as $i=>$r) {
                $actual=['sample_date'=>$r['sample_date'],'impressions'=>(int)$r['impressions'],
                  'clicks'=>(int)$r['clicks'],'conversions'=>(int)$r['conversions'],'source'=>$r['source']];
                if($actual!==self::EXPECTED[$i]) throw new RuntimeException('Conflicting sample replay');
            }
            return ['samples_persisted'=>2,'source'=>'ci_offline_google_ads_provider_fixture',
               'operational_enabled'=>false,'billable'=>false,'published'=>false];
        });
    }
}
