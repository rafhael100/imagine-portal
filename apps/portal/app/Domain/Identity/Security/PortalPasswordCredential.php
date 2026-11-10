<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

/**
 * First-party Portal password hashing/check policy. Not a login endpoint.
 *
 * A successful password verification MUST NOT create an authenticated
 * principal: email verification, durable session, MFA, tenant membership,
 * rate limiting, revocation and recovery floor are separate requirements.
 */
final class PortalPasswordCredential
{
    private const MIN_BYTES = 12;
    private const MAX_BYTES = 1024;

    public static function makeHash(string $plainText): ?string
    {
        if (!self::validPasswordLength($plainText)
            || !defined('PASSWORD_ARGON2ID')) {
            return null;
        }

        $hash = password_hash($plainText, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MiB
            'time_cost' => 3,
            'threads' => 1,
        ]);

        return is_string($hash) ? $hash : null;
    }

    public static function verify(string $plainText, ?string $storedHash): bool
    {
        if (!self::validPasswordLength($plainText)
            || !is_string($storedHash)
            || strlen($storedHash) < 50
            || strlen($storedHash) > 255
            || !str_starts_with($storedHash, '$argon2id$')) {
            return false;
        }

        $info = password_get_info($storedHash);
        if ($info['algoName'] !== 'argon2id') {
            return false;
        }
        $options = $info['options'];
        if (!is_array($options)
            || !isset($options['memory_cost'], $options['time_cost'], $options['threads'])
            || $options['memory_cost'] < 19456 || $options['memory_cost'] > 262144
            || $options['time_cost'] < 2 || $options['time_cost'] > 8
            || $options['threads'] < 1 || $options['threads'] > 4) {
            return false;
        }

        return password_verify($plainText, $storedHash);
    }

    private static function validPasswordLength(string $plainText): bool
    {
        $size = strlen($plainText);
        return $size >= self::MIN_BYTES && $size <= self::MAX_BYTES
            && str_contains($plainText, "\0") === false;
    }
}
