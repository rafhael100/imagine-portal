<?php

namespace App\Domain\PerformanceDashboard;

use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/** Read-only card; not an advertising campaign, account or remote ID. */
final readonly class DemoCampaignCard
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public PerformanceChannel $channel,
        public string $title,
        public DemoCampaignStage $stage = DemoCampaignStage::MockDraft,
    ) {
        if (preg_match('/\Ademo_campaign_[a-z0-9_]{1,64}\z/D', $id) !== 1
            || trim($title) === ''
            || strlen($title) > 100) {
            throw new InvalidArgumentException('Invalid synthetic campaign card.');
        }
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            PerformanceChannel::GoogleAds => 'Google Ads',
            PerformanceChannel::MetaAds => 'Meta (Instagram e/ou Facebook)',
        };
    }
}
