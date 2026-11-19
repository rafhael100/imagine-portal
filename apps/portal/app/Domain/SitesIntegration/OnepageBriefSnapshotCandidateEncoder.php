<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Candidate Onepage producer for Sites' immutable brief snapshot schema.
 *
 * This pure adapter does NOT verify that the Portal briefing was submitted,
 * approved or belongs to an authenticated buyer. A future trusted Portal
 * source must supply the two original bytes and independently attest authority.
 * No file, database, HTTP, queue, AI or publication side effect.
 */
final class OnepageBriefSnapshotCandidateEncoder
{
    public const SOURCE_LIMIT = 150000;
    public const MANIFEST_LIMIT = 32768;

    private const LIMITS = [
        'company_name' => 160,
        'company_intro' => 10000,
        'services' => 10000,
        'current_site' => 800,
        'contact_site' => 800,
        'domain' => 253,
        'email' => 254,
        'phone' => 60,
        'address' => 600,
        'whatsapp' => 60,
        'notes' => 5000,
    ];

    // Same ECMAScript trim code points used by the proposed Sites profile.
    private const ES_SPACE = '\x{0009}\x{000B}\x{000C}\x{0020}\x{00A0}\x{1680}'
        .'\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\x{FEFF}'
        .'\x{000A}\x{000D}\x{2028}\x{2029}';

    /**
     * @param array{siteRef:string,snapshotRef:string,operationId:string,
     *    idempotencyKey:string,sourceVersion:int,expectedBriefRevision:int,
     *    payloadRef:string} $selection
     * @return array{manifestBytes:string,sourceBytes:string,manifestSha256:string,
     *    payloadSha256:string,authorityVerified:false,clientAccepted:false,
     *    canPublish:false,canAcceptRealOrders:false}
     */
    public static function build(
        SiteContractSnapshot $contract,
        array $selection,
        string $sourceBytes,
    ): array {
        try {
            if (PHP_INT_SIZE !== 8 || $contract->productCode !== 'onepage' ||
                $sourceBytes === '' || strlen($sourceBytes) > self::SOURCE_LIMIT ||
                str_contains($sourceBytes, "\0") || !mb_check_encoding($sourceBytes, 'UTF-8')) {
                throw new RuntimeException();
            }
            self::exactKeys($selection, [
                'siteRef', 'snapshotRef', 'operationId', 'idempotencyKey',
                'sourceVersion', 'expectedBriefRevision', 'payloadRef',
            ]);
            foreach (['siteRef', 'snapshotRef'] as $field) {
                if (!is_string($selection[$field]) ||
                    preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $selection[$field]) !== 1) {
                    throw new RuntimeException();
                }
            }
            foreach (['operationId', 'idempotencyKey', 'payloadRef'] as $field) {
                if (!is_string($selection[$field]) ||
                    preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $selection[$field]) !== 1) {
                    throw new RuntimeException();
                }
            }
            if (!is_int($selection['sourceVersion']) ||
                $selection['sourceVersion'] < 0 || $selection['sourceVersion'] > 9007199254740991 ||
                !is_int($selection['expectedBriefRevision']) ||
                $selection['expectedBriefRevision'] < 0 ||
                $selection['expectedBriefRevision'] > 2147483646) {
                throw new RuntimeException();
            }
            $body = json_decode($sourceBytes, false, 8, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($sourceBytes);
            if (!$body instanceof stdClass) throw new RuntimeException();
            self::validateBody($body);

            // Generated only from an explicit selected project and contract.
            // No unseen "latest" revision, sourceVersion increment or
            // authorization is inferred from the source's self-declared data.
            $manifest = [
                'schemaVersion' => 'sites.brief-snapshot.candidate.v0.1',
                'operationId' => $selection['operationId'],
                'idempotencyKey' => $selection['idempotencyKey'],
                'tenantRef' => $contract->tenantRef,
                'siteRef' => $selection['siteRef'],
                'orderRef' => $contract->orderRef,
                'contractRef' => $contract->contractRef,
                'contractRevision' => $contract->contractRevision,
                'productCode' => 'onepage',
                'sourceProfile' => 'sites.onepage-brief-payload.candidate.v0.1',
                'sourceVersion' => $selection['sourceVersion'],
                // Required candidate label, NOT Portal approval or evidence.
                'sourceState' => 'approved',
                'snapshotRef' => $selection['snapshotRef'],
                'expectedBriefRevision' => $selection['expectedBriefRevision'],
                'briefRevision' => $selection['expectedBriefRevision'] + 1,
                'payload' => [
                    'payloadRef' => $selection['payloadRef'],
                    'mediaType' => 'application/json',
                    'sizeBytes' => strlen($sourceBytes),
                    'sha256' => hash('sha256', $sourceBytes),
                ],
                'materials' => [],
            ];
            $manifestBytes = json_encode(
                $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
            if (strlen($manifestBytes) > self::MANIFEST_LIMIT) throw new RuntimeException();
            return [
                'manifestBytes' => $manifestBytes,
                'sourceBytes' => $sourceBytes,
                'manifestSha256' => hash('sha256', $manifestBytes),
                'payloadSha256' => hash('sha256', $sourceBytes),
                'authorityVerified' => false,
                'clientAccepted' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ];
        } catch (Throwable) {
            // Never include source content, references, or parser diagnostics.
            throw new RuntimeException('ONEPAGE_BRIEF_EXPORT_CANDIDATE_DENIED');
        }
    }

