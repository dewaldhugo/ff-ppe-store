{{--
    Wear Trials page, in the storefront layout. Route: /wear-trials (route name wear-trials).

    The wording is the client's own. The request form is the Livewire component App\Livewire\WearTrials\RequestForm;
    it emails the same people as a new order (App\Domain\Enquiries\StaffMail). The email address in the copy is the
    store email (config store.email), so it follows a change there. The Register button is for visitors who are
    not signed in.
--}}
@php
    $emailHref = \App\Support\Store::emailHref();

    $steps = [
        ['title' => 'Pick Your Contenders', 'text' => 'Browse our product range and shortlist the styles that best fit your team’s daily demands.'],
        ['title' => 'Get Approved', 'text' => 'Register for a trade account so we can verify your business details.'],
        ['title' => 'Test on the Ground', 'text' => 'Once approved, we’ll ship free trial samples straight to your door so your crew can put them through their paces in real working conditions.'],
    ];
@endphp

<x-layouts.storefront title="Wear Trials" description="Free wear trials for approved business customers: test the build quality, comfort and durability of our PPE on the job before you place a bulk order.">
    <x-ui.section-heading title="Wear Trials" tag="h1" />

    {{-- Two columns from md: the pitch on the left, a photo of the kit on the right. --}}
    <div class="mt-6 grid items-center gap-8 md:grid-cols-2 md:gap-12" data-wear-trials-hero>
        <div>
            <p class="leading-relaxed text-ink" data-wear-trials-intro>Choosing the right ‘head to toe’ PPE for your workforce is a major decision, and specs on a screen only tell half the story. You need to see the build quality, feel the comfort, and test the durability on the job before placing a bulk order.</p>
            <p class="mt-4 text-lg font-semibold text-brand-navy" data-wear-trials-offer>That’s why we offer free Wear Trials to approved business customers.</p>
        </div>

        <img
            src="{{ asset('images/wear-trials/head-to-toe-ppe.webp') }}"
            alt="A hard hat, ear defenders, safety goggles and work gloves laid out on a wooden surface"
            width="1470"
            height="980"
            class="h-auto w-full rounded-lg"
            data-wear-trials-image
        >
    </div>

    <section class="mt-12" aria-labelledby="wear-trials-how" data-wear-trials-steps>
        <h2 id="wear-trials-how" class="text-2xl text-brand-navy sm:text-3xl">How It Works</h2>

        <ol class="mt-6 grid gap-8 md:grid-cols-3">
            @foreach ($steps as $step)
                <li>
                    <span class="flex size-8 items-center justify-center rounded-full border border-brand-blue text-sm font-semibold text-brand-blue" aria-hidden="true">{{ $loop->iteration }}</span>
                    <h3 class="mt-3 text-lg font-semibold text-ink">{{ $step['title'] }}</h3>
                    <p class="mt-1 text-ink-muted">{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <section class="mt-12 grid gap-10 md:grid-cols-2 md:gap-16" aria-labelledby="wear-trials-request">
        <div data-wear-trials-register>
            @guest('web')
                <h2 id="wear-trials-request" class="text-2xl text-brand-navy sm:text-3xl">Register Today</h2>
                <p class="mt-6"><x-ui.button :href="route('register')" data-wear-trials-register-link>Register today</x-ui.button></p>
            @else
                <h2 id="wear-trials-request" class="text-2xl text-brand-navy sm:text-3xl">Email your wear trial shortlist</h2>
            @endguest

            <p class="mt-6 leading-relaxed text-ink">
                @guest('web')Or email @else Email @endguest your wear trial shortlist directly to
                @if ($emailHref)
                    <a href="{{ $emailHref }}" class="font-semibold text-brand-blue underline" data-wear-trials-email>{{ \App\Support\Store::email() }}</a>.
                @else
                    <span class="font-semibold" data-wear-trials-email>{{ \App\Support\Store::email() }}</span>.
                @endif
            </p>
        </div>

        <div>
            <h2 class="text-2xl text-brand-navy sm:text-3xl">Request a wear trial</h2>
            <div class="mt-6">
                <livewire:wear-trials.request-form />
            </div>
        </div>
    </section>
</x-layouts.storefront>
