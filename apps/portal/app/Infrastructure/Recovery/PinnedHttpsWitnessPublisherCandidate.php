<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * F377: standalone, non-operational witness-first publisher candidate.
 *
 * No Laravel container/HTTP binding, session issuance or recovery authority.
 * The independent per-publisher HMAC secret is accepted only per call and is
 * NOT stored on the object. No D1/Cloudflare resources are provisioned.
 */
final readonly class PinnedHttpsWitnessPublisherCandidate
{
    private const PATH = '/v1/portal/witness-advance';
    private const DOMAIN = 'imagine.portal.witness.publish.v1';

    public function __construct(
        private string $endpoint,
        private string $tlsSpkiPin,
        private string $organization,
        private string $instance,
        private ?string $ciCaFile = null,
        private ?string $ciDnsResolve = null,
    ) {
        $url = parse_url($endpoint);
        if (!is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || !is_string($url['host'] ?? null)
            || !self::hostname($url['host'])
            || ($url['path'] ?? null) !== self::PATH
            || isset($url['user'], $url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || !preg_match(
                '~\Ahttps://[a-z0-9.-]+(?::[0-9]{2,5})?'.preg_quote(self::PATH,'~').'\z~D',
                $endpoint
            )
            || !self::scope($organization) || !self::scope($instance)
            || !self::tlsPin($tlsSpkiPin)) {
            throw new RuntimeException('Witness publisher configuration unavailable.');
        }
        // No alternate ports or DNS overrides on any non-disposable runtime.
        $ci = self::disposableCi();
        if (isset($url['port']) && (!$ci
            || $url['host'] !== 'witness.f377.test.invalid'
            || $url['port'] !== 18444)) {
            throw new RuntimeException('Witness publisher endpoint refused.');
        }
        if (!$ci && ($ciCaFile !== null || $ciDnsResolve !== null)) {
            throw new RuntimeException('Witness publisher test override refused.');
        }
        if ($ci && (($ciCaFile === null) !== ($ciDnsResolve === null))) {
            throw new RuntimeException('Witness publisher test override refused.');
        }
        if ($ciCaFile !== null
            && ($url['host'] !== 'witness.f377.test.invalid'
                || $ciDnsResolve !== 'witness.f377.test.invalid:18444:127.0.0.1'
                || !is_file($ciCaFile) || is_link($ciCaFile))) {
            throw new RuntimeException('Witness publisher test override refused.');
        }
        if (!$ci && preg_match('/\.(?:test|invalid|localhost|local|internal|example)\z/D', $url['host']) === 1) {
            throw new RuntimeException('Witness publisher endpoint refused.');
        }
    }

    /**
     * Diagnostic-only witness-first step. Even HTTP 204 never authorizes
     * a local transaction, login, recovery, serving, or production deploy.
     *
     * @param string $publisherHmacBase64 Strict raw 32-byte HMAC key base64
     */
    public function attemptAdvance(
        int $fromSequence,
        int $generationFloor,
        int $sessionFloor,
        int $membershipFloor,
        #[SensitiveParameter] string $publisherHmacBase64,
    ): array {
        foreach([$fromSequence,$generationFloor,$sessionFloor,$membershipFloor] as $number) {
            if($number < 1 || $number > 1000000000) {
                return self::result('PUBLISH_INPUT_DENIED');
            }
        }
        if (preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $publisherHmacBase64) !== 1
            || !is_string($key = base64_decode($publisherHmacBase64, true))
            || strlen($key) !== 32) {
            return self::result('PUBLISH_INPUT_DENIED');
        }

        $body = [
            'organization' => $this->organization,
            'instance' => $this->instance,
            'from_sequence' => $fromSequence,
            'generation_floor' => $generationFloor,
            'session_floor' => $sessionFloor,
            'membership_floor' => $membershipFloor,
        ];
        $raw = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = (string) time();
        $mac = hash_hmac('sha256', implode("\n", [
            self::DOMAIN,'POST',self::PATH,$now,$raw,
        ]),$key);

        if (!extension_loaded('curl')) {
            throw new RuntimeException('Independent witness publisher unavailable.');
        }
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Independent witness publisher unavailable.');
        }
        $received = '';
        try {
            $options = [
                CURLOPT_URL => $this->endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $raw,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => 650,
                CURLOPT_TIMEOUT_MS => 1500,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PINNEDPUBLICKEY => $this->tlsSpkiPin,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_DNS_CACHE_TIMEOUT => 0,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Cache-Control: no-store',
                    'X-Imagine-Timestamp: '.$now,
                    'X-Imagine-Signature: '.$mac,
                ],
                CURLOPT_WRITEFUNCTION => static function($ch,string $chunk)use(&$received):int {
                    if (strlen($received)+strlen($chunk)>128) return 0;
                    $received.=$chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR]='https';
                $options[CURLOPT_REDIR_PROTOCOLS_STR]='https';
            } else {
                $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
                $options[CURLOPT_REDIR_PROTOCOLS]=CURLPROTO_HTTPS;
            }
            if ($this->ciCaFile !== null) {
                $options[CURLOPT_CAINFO] = $this->ciCaFile;
                $options[CURLOPT_RESOLVE] = [$this->ciDnsResolve];
            }
            if (!curl_setopt_array($curl,$options)
                || curl_exec($curl) !== true
                || curl_getinfo($curl,CURLINFO_EFFECTIVE_URL) !== $this->endpoint
                || $received !== '') {
                throw new RuntimeException();
            }
            $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($status === 204) {
                return self::result('LAB_WITNESS_ADVANCED_STILL_NO_SERVING');
            }
            if ($status === 409) {
                return self::result('LAB_WITNESS_CONFLICT_STILL_NO_SERVING');
            }
            throw new RuntimeException();
        } catch (Throwable) {
            throw new RuntimeException('Independent witness publisher unavailable.');
        } finally {
            curl_close($curl);
        }
    }

    private static function scope(string $value):bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$value)===1;
    }

    private static function tlsPin(string $value):bool
    {
        if (preg_match('~\Asha256//[A-Za-z0-9+/]{43}=\z~D',$value)!==1)return false;
        $decoded=base64_decode(substr($value,8),true);
        return is_string($decoded)&&strlen($decoded)===32;
    }

    private static function hostname(string $value):bool
    {
        if ($value!==strtolower($value) || strlen($value)>253
            || filter_var($value,FILTER_VALIDATE_IP)!==false
            || str_contains($value,'_')) return false;
        $parts=explode('.',$value);
        if (count($parts)<2)return false;
        foreach($parts as $p) {
            if(strlen($p)===0||strlen($p)>63
                ||preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D',$p)!==1)return false;
        }
        return true;
    }

    private static function disposableCi():bool
    {
        return getenv('GITHUB_ACTIONS')==='true'
            &&getenv('RUNNER_ENVIRONMENT')==='github-hosted'
            &&getenv('PORTAL_F377_CI')==='DISPOSABLE_ONLY';
    }

    private static function result(string $status):array
    {
        return [
            'schema'=>'imagine.portal.f377.publisher-rehearsal.v1',
            'status'=>$status,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
        ];
    }
}
