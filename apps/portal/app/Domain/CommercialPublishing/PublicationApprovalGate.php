<?php

declare(strict_types=1);

namespace App\Domain\CommercialPublishing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F3: política determinística de laboratório para validar o fluxo editorial
 * antes de emitir o catálogo público. NÃO autentica os atores, não usa banco,
 * não assegura isolamento multiempresa e não é um publicador de produção.
 */
final class PublicationApprovalGate
{
    private const ID = '/\A[a-z][a-z0-9_-]{5,79}\z/D';
    private const SHA256 = '/\A[a-f0-9]{64}\z/D';
    private const MAX_SECONDS = 86400; // janela máxima sintética de 24h

    /**
     * @param list<string> $usedRequestIds Históricos vindos de armazenamento confiável, ainda inexistente.
     * @param list<string> $publishedReleaseIds Históricos vindos de armazenamento confiável, ainda inexistente.
     * @return array{requestId:string,tenantId:string,releaseId:string,payloadSha256:string,status:string}
     */
    public static function evaluate(
        array $request,
        array $approval,
        array $dispatch,
        array $snapshot,
        array $usedRequestIds = [],
        array $publishedReleaseIds = [],
    ): array {
        self::fields($request, ['requestId','tenantId','actorId','roles','releaseId','payloadSha256','requestedAt','status'], 'request');
        self::fields($approval, ['requestId','tenantId','actorId','roles','releaseId','payloadSha256','approvedAt','decision'], 'approval');
        self::fields($dispatch, ['requestId','tenantId','actorId','roles','releaseId','payloadSha256','dispatchedAt','status'], 'dispatch');

        foreach ([$request,$approval,$dispatch] as $item) {
            foreach (['requestId','tenantId','actorId','releaseId'] as $field) {
                if (!is_string($item[$field] ?? null) ||
                    preg_match(self::ID, $item[$field]) !== 1) {
                    throw new InvalidArgumentException('Invalid publication '.$field.'.');
                }
            }
            if (!is_string($item['payloadSha256'] ?? null) ||
                preg_match(self::SHA256, $item['payloadSha256']) !== 1) {
                throw new InvalidArgumentException('Invalid payload digest.');
            }
        }

        self::requireRole($request, 'editor');
        self::requireRole($approval, 'content_approver');
        self::requireRole($dispatch, 'publisher');

        if (($request['status'] ?? null) !== 'submitted' ||
            ($approval['decision'] ?? null) !== 'approved' ||
            ($dispatch['status'] ?? null) !== 'pending') {
            throw new InvalidArgumentException('Publication must be submitted, approved and pending.');
        }
        if (count(array_unique([
            $request['actorId'], $approval['actorId'], $dispatch['actorId']
        ])) !== 3) {
            throw new InvalidArgumentException('Editor, approver and publisher must be distinct.');
        }
        foreach ([$approval,$dispatch] as $step) {
            foreach (['requestId','tenantId','releaseId'] as $field) {
                if ($step[$field] !== $request[$field]) {
                    throw new InvalidArgumentException('Publication request scope mismatch: '.$field);
                }
            }
            if (!hash_equals($request['payloadSha256'], $step['payloadSha256'])) {
                throw new InvalidArgumentException('Publication payload digest mismatch.');
            }
        }
        if (($snapshot['releaseId'] ?? null) !== $request['releaseId']) {
            throw new InvalidArgumentException('Snapshot release does not match approval.');
        }
        $bytes = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals($request['payloadSha256'], hash('sha256', $bytes))) {
            throw new InvalidArgumentException('Snapshot changed after editorial approval.');
        }

        $requestedAt = self::parseUtc($request['requestedAt'] ?? null);
        $approvedAt = self::parseUtc($approval['approvedAt'] ?? null);
        $dispatchedAt = self::parseUtc($dispatch['dispatchedAt'] ?? null);
        $r = $requestedAt->getTimestamp();
        $a = $approvedAt->getTimestamp();
        $d = $dispatchedAt->getTimestamp();
        if ($a <= $r || $d <= $a || $a - $r > self::MAX_SECONDS ||
            $d - $a > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Approval chronology or 24-hour window invalid.');
        }
        if (in_array($request['requestId'], $usedRequestIds, true) ||
            in_array($request['releaseId'], $publishedReleaseIds, true)) {
            throw new InvalidArgumentException('Release or request already consumed.');
        }
        return [
            'requestId' => $request['requestId'],
            'tenantId' => $request['tenantId'],
            'releaseId' => $request['releaseId'],
            'payloadSha256' => $request['payloadSha256'],
            'status' => 'validated_for_laboratory_signing',
        ];
    }

    private static function fields(array $data, array $allowed, string $context): void
    {
        if (array_is_list($data)) {
            throw new InvalidArgumentException('Invalid '.$context.' object.');
        }
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unexpected '.$context.' field.');
            }
        }
    }

    private static function requireRole(array $step, string $role): void
    {
        $roles = $step['roles'] ?? null;
        if (!is_array($roles) || !array_is_list($roles) ||
            !in_array($role, $roles, true) ||
            count($roles) > 8 ||
            count(array_filter($roles, 'is_string')) !== count($roles)) {
            throw new InvalidArgumentException('Required trusted role is missing: '.$role);
        }
    }

    private static function parseUtc(mixed $raw): DateTimeImmutable
    {
        if (!is_string($raw) ||
            preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $raw) !== 1) {
            throw new InvalidArgumentException('Invalid UTC publication timestamp.');
        }
        $value = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC')
        );
        if (!$value || $value->format('Y-m-d\TH:i:s\Z') !== $raw) {
            throw new InvalidArgumentException('Invalid publication calendar date.');
        }
        return $value;
    }
}
