<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use App\Homologation\CustomerPreviewStore;
use App\Homologation\RoleBoundTestPurchase;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Queues a sandbox-only payment from the frozen server-owned offer snapshot.
 * Every SELECT/INSERT/UPDATE occurs inside a signed HMAC owner transaction.
 * There is no PSP API key in this application and no direct charge API call.
 */
final readonly class SandboxCheckoutIntent
{
    public function __construct(
        private PDO $db,
        private CustomerPreviewStore $owners
    ) {
        new RoleBoundTestPurchase($db);
    }

    public function request(string $owner,string $id,int $revision): void
    {
        $this->owners->withVerifiedScope($owner, function ()use($owner,$id,$revision):void {
            $this->db->query('SELECT pg_advisory_xact_lock(724114)');
            $q=$this->db->prepare(
                'SELECT * FROM purchase_rehearsal.customer_orders
                  WHERE id=? AND customer_id=? FOR UPDATE'
            );
            $q->execute([$id,$owner]);
            $o=$q->fetch(PDO::FETCH_ASSOC);
            if (!$o || (int)$o['revision']!==$revision ||
                $o['briefing']!==null ||
                in_array($o['payment_hold']??null,[true,'t',1,'1'],true)) {
                throw new RuntimeException('Order unavailable');
            }
            // Only Onepage and NEW versioned Performance activation are
            // eligible for this *synthetic* Asaas sandbox. Landing Page,
            // legacy prepaid Performance and recurring management charges
            // are not authorized. Never bill media or planned management.
            if (!in_array($o['product'],['onepage','performance'],true)) {
                throw new RuntimeException('Sandbox rehearsal is not enabled for this offer');
            }
            $q=$this->db->prepare(
                'SELECT order_id FROM purchase_rehearsal.sandbox_payment_intents
                  WHERE order_id=?'
            );
            $q->execute([$id]);
            if ($q->fetchColumn()!==false) {
                $this->db->prepare(
                    'UPDATE purchase_rehearsal.sandbox_payment_intents
                        SET sync_requested=true WHERE order_id=?'
                )->execute([$id]);
                return;
            }
            if ($o['payment']!=='pending' ||
                $o['payment_source']!=='manual_test' ||
                $o['offer_snapshot']===null) {
                throw new RuntimeException('Payment source conflict');
            }
            if ((int)$this->db->query(
                'SELECT purchase_rehearsal.preview_sandbox_intent_count()'
            )->fetchColumn()>=50) {
                throw new RuntimeException('Sandbox intent quota reached');
            }
            $quote=json_decode($o['offer_snapshot'],true,24,JSON_THROW_ON_ERROR);
            $amount=$quote['amount_now_cents']??null;
            if (!is_int($amount) || $amount<1 || $amount>100000000 ||
                ($quote['currency']??null)!=='BRL' ||
                ($quote['product']??null)!==$o['product'] ||
                ($quote['real_charge_enabled']??null)!==false) {
                throw new RuntimeException('Approved quote unavailable');
            }
            if ($o['product']==='performance') {
                self::requireApprovedActivationOnly($quote,$amount);
            }
            $due=(new DateTimeImmutable(
                'now',new DateTimeZone('America/Sao_Paulo')
            ))->format('Y-m-d');
            $this->db->prepare(
                'INSERT INTO purchase_rehearsal.sandbox_payment_intents
                 (order_id,amount_cents,due_date) VALUES (?,?,?)'
            )->execute([$id,$amount,$due]);
            $changed=$this->db->prepare(
                "UPDATE purchase_rehearsal.customer_orders
                    SET payment_source='asaas_sandbox',revision=revision+1
                  WHERE id=? AND customer_id=? AND payment_source='manual_test'
                    AND NOT payment_hold AND revision=?"
            );
            $changed->execute([$id,$owner,$revision]);
            if ($changed->rowCount()!==1) throw new RuntimeException('Stale order');
        });
    }

    public function read(string $owner,string $id): ?array
    {
        return $this->owners->withVerifiedScope(
            $owner,
            function ()use($id,$owner):?array {
                $q=$this->db->prepare(
                    'SELECT i.state,i.provider_status,i.invoice_url
                       FROM purchase_rehearsal.sandbox_payment_intents i
                       JOIN purchase_rehearsal.customer_orders o ON o.id=i.order_id
                      WHERE o.id=? AND o.customer_id=?'
                );
                $q->execute([$id,$owner]);
                return $q->fetch(PDO::FETCH_ASSOC)?:null;
            }
        );
    }
    /**
     * Freeze the server-created new-contract snapshot at the PSP boundary.
     * This does NOT authorize an invoice, recurring billing, ad spend, C6
     * routing, charge on production, or a legacy Performance conversion.
     */
    private static function requireApprovedActivationOnly(array $quote,int $amount): void
    {
        if (($quote['pricing_policy']??null)!==PerformancePostpaidPolicy::VERSION ||
            ($quote['gateway']??null)!=='asaas' ||
            ($quote['real_charge_enabled']??null)!==false ||
            ($quote['real_postpaid_invoice_authorized']??null)!==false ||
            ($quote['minimum_management_fee_per_channel_cents']??null)!==PerformancePostpaidPolicy::MONTHLY_MINIMUM_CENTS ||
            ($quote['activation_per_channel_cents']??null)!==PerformancePostpaidPolicy::ACTIVATION_CENTS ||
            ($quote['settlement_policy']??null)!=='calendar_month_actual_spend_per_channel_floor_postpaid' ||
            ($quote['media_paid_directly_to_platforms']??null)!==true ||
            ($quote['management_fee_basis_points']??null)!==2000) {
            throw new RuntimeException('Approved Performance activation terms unavailable');
        }
        $media=$quote['media_by_channel_cents']??null;
        if (!is_array($media) || $media===[] || count($media)>2 ||
            array_diff(array_keys($media),['meta_ads','google_ads'])!==[]) {
            throw new RuntimeException('Approved channel scope unavailable');
        }
        try {
            $eligibility=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
            $setup=\App\Domain\Performance\ActivationPostpaidPreview::activationQuote(
                array_keys($media),$eligibility
            );
            foreach ($setup['items'] as $row) {
                $budget=$media[$row['channel']]??null;
                if (!is_int($budget) || $budget<$row['minimum_media_cents'] ||
                    $budget>100_000_000) {
                    throw new \InvalidArgumentException('Invalid media budget');
                }
            }
            $calculated=PerformancePostpaidPolicy::fullMonth(array_keys($media),$media);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid frozen Performance activation snapshot');
        }
        $expected=$calculated['activation_total_cents'];
        if ($expected!==$setup['activation_due_now_cents'] ||
            $amount!==$expected ||
            ($quote['activation_total_cents']??null)!==$expected ||
            ($quote['estimated_management_fee_cents']??null)!==$calculated['management_fee_cents']) {
            throw new RuntimeException('Performance PSP amount does not match activation');
        }
    }

}
