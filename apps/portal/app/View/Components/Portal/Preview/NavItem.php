<?php

declare(strict_types=1);

namespace App\View\Components\Portal\Preview;

use App\Presentation\PreviewArea;
use App\Presentation\PreviewText;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class NavItem extends Component
{
    public readonly string $target;
    public readonly string $label;

    public function __construct(PreviewArea|string $anchor, string $label)
    {
        $this->target = PreviewArea::resolve($anchor)->value;
        $this->label = PreviewText::validated($label, 160);
    }

    public function render(): View
    {
        return view('components.portal.preview.nav-item');
    }
}
