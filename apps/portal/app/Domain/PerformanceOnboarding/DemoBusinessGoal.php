<?php

namespace App\Domain\PerformanceOnboarding;

/** A controlled demonstration vocabulary. No real briefing content or PII. */
enum DemoBusinessGoal: string
{
    case LeadGeneration = 'demo_leads';
    case BrandAwareness = 'demo_awareness';
    case Sales = 'demo_sales';
}
