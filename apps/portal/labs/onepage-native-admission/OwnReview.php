<?php
declare(strict_types=1);
namespace App\Labs\OnepageNativeAdmission;

use App\Homologation\Commerce\CommercialSelectionIntent as Intent;
use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepagePendingOrder\Adapter;
use Closure;
use Illuminate\Http\Request;
use PDO;
use Throwable;

/** Unregistered lab composition. The constructor is an offline wiring seam, not an authority source. */
final readonly class OwnReview
{
    public const KEY='lab.onepage.own_review';
    public const DENIED=['status'=>'denied','payment_link'=>null,'settled'=>false];

    public function __construct(private Closure $review, private Closure $attest) {}

    public static function guarded(PDO $runtime,PDO $bootstrap,array $identity,Closure $barrier):self
    {
        return new self((new Adapter($runtime))->review(...),static function()use($runtime,$bootstrap,$identity,$barrier):void {
            $barrier();
            Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');
            $barrier();
        });
    }

    /** Lab harness calls this for independently established server session state only. */
    public static function selectionDigest(array $intent):string
    {
        return hash('sha256',json_encode(Intent::parse($intent),JSON_THROW_ON_ERROR));
    }

    public function read(Request $request):array
    {
        if (getenv('APP_ENV')!=='testing'||getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'
            ||!$request->hasSession()) return self::DENIED;
        try {
            $session=$request->session();
            $state=$session->get('portal.browser_auth');
            if (!is_array($state)||($state['phase']??null)!=='business'||!self::digest($state['reference']??null)) return self::DENIED;
            $intent=Intent::current($session);
            $binding=$session->get(self::KEY);
            if ($intent===null||$intent['site']!=='onepage'||$intent['channels']!==''
                ||!is_array($binding)||!is_string($id=$binding['quote_id']??null)
                ||!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$id)
                ||!self::digest($binding['business_digest']??null)
                ||!hash_equals($binding['business_digest'],hash('sha256',$state['reference']))
                ||!self::digest($binding['selection_digest']??null)
                ||!hash_equals($binding['selection_digest'],self::selectionDigest($intent))) return self::DENIED;

            ($this->attest)();
            $result=($this->review)($request,$id);
            ($this->attest)();
            if (($result['status']??null)!=='review'||!is_array($row=$result['review']??null)
                ||($row['quote_id']??null)!==$id) return self::DENIED;
            $serverQuote=Intent::quote($intent);
            if (!is_array($quote=$row['quote']??null)
                ||self::canonical($quote)!==self::canonical($serverQuote)) return self::DENIED;
            foreach (['offer_digest','terms_digest','contract_digest'] as $field) {
                if (!self::digest($row[$field]??null)) return self::DENIED;
            }
            if (!is_string($row['contract_ref']??null)||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$row['contract_ref'])
                ||!is_int($row['contract_revision']??null)||$row['contract_revision']<1) return self::DENIED;
            // No SQL JSONB tampering/recomputation: digests originate in review_order.
            // Omit contract prose and arbitrary terms here: this is not an acceptance UI.
            return ['status'=>'review','quote_id'=>$id,'quote'=>$serverQuote,
                'offer_digest'=>$row['offer_digest'],'terms_digest'=>$row['terms_digest'],
                'contract_ref'=>$row['contract_ref'],'contract_revision'=>$row['contract_revision'],
                'contract_digest'=>$row['contract_digest'],'payment_link'=>null,'settled'=>false,'laboratory_only'=>true];
        } catch (Throwable) { return self::DENIED; }
    }

    private static function digest(mixed $value):bool
    {
        return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;
    }

    private static function canonical(array $value):array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$part) if (is_array($part)) $part=self::canonical($part);
        return $value;
    }
}
