<?php
declare(strict_types=1);
namespace App\Labs\OnepageConfirmation;

use App\Labs\OnepageBuyer\Guard;
use Closure;
use Illuminate\Http\Request;
use PDO;
use Throwable;

/** No route or provider registration. Native session reference remains the only authority. */
final readonly class NextStep
{
    public function __construct(private PDO $runtime, private PDO $bootstrap,
        private array $cluster, private Closure $barrier) {}

    public function read(Request $request, string $order): array
    {
        $closed=['next_step'=>null];
        if (getenv('APP_ENV')!=='testing' || getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'
            || !$request->hasSession() || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$order)) return $closed;
        $state=$request->session()->get('portal.browser_auth');
        if (!is_array($state) || ($state['phase']??null)!=='business'
            || !is_string($state['reference']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$state['reference'])) return $closed;
        try {
            if ($this->runtime->inTransaction()) return $closed;
            ($this->barrier)();
            Guard::runtime($this->runtime,$this->bootstrap,$this->cluster,'onepage_buyer_runtime');
            if ($this->runtime->query('SHOW transaction_isolation')->fetchColumn()!=='read committed') return $closed;
            $q=$this->runtime->prepare('SELECT onepage_buyer_lab.synthetic_next_step(?,?::uuid)');
            $q->execute([hash('sha256',$state['reference']),$order]);
            $result=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            ($this->barrier)();
            return $result===['next_step'=>'briefing'] ? $result : $closed;
        } catch (Throwable) { return $closed; }
    }
}
