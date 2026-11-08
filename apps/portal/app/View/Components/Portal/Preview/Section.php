<?php

declare(strict_types=1);

namespace App\View\Components\Portal\Preview;

use App\Presentation\PreviewArea;
use App\Presentation\PreviewText;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Section extends Component
{
    public readonly string $target;
    public readonly string $title;
    public readonly string $summary;
    public readonly string $tag;

    public function __construct(PreviewArea|string $anchor, string $title, string $summary, string $tag = 'Demonstração')
    {
        $this->target = PreviewArea::resolve($anchor)->value;
        $this->title = PreviewText::validated($title, 240);
        $this->summary = PreviewText::validated($summary, 1600);
        $this->tag = PreviewText::validated($tag, 160);
    }

    public function render(): View
    {
        return view('components.portal.preview.section');
    }
}
