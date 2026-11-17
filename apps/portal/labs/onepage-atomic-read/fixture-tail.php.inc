// Appended inside the pinned #923 preparation try block; no old acceptance suite.
 $companies=[];
 foreach(['a','b'] as $label){
  $company=$db->selectOne('SELECT gen_random_uuid() id')->id;$companies[$label]=$company;
  $db->insert("INSERT INTO portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,?,?,'active',1)",[$company,'lab-atomic-'.$label,'Synthetic read']);
 }
 $actors=[];$sessions=[];
 foreach(['own','same','other'] as $label){
  $actor=$db->selectOne('SELECT gen_random_uuid() id')->id;$actors[$label]=$actor;
  $company=$companies[$label==='other'?'b':'a'];$email='atomic-'.$label.'@example.invalid';
  $db->insert("INSERT INTO portal_identities(id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,?,'active',?,?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)",[$actor,$email,$hash,$cipher]);
  $db->insert("INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)",[$actor,$company]);
  $pending=$auth->beginPassword($email,$password,$company,'192.0.2.20');
  if(!is_string($pending))throw new RuntimeException('NATIVE_PASSWORD_REQUIRED');
  $n=(int)$db->selectOne('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint n')->n;
  $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);$offset=ord($h[19])&15;
  $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
  $sessions[$label]=$auth->completeMfa($pending,$code,'192.0.2.20');
  if(!is_string($sessions[$label])||!$auth->isActiveBusinessSession($sessions[$label]))throw new RuntimeException('NATIVE_MFA_REQUIRED');
 }
 $own=requestOrder($sessions['own']);
 $draft=$quotes->create($own,'atomic-read-quote',['site'=>'onepage','channels'=>[],'tier'=>'minimum']);
 if(($draft['status']??null)!=='draft')throw new RuntimeException('DRAFT_REQUIRED');
 $quote=$draft['id'];
 $db->insert("INSERT INTO onepage_buyer_lab.order_terms(quote_id,quote_snapshot,contract_ref,contract_revision,contract_text,terms) SELECT id,quote,'lab-atomic-contract',1,'Synthetic pending contract only.',?::jsonb FROM onepage_buyer_lab.drafts WHERE id=?",[json_encode(['scope'=>'synthetic_only','payment_policy'=>'undecided']),$quote]);
 $review=$orders->review($own,$quote);
 if(($review['status']??null)!=='review')throw new RuntimeException('REVIEW_REQUIRED');
 $accept=['accepted'=>true]+array_intersect_key($review['review'],array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
 $order=$orders->accept($own,'atomic-read-order',$quote,$accept);
 if(($order['status']??null)!=='pending_payment'||$order['payment_requested']!==false||$order['site_provisioned']!==false
  ||(int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n!==1)throw new RuntimeException('ONE_PENDING_ORDER_REQUIRED');
 // Only the negative expiry witness is seeded; positive sessions above are native-issued.
 $expired=bin2hex(random_bytes(32));
 $db->insert("INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,session_purpose,identity_revision,membership_revision,organization_revision,issued_at,expires_at,mfa_verified_at) VALUES(gen_random_uuid(),?,?,?,'active','business',1,1,1,clock_timestamp()-interval '2 hours',clock_timestamp()-interval '1 hour',clock_timestamp()-interval '2 hours')",[hash('sha256',$expired),$actors['own'],$companies['a']]);
 echo "PASS ATOMIC_NATIVE_FIXTURES\n";
 require __ATOMIC_HOOK__;
 $count=checkAtomicRead($db,$runtime,$argv[1],$argv[2],$own,requestOrder($sessions['same']),requestOrder($sessions['other']),requestOrder($expired),$order['id']);
 if($count!==16)throw new RuntimeException('ATOMIC_CASE_COUNT');
 echo "PASS ATOMIC_SQL_COMPLETE: 16\n";
} catch(Throwable $e) {
 fwrite(STDERR,"ATOMIC_SQL_FAILED\n");exit(1);
}
