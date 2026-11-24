{{--
    PPE Health Check page, in the storefront layout. Route: /ppe-health-check (route name ppe-health-check).

    The check itself is an online survey on another site (config store.ppe_health_check_url), so this page
    introduces it and sends people there in a new tab. The wording is the client's own, from the survey's landing
    page. The example scorecard is an illustration, labelled as an example: its scores are made up on purpose and
    are not anyone's result.
--}}
@php
    $surveyUrl = (string) config('store.ppe_health_check_url');

    // The example scorecard. Purchasing and Spend is the lowest, as the card says.
    $example = [
        ['label' => 'Compliance and Standards', 'score' => 72],
        ['label' => 'Selection and Fit', 'score' => 58],
        ['label' => 'Purchasing and Spend', 'score' => 44],
    ];

    $areas = [
        ['title' => 'Compliance and Standards', 'text' => 'How PPE is matched to documented risk assessments, and how certification and standards are checked and recorded.'],
        ['title' => 'Selection and Fit', 'text' => 'How you gather wearer feedback and make sure sizing and fit work across your whole workforce.'],
        ['title' => 'Purchasing and Spend', 'text' => 'How PPE is bought across your sites, and how well you can see spend and replacement cycles.'],
        ['title' => 'Supply Reliability', 'text' => 'How well you plan for stock-outs, delays, peaks and new starters.'],
        ['title' => 'Supplier Service and Admin', 'text' => 'How much support you get from your supplier, and how much administration PPE creates.'],
    ];

    $steps = [
        ['title' => 'Answer 12 short questions', 'text' => 'Add your details, then answer 12 quick multiple-choice questions across five areas.'],
        ['title' => 'See your score straight away', 'text' => 'Get an overall rating (Exposed, Developing or Controlled) and a section-by-section read of all five areas.'],
        ['title' => 'Know where to start', 'text' => 'See your strengths and gaps, with a clear idea of what to tackle first.'],
    ];
@endphp

