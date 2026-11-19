{{--
    Stripe.js and the checkout's card form logic. Loaded only on pages that can take a card payment (the
    checkout layout includes it while card payment is configured). The form is `cardCheckout()`, used as the
    checkout form's x-data:

      1. the customer presses "Pay by card";
      2. Stripe checks the card details they typed (elements.submit);
      3. the server validates the checkout, builds the draft order and the payment intent (startCardPayment);
      4. Stripe confirms the payment, and sends the customer to the return page, which places the order.

    Card numbers are typed into Stripe's own frame and never reach this server.
--}}
<script src="https://js.stripe.com/v3/"></script>
<script>
    window.cardCheckout = function (config) {
        return {
            stripe: null,
            elements: null,
            busy: false,
            error: null,

            init() {
                this.stripe = Stripe(config.key);
                // The amount is only for wallets and is refreshed before paying; the card is always taken for the order total.
                this.elements = this.stripe.elements({
                    mode: 'payment',
                    amount: config.amount > 0 ? config.amount : 100,
                    currency: 'gbp',
                    paymentMethodTypes: ['card'],
                });
                this.elements.create('payment', {
                    fields: { billingDetails: { name: 'never', email: 'never', phone: 'never', address: 'never' } },
                }).mount(this.$refs.card);
            },

            async pay() {
                if (this.busy) return;
                this.busy = true;
                this.error = null;

                try {
                    const amount = await this.$wire.cardAmount();
                    if (amount > 0) this.elements.update({ amount });

                    const submitted = await this.elements.submit();
                    if (submitted.error) {
                        this.error = submitted.error.message;
                        return;
                    }

                    // Null when the checkout needs fixing: the page shows what.
                    const started = await this.$wire.startCardPayment();
                    if (! started) return;

                    const wire = this.$wire;
                    const same = wire.billing_same;
                    const result = await this.stripe.confirmPayment({
                        elements: this.elements,
                        clientSecret: started.client_secret,
                        confirmParams: {
                            return_url: started.return_url,
                            payment_method_data: {
                                billing_details: {
                                    name: wire.full_name,
                                    email: wire.email,
                                    phone: wire.phone,
                                    address: {
                                        line1: same ? wire.ship_line_one : wire.bill_line_one,
                                        line2: (same ? wire.ship_line_two : wire.bill_line_two) || '',
                                        city: same ? wire.ship_city : wire.bill_city,
                                        state: (same ? wire.ship_county : wire.bill_county) || '',
                                        postal_code: same ? wire.ship_postcode : wire.bill_postcode,
                                        country: 'GB',
                                    },
                                },
                            },
                        },
                    });

                    // Only reached when the card was declined or the form is incomplete: a success leaves the page.
                    if (result.error) this.error = result.error.message;
                } catch (error) {
                    this.error = 'We could not start the card payment. Please try again.';
                } finally {
                    this.busy = false;
                }
            },
        };
    };
</script>
