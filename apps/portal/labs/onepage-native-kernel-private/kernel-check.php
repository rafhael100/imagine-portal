<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\{BuyerQuoteAdapter,Guard};
use App\Labs\OnepagePendingOrder\Adapter;
use App\Providers\PortalDevelopmentAuthServiceProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;

/** Synthetic-only. Keep production routes/providers, runtime roles and order databases unchanged. */
function nativeCookieToOrder($app,$db,PDO $owner,array $identity,Closure $barrier,Closure $connect,
    string $proof,string $key,string $hash,string $cipher,string $password,string $scratch): void
{
    $expect=static function(bool $v):void { if(!$v) throw new RuntimeException('NATIVE_KERNEL_CASE_REQUIRED'); };
    $expect(getenv('APP_ENV')==='testing'&&getenv('ONEPAGE_PENDING_ORDER_LAB')==='SYNTHETIC_ONLY'
        &&$db->getPdo()===$owner&&is_dir($scratch)&&realpath($scratch)===$scratch
        &&(fileperms($scratch)&077)===0);
    $barrier();
    $expect($db->selectOne("SELECT to_regclass('public.portal_browser_envelopes') AS target")->target===null);
    $envelopes=dirname(__DIR__,2).'/database/security/portal_browser_envelopes.sql';
    $db->unprepared(file_get_contents($envelopes));
    // Exact existing SQL creates a NOLOGIN role. Enable only this fixture's loopback
    // login, as the original browser test setup does; grant nothing extra.
    $db->statement('ALTER ROLE portal_browser_envelope_runtime LOGIN');
    $organization=$db->selectOne('SELECT gen_random_uuid() id')->id;
    $db->insert("INSERT INTO portal_organizations(id,reference,display_name,status,security_revision)
      VALUES(?, 'lab-native-kernel', 'Synthetic kernel', 'active', 1)",[$organization]);
    $identities=[];
    foreach(['buyer','neighbor','unlinked'] as $label){
        $identities[$label]=$id=$db->selectOne('SELECT gen_random_uuid() id')->id;
        $db->insert("INSERT INTO portal_identities(id,email_normalized,status,password_hash,mfa_secret_ciphertext,
          email_verified_at,mfa_confirmed_at,security_revision)
          VALUES(?,?,'active',?,?,clock_timestamp(),clock_timestamp(),1)",[$id,$label.'@example.invalid',$hash,$cipher]);
        if($label!=='unlinked') $db->insert("INSERT INTO portal_memberships(id,identity_id,organization_id,
          role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)",[$id,$organization]);
    }

    // Same isolated DB as buyer SQL, with the original 3 distinct limited roles.
    // Per-run synthetic strings satisfy the provider config contract. Inherited
    // loopback trust means this test does not attest PG password authentication.
    $origin='https://portal-dev.imaginecomunica.com.br';
    $configuration=['origin'=>$origin,'host'=>'127.0.0.1','port'=>55439,'database'=>'portal_onepage_buyer_ci',
        'identity_password'=>bin2hex(random_bytes(32)),'envelope_password'=>bin2hex(random_bytes(32)),
        'proof_key'=>$proof,'totp_key'=>base64_encode($key),'scope_pepper'=>bin2hex(random_bytes(32))];
    $file=$scratch.'/native-kernel-fixture.json';
    $oldMask=umask(0077);
    try { $f=fopen($file,'x'); } finally {umask($oldMask);}
    $expect(is_resource($f));
    try {
        $raw=json_encode($configuration,JSON_THROW_ON_ERROR);
        try {$expect(fwrite($f,$raw)===strlen($raw));} finally {fclose($f);}
        unset($configuration,$raw);
        $before=count($app['router']->getRoutes());
        // Existing integration test also uses staging-config inside an isolated
        // testing process. No middleware/check is replaced; no network request.
        $app['config']->set(['app.env'=>'staging','app.debug'=>false,'app.url'=>$origin,
            'portal_development_auth.enabled'=>true,'portal_development_auth.organization'=>$organization,
            'portal_development_auth.secret_file'=>$file,'session.driver'=>'portal_private_pg',
            'session.cookie'=>'__Host-imagine-portal-session','session.secure'=>true,'session.http_only'=>true,
            'session.domain'=>null,'session.path'=>'/','session.same_site'=>'strict',
            'session.serialization'=>'json','session.encrypt'=>false]);
        $app->instance('encrypter',new Encrypter(random_bytes(32),'AES-256-CBC'));
        $provider=new PortalDevelopmentAuthServiceProvider($app);
        $provider->register();$provider->boot();
        $expect(count($app['router']->getRoutes())>$before);
        $app['router']->getRoutes()->refreshNameLookups();
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
        $request=static function(string $method,string $slug,?string &$cookie,array $body=[],?string $token=null)
            use($app,$kernel,$origin,$expect):array {
            $app['session']->forgetDrivers();$app->forgetInstance('session.store');
            $args=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json','HTTPS'=>'on',
                'HTTP_ORIGIN'=>$origin,'HTTP_SEC_FETCH_SITE'=>'same-origin','REMOTE_ADDR'=>'192.0.2.81'];
            if($token!==null) $args['HTTP_X_CSRF_TOKEN']=$token;
            $raw=Request::create($origin.'/_development/auth/'.$slug,$method,[],
                $cookie===null?[]:['__Host-imagine-portal-session'=>$cookie],[],$args,json_encode($body,JSON_THROW_ON_ERROR));
            $response=$kernel->handle($raw);$kernel->terminate($raw,$response);
            foreach($response->headers->getCookies() as $c) if($c->getName()==='__Host-imagine-portal-session'){
                $expect($c->isSecure()&&$c->isHttpOnly()&&$c->getPath()==='/'&&$c->getDomain()===null);
                $cookie=$c->getValue();
            }
            return [$response->getStatusCode(),$raw,json_decode((string)$response->getContent(),true)];
        };
        $token=static function(?string &$cookie)use($request,$expect):string{
            [$status,,$body]=$request('GET','csrf',$cookie);
            $expect($status===200&&is_string($cookie)&&is_string($body['csrf_token']??null));
            return $body['csrf_token'];
        };
        $login=static function(string $label,?string &$cookie)use($request,$token,$organization,$password):int{
            return $request('POST','password',$cookie,['email'=>$label.'@example.invalid','password'=>$password,
                'organization'=>$organization],$token($cookie))[0];
        };
        $complete=static function(?string &$cookie)use($request,$token,$db,$expect):void{
            $n=(int)$db->selectOne('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint n')->n;
            $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);
            $offset=ord($h[19])&15;
            $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
            $expect($request('POST','mfa',$cookie,['code'=>$code],$token($cookie))[0]===200);
        };
        $runt=$connect('onepage_buyer_runtime');Guard::runtime($runt,$owner,$identity,'onepage_buyer_runtime');
        $quotes=new BuyerQuoteAdapter($runt);$orders=new Adapter($runt);
        $selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
        $buyer=$neighbor=$unlinked=null;
        $expect($login('unlinked',$unlinked)===401);
        [$status,$unlinkedRequest]=$request('GET','session',$unlinked);
        $expect($status===401&&$quotes->create($unlinkedRequest,'ignored-unlinked',$selection)===['status'=>'denied']);
        echo "PASS NATIVE_KERNEL_UNLINKED_DENIED\n";
        $expect($login('buyer',$buyer)===202);
        [$status,$pending]=$request('GET','session',$buyer);
        $expect($status===401&&$quotes->create($pending,'ignored-pending',$selection)===['status'=>'denied']);
        $expect($request('POST','mfa',$buyer,['code'=>'000000'],'invalid-csrf')[0]===419);
        echo "PASS NATIVE_KERNEL_PENDING_MFA_AND_CSRF_DENIED\n";
        $complete($buyer);
        [$status,$active]=$request('GET','session',$buyer);
        $expect($status===200);
        $expect($app['session']->driver()->getHandler() instanceof
            App\Infrastructure\Identity\EncryptedPostgresPortalSessionHandler
            &&(int)$db->selectOne('SELECT count(*) n FROM public.portal_browser_envelopes')->n>0);
        echo "PASS NATIVE_KERNEL_PASSWORD_TOTP_ENCRYPTED_SESSION\n";
        $draft=$quotes->create($active,'native-kernel-draft',$selection);
        $expect(($draft['status']??null)==='draft'&&is_string($draft['id']??null));
        $id=$draft['id'];
        $db->insert("INSERT INTO onepage_buyer_lab.order_terms(quote_id,quote_snapshot,contract_ref,
          contract_revision,contract_text,terms) SELECT id,quote,'lab-kernel-contract',1,'Synthetic checkout only.',
          ?::jsonb FROM onepage_buyer_lab.drafts WHERE id=?",[json_encode(['scope'=>'synthetic_only',
          'payment_policy'=>'undecided'],JSON_THROW_ON_ERROR),$id]);
        $expect($login('neighbor',$neighbor)===202);$complete($neighbor);
        [$status,$neighborRequest]=$request('GET','session',$neighbor);
        $expect($status===200&&$quotes->resume($neighborRequest,$id)===['status'=>'denied']);
        [$status,$resumed]=$request('GET','session',$buyer);
        $fresh=$connect('onepage_buyer_runtime');
        $expect($status===200&&(new BuyerQuoteAdapter($fresh))->resume($resumed,$id)===$draft);
        $review=$orders->review($resumed,$id);
        $expect(($review['status']??null)==='review');
        $fields=['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest'];
        $accept=['accepted'=>true]+array_intersect_key($review['review'],array_flip($fields));
        $expect(count($accept)===7&&$orders->accept($neighborRequest,'native-kernel-order',$id,$accept)===['status'=>'denied']
            &&$orders->accept($pending,'native-kernel-order',$id,$accept)===['status'=>'denied']
            &&$orders->accept($unlinkedRequest,'native-kernel-order',$id,$accept)===['status'=>'denied']);
        $expect($orders->accept($resumed,'native-kernel-order',$id,array_replace($accept,['contract_revision'=>2]))
            ===['status'=>'invalid_acceptance']);
        $expect((int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n===0);
        echo "PASS NATIVE_KERNEL_WRONG_OWNER_OR_REVISION_NO_WRITE\n";
        $order=$orders->accept($resumed,'native-kernel-order',$id,$accept);
        $expect(($order['status']??null)==='pending_payment'&&$order['payment_requested']===false
            &&$order['site_provisioned']===false
            &&(new Adapter($fresh))->accept($resumed,'native-kernel-order',$id,$accept)===$order
            &&(int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n===1);
        $expect($request('POST','logout',$buyer,[],$token($buyer))[0]===204);
        [$status,$loggedOut]=$request('GET','session',$buyer);
        $expect($status===401&&$quotes->resume($loggedOut,$id)===['status'=>'denied']);
        echo "PASS NATIVE_KERNEL_EXACT_ACCEPTANCE_AND_REPLAY_LOGOUT_DENIED\n";
    } finally {
        if(is_file($file)&&!is_link($file)) unlink($file);
    }
}
