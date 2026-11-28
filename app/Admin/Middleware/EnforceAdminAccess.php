<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Admin\ReportsAccess;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a member of staff out of the admin pages their permissions do not cover.
 *
 * Lunar hides a resource from the menu when the staff member lacks its permission (`sales:manage-orders` for Orders, and so on),
 * but with Filament 4 nothing stops the same person opening the page by its address: Filament's own `canViewAny()` does not ask
 * Lunar's permission. This closes that on every panel route:
 *
 * - A resource of Lunar's that has a permission (`BaseResource::can()`) answers 403 to staff without it, and so does any resource
 *   of the store's own that refuses (Invoices, through ReportsAccess).
 * - A Reports user (`reports:view` and nothing else, ReportsAccess::isReportsOnly) may only open the dashboard, Invoices, Financial
 *   reporting and their own profile and sign out. Everything else in the panel is 403, including pages Lunar gives no permission
 *   (such as Taxes), which a person who is only there for the figures has no need of.
 *
 * An admin is never held back. Requests that are not panel routes (Livewire's own endpoints, which only carry a page that was
 * already allowed to load) are left alone, and so are people who have not signed in (the panel's own sign-in check handles them).
 */
final class EnforceAdminAccess
{
    /** The route name prefix of the panel's pages and resources. */
    private const string PREFIX = 'filament.lunar.';

    /** What a Reports user may open (route name prefixes, after the panel prefix). */
    private const array REPORTS_ONLY_MAY_OPEN = [
        'pages.dashboard',
        'pages.financial-reporting',
        'resources.invoices.',
        'auth.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        $name = (string) $request->route()?->getName();

        if ($user === null || ! str_starts_with($name, self::PREFIX) || ($user->admin ?? false)) {
            return $next($request);
        }

        $route = substr($name, strlen(self::PREFIX));

        if (ReportsAccess::isReportsOnly($user) && ! $this->reportsOnlyMayOpen($route)) {
            abort(403);
        }

        $resource = $this->resourceOf($route);

        if ($resource !== null && ! $this->mayViewAny($resource)) {
            abort(403);
        }

        return $next($request);
    }

    private function reportsOnlyMayOpen(string $route): bool
    {
        foreach (self::REPORTS_ONLY_MAY_OPEN as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resource class a route name belongs to (`resources.orders.index` is the orders resource), or null for a page or a
     * route of another kind.
     *
     * @return class-string|null
     */
    private function resourceOf(string $route): ?string
    {
        if (! str_starts_with($route, 'resources.')) {
            return null;
        }

        $slug = explode('.', $route)[1] ?? '';

        foreach (Filament::getPanel('lunar')->getResources() as $resource) {
            if ($resource::getSlug() === $slug) {
                return $resource;
            }
        }

        return null;
    }

    /** @param  class-string  $resource */
    private function mayViewAny(string $resource): bool
    {
        // `can()` is Lunar's own check by permission (true for a resource that names none); canViewAny() is Filament's, which
        // the store's own resources override.
        $lunar = ! method_exists($resource, 'can') || $resource::can('viewAny');

        return $lunar && $resource::canViewAny();
    }
}
