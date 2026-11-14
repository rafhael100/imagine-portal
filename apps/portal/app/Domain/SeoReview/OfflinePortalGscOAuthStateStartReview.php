<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use Closure;
use PDO;
use SensitiveParameter;
use Throwable;

/**
 * F114: review-only, first-party MFA session + explicit connect grant
 * -> PKCE OAuth state persistence under one PostgreSQL transaction.
 *
 * The GSC OAuth flow is NOT enabled in HTTP and this result is NOT a
 * Google authorization. The ledger is read only by a separate, scoped
 * callback role; actual callback authentication is a future gate.
 */
final readonly class OfflinePortalGscOAuthStateStartReview
{
    private \SensitiveParameterValue $key;

    public function __construct(
        private PDO $db,
        #[SensitiveParameter] string $stateEncryptionKey,
        private array $syntheticRegistration,
    ) {
        if (strlen($stateEncryptionKey)!==32 ||
            !extension_loaded('sodium') ||
            !GoogleSearchConsoleOAuthPreflight::validRegistration(
                $syntheticRegistration
            )) {
            throw new \InvalidArgumentException(
                'F114: offline lab configuration unavailable'
            );
        }
        $this->key=new \SensitiveParameterValue($stateEncryptionKey);
    }

    public function __debugInfo(): array
    {
        return ['component'=>'offline F114 PKCE state review',
            'keys'=>'[REDACTED]','customerAccessAuthorized'=>false];
    }

    public function __serialize(): array
    {
        throw new \LogicException('F114 state review cannot be serialized');
    }

    public function begin(
        #[SensitiveParameter] mixed $firstPartySessionReference,
        mixed $selectedOrganizationUuid,
        mixed $trustedNow,
        ?Closure $onLockedRows=null,
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_GSC_F114_OAUTH_LAB')!=='1' ||
            !is_string($firstPartySessionReference) ||
            preg_match('/\A[a-f0-9]{64}\z/D',
                $firstPartySessionReference)!==1 ||
            !is_string($selectedOrganizationUuid) ||
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $selectedOrganizationUuid
            )!==1 ||
            !is_int($trustedNow) ||
            $trustedNow<1600000000 || $trustedNow>2200000000 ||
            abs(time()-$trustedNow)>4) return null;

        try {
            if ($this->db->inTransaction() ||
                $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
                return null;
            $role=$this->db->query(
                'SELECT current_database() AS db,
                  current_user=session_user AS direct_login,
                  current_user AS actor,
                  rolsuper,rolbypassrls,rolcreaterole,rolcreatedb,
                  rolinherit,rolreplication
                FROM pg_roles WHERE rolname=current_user'
            )->fetch(PDO::FETCH_ASSOC);
            $expectedLab=getenv('PORTAL_GSC_F115_CALLBACK_LAB')==='1'
                ? 'portal_gsc_f115_lab' : 'portal_gsc_f114_lab';
            if(!is_array($role) ||
                $role['db']!==$expectedLab ||
                $role['actor']!=='portal_gsc_f114_runtime' ||
                !self::pgTrue($role['direct_login']))return null;
            foreach (['rolsuper','rolbypassrls','rolcreaterole',
                'rolcreatedb','rolinherit','rolreplication'] as $priv) {
                if (!self::pgFalse($role[$priv]??null))return null;
            }
            if (!$this->db->beginTransaction())return null;
            $digest=hash('sha256',$firstPartySessionReference);
            $read=$this->db->prepare(
                'SELECT * FROM public.portal_gsc_f114_connect_principal(
                    :digest, CAST(:org AS uuid)
                )'
            );
            if (!$read || !$read->execute([
                'digest'=>$digest,'org'=>$selectedOrganizationUuid
            ]))throw new \RuntimeException('F114: locked principal unavailable');
            $principal=$read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($principal) ||
                $read->fetch(PDO::FETCH_ASSOC)!==false ||
                ($principal['organization_uuid']??null)!==
                    $selectedOrganizationUuid ||
                !self::uuid($principal['identity_uuid']??null) ||
                !is_numeric($principal['membership_revision']??null) ||
                (int)$principal['membership_revision']<1)
                throw new \RuntimeException('F114: connect grant absent');

            // Candidate mapping is derived only from verified DB UUIDs,
            // NEVER accepted from browser, DTO or a query string.
            $context=[
                'tenantRef'=>'porg-'.str_replace('-','',
                    $principal['organization_uuid']),
                'actorRef'=>'pusr-'.str_replace('-','',
                    $principal['identity_uuid']),
            ];
            $preflight=(new GoogleSearchConsoleOAuthPreflight())->prepare(
                $this->syntheticRegistration,$context,$trustedNow
            );
            if (!is_array($preflight) ||
                $preflight['networkPerformed']!==false ||
                $preflight['customerDisplayAuthorized']!==false)
                throw new \RuntimeException('F114: PKCE preflight refused');

            $server=$preflight['serverOnly'];
            $hash=$server['stateSha256'];
            $expires=$server['expiresAt'];
            // Keep the existing GSC ledger's AEAD AAD byte-for-byte, so
            // an independently scoped ledger reader can verify it.
            $aad='gsc-pkce-state-v1|'.$hash.'|'.$context['tenantRef']
                .'|'.$context['actorRef'].'|'.$expires;
            $nonce=random_bytes(
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            );
            $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $server['codeVerifier'],$aad,$nonce,$this->key->getValue()
            );
            $sealed='v1.'.base64_encode($nonce.$cipher);

            // Revalidate session/grant and insert once while the identity
            // rows stay locked. Function receives NO tenant/actor from us.
            // F115 laboratory binds the state to the ORIGINAL issued
            // Portal session hash and security revision in the same INSERT
            // transaction. The legacy F114 contract stays unchanged when
            // F115 is not explicitly selected.
            $storeFunction=getenv('PORTAL_GSC_F115_CALLBACK_LAB')==='1'
                ? 'portal_gsc_f115_store_bound_state'
                : 'portal_gsc_f114_store_state';
            $insert=$this->db->prepare(
                'SELECT * FROM public.'.$storeFunction.'(
                    :digest, CAST(:org AS uuid), :hash, :sealed,
                    CAST(:issued AS bigint), CAST(:expires AS bigint)
                )'
            );
            if (!$insert || !$insert->execute([
                'digest'=>$digest,
                'org'=>$selectedOrganizationUuid,
                'hash'=>$hash,
                'sealed'=>$sealed,
                'issued'=>$trustedNow,
                'expires'=>$expires,
            ]))throw new \RuntimeException('F114: ledger insert unavailable');
            $stored=$insert->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored) ||
                $insert->fetch(PDO::FETCH_ASSOC)!==false ||
                ($stored['tenant_ref']??null)!==$context['tenantRef'] ||
                ($stored['actor_ref']??null)!==$context['actorRef'])
                throw new \RuntimeException('F114: state scope refused');

            // A competing logout or membership revocation must wait for
            // this transaction's session/member/grant locks.
            if ($onLockedRows!==null && $onLockedRows()!==true)
                throw new \RuntimeException('F114: concurrency fence failed');

            if (!$this->db->commit())
                throw new \RuntimeException('F114: not committed');

            return [
                'contractVersion'=>'portal-gsc-f114-session-bound-pkce-lab-v1',
                'publicAuthorization'=>$preflight['publicAuthorization'],
                'stateStoredInRestrictedLedger'=>true,
                'firstPartySessionCheckedInSameTransaction'=>true,
                'explicitConnectGrantRequired'=>true,
                'connectDoesNotImplyView'=>true,
                'backendDerivedContext'=>true,
                'stateOneTimeUseRequired'=>true,
                'oauthVerified'=>false,
                'googleHttpPerformed'=>false,
                'googleTokenStored'=>false,
                'propertyAuthorizationVerified'=>false,
                'customerDisplayAuthorized'=>false,
                'productionAuthorized'=>false,
                'releaseStatus'=>'HOLD_CALLBACK_REAUTH_GOOGLE_APPSEC',
            ];
        }catch(Throwable) {
            if ($this->db->inTransaction()) {
                try{$this->db->rollBack();}catch(Throwable){}
            }
            return null;
        }
    }

    /**
     * F115: callback review ONLY, NEVER an operational OAuth token exchange.
     * A first-party bearer MUST originate from the trusted Portal session,
     * not from the callback query. PostgreSQL verifies the ORIGINAL issuer's
     * session hash and membership revision while the state is consumed once.
     *
     * A successful return is a safe local verdict; no code, PKCE verifier,
     * bearer, Google token or customer grant leaves this boundary.
     */
    public function reviewBoundCallback(
        #[SensitiveParameter] mixed $firstPartySessionReference,
        mixed $selectedOrganizationUuid,
        #[SensitiveParameter] mixed $callback,
        mixed $trustedNow,
        ?Closure $onLockedRows=null,
        ?Closure $offlineTrustedTransport=null,
    ): ?array {
        if ($offlineTrustedTransport!==null &&
            getenv('PORTAL_GSC_F118_PROVENANCE_LAB')!=='1') return null;
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_GSC_F115_CALLBACK_LAB')!=='1' ||
            !is_string($firstPartySessionReference) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$firstPartySessionReference)!==1 ||
            !self::uuid($selectedOrganizationUuid) ||
            !is_int($trustedNow) || abs(time()-$trustedNow)>4 ||
            $trustedNow<1600000000 || $trustedNow>2200000000 ||
            !is_array($callback) || array_is_list($callback) ||
            count($callback)!==2 ||
            !is_string($callback['state']??null) ||
            preg_match('/\A[A-Za-z0-9_-]{43}\z/D',
                $callback['state'])!==1 ||
            !is_string($callback['code']??null) ||
            strlen($callback['code'])<8 ||
            strlen($callback['code'])>2048 ||
            preg_match('/\A[A-Za-z0-9._~\/:-]+\z/D',
                $callback['code'])!==1) return null;

        try {
            if ($this->db->inTransaction() ||
                $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
                return null;
            $role=$this->db->query(
                'SELECT current_database() AS db,
                 current_user=session_user AS direct_login,
                 current_user AS actor,
                 rolsuper,rolbypassrls,rolcreaterole,rolcreatedb,
                 rolinherit,rolreplication
                 FROM pg_roles WHERE rolname=current_user'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role) ||
                $role['db']!=='portal_gsc_f115_lab' ||
                $role['actor']!=='portal_gsc_f114_runtime' ||
                !self::pgTrue($role['direct_login']))return null;
            foreach(['rolsuper','rolbypassrls','rolcreaterole',
                'rolcreatedb','rolinherit','rolreplication'] as $priv) {
                if(!self::pgFalse($role[$priv]??null))return null;
            }
            if(!$this->db->beginTransaction())return null;
            $state=$callback['state'];
            $stateHash=hash('sha256',$state);
            $digest=hash('sha256',$firstPartySessionReference);
            $stmt=$this->db->prepare(
                'SELECT * FROM public.portal_gsc_f115_consume_bound_state(
                    :digest, CAST(:org AS uuid), :hash,
                    CAST(:observed AS bigint)
                )'
            );
            if(!$stmt || !$stmt->execute([
                'digest'=>$digest,'org'=>$selectedOrganizationUuid,
                'hash'=>$stateHash,'observed'=>$trustedNow,
            ]))throw new \RuntimeException('F115: bounded state unavailable');
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) ||
                $stmt->fetch(PDO::FETCH_ASSOC)!==false ||
                !is_string($row['tenant_ref']??null) ||
                !is_string($row['actor_ref']??null))
                throw new \RuntimeException('F115: invalid issuer or replay');
            $expires=filter_var($row['expires_at']??null,
                FILTER_VALIDATE_INT);
            $sealed=$row['sealed_verifier']??null;
            if($expires===false || $expires<$trustedNow ||
                !is_string($sealed) || !str_starts_with($sealed,'v1.'))
                throw new \RuntimeException('F115: expired state');
            $bytes=base64_decode(substr($sealed,3),true);
            if(!is_string($bytes) ||
                strlen($bytes)<SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES+16)
                throw new \RuntimeException('F115: encrypted state invalid');
            $nonce=substr($bytes,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher=substr($bytes,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $context=['tenantRef'=>$row['tenant_ref'],
                'actorRef'=>$row['actor_ref']];
            $aad='gsc-pkce-state-v1|'.$stateHash.'|'.$context['tenantRef']
                .'|'.$context['actorRef'].'|'.$expires;
            $verifier=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $cipher,$aad,$nonce,$this->key->getValue()
            );
            if(!is_string($verifier) ||
                preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$verifier)!==1)
                throw new \RuntimeException('F115: cipher untrusted');
            $stored=[
                'tenantRef'=>$context['tenantRef'],
                'actorRef'=>$context['actorRef'],
                'stateSha256'=>$stateHash,
                'codeVerifier'=>$verifier,
                'issuedAt'=>$expires-600,
                'expiresAt'=>$expires,
                'consumed'=>false,
            ];
            // Reuse the existing strict callback syntax + stored PKCE
            // matching. No trust is placed in the incoming code.
            $decision=(new GoogleSearchConsoleOAuthCallbackPolicy(
                new GoogleSearchConsoleOAuthPreflight()
            ))->inspect($callback,$stored,$context,$trustedNow);
            if ($decision===null ||
                ($decision['decision']??null)!==
                    'CODE_RECEIVED_REQUIRES_ATOMIC_STATE_CONSUMPTION' ||
                ($decision['canExchangeTokens']??null)!==false) {
                // The state was already atomically consumed: COMMIT keeps
                // malformed callbacks from being replayed later.
                $this->db->commit();
                return null;
            }
            if($onLockedRows!==null && $onLockedRows()!==true)
                throw new \RuntimeException('F115: concurrent revocation not fenced');
            if(!$this->db->commit())
                throw new \RuntimeException('F115: callback not committed');

            // F118: NO PRODUCTION TOKEN EXCHANGE. Test-only provenance
            // proof from the exact F115 state CAS to the F116 loopback
            // transport. Caller may not supply code or verifier here:
            // both values were validated by the internal F115 policy.
            // The callback is invoked ONCE, AFTER commit; an error
            // never reopens the consumed state.
            $localTransportReviewed=null;
            if ($offlineTrustedTransport!==null){
                if(getenv('PORTAL_GSC_F118_PROVENANCE_LAB')!=='1' ||
                    !class_exists(GoogleSearchConsoleOAuthTokenPostRequest::class))
                    return null;
                $form=[
                    'client_id'=>'1234567890-f116syntheticonly.apps.googleusercontent.com',
                    'client_secret'=>'synthetic-secret-f116-private-only',
                    'code'=>$callback['code'],
                    'code_verifier'=>$verifier,
                    'redirect_uri'=>
                      'https://portal.example.test/integrations/google/search-console/callback',
                    'grant_type'=>'authorization_code',
                ];
                $envelope=new GoogleSearchConsoleOAuthTokenPostRequest($form);
                $localResponse=$offlineTrustedTransport($envelope,$trustedNow);
                $localTransportReviewed=is_array($localResponse) &&
                    ($localResponse['contractVersion']??null)===
                       'portal-gsc-f116-local-token-transport-lab-v1' &&
                    ($localResponse['loopbackOnly']??null)===true &&
                    ($localResponse['tokenResponseStructureVerified']??null)===true &&
                    ($localResponse['networkToGooglePerformed']??null)===false &&
                    ($localResponse['customerDisplayAuthorized']??null)===false;
                if(!$localTransportReviewed)return null;
            }

            return [
                'contractVersion'=>'portal-gsc-f115-session-bound-callback-lab-v1',
                'decision'=>'STATE_CONSUMED_AFTER_ORIGINAL_MFA_RECHECK',
                'originalSessionBindingVerified'=>true,
                'originalMembershipRevisionVerified'=>true,
                'explicitConnectGrantVerifiedAtConsumption'=>true,
                'stateConsumedOnce'=>true,
                'callbackCodeHandledOnlyInServerMemory'=>true,
                'syntheticF118TransportReviewed'=>$localTransportReviewed,
                'googleOAuthVerified'=>false,
                'tokenExchangeAuthorized'=>false,
                'googleHttpPerformed'=>false,
                'tokenPersistenceAuthorized'=>false,
                'customerDisplayAuthorized'=>false,
                'productionAuthorized'=>false,
                'releaseStatus'=>'HOLD_GOOGLE_OAUTH_TOKEN_BACKEND_AND_APPSEC',
            ];
        }catch(Throwable) {
            if($this->db->inTransaction()) {
                try{$this->db->rollBack();}catch(Throwable){}
            }
            return null;
        }
    }

    private static function uuid(mixed $v):bool
    {
        return is_string($v) &&
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $v
            )===1;
    }
    private static function pgTrue(mixed $v):bool
    {
        return in_array($v,[true,1,'1','t'],true);
    }
    private static function pgFalse(mixed $v):bool
    {
        return in_array($v,[false,0,'0','f'],true);
    }

    public static function canAuthorizeCustomer():bool{return false;}
    public static function canCallGoogle():bool{return false;}
    public static function canPersistOAuthTokens():bool{return false;}
}
