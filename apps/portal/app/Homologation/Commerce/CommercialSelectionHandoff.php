<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;

use InvalidArgumentException;

/**
 * F426 — exact anonymous, non-binding choice from Imagine Comercial.
 * A browser may edit query parameters: only a finite, price-free intent is
 * accepted. All prices, channel minimums and availability are recalculated by
 * ApprovedOfferCatalog. No side effects, PSP, owner, account or payment.
 */
final class CommercialSelectionHandoff
{
    private const SITES=['none','onepage','landing-pages'];
    private const CHANNELS=['','google_ads','meta_ads','google_ads,meta_ads'];
    private const TIERS=['minimum','recommended'];

    /** Strict GET allowlist, not a signature or evidence of customer acceptance. */
    public static function parse(array $params): array
    {
        $keys=array_keys($params); sort($keys);
        if ($keys!==['channels','site','tier']) {
            throw new InvalidArgumentException('Invalid commercial handoff fields');
        }
        foreach ($params as $value) {
            if (!is_string($value) || strlen($value)>40 ||
                preg_match('/[\\x00-\\x20\\x7f]/',$value)) {
                throw new InvalidArgumentException('Invalid commercial handoff value');
            }
        }
        if (!in_array($params['site'],self::SITES,true) ||
            !in_array($params['channels'],self::CHANNELS,true) ||
            !in_array($params['tier'],self::TIERS,true) ||
            ($params['site']==='none' && $params['channels']==='')) {
            throw new InvalidArgumentException('Unsupported commercial choice');
        }
        return [
            'site'=>$params['site'],
            'channels'=>$params['channels']===''?[]:explode(',',$params['channels']),
            'tier'=>$params['tier']
        ];
    }

    /** Carry only canonical enumerations through preview login/session. */
    public static function sessionData(array $selection): array
    {
        return [
            'site'=>$selection['site'],
            'channels'=>implode(',',$selection['channels']),
            'tier'=>$selection['tier']
        ];
    }

    /**
     * A merged informational summary, NEVER a combined purchase or invoice.
     * Each product is separately offered and explicitly accepted after login.
     * @return array<string,mixed>
     */
    public static function summarize(ApprovedOfferCatalog $catalog,array $selection): array
    {
        $valid=self::parse(self::sessionData($selection));
        $siteQuote=$valid['site']==='none'?null:$catalog->quote($valid['site']);
        $media=[];
        if ($valid['channels']!==[]) {
            $offer=$catalog->offers()['performance']??throw new InvalidArgumentException('Performance not in catalog');
            $budgetKey=$valid['tier']==='minimum'
                ?'minimum_media_investment_by_channel_cents'
                :'recommended_media_investment_by_channel_cents';
            foreach ($valid['channels'] as $channel) {
                $value=$offer[$budgetKey][$channel]??null;
                if (!is_int($value) || $value<1 || $value>100000000) {
                    throw new InvalidArgumentException('Approved media planning value unavailable');
                }
                $media[$channel]=$value;
            }
        }
        $adsQuote=$media===[]?null:$catalog->quote('performance',$media);
        $firstSite=$siteQuote['amount_now_cents']??0;
        $activation=$adsQuote['amount_now_cents']??0;
        $summary=[
            'selection'=>$valid,
            'catalog_version'=>$catalog->version(),
            'site_quote'=>$siteQuote,
            'ads_quote'=>$adsQuote,
            'media_by_channel_cents'=>$media,
            'planned_media_total_cents'=>array_sum($media),
            'first_site_installment_cents'=>$firstSite,
            'ads_activation_cents'=>$activation,
            'sum_of_separate_first_payments_cents'=>$firstSite+$activation,
            'estimated_ads_management_cents'=>$adsQuote['estimated_management_fee_cents']??0,
            'real_charge_enabled'=>false,
            'single_combined_checkout_enabled'=>false,
            'data_collected'=>false
        ];
        if (($siteQuote['real_charge_enabled']??false)!==false ||
            ($adsQuote['real_charge_enabled']??false)!==false ||
            ($adsQuote['real_postpaid_invoice_authorized']??false)!==false) {
            throw new InvalidArgumentException('Commercial preview cannot authorize payment');
        }
        return $summary;
    }
}
