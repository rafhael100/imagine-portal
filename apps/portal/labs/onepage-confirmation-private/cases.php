<?php
declare(strict_types=1);
// Included inside the pinned native kernel after acceptance, before real logout.
require_once __DIR__.'/NextStep.php';
$barrier();
$db->unprepared(file_get_contents(__DIR__.'/confirmation.sql'));
$barrier();
$read=function($req,string $id)use($connect,$owner,$identity,$barrier):array{
    return (new App\Labs\OnepageConfirmation\NextStep($connect('onepage_buyer_runtime'),$owner,$identity,$barrier))->read($req,$id);
};
$closed=['next_step'=>null];$open=['next_step'=>'briefing'];
// Independent fixture sentinels, selected before native order lookup. Never an ID
// derived from order, acceptance time or receipt marker (#938 checkpoint principle).
$sourceId='independent-test-feed.v1';$entryId='entry_LABalpha';$sourceEventId='event_LABconfirmed';
$orderId=$order['id'];
$expect($read($resumed,$orderId)===$closed && $read($neighborRequest,$orderId)===$closed);

// A second accepted native order for the SAME authenticated buyer stays unpaid.
$secondDraft=$quotes->create($resumed,'native-confirmation-other-draft',$selection);
$expect(($secondDraft['status']??null)==='draft');
$db->insert("INSERT INTO onepage_buyer_lab.order_terms SELECT ?::uuid,quote_snapshot,contract_ref,
 contract_revision,contract_text,terms FROM onepage_buyer_lab.order_terms WHERE quote_id=?",
 [$secondDraft['id'],$id]);
$secondReview=$orders->review($resumed,$secondDraft['id']);
$secondAcceptance=['accepted'=>true]+array_intersect_key($secondReview['review'],array_flip($fields));
$secondOrder=$orders->accept($resumed,'native-confirmation-other-order',$secondDraft['id'],$secondAcceptance);
$expect(($secondOrder['status']??null)==='pending_payment');
$nativeBefore=$db->selectOne("SELECT md5(string_agg(to_jsonb(o)::text,'' ORDER BY id)) digest FROM onepage_buyer_lab.pending_orders o")->digest;

// Bootstrap stages the independent synthetic feed and its exact native binding.
// No runtime can write this fixture source; it is not a provider receipt.
$row=$db->selectOne('SELECT *, accepted_at::text accepted FROM onepage_buyer_lab.pending_orders WHERE id=?',[$orderId]);
$stamp=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
$accepted=(new DateTimeImmutable($row->accepted))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
$event=['version'=>'onepage.synthetic-confirmation.v1','origin'=>'independent-test-reconciliation',
 'source'=>['id'=>$sourceId,'entry'=>$entryId,'event'=>$sourceEventId],
 'revision'=>1,'scope'=>['company'=>$row->company,'order'=>$row->id,'quote'=>$row->quote_id,
 'account'=>'synthetic-onepage-v1','environment'=>'synthetic','entry'=>'setup-1',
 'amount_cents'=>json_decode($row->quote_snapshot,true,512,JSON_THROW_ON_ERROR)['illustrative_three_parts_cents'][0],
 'offer_digest'=>$row->offer_digest,'terms_digest'=>$row->terms_digest,'contract_ref'=>$row->contract_ref,
 'contract_revision'=>(int)$row->contract_revision,'contract_digest'=>$row->contract_digest,
 'catalog_version'=>json_decode($row->quote_snapshot,true,512,JSON_THROW_ON_ERROR)['catalog_version'],
 'accepted_at'=>$order['accepted_at']], 'state'=>'confirmed','reversal'=>'clear',
 'confirmed_at'=>$stamp,'observed_at'=>$stamp,'remaining'=>[
 ['line'=>'setup-2','due'=>$accepted->modify('+30 days')->format('Y-m-d'),'state'=>'pending'],
 ['line'=>'setup-3','due'=>$accepted->modify('+60 days')->format('Y-m-d'),'state'=>'pending']]];
$writer=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_test_reconciler','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$profile=$writer->query("SELECT current_database() db,current_user actor,session_user login,
 host(inet_server_addr()) host,inet_server_port() port,
 extract(epoch FROM pg_postmaster_start_time())::bigint started,
 rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
 OR EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid) unsafe FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
