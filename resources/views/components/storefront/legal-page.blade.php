@props(['title', 'description', 'updated'])

{{--
    A policy page (terms, privacy, cookies, accessibility, delivery, returns): the standard layout, one h1, the date the
    wording was last changed, the text (styled by .legal-prose below) and the sidebar of policy links
    (x-storefront.legal-nav, wide screens only). Pages put plain h2, h3, p, ul
    and table elements in the slot. It carries no noindex of its own, so the STORE_NOINDEX switch alone decides (docs/launch-checklist.md).
--}}
<x-layouts.storefront :title="$title" :description="$description">
    <style>
        .legal-prose { color: var(--color-ink); font-size: 1rem; line-height: 1.7; }
        .legal-prose > * + * { margin-top: 1rem; }
        .legal-prose > :first-child { margin-top: 0; }
        .legal-prose h2 { margin-top: 2.25rem; font-size: 1.375rem; line-height: 1.3; font-weight: 700; color: var(--color-brand-navy); }
        .legal-prose h3 { margin-top: 1.5rem; font-size: 1.125rem; line-height: 1.3; font-weight: 700; color: var(--color-brand-blue); }
        .legal-prose a { color: var(--color-brand-blue); text-decoration: underline; text-underline-offset: 2px; }
        .legal-prose ul { list-style: disc; padding-left: 1.25rem; }
        .legal-prose ol { list-style: decimal; padding-left: 1.25rem; }
        .legal-prose li + li { margin-top: 0.5rem; }
        .legal-prose table { width: 100%; border-collapse: collapse; font-size: 0.9375rem; }
        .legal-prose th { text-align: left; background: var(--color-surface-muted); }
        .legal-prose th, .legal-prose td { padding: 0.5rem 0.75rem; border: 1px solid var(--color-border); vertical-align: top; }
        .legal-prose .legal-table-wrap { overflow-x: auto; }
    </style>

    <article class="w-full" data-legal-page>
        <x-ui.section-heading :title="$title" tag="h1" />

        <p class="mt-4 text-sm text-ink-muted" data-legal-updated>Last updated: {{ $updated }}</p>

        {{-- The text fills the left column and the sidebar sits beside it, on the right, from the large
             breakpoint up. Below that the sidebar is not shown and the text takes the whole width. --}}
        <div class="mt-8 lg:flex lg:items-start lg:gap-12">
            <div class="legal-prose min-w-0 lg:flex-1" data-legal-body>
                {{ $slot }}
            </div>

            <x-storefront.legal-nav />
        </div>
    </article>
</x-layouts.storefront>
