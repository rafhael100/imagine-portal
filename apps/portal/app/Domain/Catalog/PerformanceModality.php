<?php

namespace App\Domain\Catalog;

enum PerformanceModality: string
{
    case SingleChannel = 'single_channel';
    case Multichannel = 'multichannel';
    case ExpandedOperation = 'expanded_operation';
}
