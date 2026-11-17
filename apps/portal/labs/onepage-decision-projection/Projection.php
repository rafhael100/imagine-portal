<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageDecisionProjection;

use DateTimeImmutable;
use DateTimeZone;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;
use Imagine\Lab\OnepageInstallmentPolicy\{Evaluator, Outcome};
use Imagine\Lab\OnepagePaymentObservation\{BoundPaymentFixture, Observation};
use Throwable;

require_once __DIR__.'/../onepage-binding-producer/ProducerContract.php';
require_once __DIR__.'/../onepage-installment-policy/Evaluator.php';
require_once __DIR__.'/../onepage-payment-observation/Observation.php';

/** Synthetic, non-authoritative calculation only; not a receipt or writer. */
final class Projection
{
    private const VERSION='onepage-financial-condition-fixture.v1';
    private const POLICY='60db48be98e9030cf3d4f0d19b9deaa0366aa5c7';

    /**
     * $entries: setup key => independently supplied synthetic observation.
     * A received observation must carry an already-bound link, read envelope,
     * and a separate explicitly synthetic paid_at (never inferred from Asaas).
     * Open obligations need a synthetic open assertion for the exact same scope.
     */
    public static function calculate(array $nativeSnapshot,string $tenant,string $account,string $customer,
        DateTimeImmutable $asOf,bool $priorRelease,array $entries,?array $effectiveReference=null): ?array
    {
        if (!preg_match('/\Alab-[A-Za-z0-9-]{1,60}\z/D',$tenant)) return null;
        $plan=ProducerContract::prepare($nativeSnapshot,$account,$customer);
        if (!$plan) return null;
        $p=$plan->scope;
        $scope=['order_ref'=>$p['order'],'tenant_ref'=>$tenant,'company_ref'=>$p['company'],
            'contract_ref'=>$p['contract'],'contract_revision'=>$p['revision'],'product_code'=>'onepage',
            'offer_digest'=>$p['offer_digest'],'catalog_version'=>$p['catalog_version']];
        $normalized=null;
        $outcome=Outcome::REVIEW_UNVERIFIED;
        try {
            $accepted=new DateTimeImmutable($p['accepted_at']);
            if (count($entries)===3) {
                $normalized=self::normalize($plan,$customer,$scope,$entries,$accepted,$asOf);
            }
            if ($normalized!==null) {
                [$schedule,$rows]=$normalized;
                $outcome=Evaluator::evaluate($scope,$accepted,$asOf,$priorRelease,['scope'=>$scope,'rows'=>$schedule],
                    ['scope'=>$scope,'as_of'=>$asOf,'rows'=>$rows],$effectiveReference);
            }
            return self::summary($p,$tenant,$asOf,$priorRelease,$outcome,
                $outcome===Outcome::REVIEW_UNVERIFIED?null:[$normalized[2],self::canonical($effectiveReference)]);
        } catch (Throwable) {
            return self::summary($p,$tenant,$asOf,$priorRelease,Outcome::REVIEW_UNVERIFIED,null);
        }
    }

