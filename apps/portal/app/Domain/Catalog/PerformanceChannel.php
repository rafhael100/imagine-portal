<?php

namespace App\Domain\Catalog;

enum PerformanceChannel: string
{
    case GoogleAds = 'google_ads';
    case MetaAds = 'meta_ads';
}
