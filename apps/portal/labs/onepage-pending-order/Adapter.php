<?php
declare(strict_types=1);
namespace App\Labs\OnepagePendingOrder;
use Illuminate\Http\Request;
use PDO;
use Throwable;

/** Explicit lab only; not registered. Native SQL derives actor/company on every call. */
final readonly class Adapter
{
    public function __construct(private PDO $runtime) {}
    public static function matches(array $review,array $acceptance):bool {
        $expected=['accepted'=>true];
        foreach(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest'] as $key) {
            if (!array_key_exists($key,$review)) return false;
            $expected[$key]=$review[$key];
        }
        ksort($expected);ksort($acceptance);
        return $expected===$acceptance;
    }
    public function review(Request $request,string $quote):array {
        return $this->perform($request,$quote,null,null);
    }
    public function accept(Request $request,string $key,string $quote,array $acceptance):array {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$key)) return ['status'=>'invalid_acceptance'];
        return $this->perform($request,$quote,hash('sha256',$key),$acceptance);
    }
    private function perform(Request $request,string $quote,?string $key,?array $acceptance):array {
        if (getenv('APP_ENV')!=='testing'||getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'
            || !$request->hasSession() || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$quote)) return ['status'=>'denied'];
        $state=$request->session()->get('portal.browser_auth');
        if (!is_array($state)||($state['phase']??null)!=='business'
            || !is_string($state['reference']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$state['reference'])) return ['status'=>'denied'];
        try {
            if($this->runtime->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'||$this->runtime->inTransaction()) return ['status'=>'unavailable'];
            $p=$this->runtime->query("SELECT current_database() db,current_user actor,session_user login,host(inet_server_addr()) host,inet_server_port() port,
                rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
                OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
            if(!$p||$p['db']!=='portal_onepage_buyer_ci'||$p['actor']!=='onepage_buyer_runtime'||$p['login']!=='onepage_buyer_runtime'
                ||$p['host']!=='127.0.0.1'||(int)$p['port']!==55439||!in_array($p['unsafe'],[false,'f',0,'0'],true)) return ['status'=>'unavailable'];
            $session=hash('sha256',$state['reference']);
            $q=$this->runtime->prepare('SELECT onepage_buyer_lab.review_order(?,?::uuid)');$q->execute([$session,$quote]);
            $review=json_decode($q->fetchColumn()?:'null',true,512,JSON_THROW_ON_ERROR);
            if(!is_array($review))return ['status'=>'denied'];
            if($key===null)return ['status'=>'review','review'=>$review,'laboratory_only'=>true];
            if(!self::matches($review,$acceptance??[]))return ['status'=>'invalid_acceptance'];
            // SQL re-reads ownership/terms and checks the entire acceptance again, atomically.
            $q=$this->runtime->prepare('SELECT onepage_buyer_lab.accept_order(?,?,?::uuid,?::jsonb)');
            $q->execute([$session,$key,$quote,json_encode($acceptance,JSON_THROW_ON_ERROR)]);
            return json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        }catch(Throwable){return ['status'=>'unavailable'];}
    }
}
