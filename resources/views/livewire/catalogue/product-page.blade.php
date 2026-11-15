<article>
    <div class="grid gap-8 lg:grid-cols-2">
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
                <div class="aspect-square overflow-hidden rounded border border-slate-200 bg-slate-100">
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
                                    class="block w-full rounded border-2 border-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900"
                                    x-on:click="active = {{ $index }}"
                                    x-bind:class="active === {{ $index }} ? 'border-slate-900' : 'border-slate-200'"
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
            <p class="text-sm uppercase tracking-wide text-slate-500">{{ $product->brand?->name }}</p>
            <h1 id="product-heading" class="text-3xl font-bold tracking-tight">{{ $product->translateAttribute('name') }}</h1>
            <p class="text-lg text-slate-700">{{ $product->translateAttribute('title') }}</p>

            @if (filled($product->translateAttribute('short_description')))
                <p class="mt-3 text-slate-700">{{ $product->translateAttribute('short_description') }}</p>
            @endif

            @if ($price)
                <p class="mt-4" data-testid="price">
                    @if ($pricesVary)
                        <span class="text-slate-600">From</span>
                    @endif
                    <span class="text-2xl font-bold">{{ $price->formatted() }}</span>
                    <span class="text-sm text-slate-600">{{ $price->suffix() }}</span>
                    @if ($price->compareFormatted())
                        <s class="ml-2 text-slate-500"><span class="sr-only">Was</span> {{ $price->compareFormatted() }}</s>
                    @endif
                </p>

                @if ($price->vatAppliesForEmployers)
                    <p class="mt-1 text-sm text-slate-600" data-testid="vat-note">
                        Zero rated for VAT when bought for your own use. VAT at 20% applies when buying for employees.
                    </p>
                @endif
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
                                <span class="text-sm text-slate-600">(EU {{ $euSizes[$option['values'][0]['key']] }})</span>
                            @endif
                        </p>
                    @else
                        <fieldset
                            data-option="{{ $option['handle'] }}"
                            @class(['rounded', 'outline outline-2 outline-offset-4 outline-red-600' => $isMissing])
                            @if ($isMissing) aria-describedby="error-{{ $option['handle'] }}" @endif
                        >
                            <legend class="font-semibold">
                                {{ $option['name'] }}@if ($selection->selectedName($option['handle'])): <span class="font-normal">{{ $selection->selectedName($option['handle']) }}</span>@endif
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
                                            'border-slate-900 bg-slate-900 text-white' => $value['selected'],
                                            'border-slate-300 bg-white hover:border-slate-900' => ! $value['selected'] && $value['available'],
                                            'cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400 line-through' => ! $value['available'],
                                        ])
                                    >
                                        @if ($option['handle'] === 'colour')
                                            <span
                                                aria-hidden="true"
                                                class="inline-block h-4 w-4 rounded-full border border-slate-300"
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
                                <p id="error-{{ $option['handle'] }}" role="alert" class="mt-2 text-sm font-semibold text-red-700">
                                    {{ $missingPrompt }}
                                </p>
                            @endif
                        </fieldset>
                    @endif
                @endforeach

                <div>
                    <label for="quantity" class="block font-semibold text-slate-900">Quantity</label>
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
                            'border-slate-300' => $quantityError === null,
                            'border-red-600' => $quantityError !== null,
                        ])
                    >

                    @if ($quantityError !== null)
                        <p id="error-quantity" role="alert" class="mt-2 text-sm font-semibold text-red-700">{{ $quantityError }}</p>
                    @endif
                </div>

                <button
                    type="submit"
                    @disabled(! $canAdd)
                    data-add-to-cart
                    class="w-full rounded bg-amber-500 px-6 py-3 text-lg font-bold text-slate-900 hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500 sm:w-auto"
                >
                    {{ $addLabel }}
                </button>

                @if ($added)
                    <p role="status" class="rounded border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900">
                        Online ordering opens soon. Your selection is available.
                    </p>
                @endif
            </form>

            @if (filled($product->attr('key_points')))
                <ul class="mt-6 list-disc space-y-1 pl-5 text-slate-700">
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
                <p class="mt-3 text-slate-700">{{ $paragraph }}</p>
            @endforeach
        </section>
    @endif

    @if ($specification !== [])
        <section aria-labelledby="specification-heading" class="mt-10 max-w-3xl">
            <h2 id="specification-heading" class="text-xl font-bold">Specification</h2>
            <table class="mt-3 w-full text-left text-sm" data-testid="specification">
                <tbody>
                    @foreach ($specification as $row)
                        <tr class="border-b border-slate-200">
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
                    class="h-full w-full rounded border border-slate-200"
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
                    <li wire:key="related-{{ $relatedProduct->id }}" class="relative rounded border border-slate-200 p-3">
                        @if ($relatedProduct->thumbnail)
                            <img src="{{ $relatedProduct->thumbnail->getUrl('thumb') }}" alt="" loading="lazy" width="300" height="300" class="aspect-square w-full rounded bg-slate-100 object-contain">
                        @endif
                        <p class="mt-2 text-xs uppercase tracking-wide text-slate-500">{{ $relatedProduct->brand?->name }}</p>
                        <a href="{{ route('product', $relatedProduct->defaultUrl?->slug ?? $relatedProduct->id) }}" class="font-semibold after:absolute after:inset-0 hover:underline">
                            {{ $relatedProduct->translateAttribute('name') }}
                        </a>
                        @if ($relatedPrice)
                            <p class="text-sm"><span class="font-bold">{{ $relatedPrice->formatted() }}</span> <span class="text-xs text-slate-600">{{ $relatedPrice->suffix() }}</span></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</article>
