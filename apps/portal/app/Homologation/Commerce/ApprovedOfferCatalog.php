<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use InvalidArgumentException;

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
        if ($media===[]||array_diff(array_keys($media),$offer['channels'])!==[]) throw new InvalidArgumentException('Choose a supported channel');
        $total=0;
        foreach ($media as $cents) {
            if (!is_int($cents)||$cents<$offer['minimum_media_investment_per_channel_cents']||$cents>100000000) throw new InvalidArgumentException('Minimum R700 per channel');
            $total+=$cents;
        }
        // Integer arithmetic; round half up only once, at final cent.
        $fee=intdiv($total*$offer['management_fee_basis_points']+5000,10000);
        return $base+['media_by_channel_cents'=>$media,'media_paid_directly_to_platforms'=>true,'management_fee_basis_points'=>2000,'estimated_management_fee_cents'=>$fee,'reference_period'=>null,'amount_now_cents'=>null,'settlement_policy_required'=>true];
    }
    public static function money(string $text): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,6})(?:[.,][0-9]{1,2})?\z/D',$text)) throw new InvalidArgumentException('Invalid amount');
        $parts=preg_split('/[.,]/',$text);
        return ((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
    }
    public static function brl(int $cents): string { return 'R$ '.number_format($cents/100,2,',','.'); }
}
