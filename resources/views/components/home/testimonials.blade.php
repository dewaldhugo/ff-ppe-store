@php
    // Customer quotes from config/homepage.php. An item with no quote is skipped; with none left the section is not shown.
    $heading = (string) config('homepage.testimonials.title', '');
    $items = array_values(array_filter((array) config('homepage.testimonials.items', []), fn (mixed $item): bool => is_array($item) && trim((string) ($item['quote'] ?? '')) !== ''));
@endphp

{{-- What customers say, between the featured products and the service strip. --}}
@if ($items !== [])
    <section class="py-12 sm:py-16" aria-labelledby="home-testimonials" data-home-testimonials>
        <x-ui.container>
            {{-- Just the heading: no teal rule under it. --}}
            <x-ui.section-heading :title="$heading" id="home-testimonials" :center="true" :divider="false" />

            <ul class="mt-10 grid gap-6 lg:grid-cols-3">
                @foreach ($items as $item)
                    <li class="flex" data-testimonial>
                        {{-- A figure: the quote, then who said it. The big quote mark is decoration. --}}
                        <figure class="flex w-full flex-col rounded border border-border border-t-4 border-t-brand-teal bg-white p-6 sm:p-8">
                            <span class="select-none text-6xl font-extrabold leading-none text-brand-blue" aria-hidden="true">&ldquo;</span>

                            <blockquote class="mt-2 flex-1 text-base leading-relaxed text-ink">
                                <p data-testimonial-quote>{{ $item['quote'] }}</p>
                            </blockquote>

                            <figcaption class="mt-6 border-t border-border pt-4 text-sm">
                                <span class="block font-bold text-ink" data-testimonial-role>{{ $item['role'] ?? '' }}</span>
                                @if (filled($item['sector'] ?? null))
                                    <span class="block text-ink-muted" data-testimonial-sector>{{ $item['sector'] }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
