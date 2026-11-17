<?php
declare(strict_types=1);
namespace App\Labs\OnepageAtomicRead;
use Closure;
use Illuminate\Http\Request;
use PDO;
use Throwable;
use App\Labs\OnepageBuyer\Guard;
final readonly class AtomicReader {
    /** $attest must reject drift before/after each operation. Fixtures only in offline tests. */
    public function __construct(private PDO $runtime,private Closure $attest) {}
    public static function guarded(PDO $runtime,PDO $bootstrap,array $identity,Closure $barrier):self {
        return new self($runtime,static function()use($runtime,$bootstrap,$identity,$barrier):void {
            $barrier();Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');$barrier();
        });
    }
    public function read(Request $request,string $order):array {
        $deny=['status'=>'denied','settled'=>false,'preview_admitted'=>false,'binding'=>null];
        if(getenv('APP_ENV')!=='testing'||getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'
            ||!$request->hasSession()||preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$order)!==1)return $deny;
        $s=$request->session()->get('portal.browser_auth');
        if(!is_array($s)||($s['phase']??null)!=='business'||!is_string($s['reference']??null)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$s['reference'])!==1)return $deny;
        try {
            if($this->runtime->inTransaction())return $deny;
            ($this->attest)();
            $q=$this->runtime->prepare('SELECT onepage_buyer_lab.read_pending_order(?,?::uuid)');
            $q->execute([hash('sha256',$s['reference']),$order]);
            $row=json_decode($q->fetchColumn()?:'null',true,512,JSON_THROW_ON_ERROR);
            ($this->attest)();
            if(!is_array($row)||($row['id']??null)!==$order||($row['status']??null)!=='pending_payment')return $deny;
            return ['status'=>'pending_payment','snapshot'=>$row,'laboratory_only'=>true,
                'settled'=>false,'preview_admitted'=>false,'binding'=>null];
        }catch(Throwable){return $deny;}
    }
}
