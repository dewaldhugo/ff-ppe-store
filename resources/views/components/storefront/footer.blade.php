@php
    $heading = 'text-sm font-semibold uppercase tracking-wider text-white';
    $link = 'text-white/90 hover:text-white hover:underline';
    $phoneHref = \App\Support\Store::phoneHref();
    $emailHref = \App\Support\Store::emailHref();
@endphp

<footer class="surface-dark bg-brand-blue text-white">
    <x-ui.container class="grid gap-10 py-12 sm:grid-cols-2 lg:grid-cols-5">
        <div class="space-y-3 text-sm sm:col-span-2 lg:col-span-1">
            <a href="{{ route('home') }}" class="inline-block" data-footer-logo>
                <x-storefront.logo light />
            </a>
            <address class="not-italic text-white/90">{{ \App\Support\Store::contactAddress() }}</address>
            <p>
                @if ($phoneHref)
                    <a href="{{ $phoneHref }}" class="{{ $link }}">{{ \App\Support\Store::phone() }}</a>
                @else
                    <span>{{ \App\Support\Store::phone() }}</span>
                @endif
            </p>
            <p>
                @if ($emailHref)
                    <a href="{{ $emailHref }}" class="{{ $link }}">{{ \App\Support\Store::email() }}</a>
                @else
                    <span>{{ \App\Support\Store::email() }}</span>
                @endif
            </p>
        </div>

        <nav aria-labelledby="footer-products">
            <h2 id="footer-products" class="{{ $heading }}">Products</h2>
            <ul class="mt-4 space-y-2 text-sm" data-footer-categories>
                @foreach ($categories as $category)
                    <li><a href="{{ $category['url'] }}" class="{{ $link }}">{{ $category['name'] }}</a></li>
                @endforeach
            </ul>
        </nav>

        <nav aria-labelledby="footer-service">
            <h2 id="footer-service" class="{{ $heading }}">Customer service</h2>
            <ul class="mt-4 space-y-2 text-sm">
                <li><a href="{{ route('contact') }}" class="{{ $link }}">Contact</a></li>
                <li><a href="{{ route('size-guide') }}" class="{{ $link }}">Size Guide</a></li>
                <li><a href="{{ route('technical-features') }}" class="{{ $link }}">Technical Features</a></li>
                <li><a href="{{ route('wear-trials') }}" class="{{ $link }}">Wear Trials</a></li>
                <li><a href="{{ route('ppe-health-check') }}" class="{{ $link }}">PPE Health Check</a></li>
                <li><a href="{{ route('delivery') }}" class="{{ $link }}">Delivery Information</a></li>
                <li><a href="{{ route('returns') }}" class="{{ $link }}">Returns and Refunds</a></li>
            </ul>
        </nav>

        <nav aria-labelledby="footer-company">
            <h2 id="footer-company" class="{{ $heading }}">Company</h2>
            <ul class="mt-4 space-y-2 text-sm">
                <li><a href="{{ route('about') }}" class="{{ $link }}">About</a></li>
            </ul>
        </nav>

        <nav aria-labelledby="footer-legal">
            <h2 id="footer-legal" class="{{ $heading }}">Legal</h2>
            <ul class="mt-4 space-y-2 text-sm">
                <li><a href="{{ route('terms') }}" class="{{ $link }}">Terms and Conditions</a></li>
                <li><a href="{{ route('privacy') }}" class="{{ $link }}">Privacy Policy</a></li>
                <li><a href="{{ route('cookies') }}" class="{{ $link }}">Cookie Policy</a></li>
                <li><a href="{{ route('accessibility') }}" class="{{ $link }}">Accessibility Statement</a></li>
            </ul>
        </nav>
    </x-ui.container>

    <div class="border-t border-white/20">
        <x-ui.container class="space-y-2 py-6 text-sm text-white/90">
            {{-- Fixed wording from the client: it names the company and a year, and does not follow the .env or the date. --}}
            <p data-copyright>Copyright &copy; Frontline Holdings Ltd. 2021. All rights reserved.</p>
            <p data-company-number>Company Registration Number: 13184904.</p>
        </x-ui.container>
    </div>
</footer>
