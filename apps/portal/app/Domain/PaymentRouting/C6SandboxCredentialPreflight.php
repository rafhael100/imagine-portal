<?php
declare(strict_types=1);
namespace App\Domain\PaymentRouting;

use RuntimeException;

/** Local-only C6 sandbox PEM credential preflight; never touches bank or PSP. */
final class C6SandboxCredentialPreflight
{
    public static function inspect(string $dir): array
    {
        if ($dir === '' || is_link($dir) || !is_dir($dir) || ((fileperms($dir) & 0077) !== 0)) {
            throw new RuntimeException('Private C6 sandbox directory unavailable');
        }
        $files = [];
        foreach (['client_id', 'client_secret', 'client.crt', 'client.key'] as $name) {
            $path = rtrim($dir,'/').'/'.$name;
            if (is_link($path) || !is_file($path) || !is_readable($path)
                || ((fileperms($path) & 0077) !== 0) || filesize($path) < 1
                || filesize($path) > 32768) {
                throw new RuntimeException('C6 sandbox file unavailable or unsafe');
            }
            $files[$name] = file_get_contents($path);
            if (!is_string($files[$name])) {
                throw new RuntimeException('C6 sandbox file unreadable');
            }
        }
        foreach (['client_id', 'client_secret'] as $field) {
            $value = $files[$field];
            if (str_ends_with($value, "\n")) $value = substr($value, 0, -1);
            if (strlen($value) < 8 || strlen($value) > 4096
                || preg_match('/[\r\n\x00-\x1f]/', $value) === 1) {
                throw new RuntimeException('C6 sandbox secret format rejected');
            }
        }
        if (!function_exists('openssl_x509_read')) {
            throw new RuntimeException('PHP OpenSSL extension unavailable');
        }
        $cert = @openssl_x509_read($files['client.crt']);
        $key = @openssl_pkey_get_private($files['client.key']);
        if ($cert === false || $key === false || !openssl_x509_check_private_key($cert, $key)) {
            throw new RuntimeException('C6 sandbox PEM certificate/key invalid or mismatched');
        }
        $parsed = @openssl_x509_parse($cert);
        $start = $parsed['validFrom_time_t'] ?? null;
        $end = $parsed['validTo_time_t'] ?? null;
        if (!is_int($start) || !is_int($end) || time() < $start || time() >= $end) {
            throw new RuntimeException('C6 sandbox certificate not valid today');
        }
        return [
            'environment'=>'sandbox', 'ready'=>true,
            'certificate_expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $end),
            'network_checked'=>false, 'can_charge'=>false, 'can_activate'=>false,
        ];
    }
}
