{{--
    The "Key features" block of a product page: a picture, a heading and a short explanation for each feature the product has
    (App\Domain\Catalogue\Display\FeatureHighlights). Nothing when the product has none.
--}}
@props(['tiles' => []])

@if ($tiles !== [])
    {{-- Full width background (it breaks out of the page container), with the content kept in the container's width. --}}
    <section aria-labelledby="key-features-heading" class="relative left-1/2 mt-10 w-screen -translate-x-1/2 bg-brand-navy py-10 text-white" data-key-features>
        <x-ui.container>
            <h2 id="key-features-heading" class="text-2xl font-bold">Key features</h2>
            <div class="mt-2 h-1 w-14 bg-brand-teal-bright" aria-hidden="true"></div>

            <ul class="mt-8 grid gap-x-12 gap-y-10 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($tiles as $tile)
                    <li class="flex gap-4" data-key-feature="{{ $tile['code'] }}">
                        <img src="{{ $tile['image'] }}" alt="" width="255" height="260" loading="lazy" class="h-[95px] w-[95px] shrink-0 object-cover sm:h-[109px] sm:w-[109px]">
                        <div>
                            <h3 class="text-sm font-bold uppercase text-brand-teal-light">{{ $tile['title'] }}</h3>
                            <p class="mt-2 text-[0.70875rem] leading-relaxed">{{ $tile['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
