@php
    // The PPE Health Check band, from config/homepage.php. Without a title or a survey address there is nothing to show.
    $content = (array) config('homepage.health_check', []);
    $title = trim((string) ($content['title'] ?? ''));
    $surveyUrl = (string) config('store.ppe_health_check_url');
@endphp

{{-- A charcoal band (brand-charcoal, as the top bar and the footer) above "Shop by brand": the pitch and the buttons on the left, an example scorecard on the right. --}}
@if ($title !== '' && $surveyUrl !== '')
    <section class="surface-dark bg-brand-charcoal py-12 text-white sm:py-16" aria-labelledby="home-health-check" data-home-health-check>
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

            {{-- An illustration of the result, not a real one: the same card as the Health Check page. --}}
            <x-storefront.health-check-example data-home-health-check-example />
        </x-ui.container>
    </section>
@endif
