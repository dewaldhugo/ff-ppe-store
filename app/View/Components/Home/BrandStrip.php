<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeBrandStrip;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class BrandStrip extends Component
{
    public function __construct(private readonly HomeBrandStrip $brands) {}

    public function render(): View
    {
        return view('components.home.brand-strip', ['tiles' => $this->brands->tiles()]);
    }
}
