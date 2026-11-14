<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Legacy advances and new postpaid reports are separate; this class never creates PSP charges. */
final class PerformanceSettlement
{
    public function __construct(private PDO $db) {new \App\Homologation\RoleBoundTestPurchase($db);}
    public static function calculate(array $quote,string $period,array $actual,string $evidence):array
    {
        if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$period)||!preg_match('/\ASIM-[A-Z0-9][A-Z0-9-]{2,76}\z/D',$evidence)) throw new InvalidArgumentException('Synthetic report and period required');
        if (($quote['product']??null)!=='performance'||($quote['currency']??null)!=='BRL'||($quote['management_fee_basis_points']??null)!==2000||($quote['media_paid_directly_to_platforms']??null)!==true) throw new InvalidArgumentException('Unsupported accepted terms');
        $planned=$quote['media_by_channel_cents']??[];
        if (!is_array($planned)||$planned===[]||array_diff(array_keys($planned),['google_ads','meta_ads'])) throw new InvalidArgumentException('Unsupported channel');
        $sum=0;$rows=[];
        foreach ($planned as $channel=>$cents) {
            if (!is_int($cents)||$cents<70000||$cents>100000000) throw new InvalidArgumentException('Invalid planned media');
            $sum+=$cents;
        }
        // New contracts: the activation amount is independent of the
        // full-month management fee. A report remains review-only, never a
        // payment command. Old stored contracts still reconcile advances.
        if (($quote['pricing_policy']??null)===PerformancePostpaidPolicy::VERSION) {
            $activation=count($planned)*PerformancePostpaidPolicy::ACTIVATION_CENTS;
            if (($quote['amount_now_cents']??null)!==$activation ||
                ($quote['activation_total_cents']??null)!==$activation ||
                ($quote['minimum_management_fee_per_channel_cents']??null)!==PerformancePostpaidPolicy::MONTHLY_MINIMUM_CENTS ||
                ($quote['activation_per_channel_cents']??null)!==PerformancePostpaidPolicy::ACTIVATION_CENTS ||
                ($quote['real_charge_enabled']??null)!==false ||
                ($quote['real_postpaid_invoice_authorized']??null)!==false) {
                throw new InvalidArgumentException('Signed postpaid contract mismatch');
            }
            foreach ($planned as $channel=>$cents) {
                $minimum=$channel==='google_ads'?100000:70000;
                if ($cents<$minimum) throw new InvalidArgumentException('Invalid channel-specific budget');
            }
            $calculated=PerformancePostpaidPolicy::fullMonth(array_keys($planned),$actual);
            if (($quote['estimated_management_fee_cents']??null)!==
                PerformancePostpaidPolicy::fullMonth(array_keys($planned),$planned)['management_fee_cents']) {
                throw new InvalidArgumentException('Stale approved postpaid offer snapshot');
            }
            $details=[];
            foreach ($calculated['channels'] as $channel=>$amounts) {
                $minimum=$channel==='google_ads'?100000:70000;
                $details[$channel]=$amounts+[
                    'planned_cents'=>$planned[$channel],
                    'actual_cents'=>$actual[$channel],
                    'below_planned_minimum'=>$actual[$channel]<$minimum,
                ];
            }
            return [
                'policy_version'=>PerformancePostpaidPolicy::VERSION,
                'period'=>$period,
                'evidence_ref'=>$evidence,
                'currency'=>'BRL',
                'activation_paid_cents'=>$activation,
                'actual_management_fee_cents'=>$calculated['management_fee_cents'],
                'channels'=>$details,
                'status'=>'postpaid_requires_finance_review',
                'real_invoice_authorized'=>false,
                'first_partial_month_proration_not_approved'=>true,
            ];
        }
        if (array_key_exists('pricing_policy',$quote)) {
            throw new InvalidArgumentException('Unknown historical commercial policy');
        }
        $advance=intdiv($sum*2000+5000,10000);
        if (($quote['estimated_management_fee_cents']??null)!==$advance||($quote['amount_now_cents']??null)!==$advance) throw new InvalidArgumentException('Advance mismatch');
        $result=ApprovedOfferCatalog::reconcilePerformance($quote,$actual);
        foreach ($planned as $channel=>$cents) $rows[$channel]=['planned_cents'=>$cents,'actual_cents'=>$actual[$channel],'below_planned_minimum'=>$actual[$channel]<70000];
        return ['period'=>$period,'evidence_ref'=>$evidence,'currency'=>'BRL','advance_fee_cents'=>$advance,'actual_management_fee_cents'=>$result['actual_management_fee_cents'],'adjustment_cents'=>$result['adjustment_cents'],'channels'=>$rows,'status'=>'requires_finance_review_no_auto_charge_or_credit'];
    }
    public function reports(string $id):array
    {
        $q=$this->db->prepare('SELECT version,report,decision,client_note,created_at,decided_at FROM purchase_rehearsal.performance_settlement_reports WHERE order_id=? ORDER BY version DESC LIMIT 20');$q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['version']=(int)$row['version'];$row['report']=json_decode($row['report'],true,512,JSON_THROW_ON_ERROR);}unset($row);return $rows;
    }
    public function submit(string $id,int $revision,string $period,array $actual,string $evidence):void
    {
        $ownTransaction=!$this->db->inTransaction(); if ($ownTransaction) $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM purchase_rehearsal.customer_orders WHERE id=? FOR UPDATE');$q->execute([$id]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if (!$o||$o['product']!=='performance'||(int)$o['revision']!==$revision||$o['payment']!=='approved'||$o['briefing']===null||$o['offer_snapshot']===null) throw new RuntimeException('Report unavailable');
            if ($o['payment_source']==='asaas_sandbox') {
                $q=$this->db->prepare("SELECT amount_cents FROM purchase_rehearsal.sandbox_payment_intents WHERE order_id=? AND state='approved'");$q->execute([$id]);$paid=$q->fetchColumn();if($paid===false)throw new RuntimeException('Advance not confirmed');
            }
            $quote=json_decode($o['offer_snapshot'],true,512,JSON_THROW_ON_ERROR);
            $postpaid=($quote['pricing_policy']??null)===PerformancePostpaidPolicy::VERSION;
            $reports=$this->reports($id);$version=(int)$o['performance_report_version'];
            if ($version>=20 ||
                ($reports!==[] && (
                    $reports[0]['version']!==$version ||
                    ($postpaid
                        ? !(($reports[0]['decision']==='disputed' && $period===$reports[0]['report']['period']) ||
                            ($reports[0]['decision']==='acknowledged' && $period>$reports[0]['report']['period']))
                        : $reports[0]['decision']!=='disputed')
                ))) throw new RuntimeException('Existing report awaiting review');
            $report=self::calculate($quote,$period,$actual,$evidence);
            if(isset($paid)&&(int)$paid!==($report['activation_paid_cents']??$report['advance_fee_cents'])) throw new RuntimeException('Paid activation/advance mismatch');
            $q=$this->db->prepare('INSERT INTO purchase_rehearsal.performance_settlement_reports(order_id,version,report) VALUES (?,?,?::jsonb)');$q->execute([$id,$version+1,json_encode($report,JSON_THROW_ON_ERROR)]);
            $q=$this->db->prepare('UPDATE purchase_rehearsal.customer_orders SET performance_report_version=?,revision=revision+1 WHERE id=?');$q->execute([$version+1,$id]);if ($ownTransaction) $this->db->commit();
        }catch(\Throwable $e){if($ownTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function respond(string $owner,string $id,int $revision,int $version,string $decision,string $note):void
    {
        if (!in_array($decision,['acknowledged','disputed'],true)||strlen($note)>1000||($decision==='disputed'&&trim($note)===''))throw new InvalidArgumentException('Report response required');
        $ownTransaction=!$this->db->inTransaction(); if ($ownTransaction) $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT performance_report_version,revision FROM purchase_rehearsal.customer_orders WHERE id=? AND customer_id=? AND product='performance' FOR UPDATE");$q->execute([$id,$owner]);$o=$q->fetch(PDO::FETCH_ASSOC);
            if(!$o||(int)$o['revision']!==$revision||(int)$o['performance_report_version']!==$version)throw new RuntimeException('Report unavailable');
            $q=$this->db->prepare("UPDATE purchase_rehearsal.performance_settlement_reports SET decision=?,client_note=?,decided_at=now() WHERE order_id=? AND version=? AND decision='pending'");$q->execute([$decision,trim($note),$id,$version]);if($q->rowCount()!==1)throw new RuntimeException('Already reviewed');
            $q=$this->db->prepare('UPDATE purchase_rehearsal.customer_orders SET revision=revision+1 WHERE id=?');$q->execute([$id]);if ($ownTransaction) $this->db->commit();
        }catch(\Throwable $e){if($ownTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
