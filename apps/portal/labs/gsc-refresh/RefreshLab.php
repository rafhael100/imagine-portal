<?php
declare(strict_types=1);

namespace Imagine\Portal\GscRefreshLab;

use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use App\Domain\SeoReview\GoogleSearchConsoleRefreshTokenPostRequest;
use App\Infrastructure\SeoReview\GoogleSearchConsoleHttpsTransportCandidate;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Imagine\Portal\GscHttpKernelLab\LabFlow;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Manually loaded by the disposable Kernel harness. No app binding or route. */
final class RefreshFlow
{
    public int $tokenAttempts=0;
    public int $propertyAttempts=0;

    public function __construct(
        private readonly PDO $db,
        private readonly GoogleSearchConsoleEncryptedTokenVault $vault,
        private readonly GoogleSearchConsoleHttpsTransportCandidate $https,
        private readonly string $organization,
        private readonly string $site='sc-domain:example.com',
        private readonly ?Closure $afterClaim=null,
        private readonly ?Closure $afterProviderResponse=null,
        private readonly ?Closure $whileWriteLocked=null,
        private readonly ?Closure $afterComplete=null,
    ) {}

    public static function enabled(): bool
    {
        return LabFlow::enabled() && getenv('PORTAL_GSC_REFRESH_LAB')==='1' &&
            getenv('PORTAL_GSC_HTTP_KERNEL_REFRESH_LAB')==='1' &&
            getenv('PORTAL_GSC_HTTP_KERNEL_HTTPS_LAB')==='1' &&
            getenv('GITHUB_ACTIONS')==='true' && getenv('RUNNER_ENVIRONMENT')==='github-hosted';
    }

    public function renew(#[SensitiveParameter] string $reference): bool
    {
        if (!self::enabled())return false;
        try {
            $this->ready();
            $site=GoogleSearchConsolePropertyIdentifier::exactSiteUrl($this->site);
            if ($site===null)return false;
            $property=hash('sha256',$site);
            $this->db->beginTransaction();$this->context($reference);
            $q=$this->db->prepare('SELECT * FROM public.portal_gsc_refresh_claim(?,CAST(? AS uuid),?)');
            $q->execute([hash('sha256',$reference),$this->organization,$property]);
            $claim=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($claim) || $q->fetch(PDO::FETCH_ASSOC)!==false)
                throw new RuntimeException('Renewal claim unavailable');
            $secret=$this->vault->inspectSyntheticCandidate($claim['tenant_ref'],
                $claim['actor_ref'],$property,time());
            if ($secret===null)throw new RuntimeException('Current synthetic credential unavailable');
            $envelope=GoogleSearchConsoleRefreshTokenPostRequest::prepare($secret,[
                'clientId'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
                'clientSecret'=>'synthetic-secret-f116-private-only',
            ],time());
            unset($secret);
            if ($envelope===null)throw new RuntimeException('Renewal envelope unavailable');
            // The claim becomes durable BEFORE either network request. A
            // second connection now sees the version already claimed.
            if (!$this->db->commit())throw new RuntimeException('Renewal claim not committed');
            if ($this->afterClaim!==null && ($this->afterClaim)()!==true)return false;
            $this->tokenAttempts++;
            $candidate=$this->https->refresh($envelope,time());
            if ($candidate===null)return false;
            $this->propertyAttempts++;
            $evidence=$this->https->inspectSites($candidate,$site,time());
            if (!is_array($evidence) || ($evidence['exactSiteUrlMatched']??null)!==true ||
                ($evidence['tlsLabVerified']??null)!==true ||
                ($evidence['providerOriginVerified']??null)!==false ||
                ($evidence['siteIdentifierFingerprint']??null)!==$property)return false;
            if ($this->afterProviderResponse!==null && ($this->afterProviderResponse)()!==true)return false;

            $this->ready();$this->db->beginTransaction();$this->context($reference);
            $q=$this->db->prepare('SELECT * FROM public.portal_gsc_refresh_begin_replace(CAST(? AS uuid),?,CAST(? AS uuid))');
            $q->execute([$claim['claim_id'],hash('sha256',$reference),$this->organization]);
            $current=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || $q->fetch(PDO::FETCH_ASSOC)!==false ||
                $current['tenant_ref']!==$claim['tenant_ref'] ||
                $current['actor_ref']!==$claim['actor_ref'] ||
                $current['property_fingerprint']!==$property)
                throw new RuntimeException('Renewal generation or authority changed');
            $now=time();$safe=$candidate->safeReview();
            if (($safe['expiresAt']??0)<=$now ||
                !$this->vault->storeSyntheticCandidate($current['tenant_ref'],
                    $current['actor_ref'],$property,$candidate,$now))
                throw new RuntimeException('Renewal successor unavailable');
            $q=$this->db->prepare("INSERT INTO public.portal_gsc_property_grants
                (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,verified_at,expires_at)
                VALUES (?,?,?,?,'active',?,?)");
            $q->execute([$current['tenant_ref'],$current['actor_ref'],$property,
                GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,$now,$safe['expiresAt']]);
            if ($this->whileWriteLocked!==null && ($this->whileWriteLocked)()!==true)
                throw new RuntimeException('Renewal write proof failed');
            $q=$this->db->prepare('SELECT public.portal_gsc_refresh_complete(CAST(? AS uuid),?,CAST(? AS uuid))');
            $q->execute([$claim['claim_id'],hash('sha256',$reference),$this->organization]);
            if (!in_array($q->fetchColumn(),[true,1,'1','t'],true))
                throw new RuntimeException('Renewal pair not completed');
            if ($this->afterComplete!==null && ($this->afterComplete)()!==true)
                throw new RuntimeException('Renewal final-state proof failed');
            // The database constraint trigger independently checks the FINAL
            // pair at commit. A caller cannot commit the intermediate delete.
            return $this->db->commit();
        } catch(Throwable) {
            if ($this->db->inTransaction())$this->db->rollBack();
            return false;
        }
    }

