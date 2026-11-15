<?php

namespace App\Domain\PerformanceDashboard;

/** Synthetic indicators only; the commercial price and media spend are unknown. */
enum DemoMetricKey: string
{
    case Impressions = 'demo_impressions';
    case Clicks = 'demo_clicks';
    case Leads = 'demo_leads';

    public function label(): string
    {
        return match ($this) {
            self::Impressions => 'Impressões',
            self::Clicks => 'Cliques',
            self::Leads => 'Leads',
        };
    }
}
