<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;
use Imagine\Lab\OnepageDecisionProjection\Projection;

require_once __DIR__.'/../onepage-decision-projection/Projection.php';

/** Execute-only SQL witness; never hand an ID to an HTTP caller or bootstrap. */
function readComposedBinding(PDO $runtime,string $proof,string $order,string $line): ?array
{
    $q=$runtime->prepare('SELECT onepage_buyer_lab.fixture_read_bound_charge(?,CAST(? AS uuid),?)');
    $q->execute([$proof,$order,$line]);
    $raw=$q->fetchColumn();
    if ($raw===null) return null;
    if (!is_string($raw)) throw new RuntimeException('BOUND_READ_SHAPE_REQUIRED');
    $value=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new RuntimeException('BOUND_READ_SHAPE_REQUIRED');
    return $value;
}

/** Inspect only capability metadata with the attested fixture bootstrap. */
function assertComposedReadAcl(\Illuminate\Database\Connection $db): void
{
    $acl=$db->selectOne("SELECT CASE WHEN pg_get_userbyid(p.proowner)='onepage_buyer_owner'
        AND p.prosecdef AND has_function_privilege('onepage_buyer_runtime',p.oid,'EXECUTE')
        AND NOT EXISTS (SELECT FROM aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a
         WHERE a.privilege_type='EXECUTE' AND a.grantee NOT IN
          (p.proowner,(SELECT oid FROM pg_roles WHERE rolname='onepage_buyer_runtime')))
        AND NOT has_table_privilege('onepage_buyer_runtime',
            'onepage_buyer_lab.fixture_charge_bindings','SELECT,INSERT,UPDATE,DELETE')
        THEN 1 ELSE 0 END AS ok
        FROM pg_proc p WHERE p.oid='onepage_buyer_lab.fixture_read_bound_charge(text,uuid,text)'::regprocedure");
    if ($acl===null || (int)$acl->ok!==1) throw new RuntimeException('BOUND_READ_ACL_REQUIRED');
}

/** Only the entry identity/time comes from committed SQL.
 * Received/open states, timestamps, and decision are entirely synthetic. */
function checkComposedFinancial(PDO $runtime,PDO $owner,array $identity,Closure $pinned,
    string $proof,string $order,array $first,?array $binding): array
{
    $pinned();
    Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
    if ($binding===null || readComposedBinding($runtime,$proof,$order,'setup-1')!==$binding
        || ($binding['scope']['order_id']??null)!==$order) throw new RuntimeException('NATIVE_BOUND_READ_REQUIRED');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$binding['bound_at'],new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s.u\Z')!==$binding['bound_at']) throw new RuntimeException('BOUND_AT_REQUIRED');
    $binding['bound_at']=$date;
    $plan=ProducerContract::prepare($first,'lab-cas-fixture','cus_LABreserve');
    if (!$plan || $plan->scope['order']!==$order) throw new RuntimeException('NATIVE_PLAN_REQUIRED');
    $p=$plan->scope;
    // Chosen laboratory offsets, NOT clocks supplied by, inferred from, or
    // asserted about a provider. They must remain distinct from bound_at.
    $paid=$date->modify('+1 second');
    $read=$date->modify('+2 seconds');
    $asOf=$date->modify('+3 seconds');
    $entries=[];
    foreach ($plan->lines as $key=>$line) {
        $scope=['company'=>$p['company'],'order_id'=>$p['order'],'line'=>$key,'quote_id'=>$p['quote'],
            'contract_ref'=>$p['contract'],'contract_revision'=>$p['revision'],'offer_digest'=>$p['offer_digest'],
            'terms_digest'=>$p['terms_digest'],'contract_digest'=>$p['contract_digest'],'catalog_version'=>$p['catalog_version'],
            'account'=>$p['account'],'environment'=>$p['environment'],'reference'=>$line['external_reference'],
            'customer'=>'cus_LABreserve','currency'=>$p['currency'],'amount_cents'=>$line['amount_cents'],'due_date'=>$line['due_date']];
        $base=['read_at'=>$read,'evidence_ref'=>'lab-composed-'.$key,'exception_state'=>'no_exception_reported'];
        if ($key!=='setup-1') {
            $entries[$key]=$base+['kind'=>'fixture_open','scope'=>$scope];
            continue;
        }
        $entries[$key]=$base+['kind'=>'fixture_received','paid_at'=>$paid,'link'=>$binding,
            'observation'=>['scope'=>$scope,'read_at'=>$read,'reversal'=>'fixture_verified_clear',
                'payment'=>['object'=>'payment','id'=>$binding['payment_id'],'customer'=>$scope['customer'],
                  'externalReference'=>$scope['reference'],'currency'=>'BRL','value_cents'=>$scope['amount_cents'],
                  'dueDate'=>$scope['due_date'],'status'=>'RECEIVED','deleted'=>false,'subscription'=>null]]];
    }
    $calc=static fn(array $data)=>Projection::calculate($first,'lab-native-composed',$p['account'],'cus_LABreserve',$asOf,false,$data);
    $decision=$calc($entries);
    if (($decision['order']??null)!==$order || ($decision['company']??null)!==$p['company']
        || ($decision['decision']??null)!=='INITIAL_FINANCIAL_CONDITION_MET'
        || ($decision['fixture_only']??null)!==true) throw new RuntimeException('FIXTURE_PROJECTION_REQUIRED');
    $entries['setup-1']['observation']['scope']['account']='lab-another-account';
    if (($calc($entries)['decision']??null)!=='REVIEW_UNVERIFIED') throw new RuntimeException('ACCOUNT_MISMATCH_REQUIRED');
    $pinned();
    echo "PASS COMPOSED_BOUND_FINANCIAL: committed own binding, synthetic calculation only\n";
    return ['projection'=>$decision,'setup_lines'=>array_values(array_map(static fn(array $line): array => [
        'key'=>$line['key'],'reference'=>$line['external_reference'],'due_date'=>$line['due_date'],
        'amount_cents'=>$line['amount_cents']],$plan->lines))];
}
