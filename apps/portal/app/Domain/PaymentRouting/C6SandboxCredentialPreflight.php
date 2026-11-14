<?php
declare(strict_types=1);
namespace App\Domain\PaymentRouting;

use RuntimeException;

/** Local-only C6 sandbox PEM/P12 credential preflight; no bank HTTP or payments. */
final class C6SandboxCredentialPreflight
{
    public static function inspect(string $dir): array
    {
        if ($dir === '' || is_link($dir) || !is_dir($dir) || ((fileperms($dir) & 0077) !== 0)) {
            throw new RuntimeException('Private C6 sandbox directory unavailable');
        }
        $hasPem = is_file($dir.'/client.crt') || is_file($dir.'/client.key');
        $hasP12 = is_file($dir.'/client.p12') || is_file($dir.'/client_p12_passphrase');
        if ($hasPem === $hasP12) {
            throw new RuntimeException('C6 sandbox bundle must be exactly PEM or P12');
        }
        $names = $hasP12
            ? ['client_id', 'client_secret', 'client.p12', 'client_p12_passphrase']
            : ['client_id', 'client_secret', 'client.crt', 'client.key'];
        // A sandbox credential directory is a four-file immutable bundle.
        // Reject extra backups, stale credentials, hidden files and mixed
        // formats rather than accidentally selecting the wrong certificate.
        $entries = scandir($dir);
        if (!is_array($entries)
            || array_diff($entries, array_merge(['.', '..'], $names)) !== []
            || array_diff($names, $entries) !== []) {
            throw new RuntimeException('Unexpected C6 sandbox bundle files');
        }
        $dirOwner = fileowner($dir);
        if (!is_int($dirOwner)) {
            throw new RuntimeException('C6 sandbox owner unavailable');
        }
        $files = [];
        foreach ($names as $name) {
            $path = rtrim($dir,'/').'/'.$name;
            if (is_link($path) || !is_file($path) || !is_readable($path)
                || fileowner($path) !== $dirOwner
                || ((fileperms($path) & 0077) !== 0) || filesize($path) < 1
                || filesize($path) > 65536) {
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
        if ($hasP12) {
            $password = $files['client_p12_passphrase'];
            if (str_ends_with($password, "\n")) $password = substr($password, 0, -1);
            if ($password === '' || preg_match('/[\r\n\x00]/', $password) === 1
                || !openssl_pkcs12_read($files['client.p12'], $bundle, $password)) {
                throw new RuntimeException('C6 sandbox P12 invalid or passphrase incorrect');
            }
            $cert = @openssl_x509_read($bundle['cert'] ?? '');
            $key = @openssl_pkey_get_private($bundle['pkey'] ?? '');
        } else {
            $cert = @openssl_x509_read($files['client.crt']);
            $key = @openssl_pkey_get_private($files['client.key']);
        }
        if ($cert === false || $key === false || !openssl_x509_check_private_key($cert, $key)) {
            throw new RuntimeException('C6 sandbox certificate/private key invalid or mismatched');
        }
        $parsed = @openssl_x509_parse($cert);
        $start = $parsed['validFrom_time_t'] ?? null;
        $end = $parsed['validTo_time_t'] ?? null;
        if (!is_int($start) || !is_int($end) || time() < $start || time() >= $end) {
            throw new RuntimeException('C6 sandbox certificate not valid today');
        }
        return [
            'environment'=>'sandbox', 'ready'=>true, 'certificate_format'=>$hasP12?'p12':'pem',
            'certificate_expires_utc'=>gmdate('Y-m-d\TH:i:s\Z', $end),
            'network_checked'=>false, 'can_charge'=>false, 'can_activate'=>false,
        ];
    }
}
