<?php
declare(strict_types=1);

namespace App\Homologation;

use RuntimeException;

/**
 * Read-only approved catalog presentation in synthetic staging.
 * An offer preview NEVER creates a legal contract, payment or service grant.
 * Real prices must be frozen in a versioned signed contract and billing ledger.
 */
final readonly class PreviewCommercialCatalog
{
    public function __construct(private string $path) {}

    /** @return array<string,array<string,int|string>> */
    public function offers(): array
    {
        $raw=@file_get_contents($this->path);
        if (!is_string($raw) || strlen($raw)<100 || strlen($raw)>65536) {
            throw new RuntimeException('Approved offer reference unavailable.');
        }
        try { $doc=json_decode($raw,true,30,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Approved offer reference malformed.'); }
        if (!is_array($doc) || ($doc['schema_version']??null)!==3 ||
            ($doc['currency']??null)!=='BRL' ||
            ($doc['gateway']['provider']??null)!=='asaas' ||
            ($doc['gateway']['production_activation_authorized_by_this_file']??null)!==false ||
            !is_array($doc['offers']??null) || count($doc['offers'])!==3) {
            throw new RuntimeException('Unapproved or activatable offer catalog.');
        }

        $byId=[];
        foreach ($doc['offers'] as $item) {
            if (!is_array($item) || !is_string($item['id']??null) ||
                isset($byId[$item['id']])) {
                throw new RuntimeException('Duplicate or malformed offer.');
            }
            $byId[$item['id']]=$item;
        }
        if (array_keys($byId)!==['onepage','landing-pages','performance']) {
            throw new RuntimeException('Unexpected offer or order.');
        }
        $result=[];
        foreach (['onepage'=>['Site Onepage','Seu site institucional em uma página.'],
                  'landing-pages'=>['Landing Page','Página para uma campanha.']] as $id=>$label) {
            $offer=$byId[$id];
            $charges=$offer['setup_charges']??null;
            if (!is_array($charges) || count($charges)!==3 ||
                !is_int($offer['setup_total_cents']??null) ||
                !is_int($offer['monthly']['amount_cents']??null) ||
                ($offer['monthly']['mandatory']??null)!==true ||
                ($offer['monthly']['first_due_offset_days']??null)!==90 ||
                ($offer['monthly']['anchor']??null)!=='contract_acceptance') {
                throw new RuntimeException('Incomplete contract price reference.');
            }
            $total=0; $installment=0;
            foreach($charges as $i=>$charge) {
                if (($charge['offset_days']??null)!==$i*30 ||
                    !is_int($charge['amount_cents']??null) ||
                    $charge['amount_cents']<=0) {
                    throw new RuntimeException('Unapproved setup installment schedule.');
                }
                $total+=$charge['amount_cents'];
                if ($i===0) $installment=$charge['amount_cents'];
                elseif($installment!==$charge['amount_cents']) {
                    throw new RuntimeException('Unexpected installment breakdown.');
                }
            }
            if($total!==$offer['setup_total_cents'] || $total<=0 ||
                $offer['monthly']['amount_cents']<=0) {
                throw new RuntimeException('Invalid setup total or monthly amount.');
            }
            $result[$id]=[
                'name'=>$label[0],'description'=>$label[1],
                'setup_total_cents'=>$total,'installment_cents'=>$installment,
                'monthly_cents'=>$offer['monthly']['amount_cents'],
                'first_monthly_day'=>90,
            ];
        }
        $performance=$byId['performance'];
        $basis=$performance['management_fee_basis_points']??null;
        $mediaMin=$performance['minimum_media_investment_per_channel_cents']??null;
        if (($performance['billing_model']??null)!=='percentage_of_invested_media' ||
            !is_int($basis) || $basis!==2000 ||
            !is_int($mediaMin) || $mediaMin!==70000 ||
            ($performance['minimum_is_media_budget_not_management_fee']??null)!==true ||
            ($performance['direct_checkout_enabled']??null)!==false ||
            ($performance['reference_period']??'unexpected')!==null ||
            ($performance['setup_fee_cents']??'unexpected')!==null) {
            throw new RuntimeException('Performance media policy requires separate commercial closure.');
        }
        $result['performance']=[
            'name'=>'Performance',
            'description'=>'Gestão de mídia nos canais contratados.',
            'management_percent'=>intdiv($basis,100),
            'min_media_per_channel_cents'=>$mediaMin,
            'example_fee_cents'=>intdiv($mediaMin*$basis,10000),
        ];
        return $result;
    }
}