    private function context(#[SensitiveParameter] string $reference): void
    {
        $this->db->prepare("SELECT set_config('app.gsc_lab_session_digest',?,true),
            set_config('app.gsc_lab_organization',?,true),set_config('app.gsc_lab_state_hash','',true),
            set_config('app.gsc_lab_refresh_claim','',true)")
            ->execute([hash('sha256',$reference),$this->organization]);
    }

    private function ready(): void
    {
        if (!self::enabled() || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
            throw new RuntimeException('Disposable renewal laboratory required');
        $r=$this->db->query('SELECT current_database() AS db,current_user AS actor,
            session_user AS login,rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,
            rolinherit,rolreplication,EXISTS(SELECT 1 FROM pg_auth_members
            WHERE member=r.oid) AS inherited FROM pg_roles r WHERE rolname=current_user')
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r) || $r['db']!=='portal_gsc_f115_lab' ||
            $r['actor']!=='portal_gsc_f114_runtime' || $r['login']!==$r['actor'])
            throw new RuntimeException('Restricted renewal runtime required');
        foreach(['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','rolreplication','inherited'] as $key)
            if (!in_array($r[$key],[false,0,'0','f'],true))
                throw new RuntimeException('Privileged renewal runtime refused');
    }
}

final readonly class RefreshController
{
    public function renew(Request $request,RefreshFlow $flow): JsonResponse
    {
        $auth=$request->session()->get('portal.browser_auth');
        $reference=is_array($auth) && ($auth['phase']??null)==='business'
            ? ($auth['reference']??null):null;
        if (!is_string($reference) || preg_match('/\A[a-f0-9]{64}\z/D',$reference)!==1 ||
            $request->query->all()!==[] || $request->json()->all()!==[] || !$flow->renew($reference))
            return response()->json(['status'=>'unavailable'],403);
        return response()->json(['labRenewed'=>true,'tlsLabVerified'=>true,
            'providerOriginVerified'=>false,'productionAuthorized'=>false,'customerDisplayAuthorized'=>false]);
    }
}
