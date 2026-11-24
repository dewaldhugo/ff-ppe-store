{{-- "Shop by brand": logo tiles under the trust strip, each a link to that brand's products (App\Domain\Catalogue\Home\HomeBrandStrip, config/homepage.php). Left out when there is no brand to show. --}}
@if ($tiles !== [])
    <section class="py-12 sm:py-16" aria-labelledby="home-brands" data-home-brands>
        <x-ui.container>
            <x-ui.section-heading id="home-brands" :title="(string) config('homepage.brands.title', 'Shop by brand')" />

            {{-- A bordered grid, as on /brands: the ul draws the top and left edges, every cell the right and bottom. --}}
            <ul class="mt-8 grid grid-cols-3 border-l border-t border-border sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($tiles as $tile)
                    <li class="border-b border-r border-border" data-home-brand="{{ $tile->slug }}">
                        <a href="{{ route('brands.show', $tile->slug) }}" class="flex aspect-[3/2] w-full items-center justify-center bg-border/40 p-1 text-center text-ink transition-colors hover:bg-white" title="{{ $tile->name }}">
                            @if ($tile->logoUrl !== null)
                                <img src="{{ $tile->logoUrl }}" alt="" loading="lazy" decoding="async" width="360" height="240" class="h-full w-full object-contain">
                            @else
                                <span aria-hidden="true" class="line-clamp-3 break-words px-2 text-sm font-semibold leading-tight">{{ $tile->name }}</span>
                            @endif

                            {{-- A logo has no visible label, so the name is for screen readers (and the tooltip). --}}
                            <span class="sr-only">{{ $tile->name }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                <x-ui.button-outline :href="route('brands')" shape="md" data-home-brands-all>View all brands</x-ui.button-outline>
            </div>
        </x-ui.container>
    </section>
@endif
