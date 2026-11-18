<?php
declare(strict_types=1);
namespace PrivateSessionObserver;
use DomainException;
final readonly class ObservedIds
{
    private function __construct(public string $quoteId,public string $orderId) {}
    public static function fromResponses(#[\SensitiveParameter] array $draft,#[\SensitiveParameter] array $accept):self
    {
        $q=$draft['id']??null;$o=$accept['id']??null;
        foreach([$q,$o] as $v) if(!is_string($v)||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$v)!==1)throw new DomainException('OBSERVATION_REFUSED');
        if($q===$o || ($draft['status']??null)!=='draft' || ($draft['order_created']??null)!==false
            || ($accept['status']??null)!=='pending_payment' || ($accept['quote_id']??null)!==$q)throw new DomainException('OBSERVATION_REFUSED');
        foreach([$draft,$accept] as $r)if(($r['payment_requested']??null)!==false||($r['site_provisioned']??null)!==false)throw new DomainException('OBSERVATION_REFUSED');
        return new self($q,$o);
    }
    public function __debugInfo():array {return ['scope'=>'private observer'];}
    public function __serialize():array {throw new DomainException('OBSERVATION_REFUSED');}
}
