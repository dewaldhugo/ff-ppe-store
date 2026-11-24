@php
    // The PPE Health Check band, from config/homepage.php. Without a title or a survey address there is nothing to show.
    $content = (array) config('homepage.health_check', []);
    $title = trim((string) ($content['title'] ?? ''));
    $surveyUrl = (string) config('store.ppe_health_check_url');
    $example = array_values(array_filter((array) ($content['example'] ?? []), fn (mixed $row): bool => is_array($row) && isset($row['label'], $row['score'])));
@endphp

{{-- A navy band above "Shop by brand": the pitch and the buttons on the left, an example scorecard on the right. --}}
@if ($title !== '' && $surveyUrl !== '')
    <section class="surface-dark bg-brand-navy py-12 text-white sm:py-16" aria-labelledby="home-health-check" data-home-health-check>
        <x-ui.container class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
            <div>
                @if (trim((string) ($content['eyebrow'] ?? '')) !== '')
                    <p class="text-sm font-semibold uppercase tracking-wider text-brand-teal">{{ $content['eyebrow'] }}</p>
                @endif
                <h2 id="home-health-check" class="mt-2 text-3xl text-white sm:text-4xl">{{ $title }}</h2>
                <p class="mt-4 max-w-xl leading-relaxed text-white/90">{{ $content['text'] ?? '' }}</p>

                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <x-ui.button variant="accent" :href="$surveyUrl" target="_blank" rel="noopener" data-home-health-check-survey>Get my free PPE scorecard<span class="sr-only"> (opens in a new tab)</span></x-ui.button>
                    <x-ui.button-outline :href="route('ppe-health-check')" :on-dark="true" shape="md" data-home-health-check-more>Find out more</x-ui.button-outline>
                </div>

                @if (trim((string) ($content['note'] ?? '')) !== '')
                    <p class="mt-4 text-sm text-white/90">{{ $content['note'] }}</p>
                @endif
            </div>

            @if ($example !== [])
                {{-- An illustration of the result, not a real one. --}}
                <div class="rounded-xl bg-white p-6 text-ink shadow-lg" data-home-health-check-example>
                    <p class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Example report</p>
                    <p class="mt-3 text-xl font-semibold">Your PPE scorecard</p>

                    <ul class="mt-5 space-y-4">
                        @foreach ($example as $row)
                            <li>
                                <p class="text-sm font-semibold">{{ $row['label'] }}</p>
                                <div class="mt-1.5 h-2 w-full rounded-full bg-border" role="meter" aria-label="{{ $row['label'] }}, example score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $row['score'] }}">
                                    <div class="h-2 rounded-full bg-brand-blue" style="width: {{ (int) $row['score'] }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.container>
    </section>
@endif
