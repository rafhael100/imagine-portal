<?php

declare(strict_types=1);

namespace App\View\Components\Portal\Preview;

use App\Presentation\PreviewText;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ServiceCard extends Component
{
    public readonly string $name;
    public readonly string $description;
    public readonly string $status;

    public function __construct(string $name, string $description, string $status = 'Somente exemplo visual')
    {
        $this->name = PreviewText::validated($name, 240);
        $this->description = PreviewText::validated($description, 1600);
        $this->status = PreviewText::validated($status, 200);
    }

    public function render(): View
    {
        return view('components.portal.preview.service-card');
    }
}
