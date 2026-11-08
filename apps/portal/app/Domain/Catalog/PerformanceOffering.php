<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Immutable, in-memory catalog definition for P02-A only.
 *
 * Null quantitative limits mean "not homologated", never zero or unlimited.
 * This object has no price, payment, contract or provisioning capability.
 */
final readonly class PerformanceOffering
{
    /**
     * @param list<PerformanceChannel> $channels
     */
    public function __construct(
        public OfferingId $id,
        public ProductCode $product,
        public string $version,
        public string $description,
        public OfferingStatus $status,
        public PerformanceModality $modality,
        public array $channels,
        public ?int $campaignLimit = null,
        public ?int $adGroupLimit = null,
    ) {
        if ($product !== ProductCode::PerformanceAI) {
            throw new InvalidArgumentException('Only Performance AI offerings belong in this catalog.');
        }

        if (preg_match('/\Av[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D', $version) !== 1) {
            throw new InvalidArgumentException('An explicit semantic-like offering version is required.');
        }

        if (trim($description) === '' || strlen($description) > 500) {
            throw new InvalidArgumentException('Description must have 1 to 500 bytes.');
        }

        if ($campaignLimit !== null && $campaignLimit <= 0) {
            throw new InvalidArgumentException('Known campaign limit must be positive.');
        }

        if ($adGroupLimit !== null && $adGroupLimit <= 0) {
            throw new InvalidArgumentException('Known ad-group limit must be positive.');
        }

        $channelValues = [];

        foreach ($channels as $channel) {
            if (! $channel instanceof PerformanceChannel) {
                throw new InvalidArgumentException('Channels must be enumerated PerformanceChannel values.');
            }

            if (in_array($channel->value, $channelValues, true)) {
                throw new InvalidArgumentException('Duplicate channel.');
            }

            $channelValues[] = $channel->value;
        }

        if (count($channels) < 1 || count($channels) > 2) {
            throw new InvalidArgumentException('A modality must contain one or two valid channels.');
        }

        if ($modality === PerformanceModality::SingleChannel && count($channels) !== 1) {
            throw new InvalidArgumentException('Single channel must select exactly one channel.');
        }

        if ($modality === PerformanceModality::Multichannel
            && (! in_array(PerformanceChannel::GoogleAds->value, $channelValues, true)
                || ! in_array(PerformanceChannel::MetaAds->value, $channelValues, true))) {
            throw new InvalidArgumentException('Multichannel requires Google Ads and Meta Ads.');
        }

        // ExpandedOperation is a capacity model under review, not a third ad network.
        // Its configurable commercial rules remain unapproved in this phase.
    }
}
