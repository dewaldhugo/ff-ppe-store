<?php

declare(strict_types=1);

namespace App\Admin\Pages;

use App\Domain\Orders\FinancialReport;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Financial reporting, one page a year (/admin/financial-reporting/2026): revenue, gross profit, units sold and the margin as
 * tiles, then monthly unit sales, monthly turnover before VAT, units per customer, monthly gross profit and the top styles sold.
 * The figures are in FinancialReport. The menu has a Financial reporting item with a year under it for every year that has an
 * order, up to this year. Seen by the staff who see orders (sales:manage-orders), like Invoices.
 */
final class FinancialReporting extends Page
{
    protected static ?string $slug = 'financial-reporting';

    protected static ?string $title = 'Financial reporting';

    protected string $view = 'admin.financial-reporting';

    public int $year;

    public static function getRoutePath(Panel $panel): string
    {
        return '/financial-reporting/{year?}';
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('sales:manage-orders') === true;
    }

    /**
     * The parent item, then a year under it for each year with orders.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $group = __('lunarpanel::global.sections.sales');
        $parent = 'Financial reporting';

        $items = [
            NavigationItem::make($parent)
                ->key('financial-reporting')
                ->group($group)
                ->icon('heroicon-o-banknotes')
                ->sort(3)
                ->url(self::getUrl(['year' => now()->year])),
        ];

        foreach (app(FinancialReport::class)->years() as $year) {
            $items[] = NavigationItem::make((string) $year)
                ->key('financial-reporting-'.$year)
                ->group($group)
                ->parentItem($parent)
                ->sort(3)
                ->activeWhen(fn (): bool => request()->routeIs(self::getRouteName()) && (int) request()->route('year', now()->year) === $year)
                ->url(self::getUrl(['year' => $year]));
        }

        return $items;
    }

    public function mount(int|string|null $year = null): void
    {
        $year = $year === null || $year === '' ? now()->year : (int) $year;

        abort_unless($year >= 2000 && $year <= now()->year, 404);

        $this->year = $year;
    }

    public function getHeading(): string|Htmlable
    {
        return 'Financial reporting | '.$this->year;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $reports = app(FinancialReport::class);

        return [
            'report' => $reports->forYear($this->year),
            'money' => $reports->money(...),
        ];
    }
}
