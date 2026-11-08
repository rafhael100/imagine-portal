<?php

declare(strict_types=1);

namespace App\View\Components\Portal\Preview;

use App\Presentation\PreviewArea;
use App\Presentation\PreviewText;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Shell extends Component
{
    public readonly string $title;
    public readonly string $heading;
    public readonly string $organizationLabel;
    /** @var list<PreviewArea> */
    public readonly array $areas;

    public function __construct(
        string $title = 'Prévia navegável Imagine',
        string $heading = 'Espaço do cliente · prévia',
        string $organizationLabel = 'Empresa Aurora · fictícia',
    ) {
        $this->title = PreviewText::validated($title, 240);
        $this->heading = PreviewText::validated($heading, 240);
        $this->organizationLabel = PreviewText::validated($organizationLabel, 240);
        $this->areas = PreviewArea::cases();
    }

    public function render(): View
    {
        return view('components.portal.preview.shell');
    }
}
