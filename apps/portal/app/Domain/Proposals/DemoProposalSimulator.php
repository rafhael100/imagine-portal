<?php

namespace App\Domain\Proposals;

use App\Domain\Catalog\OfferingStatus;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Catalog\PerformanceOffering;
use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * Deterministic mock; does not query prices, calculate taxes, create orders,
 * accept terms, grant access or invoke any external provider.
 */
final readonly class DemoProposalSimulator
{
    /**
     * @param list<DemoAddonSelection> $requestedAddons
     */
    public function present(
        DemoProposalId $proposalId,
        OrganizationId $organizationId,
        PerformanceOffering $offering,
        string $demoTermsVersion,
        array $requestedAddons = [],
    ): DemoProposalSnapshot {
        if ($offering->status === OfferingStatus::Draft) {
            throw new InvalidArgumentException('Draft offering cannot be presented.');
        }

        if (preg_match('/\Ademo-v[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D', $demoTermsVersion) !== 1) {
            throw new InvalidArgumentException('Only versioned demo terms may be shown.');
        }

        if (count($requestedAddons) > 8) {
            throw new InvalidArgumentException('Too many synthetic add-on selections.');
        }

        $addOnCodes = [];

        foreach ($requestedAddons as $addon) {
            if (! $addon instanceof DemoAddonSelection) {
                throw new InvalidArgumentException('All add-ons must be synthetic selection objects.');
            }

            if (isset($addOnCodes[$addon->code])) {
                throw new InvalidArgumentException('Duplicate synthetic add-on code.');
            }

            $addOnCodes[$addon->code] = true;
        }

        $sortedAddons = $requestedAddons;
        usort($sortedAddons, static fn (DemoAddonSelection $a, DemoAddonSelection $b): int => strcmp($a->code, $b->code));

        $channels = $offering->channels;
        usort($channels, static fn (PerformanceChannel $a, PerformanceChannel $b): int => strcmp($a->value, $b->value));

        return new DemoProposalSnapshot(
            $proposalId,
            $organizationId,
            $offering->id,
            $offering->version,
            $offering->modality,
            $channels,
            $sortedAddons,
            $demoTermsVersion,
        );
    }
}
