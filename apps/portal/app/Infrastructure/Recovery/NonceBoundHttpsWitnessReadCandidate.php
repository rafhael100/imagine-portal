<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use Closure;
use RuntimeException;
use Throwable;

/**
 * F380: non-operational online witness challenge / signature experiment.
 *
 * Each diagnostic read makes TWO independent HTTPS connections, with two
 * different 256-bit nonces, and reads a live server-side epoch both times.
 * No route/container binding, state changes or HTTP business authorization.
 */
final readonly class NonceBoundHttpsWitnessReadCandidate
{
    private const PATH='/v2/portal/recovery-witness';
    private const MATCH='WITNESS_MATCHED_STILL_NO_SERVING';

    public function __construct(
        private PortalLiveRecoveryEpochSourceCandidate $local,
        private ChallengeBoundWitnessCandidateVerifier $verifier,
        private string $endpoint,
        private string $tlsSpkiPin,
        private string $signingPublicKeyHex,
        private string $organization,
        private string $instance,
        private ?string $ciCaFile=null,
        private ?string $ciDnsResolve=null,
    ) {
        $url=parse_url($endpoint);
        if(!is_array($url)
            ||($url['scheme']??null)!=='https'
            ||!is_string($url['host']??null)
            ||!self::hostname($url['host'])
            ||($url['path']??null)!==self::PATH
            ||isset($url['user'])||isset($url['pass'])
            ||isset($url['query'])||isset($url['fragment'])
            ||preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{2,5})?'
                 .preg_quote(self::PATH,'~').'\z~D',$endpoint)!==1
            ||!self::scope($organization)||!self::scope($instance)
            ||preg_match('/\A[a-f0-9]{64}\z/D',$signingPublicKeyHex)!==1
            ||!self::pin($tlsSpkiPin)) {
            throw new RuntimeException('Independent challenged witness configuration invalid.');
        }

        $ci=self::disposableCi();
        if(isset($url['port'])&&(!$ci
            ||$url['host']!=='witness.f380.test.invalid'
            ||$url['port']!==18446)) {
            throw new RuntimeException('Independent challenged witness port denied.');
        }
        if(!$ci&&($ciCaFile!==null||$ciDnsResolve!==null)) {
            throw new RuntimeException('Independent challenged witness override denied.');
        }
        if($ci&&(($ciCaFile===null)!==($ciDnsResolve===null))) {
            throw new RuntimeException('Independent challenged witness override denied.');
        }
        if($ciCaFile!==null
            &&($url['host']!=='witness.f380.test.invalid'
                ||$ciDnsResolve!=='witness.f380.test.invalid:18446:127.0.0.1'
                ||!is_file($ciCaFile)||is_link($ciCaFile))) {
            throw new RuntimeException('Independent challenged witness override denied.');
        }
        if(!$ci&&preg_match('/\.(?:test|invalid|localhost|local|internal|example)\z/D',
            $url['host'])===1) {
            throw new RuntimeException('Independent challenged witness endpoint denied.');
        }
    }

    /**
     * A positive SQL read is still NO SERVING. Caller must never treat a
     * returned status as a session/HTTP/resource authorization decision.
     * The callback is strictly read-only, enforced only by later review.
     */
    public function inspectReadOnly(Closure $probe):array
    {
        $first=$this->observe();
        if($first===null)return self::result('WITNESS_PRECHECK_DENIED');
        try {
            $value=$probe();
            if(!is_bool($value))return self::result('CANDIDATE_READ_INVALID');
        }catch(Throwable){
            return self::result('CANDIDATE_READ_UNAVAILABLE');
        }
        $second=$this->observe();
        if($second===null||$first!==$second){
            return self::result('WITNESS_POSTCHECK_DENIED');
        }
        return self::result($value
            ? 'LAB_CHALLENGED_READ_STILL_NO_SERVING'
            : 'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');
    }

    private function observe():?array
    {
        try {
            // No local cached state or caller-provided snapshot is accepted.
            $snapshot=$this->local->currentEpoch();
            if(($snapshot['organization']??null)!==$this->organization
                ||($snapshot['instance']??null)!==$this->instance)return null;

            // Every fetch MUST create its own cryptographic challenge.
            $nonce=bin2hex(random_bytes(32));
            $receipt=$this->requestSigned($nonce);
            if(($receipt['organization']??null)!==$this->organization
                ||($receipt['instance']??null)!==$this->instance)return null;
            $result=$this->verifier->verify(
                $snapshot,$receipt,$nonce,$this->signingPublicKeyHex,time()
            );
            if(($result['reasonCode']??null)!==self::MATCH
                ||($result['canAuthorizeHttp']??null)!==false
                ||($result['canAuthorizeBusiness']??null)!==false
                ||($result['canRecoverFromRestore']??null)!==false)return null;

            // Nonces and signatures MUST differ across fetches. Compare only
            // the fully verified source vector and signed witness floors.
            return [
                'snapshot'=>$snapshot,
                'generation_floor'=>$receipt['generation_floor'],
                'session_floor'=>$receipt['session_floor'],
                'membership_floor'=>$receipt['membership_floor'],
                'sequence'=>$receipt['sequence'],
            ];
        }catch(Throwable){
            // Do not reveal signing keys, tenancy, SQL/HTTP providers or URLs.
            return null;
        }
    }

    private function requestSigned(string $nonce):array
    {
        if(!extension_loaded('curl'))throw new RuntimeException();
        $handle=curl_init();
        if($handle===false)throw new RuntimeException();
        $body='';
        $headerBytes=0;
        try {
            $options=[
                CURLOPT_URL=>$this->endpoint,
                CURLOPT_HTTPGET=>true,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_CONNECTTIMEOUT_MS=>650,
                CURLOPT_TIMEOUT_MS=>1500,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PINNEDPUBLICKEY=>$this->tlsSpkiPin,
                CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY=>'',
                CURLOPT_NOPROXY=>'*',
                CURLOPT_FRESH_CONNECT=>true,
                CURLOPT_FORBID_REUSE=>true,
                CURLOPT_DNS_CACHE_TIMEOUT=>0,
                CURLOPT_HTTPHEADER=>[
                    'Accept: application/json',
                    'Cache-Control: no-store',
                    'Pragma: no-cache',
                    'X-Imagine-Witness-Nonce: '.$nonce,
                ],
                CURLOPT_HEADERFUNCTION=>static function($ch,string $line)
                    use(&$headerBytes):int{
                    $headerBytes+=strlen($line);
                    return $headerBytes<=4096?strlen($line):0;
                },
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)
                    use(&$body):int{
                    if(strlen($body)+strlen($chunk)>3072)return 0;
                    $body.=$chunk;
                    return strlen($chunk);
                },
            ];
            if(defined('CURLOPT_PROTOCOLS_STR')){
                $options[CURLOPT_PROTOCOLS_STR]='https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR]='https';
            }else{
                $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS]=CURLPROTO_HTTPS;
            }
            if($this->ciCaFile!==null){
                $options[CURLOPT_CAINFO]=$this->ciCaFile;
                $options[CURLOPT_RESOLVE]=[$this->ciDnsResolve];
            }
            if(!curl_setopt_array($handle,$options)||curl_exec($handle)!==true
                ||curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200
                ||curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!==$this->endpoint
                ||!is_string($mime=curl_getinfo($handle,CURLINFO_CONTENT_TYPE))
                ||preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/iD',$mime)!==1
                ||strlen($body)<2)throw new RuntimeException();
            $parsed=json_decode($body,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($parsed))throw new RuntimeException();
            return $parsed;
        }catch(Throwable){
            throw new RuntimeException('Independent challenged witness unavailable.');
        }finally{
            curl_close($handle);
        }
    }

    private static function scope(string $value):bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$value)===1;
    }

    private static function hostname(string $host):bool
    {
        if($host!==strtolower($host)||strlen($host)>253
            ||filter_var($host,FILTER_VALIDATE_IP)!==false
            ||str_contains($host,'_'))return false;
        $parts=explode('.',$host);
        if(count($parts)<2)return false;
        foreach($parts as $part){
            if(strlen($part)<1||strlen($part)>63
                ||preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',$part)!==1)
                return false;
        }
        return true;
    }

    private static function pin(string $value):bool
    {
        if(preg_match('~\Asha256//[A-Za-z0-9+/]{43}=\z~D',$value)!==1)return false;
        $decoded=base64_decode(substr($value,8),true);
        return is_string($decoded)&&strlen($decoded)===32;
    }

    private static function disposableCi():bool
    {
        return getenv('GITHUB_ACTIONS')==='true'
            &&getenv('RUNNER_ENVIRONMENT')==='github-hosted'
            &&getenv('PORTAL_F380_CI')==='DISPOSABLE_ONLY';
    }

    private static function result(string $status):array
    {
        return [
            'schema'=>'imagine.portal.f380.challenged-read-assessment.v1',
            'status'=>$status,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
        ];
    }
}
