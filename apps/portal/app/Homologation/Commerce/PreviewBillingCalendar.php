<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Informative, deterministic timeline from a SAVED synthetic offer snapshot.
 *
 * No contract acceptance, charge API, invoice, scheduled job or real
 * receivable is created by this code. Dates are examples anchored to the
 * simulated offer acknowledgement, NOT a signed customer contract.
 */
final class PreviewBillingCalendar
{
    private const ZONE='America/Sao_Paulo';

    /**
     * @param ?array<string,mixed> $quote Immutable, server-originated order quote.
     * @return list<array{kind:string,label:string,due_iso:?string,due_label:?string,amount_cents:int,automated_charge_enabled:false,reference_only:true}>
     */
    public static function forOrder(?array $quote, ?string $acceptedAt, string $product): array
    {
        // Legacy orders without an offer snapshot get NO fabricated pricing.
        if ($quote===null || $acceptedAt===null) return [];
        if (!in_array($product,['onepage','landing-pages','performance'],true) ||
            ($quote['product']??null)!==$product ||
            ($quote['currency']??null)!=='BRL' ||
            ($quote['gateway']??null)!=='asaas' ||
            ($quote['real_charge_enabled']??null)!==false) {
            throw new InvalidArgumentException('Untrusted historic offer snapshot');
        }

        try {
            $anchor=(new DateTimeImmutable($acceptedAt))
                ->setTimezone(new DateTimeZone(self::ZONE))
                ->setTime(0,0,0);
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid synthetic offer-ack date');
        }

        if ($product==='performance') {
            if (($quote['reference_period']??null)!=='monthly' ||
                ($quote['management_fee_basis_points']??null)!==2000 ||
                ($quote['media_paid_directly_to_platforms']??null)!==true ||
                !self::positiveCents($quote['estimated_management_fee_cents']??null) ||
                ($quote['amount_now_cents']??null)!==$quote['estimated_management_fee_cents'] ||
                !is_array($quote['media_by_channel_cents']??null) ||
                count($quote['media_by_channel_cents'])<1) {
                throw new InvalidArgumentException('Performance evidence not established');
            }
            foreach ($quote['media_by_channel_cents'] as $channel=>$spend) {
                if (!in_array($channel,['google_ads','meta_ads'],true) ||
                    !is_int($spend) || $spend<70000 || $spend>100000000) {
                    throw new InvalidArgumentException('Unapproved media channel or amount');
                }
            }
            // No automatic monthly receivables without trusted actual-spend
            // reconciliation, a signed contract and independent payment status.
            return [[
                'kind'=>'performance_estimate',
                'label'=>'Estimativa de honorários de gestão (20% da mídia prevista)',
                'due_iso'=>null,
                'due_label'=>null,
                'amount_cents'=>$quote['estimated_management_fee_cents'],
                'automated_charge_enabled'=>false,
                'reference_only'=>true,
            ]];
        }

        $charges=$quote['setup_charges']??null;
        $monthly=$quote['monthly']??null;
        if (!is_array($charges) || !array_is_list($charges) || count($charges)!==3 ||
            !is_array($monthly) ||
            !self::positiveCents($quote['setup_total_cents']??null) ||
            !self::positiveCents($monthly['amount_cents']??null) ||
            ($monthly['mandatory']??null)!==true ||
            ($monthly['first_due_offset_days']??null)!==90 ||
            ($monthly['anchor']??null)!=='contract_acceptance' ||
            ($monthly['independent_of_publication']??null)!==true ||
            ($quote['amount_now_cents']??null)!==($charges[0]['amount_cents']??null)) {
            throw new InvalidArgumentException('Unapproved setup or monthly schedule');
        }
        $events=[];
        $total=0;
        foreach ($charges as $index=>$charge) {
            if (!is_array($charge) ||
                ($charge['offset_days']??null)!==[0,30,60][$index] ||
                !self::positiveCents($charge['amount_cents']??null)) {
                throw new InvalidArgumentException('Unexpected setup installment');
            }
            $total+=$charge['amount_cents'];
            $date=$anchor->modify('+'.$charge['offset_days'].' days');
            $events[]=self::entry(
                'setup','Implantação '.($index+1).'/3',$date,
                $charge['amount_cents']
            );
        }
        if ($total!==$quote['setup_total_cents']) {
            throw new InvalidArgumentException('Setup total does not balance');
        }
        $firstMonthly=$anchor->modify('+90 days');
        for ($i=0;$i<3;$i++) {
            $date=self::addCalendarMonthsClamped($firstMonthly,$i);
            $events[]=self::entry(
                'monthly','Mensalidade de referência '.($i+1),$date,
                $monthly['amount_cents']
            );
        }
        return $events;
    }

    private static function positiveCents(mixed $amount):bool
    {
        return is_int($amount) && $amount>0 && $amount<=100000000;
    }

    private static function entry(
        string $kind,string $label,DateTimeImmutable $date,int $cents
    ):array {
        return [
            'kind'=>$kind,
            'label'=>$label,
            'due_iso'=>$date->format('Y-m-d'),
            'due_label'=>$date->format('d/m/Y'),
            'amount_cents'=>$cents,
            'automated_charge_enabled'=>false,
            'reference_only'=>true,
        ];
    }

    /**
     * A month after Jan 31 is Feb 28/29, not March 2/3.
     * Always calculate from the same first-monthly anchor to prevent drift.
     */
    private static function addCalendarMonthsClamped(
        DateTimeImmutable $origin,int $months
    ):DateTimeImmutable {
        $m=(int)$origin->format('n')-1+$months;
        $year=(int)$origin->format('Y')+intdiv($m,12);
        $month=($m%12)+1;
        $first=new DateTimeImmutable(
            sprintf('%04d-%02d-01',$year,$month),
            new DateTimeZone(self::ZONE)
        );
        $day=min((int)$origin->format('j'),(int)$first->format('t'));
        return $first->setDate($year,$month,$day);
    }
}