$expect($profile['db']===$identity['db']&&$profile['host']==='127.0.0.1'&&(int)$profile['port']===55439
 &&$profile['actor']==='onepage_test_reconciler'&&$profile['login']===$profile['actor']
 &&(string)$profile['started']===(string)$identity['started']&&in_array($profile['unsafe'],[false,'f',0,'0'],true));
$observer=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_test_observer','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$stage=static function(array $e)use($owner,$barrier):void{
 $barrier();
 $q=$owner->prepare('INSERT INTO onepage_buyer_lab.synthetic_source_events(source_id,event_id,entry_id,source_version,evidence) VALUES(?,?,?,1,?::jsonb)');
 $q->execute([$e['source']['id'],$e['source']['event'],$e['source']['entry'],json_encode($e,JSON_THROW_ON_ERROR)]);
};
$observe=static function(array $e)use($observer):?array{
 $q=$observer->prepare('SELECT onepage_buyer_lab.observe_synthetic_confirmation(?,?)');
 $q->execute([$e['source']['id'],$e['source']['event']]);
 return json_decode($q->fetchColumn()?:'null',true,512,JSON_THROW_ON_ERROR);
};
$appendToken=static function(PDO $p,string $id,array $e,int $version,string $digest):string{
 $q=$p->prepare('SELECT onepage_buyer_lab.append_synthetic_confirmation(?::uuid,?,?,?::bigint,?)');
 $q->execute([$id,$e['source']['id'],$e['source']['event'],$version,$digest]);return $q->fetchColumn();
};
$append=static function(PDO $p,string $id,array $e)use($observe,$appendToken):string{
 $seen=$observe($e);
 return $appendToken($p,$id,$e,$seen['version']??1,$seen['digest']??str_repeat('0',64));
};
$barrier();
$q=$owner->prepare('INSERT INTO onepage_buyer_lab.synthetic_entry_sources(source_id,entry_id,scope,confirmed_at) VALUES(?,?,?::jsonb,?::timestamptz)');
$q->execute([$sourceId,$entryId,json_encode($event['scope'],JSON_THROW_ON_ERROR),$stamp]);
// The source itself must commit before observer/writer can use it.
$owner->beginTransaction();$stage($event);
$expect($observe($event)===null&&$append($writer,$orderId,$event)==='denied'&&$read($resumed,$orderId)===$closed);
$owner->rollBack();
$expect($observe($event)===null);
$stage($event);
$seen=$observe($event);$expect(is_array($seen)&&$seen['version']===1);
$expect($appendToken($writer,$orderId,$event,2,$seen['digest'])==='denied');
$expect($appendToken($writer,$orderId,$event,1,str_repeat('0',64))==='denied');
echo "PASS CONFIRMATION_SOURCE_COMMIT_VERSION_DIGEST_REQUIRED\n";
$bad=[];
foreach(['company','order','quote','account','environment','entry','amount_cents','offer_digest','terms_digest','contract_ref',
 'contract_revision','contract_digest','catalog_version','accepted_at'] as $field){
 $v=$event;$v['scope'][$field]=$field==='contract_revision'?999:'divergent';$bad[]=$v;
}
foreach(['version'=>'unknown','origin'=>'receipt-marker','state'=>'unknown','reversal'=>'unknown',
 'confirmed_at'=>null,'observed_at'=>null,'revision'=>2] as $field=>$value){$v=$event;$v[$field]=$value;$bad[]=$v;}
$v=$event;$v['confirmed_at']='2999-01-01T00:00:00.000000Z';$bad[]=$v;
$v=$event;$v['confirmed_at']='2000-01-01T00:00:00.000000Z';$bad[]=$v;
$v=$event;$v['remaining'][0]['due']='2000-01-01';$bad[]=$v;
$v=$event;$v['state']='reversed';$v['reversal']='reversed';$bad[]=$v;
foreach($bad as $i=>$v){
 $v['source']['event']='event_LABnegative'.$i;$stage($v);
 $expect($append($writer,$orderId,$v)==='denied'&&$read($resumed,$orderId)===$closed);
}
$expect($append($writer,$secondOrder['id'],$event)==='denied');
echo "PASS CONFIRMATION_DIVERGENT_UNKNOWN_DATES_DENIED\n";

