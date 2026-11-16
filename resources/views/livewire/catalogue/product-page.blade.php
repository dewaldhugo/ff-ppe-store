<article>
    <div class="grid gap-8 lg:grid-cols-[calc((100%_-_2rem)*0.4)_1fr]">
        {{-- Gallery: the selected colour's images. --}}
        {{--
            Choosing a thumbnail shows that image in the main frame. The key
            changes with the colour, so a new colour starts on its first image.
        --}}
        <section
            aria-label="Product images"
            data-testid="gallery"
            wire:key="gallery-{{ $images === [] ? 'none' : $images[0]->id }}"
            x-data="{ active: 0, images: @js(array_map(fn ($image) => $image->getUrl('medium'), $images)) }"
        >
            @if ($images !== [])
                <div class="aspect-square overflow-hidden rounded border border-border bg-surface-muted">
                    <img
                        src="{{ $images[0]->getUrl('medium') }}"
                        x-bind:src="images[active]"
                        alt="{{ $product->translateAttribute('name') }}{{ $selection->selectedName('colour') ? ' in '.$selection->selectedName('colour') : '' }}"
                        width="800"
                        height="800"
                        class="h-full w-full object-contain"
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
                                        class="aspect-square w-full rounded object-contain"
                                    >
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>

        <section aria-labelledby="product-heading">
            <p class="text-sm uppercase tracking-wide text-ink-muted">{{ $product->brand?->name }}</p>
            <h1 id="product-heading" class="text-3xl font-bold tracking-tight">{{ $product->translateAttribute('name') }}</h1>
            <p class="text-lg text-ink-muted">{{ $product->translateAttribute('title') }}</p>

            @if (filled($product->translateAttribute('short_description')))
                <p class="mt-3 text-ink-muted">{{ $product->translateAttribute('short_description') }}</p>
            @endif

            @if ($price)
                <p class="mt-4" data-testid="price" data-price>
                    @if ($pricesVary)
                        <span class="text-ink-muted">From</span>
                    @endif
                    <span class="text-2xl font-bold">{{ $price->formatted() }}</span>
                    <span class="text-sm text-ink-muted">{{ $price->suffix() }}</span>
                </p>
            @endif

            <p class="mt-2 text-sm font-semibold" data-testid="stock" aria-live="polite">{{ $stockMessage }}</p>

            <form wire:submit="addToCart" class="mt-6 space-y-5" novalidate>
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
                                    <button
                                        type="button"
                                        wire:key="{{ $option['handle'] }}-{{ $value['key'] }}"
                                        wire:click="choose('{{ $option['handle'] }}', '{{ $value['key'] }}')"
                                        aria-pressed="{{ $value['selected'] ? 'true' : 'false' }}"
                                        data-value="{{ $value['key'] }}"
                                        @disabled(! $value['available'])
                                        @if (! $value['available']) title="Out of stock" @endif
                                        @class([
                                            'flex min-w-12 items-center gap-2 rounded border px-3 py-2 text-sm',
                                            'border-brand-blue bg-brand-blue text-white' => $value['selected'],
                                            'border-border-strong bg-white hover:border-brand-blue' => ! $value['selected'] && $value['available'],
                                            'cursor-not-allowed border-border bg-surface-muted text-ink-muted line-through' => ! $value['available'],
                                        ])
                                    >
                                        @if ($option['handle'] === 'colour')
                                            <span
                                                aria-hidden="true"
                                                class="inline-block h-4 w-4 rounded-full border border-border-strong"
                                                @if (filled($value['meta']['hex'] ?? null)) style="background-color: #{{ $value['meta']['hex'] }}" @endif
                                            ></span>
                                        @endif

                                        <span>{{ $value['name'] }}</span>

                                        @if ($option['handle'] === 'shoe_size' && isset($euSizes[$value['key']]))
                                            <span class="text-xs opacity-75">EU {{ $euSizes[$value['key']] }}</span>
                                        @endif

                                        @if (! $value['available'])
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

                <div>
                    <label for="quantity" class="block font-semibold text-ink">Quantity</label>
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
                            'mt-2 w-28 rounded border px-3 py-2 text-lg',
                            'border-border-strong' => $quantityError === null,
                            'border-danger' => $quantityError !== null,
                        ])
                    >

                    @if ($quantityError !== null)
                        <p id="error-quantity" role="alert" class="mt-2 text-sm font-semibold text-danger">{{ $quantityError }}</p>
                    @endif
                </div>

                <button
                    type="submit"
                    @disabled(! $canAdd)
                    data-add-to-cart
                    class="w-full rounded-md bg-brand-blue px-6 py-3 text-lg font-bold text-white transition-colors hover:bg-brand-navy disabled:cursor-not-allowed disabled:bg-disabled-surface disabled:text-disabled-ink sm:w-auto"
                >
                    {{ $addLabel }}
                </button>

                @if ($added)
                    <p role="status" class="rounded border border-success bg-success-surface p-3 text-sm text-success">
                        Online ordering opens soon. Your selection is available.
                    </p>
                @endif
            </form>

            @if (filled($product->attr('key_points')))
                <ul class="mt-6 list-disc space-y-1 pl-5 text-ink-muted">
                    @foreach ($product->attr('key_points') as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if ($paragraphs !== [])
        <section aria-labelledby="description-heading" class="mt-10 max-w-3xl">
            <h2 id="description-heading" class="text-xl font-bold">Description</h2>
            @foreach ($paragraphs as $paragraph)
                <p class="mt-3 text-ink-muted">{{ $paragraph }}</p>
            @endforeach
        </section>
    @endif

    @if ($specification !== [])
        <section aria-labelledby="specification-heading" class="mt-10 max-w-3xl">
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

    @if ($videoUrl)
        <section aria-labelledby="video-heading" class="mt-10 max-w-3xl">
            <h2 id="video-heading" class="text-xl font-bold">Video</h2>
            <div class="mt-3 aspect-video">
                <iframe
                    src="{{ $videoUrl }}"
                    title="{{ $product->translateAttribute('name') }} video"
                    loading="lazy"
                    allow="accelerometer; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    class="h-full w-full rounded border border-border"
                ></iframe>
            </div>
        </section>
    @endif

    @if ($related !== [])
        <section aria-labelledby="related-heading" class="mt-10" data-testid="related">
            <h2 id="related-heading" class="text-xl font-bold">Related products</h2>
            <ul class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($related as $relatedProduct)
                    @php $relatedPrice = $priceDisplay->fromPrice($relatedProduct); @endphp
                    <li wire:key="related-{{ $relatedProduct->id }}" class="relative rounded border border-border p-3">
                        @if ($relatedProduct->thumbnail)
                            <img src="{{ $relatedProduct->thumbnail->getUrl('thumb') }}" alt="" loading="lazy" width="300" height="300" class="aspect-square w-full rounded bg-surface-muted object-contain">
                        @endif
                        <p class="mt-2 text-xs uppercase tracking-wide text-ink-muted">{{ $relatedProduct->brand?->name }}</p>
                        <a href="{{ route('product', $relatedProduct->defaultUrl?->slug ?? $relatedProduct->id) }}" class="font-semibold after:absolute after:inset-0 hover:underline">
                            {{ $relatedProduct->translateAttribute('name') }}
                        </a>
                        @if ($relatedPrice)
                            <p class="text-sm" data-price><span class="font-bold">{{ $relatedPrice->formatted() }}</span> <span class="text-xs text-ink-muted">{{ $relatedPrice->suffix() }}</span></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</article>