    private static function validateBody(stdClass $body): void
    {
        self::exactKeys(get_object_vars($body), [
            ...array_keys(self::LIMITS),
            'ai_support', 'logo_unavailable', 'manual_unavailable',
        ]);
        foreach (['ai_support', 'logo_unavailable', 'manual_unavailable'] as $flag) {
            if (!is_bool($body->{$flag})) throw new RuntimeException();
        }
        // First Onepage consumer intentionally excludes uploaded materials.
        if (!$body->logo_unavailable || !$body->manual_unavailable) throw new RuntimeException();

        $logical = [];
        foreach (self::LIMITS as $field => $max) {
            $text = $body->{$field};
            if (!is_string($text) || str_contains($text, "\0") ||
                !mb_check_encoding($text, 'UTF-8') ||
                strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) > $max * 2) {
                throw new RuntimeException();
            }
            $value = preg_replace(
                '/\A['.self::ES_SPACE.']+|['.self::ES_SPACE.']+\z/u', '', $text,
            );
            if (!is_string($value)) throw new RuntimeException();
            $logical[$field] = $value;
        }
        if ($logical['company_name'] === '' || $logical['services'] === '' ||
            ($logical['company_intro'] === '' && !$body->ai_support) ||
            ($logical['email'] === '' && $logical['phone'] === '' && $logical['whatsapp'] === '')) {
            throw new RuntimeException();
        }
        foreach (['current_site', 'contact_site'] as $field) {
            if ($logical[$field] !== '') self::httpReference($logical[$field]);
        }
        if ($logical['email'] !== '' &&
            preg_match('/\A[^'.self::ES_SPACE.'@]+@[^'.self::ES_SPACE.'@]+\.[^'.self::ES_SPACE.'@]+\z/uD',
                $logical['email']) !== 1) throw new RuntimeException();
        if ($logical['domain'] !== '' &&
            preg_match('/\A(?=.{1,253}\z)(?:[a-z\d](?:[a-z\d-]{0,61}[a-z\d])?\.)+[a-z]{2,63}\z/iD',
                $logical['domain']) !== 1) throw new RuntimeException();
    }

    /** Subset of absolute HTTP(S) references accepted by the Sites candidate. */
    private static function httpReference(string $value): void
    {
        if (str_contains($value, '\\') ||
            preg_match('/[\x00-\x20\x7f-\x9f'.self::ES_SPACE.']/u', $value) !== 0 ||
            preg_match('~\A[hH][tT][tT][pP][sS]?://([^/?#]+)(?:[/?#].*)?\z~uD', $value, $parts) !== 1) {
            throw new RuntimeException();
        }
        $authority = $parts[1];
        if (str_starts_with($authority, '[')) {
            if (preg_match('/\A\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?\z/iD', $authority, $host) !== 1 ||
                filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new RuntimeException();
            }
        } else {
            if (preg_match('/\A([a-z0-9.-]+)(?::([0-9]{1,5}))?\z/iD', $authority, $host) !== 1 ||
                strlen($host[1]) > 253 || str_ends_with($host[1], '.')) {
                throw new RuntimeException();
            }
            $labels = explode('.', $host[1]);
            $last = $labels[count($labels) - 1];
            if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/iD', $last) === 1) {
                if (filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new RuntimeException();
            } else {
                foreach ($labels as $label) {
                    if (str_starts_with(strtolower($label), 'xn--') ||
                        preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/iD', $label) !== 1) {
                        throw new RuntimeException();
                    }
                }
            }
        }
        if (isset($host[2]) && (int)$host[2] > 65535) throw new RuntimeException();
    }

    private static function exactKeys(array $actual, array $expected): void
    {
        if (count($actual) !== count($expected) || array_diff($expected, array_keys($actual))) {
            throw new RuntimeException();
        }
    }

    /** json_decode would silently accept duplicate object keys. */
    private static function rejectDuplicateKeys(string $json): void
    {
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') $objects[] = [];
            elseif ($char === '[') $objects[] = null;
            elseif ($char === '}' || $char === ']') array_pop($objects);
            elseif ($char === '"') {
                $start = $i;
                for (++$i; $i < $length; ++$i) {
                    if ($json[$i] === '\\') { ++$i; continue; }
                    if ($json[$i] === '"') break;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
                if ($next < $length && $json[$next] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    $level = count($objects) - 1;
                    if ($level < 0 || !is_array($objects[$level]) || isset($objects[$level][$key])) {
                        throw new RuntimeException();
                    }
                    $objects[$level][$key] = true;
                }
            }
        }
    }
}
