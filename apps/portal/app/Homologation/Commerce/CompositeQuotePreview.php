<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use InvalidArgumentException;
require_once __DIR__.'/ApprovedOfferCatalog.php';

/**
 * Synthetic composite-quote calculator ONLY.
 *
 * P0 gap: a marketing selection can include a website and/or two ad channels
 * but the CURRENT Portal V5 checkout accepts only individual catalog quotes.
 * This calculator does not create a cart, order, contract, invoice, customer,
 * authenticated session, PSP charge, installment mandate or payment schedule.
 *
 * It recomputes ALL money on the server from the existing approved catalog.
 * No browser-selected amount, percentage or customer/tenant key is accepted.
 */
final class CompositeQuotePreview
{
    public function __construct(private readonly ApprovedOfferCatalog $catalog = new ApprovedOfferCatalog())
    {
    }

    public function quote(array $selection): array
    {
        $fields=array_keys($selection);
        sort($fields,SORT_STRING);
        if ($fields!==['channels','site','tier'])
            throw new InvalidArgumentException('Exact anonymous composite selection required');

        $site=$selection['site'];
        $tier=$selection['tier'];
        $channels=$selection['channels'];
        if (!is_string($site)||!in_array($site,['none','onepage','landing-pages'],true)||
            !is_string($tier)||!in_array($tier,['minimum','recommended'],true)||
            !is_array($channels)||!array_is_list($channels)||count($channels)>2)
            throw new InvalidArgumentException('Unreviewed composite catalog selection');
        $approved=['google_ads','meta_ads'];
        foreach ($channels as $channel) {
            if (!is_string($channel)||!in_array($channel,$approved,true))
                throw new InvalidArgumentException('Channel not approved for Portal V5 checkout');
        }
        if (count(array_unique($channels))!==count($channels))
            throw new InvalidArgumentException('Duplicate channel in composite selection');
        if ($site==='none' && $channels===[])
            throw new InvalidArgumentException('Empty selection is not a quote');

        // The catalog PHP—not the visitor—chooses site prices and channel
        // minimum/recommended spend. It independently calculates fees.
        $offer=$this->catalog->offers()['performance']??null;
        if (!is_array($offer)||($offer['direct_checkout_enabled']??null)!==false)
            throw new InvalidArgumentException('Portal V5 Performance preview catalog required');

        $media=[];
        foreach ($channels as $channel) {
            $amount=$offer[$tier.'_media_investment_by_channel_cents'][$channel]??null;
            if (!is_int($amount)||$amount<=0||$amount>100000000)
                throw new InvalidArgumentException('Missing approved server media planning amount');
            $media[$channel]=$amount;
        }
        $siteQuote=$site==='none'?null:$this->catalog->quote($site);
        $adsQuote=$media===[]?null:$this->catalog->quote('performance',$media);
        if (($siteQuote!==null&&$siteQuote['real_charge_enabled']!==false)||
            ($adsQuote!==null&&(
                $adsQuote['real_charge_enabled']!==false||
                $adsQuote['real_postpaid_invoice_authorized']!==false||
                $adsQuote['media_paid_directly_to_platforms']!==true)))
            throw new InvalidArgumentException('Catalog would authorize unreviewed charging');

        $siteSetup=$siteQuote['setup_total_cents']??0;
        $activation=$adsQuote['activation_total_cents']??0;
        $siteMonthly=$siteQuote['monthly']['amount_cents']??0;
        $management=$adsQuote['estimated_management_fee_cents']??0;
        $total=$siteSetup+$activation;
        if (!is_int($total)||$total<0||$total>100000000)
            throw new InvalidArgumentException('Unsupported combined amount');
        $regular=intdiv($total,3);
        $schedule=[$regular,$regular,$total-$regular*2];
        if (array_sum($schedule)!==$total||
            ($schedule[2]-$schedule[0])>2||
            ($schedule[2]-$schedule[0])<0)
            throw new InvalidArgumentException('Composite illustration arithmetic failure');

        return [
            'contract_version'=>'portal-composite-quote-v5-lab-v1',
            'catalog_version'=>$this->catalog->version(),
            'currency'=>'BRL',
            'site'=>$site,
            'channels'=>$channels,
            'media_tier'=>$tier,
            'site_setup_cents'=>$siteSetup,
            'advertising_activation_cents'=>$activation,
            'setup_activation_total_cents'=>$total,
            // NOT a PSP schedule, authorization or agreement. No due dates.
            'illustrative_three_parts_cents'=>$schedule,
            'site_monthly_cents'=>$siteMonthly,
            'site_monthly_first_due_offset_days'=>$siteQuote['monthly']['first_due_offset_days']??null,
            'site_monthly_anchor'=>$siteQuote['monthly']['anchor']??null,
            'site_monthly_independent_of_publication'=>$siteQuote['monthly']['independent_of_publication']??null,
            'media_paid_directly_to_platforms_cents'=>array_sum($media),
            'management_estimate_postpaid_cents'=>$management,
            'management_requires_actual_verified_media'=>true,
            'catalog_server_recomputed'=>true,
            'single_product_checkout_created'=>false,
            'atomic_multi_product_checkout_implemented'=>false,
            'atomic_multi_channel_checkout_implemented'=>false,
            'payment_schedule_created'=>false,
            'customer_or_tenant_data_captured'=>false,
            'contract_accepted'=>false,
            'real_charge_enabled'=>false,
            'real_postpaid_invoice_authorized'=>false,
            'public_checkout_authorized'=>false,
            'operator_approval_verified'=>false,
            'release_status'=>'HOLD_PORTAL_AUTHN_CART_PSP_SETTLEMENT_AND_HUMAN_GO',
        ];
    }
}
