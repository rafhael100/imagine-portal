// Inside the pinned native fixture. This is composition, not the green recovery suite.
 require __ADMISSION_ATTESTATION__;
 require __ADMISSION_CASES__;
 require __ANCHOR_BUILDER__;
 require __CONCURRENCY__;
 require_once __ATOMIC_READER__;
 require_once __PRODUCER_CONTRACT__;
 $draft2=$quotes->create($own,'admission-second-quote',['site'=>'onepage','channels'=>[],'tier'=>'minimum']);
 if(($draft2['status']??null)!=='draft')throw new RuntimeException('ADMISSION_SECOND_DRAFT_REQUIRED');
 $db->insert("INSERT INTO onepage_buyer_lab.order_terms(quote_id,quote_snapshot,contract_ref,contract_revision,contract_text,terms) SELECT id,quote,'lab-admission-contract',1,'Synthetic pending contract only.',?::jsonb FROM onepage_buyer_lab.drafts WHERE id=?",[json_encode(['scope'=>'synthetic_only','payment_policy'=>'undecided']),$draft2['id']]);
 $review2=$orders->review($own,$draft2['id']);
 if(($review2['status']??null)!=='review')throw new RuntimeException('ADMISSION_SECOND_REVIEW_REQUIRED');
 $accepted2=['accepted'=>true]+array_intersect_key($review2['review'],array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
 $second=$orders->accept($own,'admission-second-order',$draft2['id'],$accepted2);
 if(($second['status']??null)!=='pending_payment'||$second['id']===$order['id']
  ||(int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n!==2)
  throw new RuntimeException('ADMISSION_TWO_NATIVE_ORDERS_REQUIRED');
 $barrier();$db->unprepared(file_get_contents(__READ_SQL__));
 $barrier();$db->unprepared(file_get_contents(__RESERVE_SQL__));
 $barrier();$db->unprepared(file_get_contents(__BINDING_SQL__));
 $reader=App\Labs\OnepageAtomicRead\AtomicReader::guarded($runtime,$owner,$identity,$barrier);
 $owner->beginTransaction();
 try {
  foreach([$order['id'],$second['id']] as $id){
   // Reader attests a distinct buyer connection, never the bootstrap transaction.
   $native=$reader->read($own,$id);
   $plan=Imagine\Lab\OnepageBindingProducer\ProducerContract::prepare($native,'lab-admission-fixture','cus_LABadmission');
   if($plan===null)throw new RuntimeException('ADMISSION_PLAN_REQUIRED');
   $scope=array_diff_key($native['snapshot'],array_flip(['buyer','status','quote_snapshot','terms','contract_text']));
   $q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_charge_reservations(order_id,line,contract_scope,account,environment,customer,reference,due_date,amount_cents) VALUES(CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,CAST(? AS date),?)');
   foreach($plan->lines as $part)$q->execute([$id,$part['key'],json_encode($scope,JSON_THROW_ON_ERROR),$plan->scope['account'],'sandbox','cus_LABadmission',$part['external_reference'],$part['due_date'],$part['amount_cents']]);
  }
  $owner->commit();
 }finally{if($owner->inTransaction())$owner->rollBack();}
 $barrier();App\Labs\OnepageBuyer\Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
 $q=$runtime->prepare("SELECT onepage_buyer_lab.fixture_claim_charge(?,CAST(? AS uuid),'setup-1')");
 $q->execute([hash('sha256',$own->session()->get('portal.browser_auth')['reference']),$order['id']]);
 if(!in_array($q->fetchColumn(),[true,'t',1,'1'],true))throw new RuntimeException('ADMISSION_NATIVE_CLAIM_REQUIRED');
 // No provider emission. The second native order remains unattempted.
 $payload=static function(string $id,string $event,string $payment)use($owner):array {
  $q=$owner->prepare("SELECT (to_jsonb(r)-'attempted') || jsonb_build_object('probe',CAST(? AS text),'currency','BRL','payment_id',CAST(? AS text)) FROM onepage_buyer_lab.fixture_charge_reservations r WHERE order_id=CAST(? AS uuid) AND line='setup-1'");
  $q->execute([$event,$payment,$id]);return json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
 };
 $evidence=$payload($order['id'],'admit-v1:sourceEntryA','pay_LABadmissionA');
 $unattempted=$payload($second['id'],'admit-v1:sourceEntryB','pay_LABadmissionB');
 // Bootstrap-owned synthetic source: admit-v1 / sourceEntryA and pay_LABadmissionA.
 // Capture canonical full evidence before producer role/EXECUTE exists. Not a provider response.
 $barrier();
 $q=$owner->prepare("SELECT CAST(? AS jsonb)::text canonical, encode(sha256(convert_to(CAST(? AS jsonb)::text,'UTF8')),'hex') digest");
 $encoded=json_encode($evidence,JSON_THROW_ON_ERROR);$q->execute([$encoded,$encoded]);
 $anchor=$q->fetch(PDO::FETCH_ASSOC);
 $ddl=AdmissionAnchorDDL::render($anchor['canonical'],$anchor['digest']);
 $barrier();$db->unprepared($ddl);unset($ddl);
 $producer=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_fixture_evidence_producer','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $attest=static function(PDO $p,string $role)use($owner,$identity,$barrier,$argv):void {
  $barrier();
  if($role==='onepage_fixture_evidence_producer')AdmissionProducerAttestation::check($p,$owner,$identity,$barrier);
  elseif($role==='onepage_lab_bootstrap'){
   if($p->inTransaction()||App\Labs\OnepageBuyer\Guard::identity($p,$argv[1],$argv[2])!==$identity)throw new RuntimeException('ADMISSION_AUDITOR_IDENTITY_REFUSED');
  }else throw new RuntimeException('ADMISSION_ROLE_REFUSED');
  $barrier();
 };
 $freshAuditor=static function()use($attest):PDO {
  $p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_lab_bootstrap','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $attest($p,'onepage_lab_bootstrap');return $p;
 };
 $attest($producer,'onepage_fixture_evidence_producer');
 $claimOther=static function()use($runtime,$owner,$identity,$barrier,$own,$second):void {
  $barrier();App\Labs\OnepageBuyer\Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
  $q=$runtime->prepare("SELECT onepage_buyer_lab.fixture_claim_charge(?,CAST(? AS uuid),'setup-1')");
  $q->execute([hash('sha256',$own->session()->get('portal.browser_auth')['reference']),$second['id']]);
  if(!in_array($q->fetchColumn(),[true,'t',1,'1'],true))throw new RuntimeException('ADMISSION_OTHER_NATIVE_CLAIM_REQUIRED');
 };
 $concurrent=static function(PDO $p,string $canonical,string $digest,Closure $beforeCommit)use($owner,$identity,$barrier):void {
  admissionConcurrent($p,$owner,$identity,$barrier,$canonical,$digest,$beforeCommit);
 };
 checkEntryAdmission($producer,$owner,$freshAuditor,$attest,$evidence,$unattempted,$anchor['canonical'],$anchor['digest'],$claimOther,$concurrent);
