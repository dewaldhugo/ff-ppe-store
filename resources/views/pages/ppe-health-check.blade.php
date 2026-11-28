{{--
    PPE Health Check page, in the storefront layout. Route: /ppe-health-check (route name ppe-health-check).

    The page stops after what it measures: the pitch with its example scorecard, and the five areas. The hero is plain navy, with no photo.

    The check itself is an online survey on another site (config store.ppe_health_check_url), so this page
    introduces it and sends people there in a new tab. The wording is the client's own, from the survey's landing
    page. The example scorecard is an illustration, labelled as an example: its scores are made up on purpose and
    are not anyone's result.
--}}
@php
    $surveyUrl = (string) config('store.ppe_health_check_url');

    $areas = [
        ['title' => 'Compliance and Standards', 'text' => 'How PPE is matched to documented risk assessments, and how certification and standards are checked and recorded.'],
        ['title' => 'Selection and Fit', 'text' => 'How you gather wearer feedback and make sure sizing and fit work across your whole workforce.'],
        ['title' => 'Purchasing and Spend', 'text' => 'How PPE is bought across your sites, and how well you can see spend and replacement cycles.'],
        ['title' => 'Supply Reliability', 'text' => 'How well you plan for stock-outs, delays, peaks and new starters.'],
        ['title' => 'Supplier Service and Admin', 'text' => 'How much support you get from your supplier, and how much administration PPE creates.'],
    ];
@endphp

<x-layouts.storefront title="PPE Health Check" description="Take our free 3-minute PPE assessment and see where your business could reduce risk, save time and cut unnecessary costs." :full="true">
    {{-- Hero: the pitch and the button, with an example scorecard. --}}
    <section class="surface-dark bg-brand-navy text-white" aria-labelledby="health-check-title" data-health-check>
        {{-- Taller than it was (3rem on a phone and 4rem from sm up before; now 5.5rem), about 10% more height on a laptop. --}}
        <x-ui.container class="grid items-center gap-10 py-[5.5rem] lg:grid-cols-2 lg:gap-16">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-brand-teal-bright">Free PPE scorecard</p>
                <h1 id="health-check-title" class="mt-2 text-3xl sm:text-4xl">Is Your PPE Costing You More Than It Should?</h1>
                <p class="mt-4 max-w-xl leading-relaxed text-white/90">Discover hidden gaps in PPE compliance, workforce protection and supply reliability. Take our free 3-minute assessment and see where your business could reduce risk, save time and cut unnecessary costs.</p>

                <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <x-ui.button variant="accent" :href="$surveyUrl" target="_blank" rel="noopener" data-health-check-link>Get my free PPE scorecard<span class="sr-only"> (opens in a new tab)</span></x-ui.button>
                    <p class="text-sm text-white/90">Free. 12 questions. About 3 minutes.</p>
                </div>

                <p class="mt-4 max-w-xl text-xs text-white/90">We will use your details to give you your results and may contact you about PPE. <a href="{{ route('privacy') }}" class="underline">Read our privacy policy</a>.</p>
            </div>

            {{-- An illustration of the result, not a real one (the same card as the home page band). --}}
            <x-storefront.health-check-example data-health-check-example />
        </x-ui.container>
    </section>

    {{-- What it measures. --}}
    <section class="py-12 sm:py-16" aria-labelledby="health-check-measures" data-health-check-measures>
        <x-ui.container class="grid gap-10 lg:grid-cols-2 lg:gap-16">
            <div>
                <x-ui.eyebrow>What it measures</x-ui.eyebrow>
                <h2 id="health-check-measures" class="mt-1 text-3xl text-ink sm:text-4xl">Five areas that decide how well your PPE protects you.</h2>
                <p class="mt-4 leading-relaxed text-ink">This healthcheck looks at how PPE is selected, supplied and managed across your sites, then shows where your controls are strongest and where the biggest gaps sit.</p>
                <p class="mt-4 leading-relaxed text-ink">When you have your results, you can book a call with a Frontline advisor to talk through the gaps. There is no obligation.</p>
            </div>

            <ul class="divide-y divide-border border-y border-border" data-health-check-areas>
                @foreach ($areas as $area)
                    <li class="py-5">
                        <h3 class="text-lg font-semibold text-ink">{{ $area['title'] }}</h3>
                        <p class="mt-1 text-ink-muted">{{ $area['text'] }}</p>
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
</x-layouts.storefront>