    private static function normalize(ProducerContract $plan,string $customer,array $scope,array $entries,
        DateTimeImmutable $accepted,DateTimeImmutable $asOf): ?array
    {
        $p=$plan->scope;
        $schedule=$rows=$proof=[];
        foreach ($plan->lines as $key=>$line) {
            $e=$entries[$key]??null;
            if (!is_array($e) || !is_string($e['evidence_ref']??null)
                || !preg_match('/\Alab-[a-zA-Z0-9-]{1,80}\z/D',$e['evidence_ref'])
                || !in_array($e['exception_state']??null,['no_exception_reported','exception_claimed'],true)
                || !(($e['read_at']??null) instanceof DateTimeImmutable)
                || $e['read_at']<$accepted || $e['read_at']>$asOf || $e['read_at']<$asOf->modify('-60 seconds')) return null;
            $lineScope=['company'=>$p['company'],'order_id'=>$p['order'],'line'=>$key,'quote_id'=>$p['quote'],
                'contract_ref'=>$p['contract'],'contract_revision'=>$p['revision'],'offer_digest'=>$p['offer_digest'],
                'terms_digest'=>$p['terms_digest'],'contract_digest'=>$p['contract_digest'],'catalog_version'=>$p['catalog_version'],
                'account'=>$p['account'],'environment'=>$p['environment'],'reference'=>$line['external_reference'],
                'customer'=>$customer,'currency'=>$p['currency'],'amount_cents'=>$line['amount_cents'],'due_date'=>$line['due_date']];
            $ref=null;
            $paidAt=null;
            $state='verified_unpaid';
            if (($e['kind']??null)==='fixture_open') {
                if (!self::keys($e,['kind','scope','read_at','evidence_ref','exception_state'])
                    || !self::same($e['scope'],$lineScope)) return null;
            } elseif (($e['kind']??null)==='fixture_received') {
                if (!self::keys($e,['kind','link','observation','read_at','paid_at','evidence_ref','exception_state'])
                    || !($e['paid_at'] instanceof DateTimeImmutable) || $e['paid_at']<$accepted || $e['paid_at']>$e['read_at']
                    || !is_array($e['link']) || !self::same($e['link']['scope']??null,$lineScope) || !is_array($e['observation'])
                    || !(($e['observation']['read_at']??null) instanceof DateTimeImmutable)
                    || $e['observation']['read_at']!=$e['read_at']) return null;
                if (BoundPaymentFixture::inspect($e['link'],$asOf,static fn(string $id)=>$e['observation'])
                    !==Observation::MATCHED_RECEIVED_FIXTURE) return null;
                $ref=$e['link']['payment_id'];
                $paidAt=$e['paid_at'];
                $state='verified_paid';
            } else return null;

            $schedule[]=['key'=>$key,'kind'=>'setup','due_date'=>$line['due_date'],'amount_cents'=>$line['amount_cents']];
            $rows[]=['scope'=>$scope,'key'=>$key,'due_date'=>$line['due_date'],'amount_cents'=>$line['amount_cents'],
                'state'=>$state,'evidence_ref'=>$e['evidence_ref'],'payment_ref'=>$ref,'paid_at'=>$paidAt,'exception_state'=>$e['exception_state']];
            $proof[]=['key'=>$key,'state'=>$state,'ref'=>$e['evidence_ref'],'payment'=>$ref,'read_at'=>self::stamp($e['read_at']),
                'paid_at'=>$paidAt===null?null:self::stamp($paidAt),'exception'=>$e['exception_state']];
        }
        return [$schedule,$rows,$proof];
    }

    private static function summary(array $p,string $tenant,DateTimeImmutable $asOf,bool $priorRelease,
        Outcome $outcome,?array $proof): array
    {
        $body=['fixture_only'=>true,'version'=>self::VERSION,'policy_commit'=>self::POLICY,'as_of'=>self::stamp($asOf),
            'order'=>$p['order'],'company'=>$p['company'],'tenant'=>$tenant,'contract'=>$p['contract'],
            'revision'=>$p['revision'],'offer_digest'=>$p['offer_digest'],'catalog_version'=>$p['catalog_version'],
            'decision'=>$outcome->name];
        return $body+['digest'=>hash('sha256',json_encode([$body,$p,$priorRelease,$proof],JSON_THROW_ON_ERROR))];
    }

    private static function stamp(DateTimeImmutable $t): string { return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'); }
    private static function canonical(mixed $v): mixed
    {
        if ($v instanceof DateTimeImmutable) return self::stamp($v);
        if (is_array($v)) {
            if (!array_is_list($v)) ksort($v,SORT_STRING);
            return array_map(self::canonical(...),$v);
        }
        return $v;
    }
    private static function same(mixed $a,array $b): bool
    {
        if (!is_array($a)) return false;
        ksort($a,SORT_STRING);
        ksort($b,SORT_STRING);
        return $a===$b;
    }
    private static function keys(array $a,array $fields): bool
    {
        $k=array_keys($a);
        sort($k,SORT_STRING);sort($fields,SORT_STRING);
        return $k===$fields;
    }
}
