{{--
    Request a quote: /request-a-quote (route name quote). For larger quantities than the price list covers: for one product
    (?product=12), for the signed-in customer's cart (?cart=1) or in the customer's own words. The form is a Livewire component that
    emails the store (App\Livewire\Quote\RequestForm). Noindex: it is a personal request, not content.
--}}
<x-layouts.storefront title="Request a quote" description="Ask for a quote for larger quantities of safety footwear, workwear and PPE." :noindex="true">
    <x-ui.section-heading title="Request a quote" tag="h1">Buying in quantity? Tell us what you need and we will come back to you with a price.</x-ui.section-heading>

    <div class="mt-8 max-w-3xl" data-quote-page>
        <livewire:quote.request-form />
    </div>
</x-layouts.storefront>
