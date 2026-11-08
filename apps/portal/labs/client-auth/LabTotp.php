<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

/**
 * RFC 6238-style TOTP math for synthetic fixtures only. No enrollment,
 * secure secret custody, recovery codes or operational MFA is implemented.
 */
final class LabTotp
{
    public static function codeForStep(string $secretHex, int $step): string
    {
        if (! preg_match('/\A[0-9a-f]{40}\z/D', $secretHex) || $step < 0) {
            throw new \InvalidArgumentException('Invalid synthetic TOTP fixture.');
        }

        $message = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
        $digest = hash_hmac('sha1', $message, hex2bin($secretHex), true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0f;
        $binary = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function codeAt(string $secretHex, int $time): string
    {
        return self::codeForStep($secretHex, intdiv($time, 30));
    }

    public static function verifiedNewStep(
        string $secretHex,
        string $code,
        int $time,
        int $lastStep,
    ): ?int {
        if (! preg_match('/\A[0-9]{6}\z/D', $code) || $time < 30) {
            return null;
        }

        $current = intdiv($time, 30);
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($step <= $lastStep || $step < 0) {
                continue;
            }

            if (hash_equals(self::codeForStep($secretHex, $step), $code)) {
                return $step;
            }
        }

        return null;
    }
}
