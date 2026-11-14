<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use InvalidArgumentException;
use App\Domain\Performance\ActivationPostpaidPreview;
require_once __DIR__.'/PerformancePostpaidPolicy.php';
require_once __DIR__.'/../../Domain/Performance/ActivationPostpaidPreview.php';

/** Server-owned approved commercial terms. No PSP or financial side effects. */
final class ApprovedOfferCatalog
{
    private array $catalog;
    public function __construct()
    {
        $this->catalog=json_decode(file_get_contents(__DIR__.'/approved-catalog.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public function version(): string { return hash_file('sha256',__DIR__.'/approved-catalog.json'); }
    public function offers(): array
    {
        $out=[]; foreach ($this->catalog['offers'] as $offer) $out[$offer['id']]=$offer;
        return $out;
    }
    public function quote(string $product,array $media=[]): array
    {
        $offer=$this->offers()[$product]??throw new InvalidArgumentException('Unknown offer');
        $base=['catalog_version'=>$this->version(),'product'=>$product,'currency'=>'BRL','gateway'=>'asaas','real_charge_enabled'=>false];
        if ($product!=='performance') {
            if ($media!==[]) throw new InvalidArgumentException('Unexpected media');
            return $base+['setup_total_cents'=>$offer['setup_total_cents'],'setup_charges'=>$offer['setup_charges'],'monthly'=>$offer['monthly'],'amount_now_cents'=>$offer['setup_charges'][0]['amount_cents']];
        }
        if ($media === [] || !array_is_list($offer['channels']) ||
            array_diff(array_keys($media),$offer['channels']) !== [] ||
            count($media) > count($offer['channels'])) {
            throw new InvalidArgumentException('Choose supported, unique channels');
        }
        // Never trust browser-selected prices, channel eligibility or fees.
        // Use the same server-side approved floor as the isolated F419 HMAC
        // and calculator. A media budget is paid to the ad provider, NOT PSP.
        $eligibility=array_fill_keys($offer['channels'],true);
        $activation=ActivationPostpaidPreview::activationQuote(array_keys($media),$eligibility);
        foreach ($activation['items'] as $item) {
            $channel=$item['channel'];
            $budget=$media[$channel]??null;
            if (!is_int($budget) || $budget<$item['minimum_media_cents'] ||
                $budget>100000000) {
                throw new InvalidArgumentException('Media budget below channel-specific minimum');
            }
        }
        $estimate=PerformancePostpaidPolicy::fullMonth(array_keys($media),$media);
        if ($estimate['policy_version']!==ActivationPostpaidPreview::POLICY_VERSION ||
            $estimate['activation_total_cents']!==$activation['activation_due_now_cents'] ||
            $estimate['automatic_charge_enabled']!==false ||
            ($offer['pricing_policy']??null)!==PerformancePostpaidPolicy::VERSION ||
            ($offer['minimum_management_fee_per_channel_cents']??null)!==17000 ||
            ($offer['activation_per_channel_cents']??null)!==49000) {
            throw new InvalidArgumentException('Approved Performance pricing version mismatch');
        }
        return $base+[
            'pricing_policy'=>PerformancePostpaidPolicy::VERSION,
            'minimum_management_fee_per_channel_cents'=>PerformancePostpaidPolicy::MONTHLY_MINIMUM_CENTS,
            'activation_per_channel_cents'=>PerformancePostpaidPolicy::ACTIVATION_CENTS,
            'activation_total_cents'=>$estimate['activation_total_cents'],
            'media_by_channel_cents'=>$media,
            'media_paid_directly_to_platforms'=>true,
            'management_fee_basis_points'=>2000,
            'estimated_management_fee_cents'=>$estimate['management_fee_cents'],
            'reference_period'=>$offer['reference_period'],
            'amount_now_cents'=>$estimate['activation_total_cents'],
            'settlement_policy'=>$offer['operational_settlement_policy'],
            'real_postpaid_invoice_authorized'=>false,
        ];
    }
    public static function money(string $text): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:[.,][0-9]{1,2})?\z/D',$text)) throw new InvalidArgumentException('Invalid amount');
        $parts=preg_split('/[.,]/',$text);
        return ((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
    }
    /** Mathematical reconciliation only; actual spend must come from trusted evidence. */
    public static function reconcilePerformance(array $quote,array $actualMedia): array
    {
        if (isset($quote['pricing_policy'])) throw new InvalidArgumentException('Postpaid contract cannot use legacy advance reconciliation');
        if (($quote['product']??null)!=='performance'||($quote['management_fee_basis_points']??null)!==2000||!is_int($quote['estimated_management_fee_cents']??null)) throw new InvalidArgumentException('Invalid Performance quote');
        $expected=array_keys($quote['media_by_channel_cents']); $actual=array_keys($actualMedia); sort($expected); sort($actual);
        if ($actual!==$expected) throw new InvalidArgumentException('Channel scope mismatch');
        $sum=0;
        foreach ($actualMedia as $cents) {
            if (!is_int($cents)||$cents<0||$cents>100000000) throw new InvalidArgumentException('Invalid actual spend');
            $sum+=$cents;
        }
        $fee=intdiv($sum*2000+5000,10000);
        return ['actual_management_fee_cents'=>$fee,'adjustment_cents'=>$fee-$quote['estimated_management_fee_cents'],'automatic_charge_enabled'=>false];
    }
    public static function brl(int $cents): string { return 'R$ '.number_format($cents/100,2,',','.'); }
}
