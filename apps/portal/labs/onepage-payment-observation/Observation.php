<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepagePaymentObservation;

use Closure;
use DateTimeImmutable;
use Throwable;

enum Observation
{
    case UNVERIFIED;
    case MATCHED_RECEIVED_FIXTURE;
}

/** No real reader, provider evidence, binding persistence, release or settlement. */
final class BoundPaymentFixture
{
    private const FIELDS = ['company','order_id','line','quote_id','contract_ref','contract_revision',
        'offer_digest','terms_digest','contract_digest','catalog_version','account','environment',
        'reference','customer','currency','amount_cents','due_date'];

    /**
     * Both bound scope and evidence scope are independently supplied synthetic inputs.
     * ReadAt and reversal represent a proposed normalized test contract, not PSP fields.
     * The closure represents an isolated offline reader of the already-bound ID only.
     */
    public static function inspect(mixed $fixedLink, DateTimeImmutable $asOf, Closure $fixtureReadById): Observation
    {
        $no=Observation::UNVERIFIED;
        if (!self::keys($fixedLink,['scope','attempted','payment_id','bound_at']) ||
            $fixedLink['attempted']!==true || !self::text($fixedLink['payment_id'],'pay_LAB[A-Za-z0-9]{1,60}') ||
            !$fixedLink['bound_at'] instanceof DateTimeImmutable || $fixedLink['bound_at']>$asOf ||
            !self::scope($fixedLink['scope'])) return $no;
        $scope=$fixedLink['scope'];
        $id=$fixedLink['payment_id'];
        try {
            $e=$fixtureReadById($id);
            if (!self::keys($e,['scope','read_at','reversal','payment']) ||
                !self::scope($e['scope']) || !self::keys($e['payment'],['object','id','customer','externalReference',
                    'currency','value_cents','dueDate','status','deleted','subscription']) ||
                !$e['read_at'] instanceof DateTimeImmutable || $e['read_at']>$asOf ||
                $e['read_at']<$fixedLink['bound_at'] || $e['read_at']<$asOf->modify('-60 seconds') ||
                $e['reversal']!=='fixture_verified_clear') return $no;
            $expected=$scope;
            $observed=$e['scope'];
            ksort($expected);
            ksort($observed);
            if ($expected!==$observed) return $no;
            $p=$e['payment'];
            if ($p['object']!=='payment' || $p['id']!==$id || $p['customer']!==$scope['customer'] ||
                $p['externalReference']!==$scope['reference'] || $p['currency']!==$scope['currency'] ||
                $p['value_cents']!==$scope['amount_cents'] || $p['dueDate']!==$scope['due_date'] ||
                $p['status']!=='RECEIVED' || $p['deleted']!==false || $p['subscription']!==null) return $no;
            return Observation::MATCHED_RECEIVED_FIXTURE;
        } catch (Throwable) {
            return $no;
        }
    }

    private static function scope(mixed $v): bool
    {
        if (!self::keys($v,self::FIELDS) || $v['environment']!=='sandbox' || $v['currency']!=='BRL' ||
            !is_int($v['contract_revision']) || $v['contract_revision']<1 ||
            !is_int($v['amount_cents']) || $v['amount_cents']<1 || $v['amount_cents']>100000000 ||
            !in_array($v['line'],['setup-1','setup-2','setup-3'],true) ||
            !self::text($v['account'],'lab-[A-Za-z0-9-]{1,60}') ||
            !self::text($v['contract_ref'],'lab-[A-Za-z0-9-]{1,100}') ||
            !self::text($v['reference'],'imagine:lab-op-v1:[a-f0-9]{64}') ||
            !self::text($v['customer'],'cus_LAB[A-Za-z0-9]{1,60}') ||
            !self::text($v['due_date'],'[0-9]{4}-[0-9]{2}-[0-9]{2}')) return false;
        [$y,$m,$d]=array_map('intval',explode('-',$v['due_date']));
        if (!checkdate($m,$d,$y)) return false;
        foreach (['company','order_id','quote_id'] as $k)
            if (!self::text($v[$k],'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}')) return false;
        foreach (['offer_digest','terms_digest','contract_digest','catalog_version'] as $k)
            if (!self::text($v[$k],'[a-f0-9]{64}')) return false;
        return true;
    }

    private static function text(mixed $v,string $pattern): bool
    {
        return is_string($v) && preg_match('/\A'.$pattern.'\z/D',$v)===1;
    }

    private static function keys(mixed $v,array $wanted): bool
    {
        if (!is_array($v)) return false;
        $keys=array_keys($v);
        sort($keys,SORT_STRING);
        sort($wanted,SORT_STRING);
        return $keys===$wanted;
    }
}