// Defensively attempt ordinary SQL operations; expect exact permission denial.
$truth=static fn($v)=>in_array($v,[true,'t',1,'1'],true);
$writerFunction='onepage_buyer_lab.append_synthetic_confirmation(uuid,text,text,bigint,text)';
foreach(['onepage_buyer_runtime','portal_browser_runtime','portal_browser_envelope_runtime','onepage_test_reconciler','onepage_test_observer'] as $role){
 $acl=$owner->prepare("SELECT has_table_privilege(?,'onepage_buyer_lab.synthetic_confirmations','SELECT,INSERT,UPDATE,DELETE,TRUNCATE') direct,
 has_function_privilege(?,?,'EXECUTE') writer");$acl->execute([$role,$role,$writerFunction]);$acl=$acl->fetch(PDO::FETCH_ASSOC);
 $expect(!$truth($acl['direct'])&&$truth($acl['writer'])===($role==='onepage_test_reconciler'));
 $p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci',$role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['SELECT * FROM onepage_buyer_lab.synthetic_confirmations',
  "INSERT INTO onepage_buyer_lab.synthetic_confirmations(order_id,revision,contract) VALUES('$orderId',900,'{}')",
  'UPDATE onepage_buyer_lab.synthetic_confirmations SET revision=900',
  'DELETE FROM onepage_buyer_lab.synthetic_confirmations',
  'TRUNCATE onepage_buyer_lab.synthetic_confirmations'] as $sql){
  $state=null;try{$p->exec($sql);}catch(PDOException $e){$state=$e->getCode();}$expect($state==='42501');
 }
 if($role!=='onepage_test_reconciler'){
  $state=null;try{$append($p,$orderId,$event);}catch(PDOException $e){$state=$e->getCode();}$expect($state==='42501');
 }
}
$acl=$owner->query("SELECT EXISTS(SELECT FROM pg_proc p,aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
 WHERE p.oid IN ('onepage_buyer_lab.append_synthetic_confirmation(uuid,text,text,bigint,text)'::regprocedure,
 'onepage_buyer_lab.synthetic_next_step(text,uuid)'::regprocedure) AND a.grantee=0) public_execute")->fetchColumn();
