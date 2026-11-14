<?php
declare(strict_types=1);
namespace App\Homologation\Commerce;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Immutable accepted terms only. This projection does not issue PSP charges. */
final class BillingSchedule
{
    public static function project(array $quote,string $acceptedAt,int $months=12): array
    {
        if ($months<1||$months>36||!in_array($quote['product']??null,['onepage','landing-pages'],true)||($quote['currency']??null)!=='BRL') throw new InvalidArgumentException('Invalid schedule');
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T].+[+-][0-9]{2}(?::?[0-9]{2})?\z/D',$acceptedAt)&&!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T.+Z\z/D',$acceptedAt)) throw new InvalidArgumentException('Acceptance timestamp required');
        $anchor=(new DateTimeImmutable($acceptedAt))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->setTime(0,0);
        $rows=[];$sum=0;$offsets=[];
        foreach ($quote['setup_charges']??[] as $i=>$charge) {
            $days=$charge['offset_days']??null;$amount=$charge['amount_cents']??null;
            if (!is_int($days)||$days<0||$days>365||!is_int($amount)||$amount<1||$amount>100000000||in_array($days,$offsets,true)) throw new InvalidArgumentException('Invalid setup schedule');
            $offsets[]=$days;$sum+=$amount;
            $rows[]=['key'=>'setup-'.($i+1),'kind'=>'setup','due_date'=>$anchor->modify('+'.$days.' days')->format('Y-m-d'),'amount_cents'=>$amount];
        }
        if ($offsets!==[0,30,60]||$sum!==($quote['setup_total_cents']??null)) throw new InvalidArgumentException('Setup total mismatch');
        $monthly=$quote['monthly']??[];
        if (($monthly['mandatory']??null)!==true||($monthly['anchor']??null)!=='contract_acceptance'||($monthly['first_due_offset_days']??null)!==90||($monthly['independent_of_publication']??null)!==true||!is_int($monthly['amount_cents']??null)||$monthly['amount_cents']<1||$monthly['amount_cents']>100000000) throw new InvalidArgumentException('Invalid monthly schedule');
        $first=$anchor->modify('+90 days');$day=(int)$first->format('d');
        for ($i=0;$i<$months;$i++) {
            // Derive every month from the original anchor; February cannot shift March.
            $month=$first->modify('first day of this month')->modify('+'.$i.' months');
            $due=$month->setDate((int)$month->format('Y'),(int)$month->format('m'),min($day,(int)$month->format('t')));
            $rows[]=['key'=>'monthly-'.($i+1),'kind'=>'monthly','due_date'=>$due->format('Y-m-d'),'amount_cents'=>$monthly['amount_cents']];
        }
        return $rows;
    }
}
