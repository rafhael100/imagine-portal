<?php
declare(strict_types=1);
namespace App\Infrastructure\Commerce;

use App\Homologation\Commerce\CompositeQuotePreview;
use Closure;
use Throwable;

/** Shared command logic. The injected SQL gateway must enforce its native connection authority. */
final readonly class OnepageOrderCommands
{
    public function __construct(private Closure $query) {}

    public static function reference(string $reference): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D',$reference)===1;
    }

    public static function uuid(string $id): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$id)===1;
    }

    public static function matches(array $review,array $acceptance): bool
    {
        $expected=['accepted'=>true];
        foreach (['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest'] as $key) {
            if (!array_key_exists($key,$review)) return false;
            $expected[$key]=$review[$key];
        }
        ksort($expected);ksort($acceptance);
        return $expected===$acceptance;
    }

    public function createDraft(string $reference,string $requestKey,array $selection): array
    {
        $keys=array_keys($selection);sort($keys);
        if ($keys!==['channels','site','tier'] || $selection['site']!=='onepage' || $selection['channels']!==[]
            || !in_array($selection['tier'],['minimum','recommended'],true)
            || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$requestKey)) return ['status'=>'invalid_selection'];
        if (!self::reference($reference)) return ['status'=>'denied'];
        try {
            $selection=['site'=>'onepage','channels'=>[],'tier'=>$selection['tier']];
            $quote=(new CompositeQuotePreview())->quote($selection);
            return $this->call('command',[hash('sha256',$reference),'create',hash('sha256',$requestKey),null,
                json_encode($selection,JSON_THROW_ON_ERROR),json_encode($quote,JSON_THROW_ON_ERROR)])??['status'=>'unavailable'];
        } catch (Throwable) { return ['status'=>'unavailable']; }
    }

    public function resumeDraft(string $reference,string $draftId): array
    {
        if (!self::reference($reference)||!self::uuid($draftId)) return ['status'=>'denied'];
        return $this->call('command',[hash('sha256',$reference),'resume',null,$draftId,null,null])??['status'=>'unavailable'];
    }

    public function review(string $reference,string $quoteId): ?array
    {
        if (!self::reference($reference)||!self::uuid($quoteId)) return null;
        return $this->call('review_order',[hash('sha256',$reference),$quoteId]);
    }

    public function accept(string $reference,string $requestKey,string $quoteId,array $acceptance): array
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D',$requestKey)) return ['status'=>'invalid_acceptance'];
        if (!self::reference($reference)||!self::uuid($quoteId)) return ['status'=>'denied'];
        $review=$this->review($reference,$quoteId);
        if ($review===null) return ['status'=>'denied'];
        if (!self::matches($review,$acceptance)) return ['status'=>'invalid_acceptance'];
        // This second SQL call re-reads the same native authority and exact terms under locks.
        return $this->call('accept_order',[hash('sha256',$reference),hash('sha256',$requestKey),$quoteId,
            json_encode($acceptance,JSON_THROW_ON_ERROR)])??['status'=>'unavailable'];
    }

    public function list(string $reference): array
    {
        if (!self::reference($reference)) return ['status'=>'denied'];
        return $this->call('list_my_pending_orders',[hash('sha256',$reference)])??['status'=>'unavailable'];
    }

    public function read(string $reference,string $orderId): ?array
    {
        if (!self::reference($reference)||!self::uuid($orderId)) return null;
        return $this->call('read_pending_order',[hash('sha256',$reference),$orderId]);
    }

    private function call(string $function,array $parameters): ?array
    {
        try {
            $raw=($this->query)($function,$parameters);
            if (!is_string($raw)) return null;
            $value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            return is_array($value)&&!array_is_list($value)?$value:null;
        } catch (Throwable) { return null; }
    }
}
