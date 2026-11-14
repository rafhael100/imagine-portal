<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use RuntimeException;

/**
 * Immutable, read-only Asaas full payment endpoint.
 * No POST, webhook, subscription creation, charge, capture or refund.
 * Production reads require an explicit operator opt-in (default sandbox).
 * Access token is passed by an authorized secret provider, NEVER stored here.
 */
final readonly class AsaasReadOnlyPaymentClient implements AsaasOfficialPaymentReader
{
    private string $apiOrigin;

    public function __construct(
        string $environment,
        private string $apiToken,
        bool $allowProductionRead = false,
    ) {
        $this->apiOrigin=match($environment) {
            'sandbox'=>'https://api-sandbox.asaas.com',
            'production' => $allowProductionRead
                ? 'https://api.asaas.com'
                : throw new DomainException('Production PSP reads require separate approval.'),
            default=>throw new DomainException('Unknown or unapproved PSP environment.'),
        };
        if (strlen($apiToken)<16 || strlen($apiToken)>512 ||
            preg_match('/[\x00-\x20\x7f]/',$apiToken)===1) {
            throw new DomainException('PSP credential unavailable.');
        }
    }

    /** @return array<string,mixed>|null */
    public function fetchPayment(string $paymentId): ?array
    {
        if (preg_match('/\Apay_[A-Za-z0-9_]{6,64}\z/D',$paymentId)!==1 ||
            !function_exists('curl_init')) {
            throw new DomainException('Official PSP payment reader unavailable.');
        }

        $handle=curl_init($this->apiOrigin.'/v3/payments/'.$paymentId);
        if ($handle===false) throw new RuntimeException('PSP transport unavailable.');
        $body='';
        try {
            // Pin URL to the two official HTTPS origins above. Never allow
            // caller-controlled URLs, redirects, cookies or proxy fallbacks.
            curl_setopt_array($handle,[
                CURLOPT_HTTPGET=>true,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROTOCOLS_STR=>'https',
                CURLOPT_REDIR_PROTOCOLS_STR=>'https',
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'',
                CURLOPT_NOPROXY=>'*',
                CURLOPT_TIMEOUT=>7,
                CURLOPT_CONNECTTIMEOUT=>3,
                CURLOPT_HTTPHEADER=>[
                    'accept: application/json',
                    'access_token: '.$this->apiToken,
                    'User-Agent: ImaginePortal-PSP-ReadOnly/1.0',
                ],
                CURLOPT_WRITEFUNCTION=>static function ($ch,string $chunk)use(&$body):int {
                    if (strlen($body)+strlen($chunk)>32768) return 0;
                    $body.=$chunk;
                    return strlen($chunk);
                },
            ]);
            $ok=curl_exec($handle);
            $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            $type=(string)curl_getinfo($handle,CURLINFO_CONTENT_TYPE);
            if (!$ok || $status!==200 ||
                stripos($type,'application/json')!==0) {
                // No raw cURL error, response body, token or account data in logs.
                throw new RuntimeException('Official PSP verification unavailable.');
            }
            $decoded=json_decode($body,true,24,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('Official PSP verification unavailable.');
            }
            return $decoded;
        } catch (\Throwable) {
            throw new RuntimeException('Official PSP verification unavailable.');
        } finally {
            curl_close($handle);
        }
    }
}
