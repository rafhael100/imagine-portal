<?php
declare(strict_types=1);
namespace App\Labs\OnepageBuyer;

use App\Homologation\Commerce\CompositeQuotePreview;
use Illuminate\Http\Request;
use PDO;
use Throwable;

/** Unregistered laboratory adapter. Session/actor/company never accepted in input. */
final class BuyerQuoteAdapter
{
    public function __construct(private PDO $runtime) {}

    public function create(Request $request, string $requestKey, array $selection): array
    {
        $keys = array_keys($selection); sort($keys);
        if ($keys !== ['channels','site','tier']
            || $selection['site'] !== 'onepage' || $selection['channels'] !== []
            || !in_array($selection['tier'], ['minimum','recommended'], true)
            || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $requestKey)) {
            return ['status'=>'invalid_selection'];
        }
        $selection = ['site'=>'onepage','channels'=>[],'tier'=>$selection['tier']];
        $quote = (new CompositeQuotePreview())->quote($selection);
        return $this->command($request, 'create', hash('sha256', $requestKey), null, $selection, $quote);
    }

    public function resume(Request $request, string $draftId): array
    {
        if (!preg_match('/\A[0-9a-f-]{36}\z/D', $draftId)) return ['status'=>'denied'];
        return $this->command($request, 'resume', null, $draftId, null, null);
    }

    private function command(Request $request, string $operation, ?string $key, ?string $id, ?array $selection, ?array $quote): array
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('ONEPAGE_BUYER_PG_LAB') !== 'DISPOSABLE_ONLY'
            || !$request->hasSession()) return ['status'=>'denied'];
        // Reuse PortalBrowserAuthController's server-side envelope. Native SQL
        // revalidates the opaque reference, MFA, revisions and membership per call.
        $state = $request->session()->get('portal.browser_auth');
        if (!is_array($state) || ($state['phase'] ?? null) !== 'business'
            || !is_string($state['reference'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $state['reference'])) return ['status'=>'denied'];
        try {
            if ($this->runtime->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->runtime->inTransaction()) return ['status'=>'unavailable'];
            $profile = $this->runtime->query("SELECT current_database() AS db,current_user AS actor,session_user AS login,
                host(inet_server_addr()) AS host,inet_server_port() AS port,
                rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
                OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS unsafe
                FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
            if (!$profile || $profile['db'] !== 'portal_onepage_buyer_ci'
                || $profile['actor'] !== 'onepage_buyer_runtime' || $profile['login'] !== 'onepage_buyer_runtime'
                || $profile['host'] !== '127.0.0.1' || (int)$profile['port'] !== 55439
                || !in_array($profile['unsafe'], [false,'f',0,'0'], true)) return ['status'=>'unavailable'];
            $stmt = $this->runtime->prepare('SELECT onepage_buyer_lab.command(?,?,?,?,?::jsonb,?::jsonb)');
            $stmt->execute([hash('sha256',$state['reference']),$operation,$key,$id,
                $selection === null ? null : json_encode($selection,JSON_THROW_ON_ERROR),
                $quote === null ? null : json_encode($quote,JSON_THROW_ON_ERROR)]);
            return json_decode($stmt->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status'=>'unavailable'];
        }
    }
}
