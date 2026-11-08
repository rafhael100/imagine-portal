<?php

declare(strict_types=1);

namespace App\View\Components\Portal\Preview;

use App\Presentation\PreviewText;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Metric extends Component
{
    public readonly string $label;
    public readonly string $value;
    public readonly string $detail;

    public function __construct(string $label, string $value, string $detail = 'Valor exclusivamente fictício')
    {
        $this->label = PreviewText::validated($label, 160);
        $this->value = PreviewText::validated($value, 80);
        $this->detail = PreviewText::validated($detail, 800);
    }

    public function render(): View
    {
        return view('components.portal.preview.metric');
    }
}
