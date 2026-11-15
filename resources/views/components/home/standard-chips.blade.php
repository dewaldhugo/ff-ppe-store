{{-- Shortcuts into the safety footwear listing by standard or feature. --}}
@if ($chips !== [])
    <section class="bg-surface-muted py-12 sm:py-16" aria-labelledby="home-standards" data-home-standards>
        <x-ui.container>
            <x-ui.section-heading id="home-standards" eyebrow="Safety footwear" title="Shop by standard" />

            <ul class="mt-8 flex flex-wrap gap-3">
                @foreach ($chips as $chip)
                    <li>
                        <a
                            href="{{ $chip['url'] }}"
                            class="inline-flex items-center rounded-full border-2 border-brand-blue bg-white px-5 py-2 text-base font-semibold text-brand-blue transition-colors hover:bg-brand-blue hover:text-white"
                            data-standard-chip
                        >{{ $chip['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
