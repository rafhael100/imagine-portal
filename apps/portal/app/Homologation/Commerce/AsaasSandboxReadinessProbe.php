<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use Closure;
use InvalidArgumentException;
use JsonException;

/**
 * Optional, read-only Sandbox connectivity gate.
 * No routes, payments, customer records, webhook configuration or credentials
 * are persisted. A live request is only possible from the separately gated CLI.
 */
final readonly class AsaasSandboxReadinessProbe
{
    private const URL='https://api-sandbox.asaas.com/v3/payments?offset=0&limit=1';

    /** Transport injection is for OFFLINE CI only; production routes never bind this service. */
    public function __construct(private ?Closure $transport=null) {}

    public function inspect(#[\SensitiveParameter] string $key): bool
    {
        // Asaas documents different prefixes for Sandbox and production.
        if (!str_starts_with($key,'$aact_hmlg_') ||
            strlen($key)<32 || strlen($key)>512 ||
            preg_match('/[\x00-\x20\x7f]/',$key)===1) {
            throw new InvalidArgumentException('A valid Sandbox-only key is required.');
        }
        $headers=[
            'access_token: '.$key,
            'User-Agent: ImaginePortal-ReadOnlySandboxProbe/1.0',
            'Accept: application/json',
        ];
        try {
            $result=$this->transport
                ? ($this->transport)('GET',self::URL,$headers)
                : self::liveGet(self::URL,$headers);
            if (!is_array($result) || ($result['status']??null)!==200 ||
                !is_string($result['body']??null) ||
                strlen($result['body'])>65536) {
                return false;
            }
            $body=json_decode($result['body'],true,12,JSON_THROW_ON_ERROR);
            return is_array($body) && !array_is_list($body) &&
                is_array($body['data']??null) && array_is_list($body['data']) &&
                count($body['data'])<=1 && is_bool($body['hasMore']??null);
        } catch (\Throwable) {
            // No response detail, payment field, token or account ID in logs.
            return false;
        }
    }

    /** One pinned GET, HTTPS only. Explicitly disable proxy and redirects. */
    private static function liveGet(string $url,array $headers): array
    {
        if (!function_exists('curl_init')) return ['status'=>0,'body'=>''];
        $h=curl_init($url);
        if ($h===false) return ['status'=>0,'body'=>''];
        $body='';
        try {
            curl_setopt_array($h,[
                CURLOPT_HTTPGET=>true,
                CURLOPT_HTTPHEADER=>$headers,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROXY=>'',
                CURLOPT_NOPROXY=>'*',
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>12,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_VERBOSE=>false,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body):int {
                    if (strlen($body)+strlen($chunk)>65536) return 0;
                    $body.=$chunk;
                    return strlen($chunk);
                },
            ]);
            $ok=@curl_exec($h);
            $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
            return ['status'=>$ok===true?$status:0,'body'=>$body];
        } finally {
            curl_close($h);
        }
    }
}
