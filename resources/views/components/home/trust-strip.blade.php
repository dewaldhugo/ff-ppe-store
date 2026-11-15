@php
    $claims = array_values(array_filter(array_map('trim', (array) config('homepage.trust', [])), fn (string $claim): bool => $claim !== ''));
@endphp

{{-- Short claims from config/homepage.php. Empty slots are skipped. --}}
@if ($claims !== [])
    <section class="border-b border-border bg-white" aria-labelledby="home-trust" data-home-trust>
        <h2 id="home-trust" class="sr-only">Why buy from us</h2>

        <x-ui.container class="py-6">
            <ul class="grid gap-3 sm:grid-cols-2 lg:flex lg:flex-wrap lg:gap-x-10">
                @foreach ($claims as $claim)
                    <li class="flex items-center gap-3 text-sm font-semibold text-ink">
                        <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full bg-brand-blue"></span>
                        {{ $claim }}
                    </li>
                @endforeach
            </ul>
        </x-ui.container>
    </section>
@endif
