<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

/**
 * RFC 6238 TOTP/SHA-1 primitive for future first-party Portal MFA.
 *
 * This validates a six-digit code against a Base32 secret and returns the
 * matched time counter. It does NOT authenticate a subject, grant a session,
 * prove secret provenance, send email, issue a QR code or persist replay state.
 *
 * A future trusted verifier MUST atomically compare-and-swap lastUsedCounter
 * in an authorized server-side row before accepting the MFA step. Without
 * such a durable write, this method is insufficient against concurrent replay.
 */
final class PortalTotpVerifier
{
    private const STEP_SECONDS = 30;
    private const DIGITS = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function matchCounter(
        string $base32Secret,
        string $code,
        int $unixTime,
        int $lastUsedCounter = -1,
    ): ?int {
        if ($unixTime <= 0 || $unixTime > 4102444800
            || $lastUsedCounter < -1
            || preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
            return null;
        }
        $secret = self::decodeBase32($base32Secret);
        if ($secret === null) {
            return null;
        }

        $current = intdiv($unixTime, self::STEP_SECONDS);
        $matched = null;
        foreach ([-1, 0, 1] as $offset) {
            $counter = $current + $offset;
            if ($counter < 0 || $counter <= $lastUsedCounter) {
                continue;
            }

            $expected = self::codeAtCounter($secret, $counter);
            if (hash_equals($expected, $code)) {
                $matched = $matched === null ? $counter : max($matched, $counter);
            }
        }

        return $matched;
    }

    private static function codeAtCounter(string $rawSecret, int $counter): string
    {
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $digest = hash_hmac('sha1', $binaryCounter, $rawSecret, true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0f;

        $value = ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function decodeBase32(string $base32): ?string
    {
        // Require >= 128 bits. Strict, unpadded uppercase encoding only:
        // no case-folding, whitespace or ambiguous symbols.
        if (strlen($base32) < 26 || strlen($base32) > 64
            || preg_match('/\A[A-Z2-7]+\z/D', $base32) !== 1) {
            return null;
        }

        $buffer = 0;
        $bits = 0;
        $bytes = '';
        foreach (str_split($base32) as $char) {
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                return null;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        if (strlen($bytes) < 16 || $buffer !== 0) {
            return null;
        }

        return $bytes;
    }
}
