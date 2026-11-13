<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * F375 HTTPS-only candidate, never bound to the Laravel container or HTTP.
 * Requires independent operator-pinned TLS SPKI and F369 Ed25519 receipt key.
 * Transport success is DATA only, never a recovery/serving authorization.
 */
final readonly class PinnedHttpsSignedWitnessTransportCandidate implements IndependentSignedWitnessTransport
{
    private const MAX_BYTES = 3072;
    private const MAX_HEADERS = 4096;
    private const PATH = '/v1/portal/recovery-witness';
    private const RECEIPT_KEYS = [
        'schema','organization','instance','generation_floor','session_floor',
        'membership_floor','sequence','issued_at','expires_at','signature',
    ];

    public function __construct(
        private string $endpoint,
        #[SensitiveParameter] private string $tlsSpkiPin,
        private ?string $ciCaFile = null,
        private ?string $ciDnsResolve = null,
    ) {
        $url=parse_url($endpoint);
        if (!is_array($url)
            ||($url['scheme']??null)!=='https'
            ||!is_string($url['host']??null)
            ||!self::hostname($url['host'])
            ||($url['path']??null)!==self::PATH
            ||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])
            ||preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{2,5})?'.preg_quote(self::PATH,'~').'\z~D',$endpoint)!==1
            ||preg_match('~\Asha256//[A-Za-z0-9+/]{43}=\z~D',$tlsSpkiPin)!==1
            ||!is_string(base64_decode(substr($tlsSpkiPin,8),true))
            ||strlen((string)base64_decode(substr($tlsSpkiPin,8),true))!==32
        ){
            throw new RuntimeException('Independent witness transport configuration invalid.');
        }
        $ci=self::disposableCi();
        if (isset($url['port']) && (!$ci||$url['host']!=='witness.f375.test.invalid'||$url['port']!==18443)) {
            throw new RuntimeException('Independent witness port not approved.');
        }
        if ((!$ci && ($ciCaFile!==null || $ciDnsResolve!==null))
            ||($ci && (($ciCaFile===null)!==($ciDnsResolve===null)))
            ||($ci && ($ciCaFile!==null||$ciDnsResolve!==null)
                &&($url['host']!=='witness.f375.test.invalid'
                   ||$ciDnsResolve!=='witness.f375.test.invalid:18443:127.0.0.1'
                   ||!is_file($ciCaFile)
                   ||is_link($ciCaFile)))
        ){
            throw new RuntimeException('Test-only certificate/resolve must not be used outside CI.');
        }
        if (!$ci && preg_match('/\.(?:test|invalid|localhost|local|internal|example)\z/D',$url['host'])===1) {
            throw new RuntimeException('Independent witness hostname not approved.');
        }
    }

    public function latestSignedWitness(): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Independent witness unavailable.');
        }
        $handle=curl_init();
        if($handle===false)throw new RuntimeException('Independent witness unavailable.');
        $body='';
        $headerBytes=0;
        try {
            $options=[
                CURLOPT_URL=>$this->endpoint,
                CURLOPT_HTTPGET=>true,
                CURLOPT_NOBODY=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_RETURNTRANSFER=>false,
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
                ],
                CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headerBytes):int{
                    $length=strlen($line);
                    $headerBytes+=$length;
                    return $headerBytes<=self::MAX_HEADERS?$length:0;
                },
                CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body):int{
                    $length=strlen($chunk);
                    if(strlen($body)+$length>self::MAX_BYTES)return 0;
                    $body.=$chunk;
                    return $length;
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
            if(!curl_setopt_array($handle,$options) || curl_exec($handle)!==true
                ||curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200
                ||curl_getinfo($handle,CURLINFO_EFFECTIVE_URL)!==$this->endpoint
                ||!is_string($mime=curl_getinfo($handle,CURLINFO_CONTENT_TYPE))
                ||preg_match('/\Aapplication\/json(?:;\s*charset=utf-8)?\z/iD',$mime)!==1
                ||strlen($body)<2){
                throw new RuntimeException();
            }
            $parsed=json_decode($body,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($parsed)||array_keys($parsed)!==self::RECEIPT_KEYS
                ||($parsed['schema']??null)!==IndependentWitnessCandidateVerifier::SCHEMA
                ||!is_string($parsed['signature']??null)
                ||preg_match('/\A[a-f0-9]{128}\z/D',$parsed['signature'])!==1) {
                throw new RuntimeException();
            }
            // F369 verifies cryptographic signature, expiry and scope after this.
            // This class deliberately returns NO positive authorization verdict.
            return $parsed;
        }catch(Throwable){
            throw new RuntimeException('Independent witness unavailable.');
        }finally{
            curl_close($handle);
        }
    }

    private static function hostname(string $host):bool
    {
        if($host!==strtolower($host)||strlen($host)>253
            ||filter_var($host,FILTER_VALIDATE_IP)!==false
            ||str_contains($host,'_'))return false;
        $labels=explode('.',$host);
        if(count($labels)<2)return false;
        foreach($labels as $label){
            if(strlen($label)<1||strlen($label)>63
                ||preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',$label)!==1)
                return false;
        }
        return true;
    }

    private static function disposableCi():bool
    {
        return getenv('GITHUB_ACTIONS')==='true'
            &&getenv('RUNNER_ENVIRONMENT')==='github-hosted'
            &&getenv('PORTAL_F375_CI')==='DISPOSABLE_ONLY';
    }
}
