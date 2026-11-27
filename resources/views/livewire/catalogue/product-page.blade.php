<article>
    <x-storefront.breadcrumbs :items="$breadcrumbs" class="mb-4" />

    <div class="grid gap-8 lg:grid-cols-2" data-product-top>
        {{-- Gallery: the selected colour's images. --}}
        {{--
            Choosing a thumbnail shows that image in the main frame. The key
            changes with the colour, so a new colour starts on its first image.
        --}}
        {{-- The image column: the gallery, and the video under its thumbnails when the product has one. --}}
        <div class="min-w-0">
        <section
            aria-label="Product images"
            data-testid="gallery"
            wire:key="gallery-{{ $images === [] ? 'none' : $images[0]->id }}"
            x-data="{ active: 0, images: @js(array_map(fn ($image) => $image->getUrl('medium'), $images)) }"
        >
            @if ($images !== [])
                <div class="aspect-square overflow-hidden rounded border border-border bg-white" data-gallery-frame>
                    <img
                        src="{{ $images[0]->getUrl('medium') }}"
                        x-bind:src="images[active]"
                        alt="{{ $product->translateAttribute('name') }}{{ $selection->selectedName('colour') ? ' in '.$selection->selectedName('colour') : '' }}"
                        width="800"
                        height="800"
                        class="h-full w-full object-contain p-2.5"
                        data-gallery-main
                        data-colour="{{ $images[0]->getCustomProperty('colour') }}"
                    >
                </div>

                @if (count($images) > 1)
                    <ul class="mt-3 grid grid-cols-5 gap-2">
                        @foreach ($images as $index => $image)
                            <li wire:key="image-{{ $image->id }}">
                                <button
                                    type="button"
                                    class="block w-full rounded border-2 border-border focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue"
                                    x-on:click="active = {{ $index }}"
                                    x-bind:class="active === {{ $index }} ? 'border-brand-blue' : 'border-border'"
                                    x-bind:aria-current="active === {{ $index }} ? 'true' : null"
                                    aria-label="Show image {{ $index + 1 }} of {{ count($images) }}: {{ $image->getCustomProperty('role') }} view"
                                    data-gallery-thumb="{{ $index }}"
                                    @if ($index === 0) aria-current="true" @endif
                                >
                                    <img
                                        src="{{ $image->getUrl('thumb') }}"
                                        alt=""
                                        loading="lazy"
                                        width="300"
                                        height="300"
                                        class="aspect-square w-full rounded object-contain p-[5px]"
                                    >
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>

        </div>

        <section aria-labelledby="product-heading">
            {{-- The brand's logo, or its name when there is no logo, linking to its products. --}}
            @if ($brand !== null)
                <a href="{{ route('brands.show', $brand['slug']) }}" class="mb-3 inline-block" data-product-brand="{{ $brand['slug'] }}">
                    @if ($brand['logoUrl'] !== null)
                        <img src="{{ $brand['logoUrl'] }}" alt="{{ $brand['name'] }}" width="100" class="h-auto w-[100px]" data-product-brand-logo>
                    @else
                        <span class="text-sm uppercase tracking-wide text-ink-muted hover:underline">{{ $brand['name'] }}</span>
                    @endif
                </a>
            @endif
            {{-- The name, with the safety class beside it at 20% smaller type on the light teal of the menu bar, like the vendor's own page. --}}
            <h1 id="product-heading" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-3xl font-bold leading-tight tracking-tight">
                {{ $product->translateAttribute('name') }}
                @if (($safetyStandard['class'] ?? null) !== null)
                    <span class="rounded bg-brand-teal-light px-3 text-[1.5rem] text-brand-navy" data-name-class>{{ $safetyStandard['class'] }}</span>
                @endif
            </h1>
            <p class="mt-2 text-lg text-ink-muted">{{ $product->translateAttribute('title') }}</p>

            @if (filled($product->translateAttribute('short_description')))
                <p class="mt-3 text-ink-muted">{{ $product->translateAttribute('short_description') }}</p>
            @endif

            @if ($price)
                <p class="mt-4" data-testid="price" data-price>
                    @if ($pricesVary)
                        <span class="text-ink-muted">From</span>
                    @endif
                    <span class="text-[2.16rem] font-semibold text-brand-navy">{{ $price->formatted() }}</span>
                    <span class="text-sm text-ink-muted">{{ $price->suffix() }}</span>
                </p>
            @endif

            <p class="mt-2 text-sm font-semibold" data-testid="stock" aria-live="polite">{{ $stockMessage }}</p>

            {{-- Where it can be delivered, only while EU delivery is switched on. --}}
            <x-storefront.ships-to :product="$product" class="mt-2" />

            <form wire:submit="addToCart" id="add-to-cart-form" class="mt-6 space-y-5" novalidate>
                @foreach ($options as $option)
                    @php $isMissing = $missingOption === $option['handle']; @endphp

                    @if ($option['fixed'])
                        {{-- A single value needs no picker. --}}
                        <p data-option="{{ $option['handle'] }}" data-fixed>
                            <span class="font-semibold">{{ $option['name'] }}:</span>
                            {{ $option['values'][0]['name'] }}
                            @if ($option['handle'] === 'shoe_size' && isset($euSizes[$option['values'][0]['key']]))
                                <span class="text-sm text-ink-muted">(EU {{ $euSizes[$option['values'][0]['key']] }})</span>
                            @endif
                            @if (str_ends_with($option['handle'], '_size'))
                                <a href="{{ route('size-guide') }}" class="ml-3 inline-flex items-center rounded border border-border bg-surface-muted px-2.5 py-1 align-middle text-xs font-semibold text-ink-muted hover:bg-border" data-size-guide-link>Size guide</a>
                            @endif
                        </p>
                    @else
                        <fieldset
                            data-option="{{ $option['handle'] }}"
                            @class(['rounded', 'outline outline-2 outline-offset-4 outline-danger' => $isMissing])
                            @if ($isMissing) aria-describedby="error-{{ $option['handle'] }}" @endif
                        >
                            <legend class="font-semibold">
                                {{ $option['name'] }}@if ($selection->selectedName($option['handle'])): <span class="font-normal">{{ $selection->selectedName($option['handle']) }}</span>@endif
                                @if (str_ends_with($option['handle'], '_size'))
                                    <a href="{{ route('size-guide') }}" class="ml-3 inline-flex items-center rounded border border-border bg-surface-muted px-2.5 py-1 align-middle text-xs font-semibold text-ink-muted hover:bg-border" data-size-guide-link>Size guide</a>
                                @endif
                            </legend>

                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($option['values'] as $value)
                                    @php
                                        // Guests see every size, in stock or not, and cannot pick one.
                                        $sizeReadOnly = ! $showPrices && str_ends_with($option['handle'], '_size');
                                        $available = $showPrices ? $value['available'] : true;
                                    @endphp

                                    @if ($sizeReadOnly)
                                        <span
                                            wire:key="{{ $option['handle'] }}-{{ $value['key'] }}"
                                            data-value="{{ $value['key'] }}"
                                            data-size-readonly
                                            class="flex min-h-11 min-w-12 cursor-default items-center justify-center gap-2 rounded border border-border-strong bg-white px-3.5 py-2 text-base"
                                        >
                                            <span>{{ $value['name'] }}</span>

                                            @if ($option['handle'] === 'shoe_size' && isset($euSizes[$value['key']]))
                                                <span class="text-sm opacity-75">EU {{ $euSizes[$value['key']] }}</span>
                                            @endif
                                        </span>
                                        @continue
                                    @endif

                                    <button
                                        type="button"
                                        wire:key="{{ $option['handle'] }}-{{ $value['key'] }}"
                                        wire:click="choose('{{ $option['handle'] }}', '{{ $value['key'] }}')"
                                        aria-pressed="{{ $value['selected'] ? 'true' : 'false' }}"
                                        data-value="{{ $value['key'] }}"
                                        @disabled(! $available)
                                        @if (! $available) title="Out of stock" @endif
                                        @class([
                                            'flex min-h-11 min-w-12 items-center justify-center gap-2 rounded border px-3.5 py-2 text-base',
                                            'border-brand-blue bg-brand-blue text-white' => $value['selected'],
                                            'border-border-strong bg-white hover:border-brand-blue' => ! $value['selected'] && $available,
                                            'cursor-not-allowed border-border bg-surface-muted text-ink-muted line-through' => ! $available,
                                        ])
                                    >
                                        @if ($option['handle'] === 'colour')
                                            <x-ui.swatch
                                                :hexes="\App\Domain\Catalogue\Options\ColourSwatch::hexes($value['name'], $value['meta']['hex'] ?? null)"
                                                aria-hidden="true"
                                                class="h-4 w-4"
                                            />
                                        @endif

                                        <span>{{ $value['name'] }}</span>

                                        @if ($option['handle'] === 'shoe_size' && isset($euSizes[$value['key']]))
                                            <span class="text-sm opacity-75">EU {{ $euSizes[$value['key']] }}</span>
                                        @endif

                                        @if (! $available)
                                            <span class="sr-only">(out of stock)</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>

                            @if ($isMissing)
                                <p id="error-{{ $option['handle'] }}" role="alert" class="mt-2 text-sm font-semibold text-danger">
                                    {{ $missingPrompt }}
                                </p>
                            @endif
                        </fieldset>
                    @endif
                @endforeach

                {{-- Guests see no prices, so they cannot order either: no quantity or add to cart. --}}
                @if ($showPrices)
                <div>
                    <label for="quantity" class="block font-semibold text-ink">Quantity</label>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <input
                            id="quantity"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            step="1"
                            @if ($maxQuantity !== null) max="{{ $maxQuantity }}" @endif
                            wire:model.live.debounce.300ms="quantity"
                            @if ($quantityError !== null) aria-invalid="true" aria-describedby="error-quantity" @endif
                            data-quantity
                            @class([
                                'h-11 w-28 rounded border px-3 text-lg',
                                'border-border-strong' => $quantityError === null,
                                'border-danger' => $quantityError !== null,
                            ])
                        >

                        {{-- Once every option is chosen: the stock of that exact variant, which is also the input's max. --}}
                        @if ($variant !== null)
                            {{-- The figure alone, with no icon, border or fill: navy when comfortably in stock, red when low or out. --}}
                            <p
                                @class([
                                    'inline-flex items-center gap-2 text-base font-bold',
                                    'text-brand-navy' => $maxQuantity !== null && ! $lowStock,
                                    'text-danger' => $maxQuantity === null || $lowStock,
                                ])
                                data-stock-available
                                aria-live="polite"
                            >
                                {{ $maxQuantity !== null ? $maxQuantity.' available' : 'Out of stock' }}
                            </p>
                        @endif
                    </div>

                    @if ($quantityError !== null)
                        <p id="error-quantity" role="alert" class="mt-2 text-sm font-semibold text-danger">{{ $quantityError }}</p>
                    @endif
                </div>

                @endif
            </form>

            {{--
                Add to cart and Add to favourites, side by side and the same size. Neither can sit inside the other's form (forms
                cannot nest), so the cart button is outside its form and joined to it with the form attribute: it submits the
                cart form, and Enter in the quantity field still does. The label never changes; the button is disabled until
                every option is chosen and a quantity that fits the stock is entered. Guests see neither.
            --}}
            @php $canFavourite = app(\App\Domain\Favourites\Favourites::class)->available(); @endphp

            @if ($showPrices || $canFavourite)
                <div class="mt-5 flex flex-wrap gap-3" data-buy-buttons @if ($canFavourite) data-favourite-wrap @endif>
                    @if ($showPrices)
                        <button
                            type="submit"
                            form="add-to-cart-form"
                            @disabled(! $canAdd)
                            data-add-to-cart
                            class="w-full rounded-md border-2 border-brand-teal bg-brand-teal px-8 py-3 text-lg font-bold text-brand-navy transition-colors hover:border-brand-navy hover:bg-brand-navy hover:text-white disabled:cursor-not-allowed disabled:border-disabled-surface disabled:bg-disabled-surface disabled:text-disabled-ink sm:w-72"
                        >
                            Add to cart
                        </button>
                    @endif

                    <x-storefront.favourite-button :product="$product" />
                </div>
            @endif

            @if ($showPrices)
                @if ($added)
                    <p role="status" class="mt-4 rounded border border-success bg-success-surface p-3 text-sm text-success" data-added-to-cart>
                        {{ $addedQuantity }} added to your cart.
                        <a href="{{ route('cart') }}" class="font-semibold underline">View cart</a>
                    </p>
                @endif

                @if ($cartError !== null)
                    <p role="alert" class="mt-4 rounded border border-danger bg-surface-muted p-3 text-sm font-semibold text-danger" data-cart-error>{{ $cartError }}</p>
                @endif
            @endif

            @if ($keyPoints !== [])
                <ul class="mt-6 list-disc space-y-1 pl-5 text-ink-muted">
                    @foreach ($keyPoints as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            @endif

            <x-storefront.safety-standard :panel="$safetyStandard" />

            <x-storefront.pictograms :pictograms="$pictograms" />

            {{-- The English product sheet for the colour chosen, when the vendor has one. The only document shown. --}}
            @if ($productSheet !== null)
                <p class="mt-6" data-product-sheet>
                    <a href="{{ $productSheet['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-blue underline hover:text-brand-navy">
                        <svg aria-hidden="true" class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" data-pdf-icon><path d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                        Product sheet{{ $productSheet['colour'] !== null ? ', '.$productSheet['colour'] : '' }} (PDF)
                    </a>
                </p>
            @endif

            @if ($industries !== [])
                <section aria-labelledby="industries-heading" class="mt-8" data-industries>
                    <h2 id="industries-heading" class="text-lg font-bold">Industries</h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($industries as $industry)
                            <li>
                                {{-- Each industry is the listing filtered to it. --}}
                                <a href="{{ route('search', ['industry' => [$industry]]) }}" class="inline-block rounded-full border border-border px-3 py-1 text-sm text-ink hover:border-brand-teal hover:bg-surface-muted" data-industry="{{ $industry }}">{{ $industry }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </section>
    </div>

    {{-- A rule across the container, then the description on the left and the specification on the right, 50 / 50. --}}
    @if ($paragraphs !== [] || $specification !== [])
        <hr class="my-10 border-border" data-product-rule>

        <div class="grid gap-10 lg:grid-cols-2" data-product-details>
        @if ($paragraphs !== [])
            <section aria-labelledby="description-heading" data-description>
                <h2 id="description-heading" class="text-xl font-bold">Description</h2>
                @foreach ($paragraphs as $paragraph)
                    <p class="mt-3 text-ink-muted">{{ $paragraph }}</p>
                @endforeach
            </section>
        @endif

        @if ($specification !== [])
        <section aria-labelledby="specification-heading" data-specification>
            <h2 id="specification-heading" class="text-xl font-bold">Specification</h2>
            <table class="mt-3 w-full text-left text-sm" data-testid="specification">
                <tbody>
                    @foreach ($specification as $row)
                        <tr class="border-b border-border">
                            <th scope="row" class="w-1/3 py-2 pr-4 align-top font-semibold">{{ $row['label'] }}</th>
                            <td class="py-2">
                                @if (count($row['values']) === 1)
                                    <span @if ($row['values'][0]['tooltip']) title="{{ $row['values'][0]['tooltip'] }}" @endif>{{ $row['values'][0]['text'] }}</span>
                                @else
                                    <ul class="space-y-1">
                                        @foreach ($row['values'] as $value)
                                            <li @if ($value['tooltip']) title="{{ $value['tooltip'] }}" @endif>{{ $value['text'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif
        </div>
    @endif

    {{-- The videos, the full width of the page container, under the product details: the vendor's own first, then any uploaded in the admin, then those added by web address, each in their order. --}}
    @if ($videoUrl || $uploadedVideos !== [] || $linkedVideos !== [])
        <section aria-labelledby="video-heading" class="mt-10" data-video>
            <h2 id="video-heading" class="text-xl font-bold">{{ ($videoUrl ? 1 : 0) + count($uploadedVideos) + count($linkedVideos) > 1 ? 'Product videos' : 'Product video' }}</h2>

            @if ($videoUrl)
                <div class="mt-4 aspect-video" data-video-vendor>
                    <iframe
                        src="{{ $videoUrl }}"
                        title="{{ $product->translateAttribute('name') }} video"
                        loading="lazy"
                        allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        class="h-full w-full rounded border border-border"
                    ></iframe>
                </div>
            @endif

            @foreach ($uploadedVideos as $index => $video)
                <div class="mt-4 aspect-video" data-video-uploaded>
                    <video controls preload="metadata" playsinline class="h-full w-full rounded border border-border bg-brand-navy" aria-label="{{ $product->translateAttribute('name') }} video {{ $index + 1 }}">
                        <source src="{{ $video['url'] }}" type="{{ $video['type'] }}">
                    </video>
                </div>
            @endforeach

            @foreach ($linkedVideos as $index => $video)
                <div class="mt-4 aspect-video" data-video-linked>
                    @if ($video['kind'] === 'frame')
                        <iframe
                            src="{{ $video['src'] }}"
                            title="{{ $product->translateAttribute('name') }} video {{ $index + 1 }}"
                            loading="lazy"
                            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen
                            referrerpolicy="strict-origin-when-cross-origin"
                            class="h-full w-full rounded border border-border"
                        ></iframe>
                    @else
                        <video controls preload="metadata" playsinline class="h-full w-full rounded border border-border bg-brand-navy" aria-label="{{ $product->translateAttribute('name') }} video {{ $index + 1 }}">
                            <source src="{{ $video['src'] }}" type="{{ $video['type'] }}">
                        </video>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <x-storefront.feature-highlights :tiles="$featureTiles" />

    @if ($related !== [])
        <section aria-labelledby="related-heading" class="mt-10" data-testid="related">
            <h2 id="related-heading" class="text-xl font-bold">Related products</h2>
            <ul class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($related as $relatedProduct)
                    <x-storefront.product-card :product="$relatedProduct" wire:key="related-{{ $relatedProduct->id }}" data-related-product="{{ $relatedProduct->id }}" />
                @endforeach
            </ul>
        </section>
    @endif
</article>
