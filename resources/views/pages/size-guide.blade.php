{{-- Size guide page content. Wrap in the same layout as the other storefront pages.
     Footwear data: EU size with UK/AU and US equivalents, men and women, single and jump sizes.
     Workwear and glove charts are from the Safety Jogger size guide. Verify before publishing:
     the men's trousers UK row shows 33 for EU 48, which breaks the step-by-2 pattern. --}}
<x-layouts.storefront title="Size Guide" description="Size guide for safety footwear, workwear and gloves: UK to EU size conversions and measuring advice.">
<style>[x-cloak] { display: none !important; }</style>

<div
    class="w-full py-10"
    x-data="{
        tabs: ['shoes', 'workwear', 'gloves'],
        tab: 'shoes',
        init() {
            const fromHash = () => {
                const name = window.location.hash.slice(1);
                if (this.tabs.includes(name)) this.tab = name;
            };
            fromHash();
            window.addEventListener('hashchange', fromHash);
        },
        select(name) {
            this.tab = name;
            history.replaceState(null, '', '#' + name);
        },
        move(step) {
            const next = this.tabs[(this.tabs.indexOf(this.tab) + step + this.tabs.length) % this.tabs.length];
            this.select(next);
            this.$nextTick(() => this.$refs[next].focus());
        },
        first() { this.select(this.tabs[0]); this.$nextTick(() => this.$refs[this.tabs[0]].focus()); },
        last() { const n = this.tabs[this.tabs.length - 1]; this.select(n); this.$nextTick(() => this.$refs[n].focus()); },
    }"
>
    <h1 class="text-3xl font-semibold text-brand-navy">Find your perfect size with the our Size Guide</h1>
    <p class="mt-3 text-ink">
        Use these charts to choose the right size. If you are unsure,
        <a href="{{ route('contact') }}" class="font-semibold text-brand-blue underline">contact us</a>
        and we will help.
    </p>

    <div
        class="mt-8 flex flex-wrap gap-1 border-b border-border"
        role="tablist"
        aria-label="Size guide categories"
        x-on:keydown.arrow-right.prevent="move(1)"
        x-on:keydown.arrow-left.prevent="move(-1)"
        x-on:keydown.home.prevent="first()"
        x-on:keydown.end.prevent="last()"
    >
        <button
            type="button" role="tab" id="tab-shoes" aria-controls="panel-shoes" x-ref="shoes"
            x-on:click="select('shoes')"
            x-bind:aria-selected="tab === 'shoes'" x-bind:tabindex="tab === 'shoes' ? 0 : -1"
            x-bind:class="tab === 'shoes' ? 'border-brand-blue text-brand-navy' : 'border-transparent text-ink hover:border-border'"
            class="-mb-px whitespace-nowrap border-b-4 px-4 py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue"
        >Footwear</button>
        <button
            type="button" role="tab" id="tab-workwear" aria-controls="panel-workwear" x-ref="workwear"
            x-on:click="select('workwear')"
            x-bind:aria-selected="tab === 'workwear'" x-bind:tabindex="tab === 'workwear' ? 0 : -1"
            x-bind:class="tab === 'workwear' ? 'border-brand-blue text-brand-navy' : 'border-transparent text-ink hover:border-border'"
            class="-mb-px whitespace-nowrap border-b-4 px-4 py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue"
        >Workwear</button>
        <button
            type="button" role="tab" id="tab-gloves" aria-controls="panel-gloves" x-ref="gloves"
            x-on:click="select('gloves')"
            x-bind:aria-selected="tab === 'gloves'" x-bind:tabindex="tab === 'gloves' ? 0 : -1"
            x-bind:class="tab === 'gloves' ? 'border-brand-blue text-brand-navy' : 'border-transparent text-ink hover:border-border'"
            class="-mb-px whitespace-nowrap border-b-4 px-4 py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue"
        >Gloves</button>
    </div>

    {{-- Footwear --}}
    <section
        id="panel-shoes" role="tabpanel" aria-labelledby="tab-shoes" tabindex="0"
        class="mt-8 space-y-6"
        x-show="tab === 'shoes'"
    >
        <h2 class="text-2xl font-semibold text-brand-navy">Footwear sizes</h2>
        <p class="text-ink">
            Find your EU size, then read across for the UK/AU and US equivalents. Jump sizes cover
            two EU sizes in one fit. Fit can vary between brands and models.
        </p>
        <div class="space-y-3">
          <h3 class="text-lg font-semibold text-brand-navy">Men</h3>
          <div class="overflow-x-auto" role="region" aria-label="Men footwear sizes" tabindex="0">
            <table class="table-fixed border-collapse text-sm" style="width: 103rem">
              <colgroup><col style="width: 5rem"><col span="14" style="width: 7rem"></colgroup>
              <caption class="sr-only">Men footwear sizes: EU size with the UK/AU and US equivalents</caption>
              <thead>
                <tr>
                  <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-bold text-white">EU</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">35</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">36</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">37</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">38</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">39</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">40</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">41</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">42</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">43</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">44</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">45</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">46</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">47</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">48</th>
                </tr>
              </thead>
              <tbody>
                <tr class="bg-surface-muted">
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">UK/AU</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">5.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">11.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">12.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">13.0</td>
                </tr>
                <tr>
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">US</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">5.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">11.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">12.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">13.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">13.5</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="space-y-3">
          <h3 class="text-lg font-semibold text-brand-navy">Men - Jump Sizes</h3>
          <div class="overflow-x-auto" role="region" aria-label="Men - Jump Sizes footwear sizes" tabindex="0">
            <table class="table-fixed border-collapse text-sm" style="width: 54rem">
              <colgroup><col style="width: 5rem"><col span="7" style="width: 7rem"></colgroup>
              <caption class="sr-only">Men - Jump Sizes footwear sizes: EU size with the UK/AU and US equivalents</caption>
              <thead>
                <tr>
                  <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-bold text-white">EU</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">35/36</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">37/38</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">39/40</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">41/42</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">43/44</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">45/46</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">47/48</th>
                </tr>
              </thead>
              <tbody>
                <tr class="bg-surface-muted">
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">UK/AU</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0/3.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.0/5.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.0/6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.0/8.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.0/10.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.5/11.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">12.0/13.0</td>
                </tr>
                <tr>
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">US</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0/4.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.5/5.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5/7.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0/9.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.0/10.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">11.5/12.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">13.0/13.5</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="space-y-3">
          <h3 class="text-lg font-semibold text-brand-navy">Women</h3>
          <div class="overflow-x-auto" role="region" aria-label="Women footwear sizes" tabindex="0">
            <table class="table-fixed border-collapse text-sm" style="width: 61rem">
              <colgroup><col style="width: 5rem"><col span="8" style="width: 7rem"></colgroup>
              <caption class="sr-only">Women footwear sizes: EU size with the UK/AU and US equivalents</caption>
              <thead>
                <tr>
                  <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-bold text-white">EU</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">35</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">36</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">37</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">38</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">39</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">40</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">41</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">42</th>
                </tr>
              </thead>
              <tbody>
                <tr class="bg-surface-muted">
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">UK/AU</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">5.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0</td>
                </tr>
                <tr>
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">US</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">5.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">10.5</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="space-y-3">
          <h3 class="text-lg font-semibold text-brand-navy">Women - Jump Sizes</h3>
          <div class="overflow-x-auto" role="region" aria-label="Women - Jump Sizes footwear sizes" tabindex="0">
            <table class="table-fixed border-collapse text-sm" style="width: 33rem">
              <colgroup><col style="width: 5rem"><col span="4" style="width: 7rem"></colgroup>
              <caption class="sr-only">Women - Jump Sizes footwear sizes: EU size with the UK/AU and US equivalents</caption>
              <thead>
                <tr>
                  <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-bold text-white">EU</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">35/36</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">37/38</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">39/40</th>
                  <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-bold">41/42</th>
                </tr>
              </thead>
              <tbody>
                <tr class="bg-surface-muted">
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">UK/AU</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">3.0/3.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">4.0/5.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.0/6.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">7.0/8.0</td>
                </tr>
                <tr>
                  <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-normal text-ink">US</th>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">5.5/6.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">6.5/7.5</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">8.0/9.0</td>
                  <td class="border border-border px-3 py-2 text-center whitespace-nowrap font-normal text-ink">9.5/10.5</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
    </section>

    {{-- Workwear --}}
    <section
        id="panel-workwear" role="tabpanel" aria-labelledby="tab-workwear" tabindex="0"
        class="mt-8 space-y-8" x-cloak
        x-show="tab === 'workwear'"
    >
        <div class="space-y-4">
            <h2 class="text-2xl font-semibold text-brand-navy">Workwear sizes</h2>
            <p class="text-ink">
                All measurements are body measurements in centimetres, not garment measurements.
                These charts apply to the Safety Jogger range. Other brands may size differently,
                so check the product page.
            </p>
            <h3 class="text-lg font-semibold text-brand-navy">How to measure</h3>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[10rem_1fr]">
                <dt class="font-semibold text-brand-navy">Chest</dt>
                <dd class="text-ink">Around the widest part of the chest. Keep the tape level across the back and do not pull it tight.</dd>
                <dt class="font-semibold text-brand-navy">Waist</dt>
                <dd class="text-ink">Men: around the body at belly button height. Women: around the narrowest part of the waist.</dd>
                <dt class="font-semibold text-brand-navy">Hip</dt>
                <dd class="text-ink">Around the widest part of the hips.</dd>
                <dt class="font-semibold text-brand-navy">Inside leg</dt>
                <dd class="text-ink">From the crotch to just below the ankle bone, with the tape pulled straight.</dd>
                <dt class="font-semibold text-brand-navy">Total height</dt>
                <dd class="text-ink">From the floor to the top of the head, standing upright without shoes.</dd>
            </dl>
        </div>

        <div class="space-y-3">
            <h3 class="text-lg font-semibold text-brand-navy">Men: jackets and tops</h3>
            <div class="overflow-x-auto" role="region" aria-label="Men's jackets and tops size table" tabindex="0">
              <table class="table-fixed border-collapse text-sm" style="width: 53.5rem">
              <colgroup><col style="width: 11.5rem"><col span="7" style="width: 6rem"></colgroup>
                <caption class="sr-only">Men's jackets and tops, chest measurement in centimetres</caption>
                <thead>
                  <tr>
                    <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-semibold text-white">Size</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">XS</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">S</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">M</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">L</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">XL</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">XXL</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">3XL</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Chest</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-86</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">86-94</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">94-102</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">102-110</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">110-118</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">118-128</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">128-140</td>
                  </tr>
                </tbody>
              </table>
            </div>
        </div>

        <div class="space-y-3">
            <h3 class="text-lg font-semibold text-brand-navy">Men: trousers and shorts</h3>
            <div class="overflow-x-auto" role="region" aria-label="Men's trousers and shorts size table" tabindex="0">
              <table class="table-fixed border-collapse text-sm" style="width: 89.5rem">
              <colgroup><col style="width: 11.5rem"><col span="13" style="width: 6rem"></colgroup>
                <caption class="sr-only">Men's trousers and shorts, measurements in centimetres</caption>
                <thead>
                  <tr>
                    <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-semibold text-white">EU size</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">40</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">42</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">44</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">46</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">48</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">50</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">52</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">54</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">56</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">58</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">60</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">62</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">64</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">FR / PT / CH / ES</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">34</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">36</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">38</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">40</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">42</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">44</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">46</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">48</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">50</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">52</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">54</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">56</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">58</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">UK</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">26</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">28</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">30</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">32</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">33</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">34</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">36</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">38</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">40</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">42</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">44</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">46</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">48</td>
                  </tr>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Waist</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">66-70</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">70-74</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">74-78</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">78-82</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">82-86</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">86-90</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">90-94</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">94-99</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">99-105</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">105-110</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">110-114</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">114-119</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">119-124</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Hip</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">82-86</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">86-90</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">90-94</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">94-98</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">98-102</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">102-106</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">106-110</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">110-114</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">114-118</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">118-122</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">122-126</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">126-130</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">130-134</td>
                  </tr>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Inside leg</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">77-81</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">77-81</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">78-82</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">79-83</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">80-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">81-85</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">82-86</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">83-87</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Total height</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">176-180</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182-186</td>
                  </tr>
                </tbody>
              </table>
            </div>
        </div>

        <div class="space-y-3">
            <h3 class="text-lg font-semibold text-brand-navy">Women: jackets and tops</h3>
            <div class="overflow-x-auto" role="region" aria-label="Women's jackets and tops size table" tabindex="0">
              <table class="table-fixed border-collapse text-sm" style="width: 41.5rem">
              <colgroup><col style="width: 11.5rem"><col span="5" style="width: 6rem"></colgroup>
                <caption class="sr-only">Women's jackets and tops, measurements in centimetres</caption>
                <thead>
                  <tr>
                    <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-semibold text-white">Size</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">XS</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">S</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">M</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">L</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">XL</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Chest</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">74-82</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">82-90</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">90-98</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">98-107</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">107-119</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Hip</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">86-92</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">92-98</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">98-104</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">104-112</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">112-122</td>
                  </tr>
                </tbody>
              </table>
            </div>
        </div>

        <div class="space-y-3">
            <h3 class="text-lg font-semibold text-brand-navy">Women: trousers and shorts</h3>
            <div class="overflow-x-auto" role="region" aria-label="Women's trousers and shorts size table" tabindex="0">
              <table class="table-fixed border-collapse text-sm" style="width: 71.5rem">
              <colgroup><col style="width: 11.5rem"><col span="10" style="width: 6rem"></colgroup>
                <caption class="sr-only">Women's trousers and shorts, measurements in centimetres</caption>
                <thead>
                  <tr>
                    <th scope="col" class="sticky left-0 z-10 border border-border bg-brand-navy px-3 py-2 text-left whitespace-nowrap font-semibold text-white">EU size</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">32</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">34</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">36</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">38</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">40</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">42</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">44</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">46</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">48</th>
                    <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">50</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">FR / PT / CH / ES</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">34</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">36</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">38</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">40</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">42</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">44</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">46</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">48</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">50</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">52</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">UK</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">4</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">6</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">8</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">10</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">12</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">14</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">16</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">18</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">20</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">22</td>
                  </tr>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Waist</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">63-66</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">66-69</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">69-72</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">72-76</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-80</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">80-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">84-88</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">88-92</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">92-98</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">98-104</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Hip</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">86-89</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">89-92</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">92-95</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">95-98</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">98-101</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">101-104</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">104-108</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">108-112</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">112-116</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">116-120</td>
                  </tr>
                  <tr class="bg-surface-muted">
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Inside leg</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">76-84</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">75-83</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">75-83</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">75-83</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">75-83</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">74-82</td>
                  </tr>
                  <tr>
                    <th scope="row" class="sticky left-0 z-10 border border-border bg-white px-3 py-2 text-left whitespace-nowrap font-semibold text-brand-navy">Total height</th>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                    <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">164-172</td>
                  </tr>
                </tbody>
              </table>
            </div>
        </div>
    </section>

    {{-- Gloves --}}
    <section
        id="panel-gloves" role="tabpanel" aria-labelledby="tab-gloves" tabindex="0"
        class="mt-8 space-y-6" x-cloak
        x-show="tab === 'gloves'"
    >
                <div class="grid gap-8 lg:grid-cols-[1fr_calc((100%_-_2rem)*0.4)] lg:items-end">
          <div class="space-y-6">
<h2 class="text-2xl font-semibold text-brand-navy">Glove sizes</h2>
        <p class="max-w-2xl text-ink">
            Measure your hand length from the wrist to the tip of the middle finger, and your hand
            circumference around the widest part of the palm, excluding the thumb, then compare them
            with the chart. The cuff binding colour applies to Safety Jogger gloves.
        </p>
        <div class="overflow-x-auto" role="region" aria-label="Glove size table" tabindex="0">
          <table class="min-w-max border-collapse text-sm">
            <caption class="sr-only">Glove sizes with hand length and hand circumference in millimetres</caption>
            <thead>
              <tr>
                <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">Glove size</th>
                <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">Hand length (mm)</th>
                <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">Hand circumference (mm)</th>
                <th scope="col" class="border border-border px-3 py-2 text-center whitespace-nowrap bg-brand-navy text-white font-semibold">Cuff binding colour</th>
              </tr>
            </thead>
            <tbody>
              <tr class="bg-surface-muted">
                <th scope="row" class="border border-border px-3 py-2 text-center font-semibold text-brand-navy">7</th>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">171</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">178</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink"><span class="inline-flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full border border-border bg-swatch-red" aria-hidden="true"></span>Red</span></td>
              </tr>
              <tr>
                <th scope="row" class="border border-border px-3 py-2 text-center font-semibold text-brand-navy">8</th>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">182</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">203</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink"><span class="inline-flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full border border-border bg-swatch-yellow" aria-hidden="true"></span>Yellow</span></td>
              </tr>
              <tr class="bg-surface-muted">
                <th scope="row" class="border border-border px-3 py-2 text-center font-semibold text-brand-navy">9</th>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">192</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">229</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink"><span class="inline-flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full border border-border bg-swatch-brown" aria-hidden="true"></span>Brown</span></td>
              </tr>
              <tr>
                <th scope="row" class="border border-border px-3 py-2 text-center font-semibold text-brand-navy">10</th>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">204</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">254</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink"><span class="inline-flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full border border-border bg-swatch-charcoal" aria-hidden="true"></span>Black</span></td>
              </tr>
              <tr class="bg-surface-muted">
                <th scope="row" class="border border-border px-3 py-2 text-center font-semibold text-brand-navy">11</th>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">215</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink">279</td>
                <td class="border border-border px-3 py-2 text-center whitespace-nowrap text-ink"><span class="inline-flex items-center gap-2"><span class="inline-block h-3 w-3 rounded-full border border-border bg-swatch-blue" aria-hidden="true"></span>Blue</span></td>
              </tr>
            </tbody>
          </table>
        </div>
          </div>
          <figure>
            <img src="{{ Vite::asset('resources/images/size-guide/glove-measurements.png') }}" alt="Glove diagram: A is the hand length, from the wrist to the tip of the middle finger. B is the hand circumference, around the widest part of the palm. C is the cuff binding colour." width="457" height="352" loading="lazy" class="h-auto w-full max-w-lg">
          </figure>
        </div>
    </section>
</div>
</x-layouts.storefront>
