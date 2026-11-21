@php
    // Route name => label. The labels match the footer links.
    $groups = [
        'Legal' => [
            'terms' => 'Terms and Conditions',
            'privacy' => 'Privacy Policy',
            'cookies' => 'Cookie Policy',
            'accessibility' => 'Accessibility Statement',
        ],
        'Help with your order' => [
            'delivery' => 'Delivery Information',
            'returns' => 'Returns and Refunds',
        ],
    ];
@endphp

{{--
    The sidebar of the policy pages: the legal pages, then the delivery and returns pages, with the page
    you are on marked. It is for wide screens only. A phone gets the same links in the footer, and the
    text takes the whole width. Links go through route(), so a moved page cannot break them.
--}}
<aside {{ $attributes->class(['hidden w-60 shrink-0 lg:block']) }} aria-label="Policies and help" data-legal-nav>
    <nav class="rounded-lg border border-border p-4">
        @foreach ($groups as $heading => $links)
            <h2 @class(['text-sm font-semibold uppercase tracking-wider text-ink-muted', 'mt-6 border-t border-border pt-5' => ! $loop->first]) data-legal-nav-heading>{{ $heading }}</h2>

            <ul class="mt-3 space-y-1">
                @foreach ($links as $name => $label)
                    <li>
                        <a
                            href="{{ route($name) }}"
                            @if (request()->routeIs($name)) aria-current="page" @endif
                            data-legal-nav-link="{{ $name }}"
                            @class([
                                'block rounded-md px-3 py-2 text-sm font-semibold',
                                'bg-brand-blue text-white' => request()->routeIs($name),
                                'text-brand-blue hover:bg-surface-muted' => ! request()->routeIs($name),
                            ])
                        >{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>
</aside>
