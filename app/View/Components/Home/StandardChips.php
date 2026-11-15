<?php

declare(strict_types=1);

namespace App\View\Components\Home;

use App\Domain\Catalogue\Home\HomeStandardChips;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class StandardChips extends Component
{
    public function __construct(private readonly HomeStandardChips $chips) {}

    public function render(): View
    {
        return view('components.home.standard-chips', ['chips' => $this->chips->chips()]);
    }
}
