<?php
declare(strict_types=1);
namespace PrivateSessionObserver;
use App\Domain\Commerce\OnepageOrderReader;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Infrastructure\Commerce\PostgresPortalSiteContractSource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Closure;
use DomainException;
use Throwable;
/** Manually loaded test harness hook after native kernel handling, before response emission. */
final class Observer
{
    public function __construct(#[\SensitiveParameter] private OnepageOrderReader $reader,
        #[\SensitiveParameter] private Closure $consume)
    {
        if(!in_array(PHP_SAPI,['cli','cli-server'],true))throw new DomainException('OBSERVATION_REFUSED');
    }
    public function inspect(#[\SensitiveParameter] Request $request,#[\SensitiveParameter] JsonResponse $response,
        #[\SensitiveParameter] ObservedIds $ids):void
    {
        $level=ob_get_level();ob_start();
        try {
            $need=static function(bool $ok):void {if(!$ok)throw new DomainException('OBSERVATION_REFUSED');};
            $need($request->method()==='GET' && $request->getSchemeAndHttpHost()==='https://portal-dev.imaginecomunica.com.br'
                && $request->getPathInfo()==='/_development/commerce/onepage/orders/'.$ids->orderId
                && $request->query->count()===0 && $request->hasSession());
            $need($request->route()?->getActionName()==='App\Http\Controllers\OnepageOrderController@show');
            $need($response->getStatusCode()===200);
            $state=$request->session()->get('portal.browser_auth');
            $need(is_array($state)&&($state['phase']??null)==='business'&&is_string($state['reference']??null)
                &&preg_match('/\A[a-f0-9]{64}\z/D',$state['reference'])===1);
            $body=$response->getData(true);$shown=$body['order']??null;
            $need(($body['status']??null)==='pending_payment' && is_array($shown)
                &&($shown['id']??null)===$ids->orderId&&($shown['quote_id']??null)===$ids->quoteId&&($shown['product']??null)==='onepage');
            // Reference never comes from request JSON/header/cookie decoding or browser observations.
            $order=$this->reader->findOwnedAcceptedOrder($state['reference'],$ids->orderId);
            $need($order!==null&&$order->id===$ids->orderId&&$order->quoteId===$ids->quoteId);
            foreach(['contract_ref'=>'contractRef','contract_revision'=>'contractRevision','offer_digest'=>'offerDigest',
                'terms_digest'=>'termsDigest','contract_digest'=>'contractDigest','accepted_at'=>'acceptedAt'] as $key=>$property)
                $need(($shown[$key]??null)===$order->$property);
            $source=new PostgresPortalSiteContractSource($this->reader,$state['reference']);
            $wire=(new SitesCreateProjectEncoder($source))->build($order->id,'native-pair-operation-'.$order->id,'native-pair-idempotency-'.$order->id);
            $need($wire['tenantRef']===$order->companyReference&&$wire['orderRef']===$order->id
                &&$wire['contractRef']===$order->contractRef&&$wire['contractRevision']===$order->contractRevision);
            unset($state,$source);
            // Trusted in-process test callback only. No session reference crosses this boundary.
            ($this->consume)($order,$wire);
            $need(ob_get_level()===$level+1 && ob_get_contents()==='');
        } catch(Throwable) {
            throw new DomainException('OBSERVATION_REFUSED');
        } finally {
            while(ob_get_level()>$level)ob_end_clean();
        }
    }
    public function __debugInfo():array {return ['scope'=>'private observer'];}
    public function __serialize():array {throw new DomainException('OBSERVATION_REFUSED');}
}
