<?php
declare(strict_types=1);

namespace Imagine\Portal\GscHttpKernelLab;

use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenCandidate;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthTokenPostRequest;
use App\Domain\SeoReview\OfflinePortalGscLoopbackTokenTransportReview;
use App\Domain\SeoReview\OfflinePortalGscOAuthStateStartReview;
use App\Domain\SeoReview\OfflinePortalGscSiteListEvidenceReview;
use Closure;
use CurlHandle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Manually loaded by verify-http-kernel.php; never registered by the app. */
final class LabFlow
{
    public int $tokenAttempts=0;
    public int $propertyAttempts=0;

    public function __construct(
        private readonly PDO $db,
        private readonly OfflinePortalGscOAuthStateStartReview $issuer,
        private readonly GoogleSearchConsoleEncryptedTokenVault $vault,
        private readonly string $organization,
        private readonly string $site='sc-domain:example.com',
        private readonly ?Closure $afterProviderResponse=null,
        private readonly ?Closure $whileWriteLocked=null,
    ) {}

    public static function enabled(): bool
    {
        return PHP_SAPI==='cli' && getenv('APP_ENV')==='testing' &&
            getenv('PORTAL_GSC_HTTP_KERNEL_LAB')==='1' &&
            getenv('PORTAL_GSC_F114_OAUTH_LAB')==='1' &&
            getenv('PORTAL_GSC_F115_CALLBACK_LAB')==='1' &&
            getenv('PORTAL_GSC_F118_PROVENANCE_LAB')==='1' &&
            getenv('PORTAL_GSC_F116_LOOPBACK_LAB')==='1' &&
            getenv('PORTAL_GSC_F117_PROPERTY_LAB')==='1' &&
            getenv('PORTAL_GSC_TOKEN_VAULT_LAB')==='1' &&
            getenv('PGHOST')==='127.0.0.1' &&
            getenv('PGDATABASE')==='portal_gsc_f115_lab';
    }

