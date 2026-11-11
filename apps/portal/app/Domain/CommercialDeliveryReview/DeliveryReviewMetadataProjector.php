<?php

declare(strict_types=1);

namespace App\Domain\CommercialDeliveryReview;

use InvalidArgumentException;

/**
 * F130: STRICT, PURE metadata projection for a future operator read model.
 *
 * No database read, login check, resource ownership check or HTTP capability.
 * Calling this function never turns unverified rows into authorized data:
 * trusted tenant scoping/session verification must precede invocation.
 */
final class DeliveryReviewMetadataProjector
{
    private const REASONS = [
        'unknown_outcome' => 'Resultado desconhecido',
        'expired_dispatch_may_have_committed' => 'Possível envio sem confirmação',
        'claim_attempt_limit_reached' => 'Limite de reservas atingido',
    ];

    /**
     * @param list<array<string,mixed>> $candidateRows
     * @return list<array{reference:string,reason_code:string,reason_label:string,attempts:int,claim_version:int,requires_manual_review:bool,can_retry:bool,can_acknowledge_visitor:bool,real_crm_verified:bool}>
     */
    public function project(array $candidateRows, string $serverSelectedOrganization): array
    {
        $tenant = strtolower($serverSelectedOrganization);
        if (!self::uuid($tenant) || count($candidateRows) > 100
            || !array_is_list($candidateRows)) {
            throw new InvalidArgumentException('Invalid review projection scope.');
        }

        $result = [];
        $seen = [];
        foreach ($candidateRows as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Malformed metadata row.');
            }
            $org = $item['organization_reference'] ?? null;
            $submission = $item['submission_reference'] ?? null;
            $reason = $item['review_reason'] ?? null;
            $attempts = self::positiveInt($item['attempts'] ?? null, 3);
            $version = self::positiveInt($item['claim_version'] ?? null, 2147483647);

            if (!is_string($org) || !self::uuid(strtolower($org))
                || strtolower($org) !== $tenant
                || !is_string($submission) || !self::uuid(strtolower($submission))
                || !is_string($reason) || !isset(self::REASONS[$reason])
                || $attempts === null || $version === null
                || !self::strictBoolean($item['requires_manual_review'] ?? null, true)
                || !self::strictBoolean($item['automatic_retry_allowed'] ?? null, false)
                || !self::strictBoolean($item['real_crm_persisted_verified'] ?? null, false)
                || !self::strictBoolean($item['can_acknowledge_visitor'] ?? null, false)) {
                // Fail the *entire* request to avoid partial foreign-tenant output.
                throw new InvalidArgumentException('Untrusted delivery-review evidence.');
            }

            $id = strtolower($submission);
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Duplicate review reference.');
            }
            $seen[$id] = true;
            $result[] = [
                'reference' => $id,
                'reason_code' => $reason,
                'reason_label' => self::REASONS[$reason],
                'attempts' => $attempts,
                'claim_version' => $version,
                'requires_manual_review' => true,
                'can_retry' => false,
                'can_acknowledge_visitor' => false,
                'real_crm_verified' => false,
            ];
        }

        return $result;
    }

    /** @return array<string,int> */
    public function counts(array $projected): array
    {
        $counts = array_fill_keys(array_keys(self::REASONS), 0);
        foreach ($projected as $item) {
            if (!is_array($item) || !isset($item['reason_code'])
                || !is_string($item['reason_code'])
                || !array_key_exists($item['reason_code'], $counts)) {
                throw new InvalidArgumentException('Invalid projected review item.');
            }
            ++$counts[$item['reason_code']];
        }
        return $counts;
    }

    public static function canAuthorizeQueueRead(): bool
    {
        return false;
    }

    private static function uuid(string $value): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $value,
        ) === 1;
    }

    private static function strictBoolean(mixed $value, bool $expected): bool
    {
        return $value === $expected || $value === ($expected ? 't' : 'f');
    }

    private static function positiveInt(mixed $value, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= $max ? $value : null;
        }
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) !== 1) {
            return null;
        }
        $int = (int) $value;
        return $int >= 1 && $int <= $max && (string) $int === $value
            ? $int : null;
    }
}
