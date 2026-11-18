<?php
declare(strict_types=1);
/** Additive test hook; no connection, credentials, DDL, provisioning or standalone entrypoint.
 * The unchanged #940 isolated runner must attest effective Laravel config and fresh cluster
 * before installing admission.sql and before invoking this hook. Never run on this executor.
 * $attest must recheck both process/SQL identity and each actual PDO backend, including producer.
 * Evidence is bootstrap-built from a native reservation, NOT buyer input.
 */
function checkEntryAdmission(PDO $producer, PDO $audit, Closure $freshAuditor, Closure $attest,
    array $evidence, array $unattempted, string $canonical, string $anchorDigest, Closure $claimOther, Closure $concurrent): void
{
    $check=static function(bool $ok): void {if (!$ok) throw new RuntimeException('ADMISSION_CASE_REFUSED');};
    $attest($producer, 'onepage_fixture_evidence_producer');
    $attest($audit, 'onepage_lab_bootstrap');
    $check(!$producer->inTransaction() && !$audit->inTransaction());
    $json=static fn(array $e): string => json_encode($e, JSON_THROW_ON_ERROR);
    // Canonical digest uses the SQL jsonb representation, not a competing PHP serialization.
    $digest=static function(array $e) use ($audit,$json): string {
        $q=$audit->prepare("SELECT encode(sha256(convert_to(CAST(? AS jsonb)::text,'UTF8')),'hex')");
        $q->execute([$json($e)]);return $q->fetchColumn();
    };
    $call=static function(array $e, ?string $hash=null, ?string $order=null, ?string $line=null)
        use ($producer,$digest,$json): string {
        $q=$producer->prepare('SELECT onepage_buyer_lab.fixture_admit_entry(CAST(? AS uuid),?,CAST(? AS jsonb),?)');
        $q->execute([$order??$e['order_id'],$line??$e['line'],$json($e),$hash??$digest($e)]);
        return $q->fetchColumn();
    };
    $count=static function(PDO $p) use ($evidence): int {
        $q=$p->prepare('SELECT count(*) FROM onepage_buyer_lab.fixture_binding_witnesses WHERE order_id=CAST(? AS uuid) AND line=?');
        $q->execute([$evidence['order_id'],'setup-1']);return (int)$q->fetchColumn();
    };
    $state=static function(PDO $p): string {
        // Test-only bootstrap oracle: no worker table read. Detect any financial/order/binding mutation.
        return $p->query("SELECT md5(coalesce((SELECT jsonb_agg(to_jsonb(b) ORDER BY order_id,line)::text FROM onepage_buyer_lab.fixture_charge_bindings b),'')) || ':' || md5(coalesce((SELECT jsonb_agg(to_jsonb(o) ORDER BY id)::text FROM onepage_buyer_lab.pending_orders o),''))")->fetchColumn();
    };
    $check(hash_equals(hash('sha256',$canonical),$anchorDigest) && json_decode($canonical,true,32,JSON_THROW_ON_ERROR)===$evidence);
    $check($evidence['line']==='setup-1' && $unattempted['line']==='setup-1');
    $check($evidence['order_id']!==$unattempted['order_id']);
    foreach ([[$evidence,true],[$unattempted,false]] as [$item,$attempted]) {
        $q=$audit->prepare("SELECT attempted, to_jsonb(r)-'attempted' AS scope FROM onepage_buyer_lab.fixture_charge_reservations r WHERE order_id=CAST(? AS uuid) AND line='setup-1'");
        $q->execute([$item['order_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $check(is_array($row));
        $check(in_array($row['attempted'],[true,'t',1,'1'],true)===$attempted);
        $expected=json_decode($row['scope'],true,32,JSON_THROW_ON_ERROR);
        foreach($expected as $key=>$value)$check(array_key_exists($key,$item) && $item[$key]===$value);
    }
    $check($count($audit)===0);$baseline=$state($audit);
    $unsafe=$audit->query("SELECT count(*) FROM pg_roles r WHERE rolname='onepage_fixture_evidence_producer' AND (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid))")->fetchColumn();
    $check((int)$unsafe===0);
    $tables=$audit->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='onepage_buyer_lab' AND c.relkind IN ('r','p') AND has_table_privilege('onepage_fixture_evidence_producer',c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')")->fetchColumn();
    $check((int)$tables===0);
    $functions=$audit->query("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='onepage_buyer_lab' AND has_function_privilege('onepage_fixture_evidence_producer',p.oid,'EXECUTE') ORDER BY p.proname")->fetchAll(PDO::FETCH_COLUMN);
    $check($functions===['fixture_admit_entry']);
    $catalog=$audit->query("SELECT c.relrowsecurity AND c.relforcerowsecurity AS forced,
      (SELECT count(*) FROM pg_policy WHERE polrelid=c.oid AND polcmd IN ('a','*')) AS insert_policies,
      has_table_privilege('onepage_buyer_owner',c.oid,'INSERT') AS owner_insert,
      has_table_privilege('onepage_buyer_owner',c.oid,'UPDATE,DELETE,TRUNCATE') AS owner_mutation
      FROM pg_class c WHERE c.oid='onepage_buyer_lab.fixture_binding_witnesses'::regclass")->fetch(PDO::FETCH_ASSOC);
    $truth=static fn($v):bool=>in_array($v,[true,'t',1,'1'],true);
    $check($truth($catalog['forced'])&&(int)$catalog['insert_policies']===1&&$truth($catalog['owner_insert'])&&!$truth($catalog['owner_mutation']));
    $ownerRole=$audit->query("SELECT NOT (rolcanlogin OR rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolreplication) FROM pg_roles WHERE rolname='onepage_buyer_owner'")->fetchColumn();
    $check($truth($ownerRole));
    $denied=static function(string $sql,array $args=[]) use ($producer,$check): void {
        try {$q=$producer->prepare($sql);$q->execute($args);}
        catch(PDOException $e) {$check($e->getCode()==='42501');return;}
        throw new RuntimeException('ADMISSION_ACL_REFUSED');
    };
    $denied('SELECT * FROM onepage_buyer_lab.fixture_binding_witnesses');
    $denied('INSERT INTO onepage_buyer_lab.fixture_binding_witnesses(probe) VALUES (?)',['admit-v1:forbidden']);
    $denied('UPDATE onepage_buyer_lab.fixture_binding_witnesses SET probe=probe WHERE false');
    $denied('DELETE FROM onepage_buyer_lab.fixture_binding_witnesses WHERE false');
    $denied('SELECT onepage_buyer_lab.fixture_bind_charge(?,CAST(? AS uuid),?)',['no-buyer-session',$evidence['order_id'],'setup-1']);
    $check($call($evidence,str_repeat('0',64))==='denied');
    $check($call($evidence,null,null,'setup-2')==='denied');
    $check($call($evidence,null,$unattempted['order_id'])==='denied');
    $check($call(array_replace($evidence,['account'=>'lab-other']))==='denied');
    foreach(['environment'=>'other','customer'=>'cus_LABother','reference'=>'imagine:lab-op-v1:'.str_repeat('f',64),
      'amount_cents'=>$evidence['amount_cents']+1,'due_date'=>'2000-01-01','currency'=>'USD'] as $k=>$v)
      $check($call(array_replace($evidence,[$k=>$v]))==='denied');
    $scope=$evidence['contract_scope'];$scope['company']=$unattempted['order_id'];
    $check($call(array_replace($evidence,['contract_scope'=>$scope]))==='denied');
    $check($call(array_replace($evidence,['payment_id'=>'pay_real']))==='denied');
    $check($call(array_replace($evidence,['confirmed_at'=>'2000-01-01']))==='denied');
    $check($call($unattempted)==='denied');
    // A different native D0 remains unauthorized even after a legitimate buyer claim.
    $claimOther();
    $q=$audit->prepare("SELECT attempted FROM onepage_buyer_lab.fixture_charge_reservations WHERE order_id=CAST(? AS uuid) AND line='setup-1'");
    $q->execute([$unattempted['order_id']]);$check(in_array($q->fetchColumn(),[true,'t',1,'1'],true));
    $check($call($unattempted)==='denied');
    $check($call(array_replace($unattempted,['probe'=>$evidence['probe'],'payment_id'=>$evidence['payment_id']]))==='denied');
    // NULL at each argument and well-formed JSON of invalid shape are rejected by the function.
    $args=[$evidence['order_id'],'setup-1',$canonical,$anchorDigest];
    foreach([0,1,2,3] as $index){$bad=$args;$bad[$index]=null;
      $q=$producer->prepare('SELECT onepage_buyer_lab.fixture_admit_entry(CAST(? AS uuid),?,CAST(? AS jsonb),?)');
      $q->execute($bad);$check($q->fetchColumn()==='denied');}
    foreach(['null','[]','1','"scalar"','{}'] as $badJson){
      $q=$producer->prepare('SELECT onepage_buyer_lab.fixture_admit_entry(CAST(? AS uuid),?,CAST(? AS jsonb),?)');
      $q->execute([$evidence['order_id'],'setup-1',$badJson,$anchorDigest]);$check($q->fetchColumn()==='denied');}
    foreach(['due_date'=>'invalid','amount_cents'=>'invalid','order_id'=>'invalid','contract_scope'=>null,'probe'=>null,'payment_id'=>null] as $key=>$value)
      $check($call(array_replace($evidence,[$key=>$value]),null,$evidence['order_id'])==='denied');
    foreach([[0,'invalid-uuid'],[2,'{invalid-json']] as [$index,$badValue]){
      $bad=$args;$bad[$index]=$badValue;$caught=false;
      try{$q=$producer->prepare('SELECT onepage_buyer_lab.fixture_admit_entry(CAST(? AS uuid),?,CAST(? AS jsonb),?)');$q->execute($bad);}
      catch(PDOException $e){$check($e->getCode()==='22P02');$caught=true;}
      $check($caught);
    }
    $check($count($audit)===0 && $state($audit)===$baseline);
    $producer->beginTransaction();
    try {$check($call($evidence)==='recorded');$check($count($audit)===0);$producer->rollBack();}
    finally {if($producer->inTransaction())$producer->rollBack();}
    $check($count($audit)===0 && $state($audit)===$baseline);
    $producer->beginTransaction();
    try {$check($call($evidence)==='recorded');$check($count($audit)===0);$concurrent($producer,$canonical,$anchorDigest,static function()use($check,$count,$audit):void{$check($count($audit)===0);});}
    finally {if($producer->inTransaction())$producer->rollBack();}
    $fresh=$freshAuditor();$check($fresh instanceof PDO && $fresh!==$audit);
    $attest($fresh,'onepage_lab_bootstrap');$check(!$fresh->inTransaction());
    $check($fresh->query('SELECT pg_backend_pid()')->fetchColumn()!==$audit->query('SELECT pg_backend_pid()')->fetchColumn());
    $readback=static function(PDO $p)use($evidence):array{
      $q=$p->prepare("SELECT to_jsonb(w)::text canonical, encode(sha256(convert_to(to_jsonb(w)::text,'UTF8')),'hex') digest, xmin::text revision FROM onepage_buyer_lab.fixture_binding_witnesses w WHERE probe=?");
      $q->execute([$evidence['probe']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
      if(count($rows)!==1)throw new RuntimeException('ADMISSION_READBACK_REFUSED');return $rows[0];};
    $check($count($fresh)===1 && $state($fresh)===$baseline);
    $stored=$readback($fresh);$check($stored['canonical']===$canonical && $stored['digest']===$anchorDigest);
    $check($call($evidence)==='replay' && $count($fresh)===1);
    $check($call(array_replace($evidence,['payment_id'=>'pay_LABconflict']))==='denied');
    $check($call(array_replace($evidence,['probe'=>'admit-v1:anotherEvent']))==='denied');
    $check($count($fresh)===1 && $state($fresh)===$baseline && $readback($fresh)===$stored);
    echo "PASS ADMISSION_ANCHORED_ACL_NULL_OTHER_ATTEMPTED_ROLLBACK_CONCURRENT_COMMIT_READBACK_REPLAY\n";
}
