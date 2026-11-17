{{--
    The "View product" button on a product card. Decoration only: the card's name link is stretched over the
    whole card (after:absolute after:inset-0), so this is not a second link. Place it inside an element with
    the `group` class, so it fills in when the card is hovered or has focus.
--}}
<span aria-hidden="true" {{ $attributes->class('inline-flex items-center justify-center rounded-md border-2 border-brand-teal px-4 py-2 text-sm font-semibold text-brand-blue transition-colors group-hover:border-brand-blue group-hover:bg-brand-blue group-hover:text-white group-focus-within:border-brand-blue group-focus-within:bg-brand-blue group-focus-within:text-white') }}>{{ $slot }}</span>
