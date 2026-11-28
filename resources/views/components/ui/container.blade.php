{{-- Page width and gutters. 80rem (max-w-7xl), and 15% wider (92rem) at the largest breakpoint only, 2xl (1536px and up): the page then fills 1472 of the 1536 pixels. --}}
<div {{ $attributes->class('mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8 2xl:max-w-[92rem]') }}>
    {{ $slot }}
</div>
