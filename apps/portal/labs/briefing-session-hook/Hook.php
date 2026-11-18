<?php
declare(strict_types=1);
namespace PrivateBriefingHook;
use App\Domain\Commerce\OnepageOrderReader;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use NativeBriefingFixture\Fixture;
use PrivateSessionObserver\Observer;
use PrivateSessionPipe\Channel;
use PDO;
use RuntimeException;
use Throwable;
/** Test-only, single request. Caller supplies pre-attested restricted PDO and bootstrap anchor. */
final class Hook
{
    private bool $spent=false;
    private bool $consulted=false;
    public function projectionWasConsulted():bool{return $this->consulted;}
    private ?array $projection=null;
    public function __construct(#[\SensitiveParameter] private PDO $db,
        #[\SensitiveParameter] private OnepageOrderReader $reader,
        #[\SensitiveParameter] private string $anchorOrder) {}
    public function run(#[\SensitiveParameter] Kernel $kernel,#[\SensitiveParameter] Request $request,
        #[\SensitiveParameter] Channel $channel):array
    {
        if($this->spent)throw new RuntimeException('HOOK_REFUSED');
        $this->spent=true;
        // Retain the actual handled response even if the observer refuses; terminate must still run.
        $proxy=new class($kernel) implements Kernel {
            public $response=null;
            public function __construct(private Kernel $inner){}
            public function bootstrap(){return $this->inner->bootstrap();}
            public function getApplication(){return $this->inner->getApplication();}
            public function handle($request){return $this->response=$this->inner->handle($request);}
            public function terminate($request,$response){$this->inner->terminate($request,$response);}
        };
        $observer=new Observer($this->reader,function($order,$wire)use($request):void{
            if($order->id!==$this->anchorOrder)throw new RuntimeException('HOOK_REFUSED');
            $state=$request->session()->get('portal.browser_auth');
            if(!is_array($state)||($state['phase']??null)!=='business'||!is_string($state['reference']??null)
                ||preg_match('/\A[a-f0-9]{64}\z/D',$state['reference'])!==1)throw new RuntimeException('HOOK_REFUSED');
            $this->consulted=true;
            $this->projection=Fixture::next($this->db,$state['reference'],$order->id);
        });
        try {
            try {$response=\PrivateSessionPipe\handleNative($proxy,$request,$observer,$channel);}
            finally {if($proxy->response!==null)$proxy->terminate($request,$proxy->response);}
            return ['response'=>$response,'projection'=>$this->projection];
        } catch(Throwable) {
            $this->projection=null;
            throw new RuntimeException('HOOK_REFUSED');
        }
    }
    public function __debugInfo():array{return ['scope'=>'test-only projection hook'];}
    public function __serialize():array{throw new RuntimeException('HOOK_REFUSED');}
}
