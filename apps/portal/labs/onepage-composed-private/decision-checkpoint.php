<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use Illuminate\Database\Connection;

/** Trusted fixture semeador: it receives the actual PHP result and a runtime-read
 * binding. This creates synthetic witness only, never asserts PSP provenance. */
function checkComposedDecision(Connection $db,PDO $a,PDO $b,PDO $bootstrap,array $identity,Closure $pinned,
    string $proof,string $sameCompanyProof,string $foreignProof,string $order,array $first,array $binding,array $calculated): Closure
{
    $pinned();
    Guard::runtime($a,$bootstrap,$identity,'onepage_buyer_runtime');
    Guard::runtime($b,$bootstrap,$identity,'onepage_buyer_runtime');
    $scope=array_diff_key($first['snapshot'],array_flip(['buyer','status','quote_snapshot','terms','contract_text']));
    $check=static function(bool $v): void { if (!$v) throw new RuntimeException('PROJECTION_DURABILITY_REQUIRED'); };
    $record=static function(PDO $p,?string $principal=null)use($proof,$order): string {
        $q=$p->prepare('SELECT onepage_buyer_lab.fixture_record_projection(?,CAST(? AS uuid))');
        $q->execute([$principal??$proof,$order]);
        $r=$q->fetchColumn();
        if (!in_array($r,['pending','denied','recorded','replay','conflict'],true)) throw new RuntimeException('PROJECTION_CODE_REQUIRED');
        return $r;
    };
    $fingerprint=static function()use($db): array {
        return (array)$db->selectOne("SELECT count(*) n, min(xmin::text) x
          FROM onepage_buyer_lab.fixture_financial_projections");
    };
    $encode=static fn(array $x):string=>json_encode($x,JSON_THROW_ON_ERROR);
    $stage=static function(string $label,array $data,string $offset='0 seconds')use(
        $pinned,$bootstrap,$order,$scope,$binding,$encode): void {
        $pinned();
        $q=$bootstrap->prepare('INSERT INTO onepage_buyer_lab.fixture_decision_witnesses
          (label,order_id,native_scope,bound_entry,setup_lines,projection,staged_at)
          VALUES(?,CAST(? AS uuid),CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),
            clock_timestamp()+CAST(? AS interval))');
        $q->execute([$label,$order,$encode($scope),$encode($binding),$encode($data['setup_lines']),
           $encode($data['projection']),$offset]);
    };
    $drop=static function(string $label)use($pinned,$bootstrap,$check): void {
        $pinned();
        $q=$bootstrap->prepare('DELETE FROM onepage_buyer_lab.fixture_decision_witnesses WHERE label=?');
        $q->execute([$label]);
        $check($q->rowCount()===1);
    };

    // Bootstrap only examines catalog metadata; no access escalation for runtime.
    $acl=$db->selectOne("SELECT CASE WHEN pg_get_userbyid(p.proowner)='onepage_buyer_owner' AND p.prosecdef
          AND has_function_privilege('onepage_buyer_runtime',p.oid,'EXECUTE')
          AND NOT has_function_privilege('portal_browser_runtime',p.oid,'EXECUTE')
          AND NOT EXISTS (SELECT FROM aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a
              WHERE a.privilege_type='EXECUTE' AND a.grantee NOT IN
                (p.proowner,(SELECT oid FROM pg_roles WHERE rolname='onepage_buyer_runtime')))
          AND (SELECT count(*) FROM pg_class c WHERE c.oid IN
                ('onepage_buyer_lab.fixture_decision_witnesses'::regclass,
                 'onepage_buyer_lab.fixture_financial_projections'::regclass)
                AND c.relrowsecurity AND c.relforcerowsecurity)=2
          AND NOT has_table_privilege('onepage_buyer_runtime',
             'onepage_buyer_lab.fixture_decision_witnesses','SELECT,INSERT,UPDATE,DELETE,TRUNCATE')
          AND NOT has_table_privilege('onepage_buyer_runtime',
             'onepage_buyer_lab.fixture_financial_projections','SELECT,INSERT,UPDATE,DELETE,TRUNCATE')
          THEN 1 ELSE 0 END AS ok
          FROM pg_proc p WHERE p.oid='onepage_buyer_lab.fixture_record_projection(text,uuid)'::regprocedure");
    $check($acl!==null && (int)$acl->ok===1);

    $before=$fingerprint();
    $check((int)$before['n']===0 && $record($a)==='pending');
    $stage('stale',$calculated,'-90 seconds');
    $check($record($a)==='conflict' && $fingerprint()===$before);
    $drop('stale');
    $mismatch=$calculated;
    $mismatch['setup_lines'][1]['reference']='imagine:lab-op-v1:'.str_repeat('0',64);
    $stage('mismatch',$mismatch);
    $check($record($a)==='conflict' && $fingerprint()===$before);
    $drop('mismatch');
    $stage('expected',$calculated);
    $stage('duplicate',$calculated);
    $check($record($a)==='conflict' && $fingerprint()===$before);
    $drop('duplicate');
    // A unique, otherwise eligible seed does not authorize another purchaser.
    $check($record($b,$sameCompanyProof)==='denied' && $fingerprint()===$before);
    $check($record($b,$foreignProof)==='denied' && $fingerprint()===$before);

    $a->beginTransaction();
    try {
        $check($record($a)==='recorded' && $fingerprint()===$before);
        $a->rollBack();
    } finally {
        if ($a->inTransaction()) $a->rollBack();
    }
    $check($fingerprint()===$before);
    $a->beginTransaction();
    try {
        $check($record($a)==='recorded');
        $a->commit();
    } finally {
        if ($a->inTransaction()) $a->rollBack();
    }
    $once=$fingerprint();
    $check((int)$once['n']===1 && $once['x']!==null);
    $projection=$calculated['projection'];
    $matches=$db->selectOne('SELECT CASE WHEN EXISTS(
         SELECT 1 FROM onepage_buyer_lab.fixture_financial_projections
         WHERE order_id=CAST(? AS uuid) AND version=? AND evaluated_at=CAST(? AS timestamptz)
           AND eval_digest=? AND setup_lines=CAST(? AS jsonb) AND projection=CAST(? AS jsonb)
         ) THEN 1 ELSE 0 END AS ok',[$order,$projection['version'],$projection['as_of'],$projection['digest'],
            $encode($calculated['setup_lines']),$encode($projection)]);
    $check($matches!==null && (int)$matches->ok===1);
    $check($record($b)==='replay' && $fingerprint()===$once);
    return static function()use($pinned,$record,$b,$once,$fingerprint,$check): void {
        $pinned();
        $check($record($b)==='denied' && $fingerprint()===$once);
        echo "PASS COMPOSED_DECISION_DURABLE: one synthetic projection, rollback and stable replay\n";
    };
}