<x-layouts.storefront title="PPE Health Check" description="Take our free 3-minute PPE assessment and see where your business could reduce risk, save time and cut unnecessary costs." :full="true">
    {{-- Hero: the pitch and the button, with an example scorecard. --}}
    <section class="surface-dark relative isolate overflow-hidden bg-brand-navy text-white" aria-labelledby="health-check-title" data-health-check>
        {{-- The photo behind the hero, under a dark overlay so the white text stays readable. It is above the fold, so it loads at once. --}}
        <img src="{{ asset('images/hero-bg-9.jpg') }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover" data-health-check-hero-image>
        <span class="absolute inset-0 -z-10 bg-brand-navy/80" aria-hidden="true" data-health-check-hero-overlay></span>

        {{-- Taller than it was (3rem on a phone and 4rem from sm up before; now 5.5rem), about 10% more height on a laptop. --}}
        <x-ui.container class="grid items-center gap-10 py-[5.5rem] lg:grid-cols-2 lg:gap-16">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-brand-teal">Free PPE scorecard</p>
                <h1 id="health-check-title" class="mt-2 text-3xl sm:text-4xl">Is Your PPE Costing You More Than It Should?</h1>
                <p class="mt-4 max-w-xl leading-relaxed text-white/90">Discover hidden gaps in PPE compliance, workforce protection and supply reliability. Take our free 3-minute assessment and see where your business could reduce risk, save time and cut unnecessary costs.</p>

                <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <x-ui.button variant="accent" :href="$surveyUrl" target="_blank" rel="noopener" data-health-check-link>Get my free PPE scorecard<span class="sr-only"> (opens in a new tab)</span></x-ui.button>
                    <p class="text-sm text-white/90">Free. 12 questions. About 3 minutes.</p>
                </div>

                <p class="mt-4 max-w-xl text-xs text-white/90">We will use your details to give you your results and may contact you about PPE. <a href="{{ route('privacy') }}" class="underline">Read our privacy policy</a>.</p>
            </div>

            {{-- An illustration of the result, not a real one. --}}
            <div class="rounded-xl bg-white p-6 text-ink shadow-lg" data-health-check-example>
                <p class="text-xs font-semibold uppercase tracking-widest text-ink-muted">Example report</p>
                <div class="mt-3 flex items-center justify-between gap-4">
                    <p class="text-xl font-semibold">Your PPE scorecard</p>
                    <span class="rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-brand-blue">Developing</span>
                </div>

                <ul class="mt-5 space-y-4">
                    @foreach ($example as $row)
                        <li>
                            <p class="text-sm font-semibold">{{ $row['label'] }}</p>
                            <div class="mt-1.5 h-2 w-full rounded-full bg-border" role="meter" aria-label="{{ $row['label'] }}, example score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $row['score'] }}">
                                <div class="h-2 rounded-full bg-brand-blue" style="width: {{ $row['score'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-5 border-t border-border pt-4 text-sm text-ink-muted">Lowest-scoring section: <span class="font-semibold text-ink">Purchasing and Spend</span></p>
            </div>
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

    {{-- A photo under a dark overlay, with one line over it. --}}
    <div class="surface-dark relative isolate flex min-h-[14rem] items-center justify-center overflow-hidden bg-brand-navy px-4 py-16 text-center text-white" data-health-check-banner>
        <img src="{{ asset('images/hero-bg-7.jpg') }}" alt="" loading="lazy" class="absolute inset-0 -z-10 h-full w-full object-cover">
        <span class="absolute inset-0 -z-10 bg-brand-navy/75" aria-hidden="true"></span>
        <p class="max-w-2xl text-2xl font-semibold leading-snug sm:text-3xl">See how your PPE controls score across five areas, in less time than a coffee break.</p>
    </div>

    {{-- About Frontline. --}}
    <section class="bg-surface-muted py-12 sm:py-16" aria-labelledby="health-check-about" data-health-check-about>
        <x-ui.container>
            <div class="max-w-3xl">
                <x-ui.eyebrow>About Frontline Safety Gear</x-ui.eyebrow>
                <h2 id="health-check-about" class="mt-1 text-3xl text-ink sm:text-4xl">Practical PPE support, not just products.</h2>
                <p class="mt-4 leading-relaxed text-ink">Frontline Safety Gear supplies protective footwear, workwear and PPE from established manufacturers, with thousands of products to choose from.</p>
                <p class="mt-4 leading-relaxed text-ink">Alongside the products, we offer an in-house Health and Safety Consultation service, free trials of selected safety footwear and PPE, personalised account management and dedicated online ordering, so buying PPE takes less time and less administration.</p>
                <p class="mt-4"><a href="{{ route('wear-trials') }}" class="font-semibold text-brand-blue underline">Find out about our wear trials</a></p>
            </div>
        </x-ui.container>
    </section>

    {{-- How it works. --}}
    <section class="py-12 sm:py-16" aria-labelledby="health-check-how" data-health-check-steps>
        <x-ui.container>
            <x-ui.eyebrow>How it works</x-ui.eyebrow>
            <h2 id="health-check-how" class="sr-only">How it works</h2>

            <ol class="mt-6 grid gap-8 md:grid-cols-3">
                @foreach ($steps as $step)
                    <li>
                        <span class="flex size-8 items-center justify-center rounded-full border border-brand-blue text-sm font-semibold text-brand-blue" aria-hidden="true">{{ $loop->iteration }}</span>
                        <h3 class="mt-3 text-lg font-semibold text-ink">{{ $step['title'] }}</h3>
                        <p class="mt-1 text-ink-muted">{{ $step['text'] }}</p>
                    </li>
                @endforeach
            </ol>
        </x-ui.container>
    </section>

    {{-- The closing call to action. --}}
    <section class="surface-dark bg-brand-navy py-12 text-center text-white sm:py-16" aria-labelledby="health-check-end" data-health-check-end>
        <x-ui.container>
            <h2 id="health-check-end" class="mx-auto max-w-3xl text-3xl sm:text-4xl">See where your PPE controls stand, and fix the gaps <em>before they become problems.</em></h2>
            <p class="mx-auto mt-4 max-w-3xl leading-relaxed text-white/90">In about three minutes you will see where your controls are strong, and where gaps in compliance, fit, purchasing, supply and service could expose you to risk, delays and avoidable cost.</p>

            <p class="mt-8">
                <x-ui.button variant="accent" :href="$surveyUrl" target="_blank" rel="noopener" data-health-check-link>Get my free PPE scorecard<span class="sr-only"> (opens in a new tab)</span></x-ui.button>
            </p>
            <p class="mt-3 text-sm text-white/90">Free. 12 questions. About 3 minutes.</p>
            <p class="mx-auto mt-2 max-w-xl text-xs text-white/90">We will use your details to give you your results and may contact you about PPE. <a href="{{ route('privacy') }}" class="underline">Read our privacy policy</a>.</p>
        </x-ui.container>
    </section>
</x-layouts.storefront>
