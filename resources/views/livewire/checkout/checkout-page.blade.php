<div>
    @if (! $hasLines)
        <div class="rounded border border-border bg-white p-6" data-checkout-empty>
            <h1 class="text-2xl font-bold">Your cart is empty</h1>
            <x-ui.button :href="route('shop')" class="mt-4">Browse products</x-ui.button>
        </div>
    @else
        <h1 class="text-2xl font-bold sm:text-3xl">Checkout</h1>
        <p class="mt-1 text-sm"><a href="{{ route('cart') }}" class="font-semibold text-brand-blue hover:underline">Back to cart</a></p>

        <form wire:submit="placeOrder" novalidate class="mt-6 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_26rem]" data-checkout-form>
            {{-- Left: data entry and logistics. --}}
            <div class="space-y-6">
                <section aria-labelledby="contact-heading" class="rounded border border-border bg-white p-5" data-section="contact">
                    <h2 id="contact-heading" class="text-lg font-bold">Contact details</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-checkout.field name="full_name" label="Full name" autocomplete="name" class="sm:col-span-2" />
                        <x-checkout.field name="email" label="Business email" type="email" autocomplete="email" hint="Where we contact you about this order and send your VAT invoice." />
                        <x-checkout.field name="phone" label="Phone number" type="tel" autocomplete="tel" hint="The courier calls this to arrange delivery." />
                    </div>
                </section>

                <section aria-labelledby="company-heading" class="rounded border border-border bg-white p-5" data-section="company">
                    <h2 id="company-heading" class="text-lg font-bold">Company and tax details</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-checkout.field name="company_name" label="Registered company name" autocomplete="organization" />
                        <x-checkout.field name="vat_number" label="VAT registration number" :optional="true" hint="For example GB123456789." />
                        <x-checkout.field name="po_number" label="Purchase order (PO) number" :optional="true" hint="Printed on your invoice." />
                    </div>

                    <div class="mt-4">
                        <label for="checkout-notes" class="block text-sm font-semibold">Order notes <span class="font-normal text-ink-muted">(optional)</span></label>
                        <textarea id="checkout-notes" wire:model.blur="notes" rows="3" maxlength="1000" placeholder="Site access instructions, for example: deliver to Receiving Bay 2" class="mt-1 block w-full rounded-md border border-border-strong bg-white px-3 py-2.5 text-ink focus:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue/30"></textarea>
                        @error('notes') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </section>

                <section aria-labelledby="delivery-heading" class="rounded border border-border bg-white p-5" data-section="delivery-address">
                    <h2 id="delivery-heading" class="text-lg font-bold">Delivery address</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-checkout.field name="ship_line_one" label="Address line 1" autocomplete="shipping address-line1" class="sm:col-span-2" />
                        <x-checkout.field name="ship_line_two" label="Address line 2" :optional="true" autocomplete="shipping address-line2" class="sm:col-span-2" />
                        <x-checkout.field name="ship_city" label="Town or city" autocomplete="shipping address-level2" />
                        <x-checkout.field name="ship_county" label="County" :optional="true" autocomplete="shipping address-level1" />
                        <x-checkout.field name="ship_postcode" label="Postcode" autocomplete="shipping postal-code" maxlength="10" />
                        <div>
                            <span class="block text-sm font-semibold">Country</span>
                            <p class="mt-1 rounded-md border border-border bg-surface-muted px-3 py-2.5 text-ink-muted" data-country>United Kingdom</p>
                        </div>
                    </div>

                    <fieldset class="mt-5">
                        <legend class="text-sm font-semibold">Delivery to <span class="font-normal text-ink-muted">(couriers treat these differently)</span></legend>
                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="site_type" value="commercial" class="h-4 w-4"> Commercial or business premises</label>
                            <label class="flex items-center gap-2"><input type="radio" wire:model.live="site_type" value="residential" class="h-4 w-4"> Residential address</label>
                        </div>
                    </fieldset>
                </section>

                <section aria-labelledby="billing-heading" class="rounded border border-border bg-white p-5" data-section="billing-address">
                    <h2 id="billing-heading" class="text-lg font-bold">Billing address</h2>

                    <label class="mt-3 flex items-center gap-2">
                        <input type="checkbox" wire:model.live="billing_same" class="h-4 w-4" data-billing-same>
                        Billing address is the same as the delivery address
                    </label>

                    @if (! $billing_same)
                        <div class="mt-4 grid gap-4 sm:grid-cols-2" data-billing-fields>
                            <x-checkout.field name="bill_line_one" label="Address line 1" autocomplete="billing address-line1" class="sm:col-span-2" />
                            <x-checkout.field name="bill_line_two" label="Address line 2" :optional="true" autocomplete="billing address-line2" class="sm:col-span-2" />
                            <x-checkout.field name="bill_city" label="Town or city" autocomplete="billing address-level2" />
                            <x-checkout.field name="bill_county" label="County" :optional="true" autocomplete="billing address-level1" />
                            <x-checkout.field name="bill_postcode" label="Postcode" autocomplete="billing postal-code" maxlength="10" />
                        </div>
                    @endif
                </section>

                <section aria-labelledby="method-heading" class="rounded border border-border bg-white p-5" data-section="delivery-method">
                    <h2 id="method-heading" class="text-lg font-bold">Delivery method</h2>

                    @if ($deliveryOption)
                        <label class="mt-3 flex items-start gap-3 rounded border border-brand-blue p-3" data-delivery-option>
                            <input type="radio" checked class="mt-1 h-4 w-4" aria-label="{{ $deliveryOption['name'] }}">
                            <span class="flex-1">
                                <span class="block font-semibold">{{ $deliveryOption['name'] }}</span>
                                @if ($deliveryOption['description'])
                                    <span class="block text-sm text-ink-muted">{{ $deliveryOption['description'] }}</span>
                                @endif
                            </span>
                            <span class="font-bold" data-delivery-price>
                                {{ $deliveryOption['free'] ? 'Free' : $deliveryOption['price'] }}
                                @unless ($deliveryOption['free']) <span class="text-xs font-normal text-ink-muted">{{ $suffix }}</span> @endunless
                            </span>
                        </label>
                    @else
                        <p class="mt-3 text-sm text-ink-muted" data-delivery-pending>Enter your delivery address to see the delivery options.</p>
                    @endif
                </section>

                <section aria-labelledby="payment-heading" class="rounded border border-border bg-white p-5" data-section="payment">
                    <h2 id="payment-heading" class="text-lg font-bold">Payment method</h2>

                    <div class="mt-3 space-y-3">
                        <label class="flex items-start gap-3 rounded border border-brand-blue p-3" data-payment-option="bank-transfer">
                            <input type="radio" wire:model="payment_method" value="bank-transfer" class="mt-1 h-4 w-4">
                            <span>
                                <span class="block font-semibold">Bank transfer</span>
                                <span class="block text-sm text-ink-muted">We place your order now and contact you with our bank details. Goods are dispatched once payment is received.</span>
                            </span>
                        </label>

                        <div class="flex items-start gap-3 rounded border border-border bg-surface-muted p-3 text-ink-muted" data-payment-option="card" aria-disabled="true">
                            <input type="radio" disabled class="mt-1 h-4 w-4" aria-label="Debit or credit card, not yet available">
                            <span>
                                <span class="block font-semibold">Debit or credit card</span>
                                <span class="block text-sm">Coming soon.</span>
                                <x-checkout.payment-logos :names="['stripe']" class="mt-2 justify-start" />
                            </span>
                        </div>
                    </div>
                    @error('payment_method') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
                </section>
            </div>

            {{-- Right: the live summary, sticky on large screens. --}}
            <aside aria-labelledby="summary-heading" class="rounded border border-border bg-white p-5 lg:sticky lg:top-6" data-checkout-summary>
                <h2 id="summary-heading" class="text-lg font-bold">Order summary</h2>

                <ul class="mt-4 divide-y divide-border" data-summary-lines>
                    @foreach ($lines as $line)
                        <li wire:key="summary-line-{{ $line['id'] }}" class="flex gap-3 py-3 first:pt-0" data-summary-line>
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded border border-border bg-surface-muted">
                                @if ($line['image'])
                                    <img src="{{ $line['image'] }}" alt="" loading="lazy" width="300" height="300" class="h-full w-full object-contain">
                                @endif
                            </div>

                            <div class="min-w-0 flex-1 text-sm">
                                <p class="font-semibold">{{ $line['name'] }}</p>
                                <p class="text-xs text-ink-muted" data-line-sku>Code {{ $line['sku'] }}</p>
                                @if ($line['options'] !== [])
                                    <p class="text-xs text-ink-muted">
                                        @foreach ($line['options'] as $option){{ $option['label'] }}: {{ $option['value'] }}@if (! $loop->last), @endif @endforeach
                                    </p>
                                @endif
                                <p class="mt-0.5 text-ink-muted">
                                    <span data-line-unit>{{ $line['unit'] }}</span> each <span class="text-xs">{{ $suffix }}</span>
                                    <span aria-hidden="true">&times;</span> Qty {{ $line['quantity'] }}
                                </p>

                                @if ($line['zeroRated'])
                                    <p class="mt-1" data-line-vat><span class="rounded border border-success bg-success-surface px-2 py-0.5 text-xs font-bold text-success" data-zero-rated>Zero-rated: 0% VAT</span></p>
                                @else
                                    <p class="mt-1 text-xs text-ink-muted" data-line-vat>VAT {{ $line['vatRate'] }}%: <span class="font-semibold text-ink">{{ $line['vat'] }}</span></p>
                                @endif

                                @if ($line['problem'])
                                    <p role="alert" class="mt-1 text-xs font-semibold text-danger" data-line-problem>{{ $line['problem'] }}</p>
                                @endif
                            </div>

                            <div class="shrink-0 text-right text-sm">
                                <p class="text-xs text-ink-muted">Line total</p>
                                <p class="font-bold" data-line-total>{{ $line['total'] }}</p>
                                <p class="text-xs text-ink-muted">{{ $suffix }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- A trade or contract discount code, tucked away until wanted. --}}
                <details class="mt-4 border-t border-border pt-4" @if ($promo_applied || $promoError) open @endif data-promo>
                    <summary class="cursor-pointer text-sm font-semibold text-brand-blue">Discount code</summary>

                    @if ($promo_applied)
                        <p class="mt-2 flex items-center justify-between gap-3 text-sm" data-promo-applied>
                            <span>Code <strong>{{ $promo_applied }}</strong> applied</span>
                            <button type="button" wire:click="removePromo" class="font-semibold text-brand-blue underline">Remove</button>
                        </p>
                    @else
                        <div class="mt-2 flex gap-2">
                            <label for="promo-code" class="sr-only">Discount code</label>
                            <input id="promo-code" type="text" wire:model="promo_code" wire:keydown.enter.prevent="applyPromo" maxlength="50" class="w-full rounded-md border border-border-strong px-3 py-2 text-sm">
                            <x-ui.button-outline type="button" shape="md" wire:click="applyPromo" class="px-4 py-2 text-sm">Apply</x-ui.button-outline>
                        </div>
                    @endif

                    @if ($promoError)
                        <p role="alert" class="mt-2 text-sm text-danger" data-promo-error>{{ $promoError }}</p>
                    @endif
                </details>

                {{-- The stack, in order: subtotal, delivery, net total, VAT, total. --}}
                <dl class="mt-4 space-y-2 border-t border-border pt-4 text-sm" data-checkout-totals>
                    <div class="flex justify-between gap-4">
                        <dt>Subtotal <span class="text-xs text-ink-muted">(excl. VAT)</span></dt>
                        <dd class="font-semibold" data-total-subtotal>{{ $totals['subtotal'] }}</dd>
                    </div>
                    @if ($totals['discount'])
                        <div class="flex justify-between gap-4">
                            <dt>Discount</dt>
                            <dd class="font-semibold" data-total-discount>-{{ $totals['discount'] }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4">
                        <dt>Delivery <span class="text-xs text-ink-muted">(excl. VAT)</span></dt>
                        <dd class="font-semibold" data-total-delivery>
                            @if ($totals['delivery'] === null)
                                <span class="font-normal text-ink-muted">Enter address</span>
                            @else
                                {{ $totals['deliveryFree'] ? 'Free' : $totals['delivery'] }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-border pt-2">
                        <dt>Net Total</dt>
                        <dd class="font-semibold" data-total-ex-vat>{{ $totals['totalExVat'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt>VAT</dt>
                        <dd class="font-semibold" data-total-vat>{{ $totals['vat'] }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-t border-border pt-3 text-xl font-bold">
                        <dt>Total <span class="text-xs font-normal text-ink-muted">(incl. VAT)</span></dt>
                        <dd data-total-grand>{{ $totals['total'] }}</dd>
                    </div>
                </dl>

                <x-storefront.vat-breakdown :rows="$totals['vatRows']" :suffix="$suffix" class="mt-4" />

                @if ($formError)
                    <x-ui.alert variant="error" class="mt-4" data-form-error>{{ $formError }}</x-ui.alert>
                @endif

                @if ($errors->any())
                    <x-ui.alert variant="error" class="mt-4" data-form-invalid>Please check the details marked above.</x-ui.alert>
                @endif

                <x-ui.button type="submit" class="mt-5 w-full py-4 text-lg" :disabled="! $canOrder" wire:loading.attr="disabled" wire:target="placeOrder" data-place-order>
                    Place order
                </x-ui.button>
                @unless ($canOrder)
                    <p class="mt-2 text-sm text-danger">Some items need attention. <a href="{{ route('cart') }}" class="font-semibold underline">Review your cart</a>.</p>
                @endunless

                {{-- Card brands, below the button. --}}
                <x-checkout.payment-logos :names="['visa', 'mastercard']" class="mt-4" />

                <p class="mt-3 text-center text-xs text-ink-muted">
                    By placing your order you agree to our <a href="{{ route('terms') }}" class="underline" target="_blank" rel="noopener">terms and conditions</a>.
                </p>
                <p class="mt-2 flex items-center justify-center gap-1.5 text-xs text-ink-muted" data-secure-note>
                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 10V7a4 4 0 0 0-8 0v3M6 10h12v10H6V10Z"/></svg>
                    Secure checkout over an encrypted connection
                </p>
            </aside>
        </form>
    @endif
</div>
