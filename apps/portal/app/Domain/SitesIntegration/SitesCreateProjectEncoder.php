<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use DomainException;
use InvalidArgumentException;

/**
 * Synthetic CreateProject v1 envelope builder, no network, queue or external effect.
 * This does not verify legitimacy of a snapshot; that is a future trusted source's duty.
 */
final class SitesCreateProjectEncoder
{
    public function __construct(
        private readonly PortalSiteContractSource $source = new DenyingPortalSiteContractSource(),
    ) {}

    /** @return array<string, int|string> */
    public function build(string $orderRef, string $operationId, string $idempotencyKey): array
    {
        foreach ([$orderRef, $operationId, $idempotencyKey] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
                throw new InvalidArgumentException('Unsafe Portal-Sites command reference.');
            }
        }
        $snapshot = $this->source->findAuthorizedSiteOrder($orderRef);
        if ($snapshot === null || $snapshot->orderRef !== $orderRef) {
            throw new DomainException('No verified eligible official Site contract.');
        }
        return [
            'schemaVersion' => '1',
            'operationId' => $operationId,
            'tenantRef' => $snapshot->tenantRef,
            'orderRef' => $snapshot->orderRef,
            'contractRef' => $snapshot->contractRef,
            'contractRevision' => $snapshot->contractRevision,
            'productCode' => $snapshot->productCode,
            'idempotencyKey' => $idempotencyKey,
        ];
    }
}