    public function begin(#[SensitiveParameter] string $reference): ?array
    {
        if (!self::enabled())return null;
        return $this->issuer->begin($reference,$this->organization,time());
    }

    public function callback(
        #[SensitiveParameter] string $reference,
        #[SensitiveParameter] array $callback,
    ): bool {
        if (!self::enabled())return false;
        $stored=false;
        $decision=$this->issuer->reviewBoundCallback(
            $reference,$this->organization,$callback,time(),null,
            function(GoogleSearchConsoleOAuthTokenPostRequest $envelope,int $now)
                use($reference,$callback,&$stored):?array {
                $this->tokenAttempts++;
                return (new OfflinePortalGscLoopbackTokenTransportReview())->inspect(
                    $envelope,$now,
                    function(GoogleSearchConsoleOAuthTokenCandidate $candidate)
                        use($reference,$callback,&$stored):bool {
                        // This is the SAME object parsed from the F118 envelope's
                        // HTTP response, not another fixture or reconstructed token.
                        $evidence=$this->propertyEvidence($candidate);
                        if ($evidence===null)return false;
                        if ($this->afterProviderResponse!==null &&
                            ($this->afterProviderResponse)()!==true)return false;
                        $stored=$this->persist($reference,
                            hash('sha256',$callback['state']),$candidate,$evidence);
                        return $stored;
                    }
                );
            }
        );
        return $stored && is_array($decision) &&
            ($decision['syntheticF118TransportReviewed']??null)===true;
    }

    private function propertyEvidence(GoogleSearchConsoleOAuthTokenCandidate $candidate): ?array
    {
        $data=$candidate->takeForTrustedVault();
        if (($data['expiresAt']??0)<=time() ||
            !is_string($data['accessToken']??null) ||
            !str_starts_with($data['accessToken'],'synthetic-'))return null;
        $handle=null;$body='';$mime=null;$size=0;
        try {
            // No URL, bearer, site response or provider identity from HTTP input.
            $endpoint='http://127.0.0.1:18765/sites';
            $handle=curl_init($endpoint);
            if (!$handle instanceof CurlHandle)return null;
            $options=[
                CURLOPT_HTTPGET=>true,
                CURLOPT_HTTPHEADER=>['Accept: application/json',
                    'Authorization: Bearer '.$data['accessToken'],'Connection: close'],
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>2,
                CURLOPT_TIMEOUT=>3,CURLOPT_NOSIGNAL=>true,
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'127.0.0.1',
                CURLOPT_HEADERFUNCTION=>static function(CurlHandle $h,string $line)use(&$mime):int {
                    if (stripos($line,'Content-Type:')===0)$mime=strtolower(trim(substr($line,13)));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function(CurlHandle $h,string $chunk)use(&$body,&$size):int {
                    $size+=strlen($chunk);
                    if ($size>32768)return 0;
                    $body.=$chunk;return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR'))$options[CURLOPT_PROTOCOLS_STR]='http';
            else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP;
            if (!curl_setopt_array($handle,$options))return null;
            $this->propertyAttempts++;
            if (curl_exec($handle)!==true || $size>32768 ||
                curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!==$endpoint ||
                $mime!=='application/json')return null;
            return (new OfflinePortalGscSiteListEvidenceReview())->inspect(
                curl_getinfo($handle,CURLINFO_RESPONSE_CODE),$body,$this->site,time()
            );
        } catch(Throwable) {return null;}
        finally {
            if ($handle instanceof CurlHandle)curl_close($handle);
            unset($data);$body='';
        }
    }

    private function persist(
        #[SensitiveParameter] string $reference,string $stateHash,
        GoogleSearchConsoleOAuthTokenCandidate $candidate,array $evidence,
    ): bool {
        try {
            $this->ready();
            $this->db->beginTransaction();
            $this->setPrincipalContext($reference,$stateHash);
            $q=$this->db->prepare('SELECT * FROM public.portal_gsc_http_kernel_write_principal(
                :digest,CAST(:org AS uuid),:state)');
            $q->execute(['digest'=>hash('sha256',$reference),
                'org'=>$this->organization,'state'=>$stateHash]);
            $context=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($context) || $q->fetch(PDO::FETCH_ASSOC)!==false)
                throw new RuntimeException('Lab principal unavailable');
            // The SQL function has reacquired the original session, identity,
            // org, membership and connect grant locks after both HTTP calls.
            $now=time();$safe=$candidate->safeReview();
            if (($evidence['exactSiteUrlMatched']??null)!==true ||
                ($evidence['providerOriginVerified']??null)!==false ||
                ($safe['expiresAt']??0)<=$now)
                throw new RuntimeException('Lab candidate unavailable');
            $property=$evidence['siteIdentifierFingerprint'];
            if (!$this->vault->storeSyntheticCandidate($context['tenant_ref'],
                $context['actor_ref'],$property,$candidate,$now))
                throw new RuntimeException('Lab vault unavailable');
            // This row is ONLY synthetic evidence in the guarded disposable DB.
            // F117 remains non-authorizing; no Portal membership/view grant is
            // created, no customer data is enabled, no real Google is attested.
            $grant=$this->db->prepare('INSERT INTO portal_gsc_property_grants
                (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
                 verified_at,expires_at) VALUES (?,?,?,?,\'active\',?,?)');
            $grant->execute([$context['tenant_ref'],$context['actor_ref'],$property,
                GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,$now,$safe['expiresAt']]);
            if ($this->whileWriteLocked!==null &&
                ($this->whileWriteLocked)()!==true)
                throw new RuntimeException('Lab lock proof failed');
            return $this->db->commit();
        } catch(Throwable) {
            if ($this->db->inTransaction())$this->db->rollBack();
            return false;
        }
    }

    public function revoke(#[SensitiveParameter] string $reference): bool
    {
        if (!self::enabled())return false;
        try {
            $this->ready();$this->db->beginTransaction();
            $this->setPrincipalContext($reference,'');
            $q=$this->db->prepare('SELECT * FROM public.portal_gsc_f114_connect_principal(
                :digest,CAST(:org AS uuid))');
            $q->execute(['digest'=>hash('sha256',$reference),'org'=>$this->organization]);
            $p=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($p) || $q->fetch(PDO::FETCH_ASSOC)!==false)
                throw new RuntimeException('Lab principal unavailable');
            $tenant='porg-'.str_replace('-','',$p['organization_uuid']);
            $actor='pusr-'.str_replace('-','',$p['identity_uuid']);
            $this->db->prepare("SELECT set_config('app.tenant_ref',?,true),
                set_config('app.actor_ref',?,true)")->execute([$tenant,$actor]);
            $property=hash('sha256',$this->site);$now=time();
            if (!$this->vault->revokeSyntheticCandidate($tenant,$actor,$property,$now))
                throw new RuntimeException('Lab candidate unavailable');
            $q=$this->db->prepare("UPDATE portal_gsc_property_grants
                SET grant_status='revoked',revoked_at=? WHERE tenant_ref=?
                AND actor_ref=? AND property_fingerprint=? AND revoked_at IS NULL");
            $q->execute([$now,$tenant,$actor,$property]);
            if ($q->rowCount()!==1)throw new RuntimeException('Lab grant unavailable');
            return $this->db->commit();
        } catch(Throwable) {
            if ($this->db->inTransaction())$this->db->rollBack();
            return false;
        }
    }

    private function setPrincipalContext(#[SensitiveParameter] string $reference,string $stateHash): void
    {
        $this->db->prepare("SELECT set_config('app.gsc_lab_session_digest',?,true),
            set_config('app.gsc_lab_organization',?,true),
            set_config('app.gsc_lab_state_hash',?,true)")->execute([
                hash('sha256',$reference),$this->organization,$stateHash]);
    }

    private function ready(): void
    {
        if (!self::enabled() || $this->db->inTransaction() ||
            $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
            throw new RuntimeException('Disposable laboratory required');
        $row=$this->db->query('SELECT current_database() AS db,
            current_user AS actor,session_user AS login,rolsuper,rolbypassrls,
            rolcreatedb,rolcreaterole,rolinherit,rolreplication,
            EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
            FROM pg_roles r WHERE rolname=current_user')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['db']!=='portal_gsc_f115_lab' ||
            $row['actor']!=='portal_gsc_f114_runtime' || $row['login']!==$row['actor'])
            throw new RuntimeException('Restricted laboratory role required');
        foreach(['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole',
            'rolinherit','rolreplication','inherited'] as $key)
            if (!in_array($row[$key],[false,0,'0','f'],true))
                throw new RuntimeException('Privileged laboratory role refused');
    }
}

/** HTTP input has no tenant/actor/session/permission/provider evidence fields. */
final readonly class LabController
{
    public function connect(Request $request,LabFlow $flow): JsonResponse
    {
        $reference=$this->reference($request);
        if ($reference===null || $request->query->all()!==[] || $request->json()->all()!==[])
            return $this->denied();
        $result=$flow->begin($reference);
        if ($result===null)return $this->denied();
        // The harness never follows this synthetic authorization URL.
        return response()->json(['labAuthorization'=>$result['publicAuthorization'],
            'productionAuthorized'=>false,'customerDisplayAuthorized'=>false]);
    }

    public function callback(Request $request,LabFlow $flow): JsonResponse
    {
        $reference=$this->reference($request);
        $query=$request->query->all();$keys=array_keys($query);sort($keys);
        if ($reference===null || $keys!==['code','state'] || $request->getContent()!=='' ||
            !$flow->callback($reference,$query))return $this->denied();
        return response()->json(['labCandidateAndGrantRecorded'=>true,
            'providerOriginVerified'=>false,'googleOAuthVerified'=>false,
            'tokenPersistenceAuthorized'=>false,'customerDisplayAuthorized'=>false,
            'productionAuthorized'=>false]);
    }

    public function revoke(Request $request,LabFlow $flow): JsonResponse
    {
        $reference=$this->reference($request);
        if ($reference===null || $request->query->all()!==[] || $request->json()->all()!==[] ||
            !$flow->revoke($reference))return $this->denied();
        return response()->json(['labCandidateAndGrantRevoked'=>true,
            'googleRevocationPerformed'=>false,'productionAuthorized'=>false]);
    }

    private function reference(Request $request): ?string
    {
        if (!LabFlow::enabled() || !$request->hasSession())return null;
        $state=$request->session()->get('portal.browser_auth');
        return is_array($state) && ($state['phase']??null)==='business' &&
            is_string($state['reference']??null) &&
            preg_match('/\A[0-9a-f]{64}\z/D',$state['reference'])===1
            ? $state['reference'] : null;
    }

    private function denied(): JsonResponse
    {
        return response()->json(['error'=>'laboratory_operation_denied'],403);
    }
}
