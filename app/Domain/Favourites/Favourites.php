<?php

declare(strict_types=1);

namespace App\Domain\Favourites;

use App\Models\Favourite;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Product;

/**
 * The signed-in customer's favourite products. Scoped to the request: the ids are read once,
 * however many product cards ask, and kept current by toggle(). Guests (and staff signed in to
 * Lunar, who are guests on the storefront) have none and cannot save any.
 */
final class Favourites
{
    /** @var array<int, true>|null */
    private ?array $ids = null;

    private function user(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /** Whether the visitor can save favourites at all: only a signed-in customer can. */
    public function available(): bool
    {
        return $this->user() !== null;
    }

    public function has(int $productId): bool
    {
        return isset($this->all()[$productId]);
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Saves the product, or removes it when it is already saved. Only a published product can
     * be added; any saved product can be removed. Returns whether it is now a favourite.
     */
    public function toggle(Product $product): bool
    {
        $user = $this->user() ?? throw new \LogicException('Only a signed-in customer has favourites.');

        return DB::transaction(function () use ($user, $product): bool {
            $existing = Favourite::query()->where('user_id', $user->id)->where('product_id', $product->id)->first();

            if ($existing !== null) {
                $existing->delete();
                unset($this->ids[$product->id]);

                return false;
            }

            if ($product->status !== 'published') {
                return false;
            }

            // A double click must not fail on the unique key.
            Favourite::query()->firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id]);
            $this->ids = null;

            return true;
        });
    }

    /**
     * @return array<int, true>
     */
    private function all(): array
    {
        $user = $this->user();

        if ($user === null) {
            return [];
        }

        return $this->ids ??= Favourite::query()
            ->where('user_id', $user->id)
            ->pluck('product_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /**
     * The customer's published favourites, newest first, with what a product card needs.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        $user = $this->user();

        if ($user === null) {
            return new Collection;
        }

        return Product::query()
            ->where('status', 'published')
            ->join('favourites', 'favourites.product_id', '=', 'lunar_products.id')
            ->where('favourites.user_id', $user->id)
            ->orderByDesc('favourites.id')
            ->select('lunar_products.*')
            ->with(['brand', 'defaultUrl', 'thumbnail', 'variants.prices', 'variants.values.option'])
            ->get();
    }
}