$expect(!$truth($acl));
$expect((int)$owner->query("SELECT count(*) FROM pg_roles r WHERE rolname IN ('onepage_confirmation_owner','onepage_test_reconciler','onepage_test_observer')
 AND NOT (rolsuper OR rolcreatedb OR rolcreaterole OR rolinherit OR rolbypassrls OR rolreplication)
 AND NOT EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid)
 AND rolcanlogin=(rolname<>'onepage_confirmation_owner')")->fetchColumn()===3);
$expect(!$truth($owner->query("SELECT has_function_privilege('onepage_test_reconciler',
 'onepage_buyer_lab.synthetic_next_step(text,uuid)','EXECUTE')")->fetchColumn()));
foreach(['onepage_buyer_runtime','portal_browser_runtime','portal_browser_envelope_runtime','onepage_test_reconciler','onepage_test_observer'] as $role){
 foreach(['synthetic_entry_sources','synthetic_source_events'] as $table){
  $acl=$owner->prepare("SELECT has_table_privilege(?,?,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')");
  $acl->execute([$role,'onepage_buyer_lab.'.$table]);$expect(!$truth($acl->fetchColumn()));
 }
}
echo "PASS CONFIRMATION_RUNTIME_PRIVILEGE_WALL\n";

$writer->beginTransaction();
$expect($append($writer,$orderId,$event)==='recorded');
$expect($read($resumed,$orderId)===$closed); // genuinely separate connection
$writer->rollBack();
$expect($read($resumed,$orderId)===$closed);
$expect((int)$owner->query('SELECT count(*) FROM onepage_buyer_lab.synthetic_confirmations')->fetchColumn()===0);
echo "PASS CONFIRMATION_UNCOMMITTED_AND_ROLLBACK_CLOSED\n";
$writer->beginTransaction();$expect($append($writer,$orderId,$event)==='recorded');$writer->commit();
$expect($read($resumed,$orderId)===$open&&$read($neighborRequest,$orderId)===$closed
 &&$read($pending,$orderId)===$closed&&$read($unlinkedRequest,$orderId)===$closed
 &&$read($resumed,$secondOrder['id'])===$closed);
$expect($append($writer,$orderId,$event)==='replay');
$expect((int)$owner->query('SELECT count(*) FROM onepage_buyer_lab.synthetic_confirmations')->fetchColumn()===1);
echo "PASS CONFIRMATION_COMMIT_FRESH_CONNECTION_OWN_ORDER_ONLY\n";
// Exact source/event cannot be reassigned even with the second order's valid
// native scope. Source ID conflict is tested separately from order mismatch.
$secondRow=$db->selectOne('SELECT * FROM onepage_buyer_lab.pending_orders WHERE id=?',[$secondOrder['id']]);
$conflict=$event;
foreach(['company','offer_digest','terms_digest','contract_ref','contract_digest'] as $field)$conflict['scope'][$field]=$secondRow->$field;
$conflict['scope']['order']=$secondRow->id;$conflict['scope']['quote']=$secondRow->quote_id;
$conflict['scope']['accepted_at']=$secondOrder['accepted_at'];
$conflict['scope']['contract_revision']=(int)$secondRow->contract_revision;
$state=null;try{$stage($conflict);}catch(PDOException $e){$state=$e->getCode();}$expect($state==='23505');
$expect($append($writer,$secondOrder['id'],$event)==='denied'&&$read($resumed,$secondOrder['id'])===$closed);
$conflict['source']['event']='event_LABreassign';$stage($conflict);
$expect($append($writer,$secondOrder['id'],$conflict)==='denied');
$persisted=$owner->query("SELECT source_id,event_id,entry_id FROM onepage_buyer_lab.synthetic_confirmations")->fetchAll(PDO::FETCH_ASSOC);
$expect($persisted===[['source_id'=>$sourceId,'event_id'=>$sourceEventId,'entry_id'=>$entryId]]);
echo "PASS CONFIRMATION_SOURCE_EVENT_UNIQUE_NATIVE_BINDING\n";
// A pre-existing transaction must be refused without even querying authority.
$held=$connect('onepage_buyer_runtime');$held->beginTransaction();
$held->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$held->query('SELECT 1')->fetchColumn();
$attested=0;
$reader=new App\Labs\OnepageConfirmation\NextStep($held,$owner,$identity,static function()use(&$attested){$attested++;});
$expect($reader->read($resumed,$orderId)===$closed&&$attested===0&&$held->inTransaction());
$held->rollBack();
$held->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$reader=new App\Labs\OnepageConfirmation\NextStep($held,$owner,$identity,$barrier);
$expect($reader->read($resumed,$orderId)===$closed);
$expect($read($resumed,$orderId)===$open);
echo "PASS CONFIRMATION_OLD_TRANSACTION_SNAPSHOT_REFUSED\n";
// Only in-good-standing positive: no assertion authorizes first activation in
// the ambiguous 1-7-day range or suspends an already active service.
$expect($truth($owner->query("SELECT onepage_buyer_lab.confirmation_first_release_in_scope('2026-10-01','2026-10-01')")->fetchColumn()));
$expect($truth($owner->query("SELECT onepage_buyer_lab.confirmation_first_release_in_scope('2026-10-02','2026-10-01')")->fetchColumn()));
$expect($truth($owner->query("SELECT
 onepage_buyer_lab.confirmation_first_release_in_scope('2026-11-30',
 (timestamptz '2026-11-01 02:59:59+00' AT TIME ZONE 'America/Sao_Paulo')::date)
 AND onepage_buyer_lab.confirmation_first_release_in_scope('2026-11-30',
 (timestamptz '2026-11-01 03:00:00+00' AT TIME ZONE 'America/Sao_Paulo')::date)")->fetchColumn()));
echo "PASS CONFIRMATION_FIRST_RELEASE_IN_GOOD_STANDING_ONLY\n";
$reversal=$event;$reversal['revision']=2;$reversal['state']='reversed';$reversal['reversal']='reversed';$reversal['source']['event']='event_LABreversed';$stage($reversal);
$writer->beginTransaction();$expect($append($writer,$orderId,$reversal)==='recorded');$writer->commit();
$expect($read($resumed,$orderId)===$closed&&$append($writer,$orderId,$event)==='denied');
$reopen=$event;$reopen['revision']=3;$reopen['source']['event']='event_LABreopen';$stage($reopen);$expect($append($writer,$orderId,$reopen)==='denied');
$expect($read($resumed,$secondOrder['id'])===$closed
 &&$db->selectOne("SELECT md5(string_agg(to_jsonb(o)::text,'' ORDER BY id)) digest FROM onepage_buyer_lab.pending_orders o")->digest===$nativeBefore);
echo "PASS CONFIRMATION_REVERSAL_TERMINAL_NO_ORDER_MUTATION\n";
