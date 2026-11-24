{{--
    Technical features page, in the storefront layout.
    Route: /technical-features (route name technical-features), see routes/web.php.

    Images are hot-linked from the old site ($wp) and from safetyjogger.com ($sj).
    To self-host later, download the files and change these two base URLs only.
--}}
@php
    $wp = 'https://frontline-footwear.com/wp-content/uploads/';
    $sj = 'https://www.safetyjogger.com/image/technical/esd/';
@endphp

<x-layouts.storefront title="Technical Features" description="Technical features of safety footwear explained: safety icons, steel and composite toe caps, slip resistance, waterproofing, ESD and more.">
<style>
    [x-cloak] { display: none !important; }

    .tf-prose { color: var(--color-ink); font-size: 1rem; line-height: 1.7; }
    .tf-prose > * + * { margin-top: 1rem; }
    .tf-prose > :first-child { margin-top: 0; }
    .tf-prose h2 { margin-top: 2rem; font-size: 1.625rem; line-height: 1.25; font-weight: 700; color: var(--color-brand-navy); }
    .tf-prose h3 { margin-top: 1.75rem; font-size: 1.25rem; line-height: 1.3; font-weight: 700; color: var(--color-brand-blue); }
    .tf-prose a { color: var(--color-brand-blue); text-decoration: underline; text-underline-offset: 2px; }
    .tf-prose ul { list-style: disc; padding-left: 1.25rem; }
    .tf-prose li + li { margin-top: 0.5rem; }
    .tf-prose ul.tf-plain { list-style: none; padding-left: 0; }
    .tf-prose ul.tf-plain li { margin-top: 0; padding: 0.625rem 0; border-top: 1px solid var(--color-border); }
    .tf-prose ul.tf-plain li:first-child { border-top: 0; }
    .tf-prose img { display: block; max-width: 100%; height: auto; border-radius: 0.5rem; }
    .tf-prose table { width: 100%; border-collapse: collapse; }
    .tf-prose td { padding: 0.5rem 0.75rem; border: 1px solid var(--color-border); vertical-align: top; }
    .tf-prose td:first-child { white-space: nowrap; font-weight: 600; }
    .tf-prose .tf-card { border: 1px solid var(--color-border); border-radius: 0.5rem; padding: 1.25rem; background: var(--color-surface-muted); }
    .tf-prose .tf-card h3 { margin-top: 0; }
    .tf-prose .tf-note { border-left: 4px solid var(--color-brand-blue); background: var(--color-surface-muted); padding: 1rem 1.25rem; border-radius: 0.25rem; }
</style>

<div
    class="w-full"
    x-data="{
        tab: 'safety-icons',
        tabs: [
            { id: 'safety-icons', label: 'SAFETY ICONS' },
            { id: 'steel-vs-composite-toe', label: 'STEEL vs. COMPOSITE TOE' },
            { id: 'breathable-leather-boots', label: 'BREATHABLE LEATHER BOOTS' },
            { id: 'hydrolysis-explained', label: 'HYDROLYSIS EXPLAINED' },
            { id: 'slip-resistance', label: 'SLIP RESISTANCE' },
            { id: 'electrical-hazard-boots', label: 'ELECTRICAL HAZARD BOOTS' },
            { id: 'puncture-resistant-boots', label: 'PUNCTURE RESISTANT BOOTS' },
            { id: 'waterproof-boots', label: 'WATERPROOF BOOTS' },
            { id: 'heavy-duty-boots', label: 'HEAVY DUTY BOOTS' },
            { id: 'esd-or-antistatic', label: 'ESD OR ANTISTATIC?' }
        ],
        vertical: false,
        init() {
            // From the lg breakpoint the pills are a menu down the left, so the tab list is vertical there.
            const wide = window.matchMedia('(min-width: 1024px)');
            this.vertical = wide.matches;
            wide.addEventListener('change', e => this.vertical = e.matches);
            this.fromHash();
        },
        fromHash() {
            const id = location.hash.slice(1);
            if (this.tabs.some(t => t.id === id)) this.tab = id;
        },
        select(id) {
            this.tab = id;
            history.replaceState(null, '', '#' + id);
        },
        move(step, focus = true) {
            const i = this.tabs.findIndex(t => t.id === this.tab);
            const next = this.tabs[(i + step + this.tabs.length) % this.tabs.length].id;
            this.select(next);
            if (focus) this.$nextTick(() => document.getElementById('tab-' + next)?.focus());
        },
        first() { this.select(this.tabs[0].id); this.$nextTick(() => document.getElementById('tab-' + this.tab)?.focus()); },
        last() { this.select(this.tabs[this.tabs.length - 1].id); this.$nextTick(() => document.getElementById('tab-' + this.tab)?.focus()); }
    }"
    @hashchange.window="fromHash()"
>
    <x-ui.section-heading title="Technical features" tag="h1" />

    {{-- Menu on the left from lg (stuck in view while the page scrolls); wrapped pills above the content on smaller screens. --}}
    <div class="mt-8 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-10" data-tf-layout>

    {{-- Tab list --}}
    <div
        class="flex flex-wrap gap-2 lg:sticky lg:top-6 lg:flex-col lg:flex-nowrap"
        role="tablist"
        aria-label="Technical features"
        :aria-orientation="vertical ? 'vertical' : 'horizontal'"
        data-tf-menu
        @keydown.arrow-right.prevent="move(1)"
        @keydown.arrow-left.prevent="move(-1)"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.home.prevent="first()"
        @keydown.end.prevent="last()"
    >
        <template x-for="t in tabs" :key="t.id">
            <button
                type="button"
                role="tab"
                :id="'tab-' + t.id"
                :aria-selected="tab === t.id ? 'true' : 'false'"
                :aria-controls="'panel-' + t.id"
                :tabindex="tab === t.id ? 0 : -1"
                @click="select(t.id)"
                class="rounded-md border px-3 py-2 text-xs font-semibold tracking-wide transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 sm:text-sm lg:w-full lg:text-left"
                :class="tab === t.id
                    ? 'border-brand-blue bg-brand-blue text-white'
                    : 'border-border bg-white text-ink'"
                x-text="t.label"
            ></button>
        </template>
    </div>

    <div class="mt-8 min-w-0 lg:mt-0" data-tf-content>

        {{-- 1. SAFETY ICONS --}}
        <section id="panel-safety-icons" role="tabpanel" aria-labelledby="tab-safety-icons" tabindex="0" x-show="tab === 'safety-icons'" x-cloak class="tf-prose">
            <h2>Are you up on your feet all day working in heavy environments?</h2>
            <p>Safety footwear makes for an essential component when it comes to your day to day functioning within the work place. Thanks to our continuous investment in innovation and extensive product testing, we at Frontline Footwear are able to offer you a wide range of technical features that compliment our quality footwear range.</p>
            <img src="{{ $wp }}2021/12/image_2021_12_27T14_10_10_844Z.png" alt="Overview of safety footwear features" width="816" height="550" loading="lazy" decoding="async" class="mx-auto">
            <img src="{{ $wp }}2021/12/unnamed-1.png" alt="Safety footwear standard icons, part 1" loading="lazy" decoding="async" class="w-full">
            <img src="{{ $wp }}2021/12/unnamed-2.png" alt="Safety footwear standard icons, part 2" loading="lazy" decoding="async" class="w-full">
        </section>

        {{-- 2. STEEL vs. COMPOSITE TOE --}}
        <section id="panel-steel-vs-composite-toe" role="tabpanel" aria-labelledby="tab-steel-vs-composite-toe" tabindex="0" x-show="tab === 'steel-vs-composite-toe'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-2-1.jpg" alt="" loading="lazy" decoding="async" class="w-full">
            <h2>Steel or composite toe boots, what do I need?</h2>
            <p>Safety toe work boots are designed to protect against a variety of hazards and injuries. The footwear and its protective grade is best chosen after evaluation (risk assessment) of the potential dangers in the work environment. Safety toe work boots should always be used as a last resort, it is the final line of defence against hazards, after all other reasonable or practical measures have been taken to manage the risks of the workplace. Impact, compression and puncture are the most common types of foot injury.</p>
            <p>Common dangers are related to the kind of materials that are handled by the worker, risks of objects falling on or rolling over the feet, blades that might cut the top of the feet... Below we are going a little deeper into the differences between steel toe work boots and composite toe work boots.</p>
            <p>Do you need advice or do you still have a question about steel or composite toe work boots after reading this page?</p>
            <img src="{{ $wp }}2021/12/Untitled-1.png" alt="Steel and composite toe caps compared" width="884" height="304" loading="lazy" decoding="async">
            <h3>Steel vs composite toe work boots</h3>
            <p>The difference in steel and composite toe caps If you’re involved in the purchase of safety toe work boots, it is important not just to know about safety footwear codes and standards, but also to have an understanding of the materials they are made from. You will see safety toe work boots that have steel toe caps in their specifications while others have composite toe caps. Strange, because both materials have the same purpose. A protective toe cap made according to European or ASTM standard will protect the feet from impact, regardless the material. But than what exactly is the difference between steel toe work boots and composite toe work boots and what could be your best choice? An understanding of the differences between steel toe work boots and composite toe work boots means that you can make a confident and correct purchase for your work activities. This list, with all the differences you should know about steel toe work boots and composite toe work boots, will help you cut the knot!</p>
            <h3>Steel toe cap</h3>
            <ul>
                <li>Made from steel only</li>
                <li>Used since 1930’s and steel toe caps are still very popular</li>
                <li>Naturally stronger than composite toe caps, a thinner layer is used in the work boots to reach the same strength as a composite toe</li>
                <li>Lower cost compared to composite toe work boots</li>
                <li>Conducting cold, heat and electricity.</li>
            </ul>
            <h3>Composite toe cap</h3>
            <ul>
                <li>Made from non-metal materials such as fiberglass, carbon fiber or plastics</li>
                <li>Gaining popularity due to several benefits in comfort</li>
                <li>Lighter compared to a steel toe, reducing stress and fatigue on the legs and joints throughout long workdays</li>
                <li>Mostly used in a higher quality- and price range of safety toe work boots</li>
                <li>Not conducting heat or cold in extreme temperatures, for extra comfort</li>
                <li>More thick and bulky compared to steel toe work boots</li>
                <li>Nanocarbon toe caps are a solution for the dimensional problems, as they are very lightweight and thin toe caps</li>
                <li>Metal-free work boots, practical when working in electrical hazardous environments, or workplaces with metal detectors such as courts, airports.</li>
            </ul>
        </section>

        {{-- 3. BREATHABLE LEATHER BOOTS --}}
        <section id="panel-breathable-leather-boots" role="tabpanel" aria-labelledby="tab-breathable-leather-boots" tabindex="0" x-show="tab === 'breathable-leather-boots'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-4.jpg" alt="" loading="lazy" decoding="async" class="w-full">
            <h2>Breathable leather work boots means allround quality around the foot</h2>
            <h3>Breathability / tear-resistance / high resistance to puncture &amp; much more ...</h3>
            <p>When you’re up on your feet working all day long it goes without saying that your work boots make up a vital part of your equipment. With advances in faux or synthetic leather, real leather work boots will always stand out, with the main advantages being breathability and strength. It's important to keep your feet as cool as possible when you work up a sweat. Almost every Safety Jogger work boot has a breathable leather upper to keep your feet cool and refreshing so the chance of smelly feet, foot odor or fungal infection won’t ruin your daily afterwork life.</p>
            <p>Besides the fact that real leather is vital for the ventilation inside a work boot, leather work boots are much stronger than normal work boots plus leather stretches way more than synthetics so they follow the shape of your feet throughout the day.</p>
        </section>

        {{-- 4. HYDROLYSIS EXPLAINED --}}
        <section id="panel-hydrolysis-explained" role="tabpanel" aria-labelledby="tab-hydrolysis-explained" tabindex="0" x-show="tab === 'hydrolysis-explained'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-5.jpg" alt="" loading="lazy" decoding="async" class="w-full">
            <h2>Hydrolysis and Polyurethane Explained</h2>
            <div class="flow-root">
                <img src="{{ $wp }}2021/12/unnamed-6.jpg" alt="Polyurethane sole construction" loading="lazy" decoding="async" class="mb-4 w-full md:float-left md:mr-6 md:w-1/2">
                <p>Many of our shoes have the soles attached to the uppers by a process where 2 materials are brought together in a mould, the resultant chemical reaction forms <a href="https://en.wikipedia.org/wiki/Polyurethane" target="_blank" rel="noopener">polyurethane (PU)</a>. the use of polyurethane makes a lightweight flexible sole which is not only shock absorbent but also extremely hard wearing, that's why polyurethane is ideal for shoes.</p>
                <p class="mt-4">One of the main technical challenges with polyurethane is overcoming an ageing deterioration known as ‘hydrolysis’. Hydrolysis is the chemical breakdown of the PU polymer and the resulting physical breakdown or crumbling of the PU sole by the attack of water (usually in vapour form), occurring over a period of several years (even when the shoes are in store!).</p>
                <p class="mt-4">This process is accelerated by warmth and high humidity. It will, therefore, happen more quickly in tropical climates, but also in confined spaces (such as lockers) if the safety shoes are put away damp. In the most advanced state of hydrolysis, the PU sole will lose all its physical strength, thus cracking or crumbling.</p>
                <p class="mt-4">The Safety Jogger PU sole has been improved to increase the resistance against hydrolysis and performs better than any average safety shoe. Below you will read why!</p>
            </div>
            <h3>The Safety Jogger PU sole</h3>
            <p>There are 2 types of PU soles. There is a Polyether based PU sole and a Polyester based PU sole. Polyether based PU soles have a high resistance against hydrolysis and low oil resistance. Polyester based PU soles have a low resistance against hydrolysis and high oil resistance.</p>
            <p>Safety shoes are always using a polyester based PU sole. The basic PU sole used in most safety shoes on the market lasts for 1.5 year in tropical conditions and 3 years in normal conditions.</p>
            <p>The Safety Jogger PU sole, upgraded with improved resistance against hydrolysis, lasts for 4 years in tropical conditions and 8 years in normal conditions. <a href="https://www.safetyjogger.com/en/catalog#!/list?safety_maincategory=Safety%20Shoes" target="_blank" rel="noopener">Making our safety shoes a reliable product of endurance.</a></p>
            <img src="{{ $wp }}2021/12/unnamed.gif" alt="Lifespan of a standard PU sole compared with the Safety Jogger PU sole" loading="lazy" decoding="async" class="w-full">
        </section>

        {{-- 5. SLIP RESISTANCE --}}
        <section id="panel-slip-resistance" role="tabpanel" aria-labelledby="tab-slip-resistance" tabindex="0" x-show="tab === 'slip-resistance'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-7.jpg" alt="" width="1920" height="742" loading="lazy" decoding="async" class="w-full">
            <h2>Slip resistance is vital for PPE</h2>
            <h3>The Slip resistant sole experts</h3>
            <p>As a safety shoe company we have to take a number of factors in account. Slip resistant soles however are one of the most vital elements in safety footwear, also according to the <a href="https://www.safetyjogger.com/en/academy/safety-shoes-standards-and-certifications" target="_blank" rel="noopener">EN ISO 20345 standard</a> every safety footwear must have at least one type of slip resistant sole.</p>
            <p>Slipping, tripping and falling are the main causes of accidents on the work floor. They often cause serious injuries to installers, construction workers, plumbers, oil workers and ambulance personnel. This leads often more than three days of absence at work.</p>
            <p>Safety Jogger offers a wide selection of shoes with an outsole that exceeds the generally applicable European slip resistance standards by far. As a result, you minimise the risk to trip or slide on a slippery surface.</p>
            <img src="{{ $wp }}2021/12/unnamed-18.jpg" alt="Slip resistant outsole" width="448" height="397" loading="lazy" decoding="async">
            <h3>SRA, SRB &amp; SRC - what's the difference?</h3>
            <p>Almost every Safety Jogger product is provided with an SRC certified slip resistant sole, meaning the slip resistance of the sole was tested on a ceramic tile wetted with a dilute soap solution and on a smooth steel plate with glycerol. Safety Jogger soles pass both tests with glance and do not only fulfill the minimum standards (SRA/SRB), but exceed them remarkably.</p>
            <p>Make sure you’re aware of the degree of slip resistance below your feet, accidents are expensive! All Safety Jogger products are tested in laboratory conditions to be granted with a slip resistant rating. However, there are shoes out there described as ‘slip resistant’ or ‘improved grip performance’ that haven’t been formally tested. You can be 100% sure the safety footwear is slip resistant when you identify a slip resistant rating SRA, SRB or SRC.</p>
            <img src="{{ $wp }}2021/12/unnamed-19.jpg" alt="SRA, SRB and SRC slip resistance ratings" width="1588" height="175" loading="lazy" decoding="async" class="w-full">
            <ul>
                <li>SRA slip resistant soles are tested on a ceramic tile wetted with dilute soap solution.</li>
                <li>SRB slip resistant soles are tested on smooth steel with glycerol.</li>
                <li>SRC slip resistant soles pass both SRA and SRB slip resistance tests.</li>
            </ul>
            <h3>Laboratory tests for slip resistant soles</h3>
            <img src="{{ $wp }}2021/12/unnamed-1.gif" alt="Slip resistance laboratory test" width="533" height="247" loading="lazy" decoding="async">
            <p>It’s important to understand the performance of an outsole in terms of slip resistance. Slip resistant sole testing is the science of measuring the coefficient of friction (or resistance to slip accidents) of flooring surfaces. To assess a slip resistant sole, a reliable, thoroughly researched (in interlaboratory studies) friction test method must be used, and a minimum safety criterion is needed to apply to the results.</p>
            <p>Safety Jogger’s first investigations on slip resistant soles can be traced back to the 90’s where we conducted studies using bare hands and simple test devices.</p>
            <p>Meanwhile, over the years, our testing facilities have grown, together with the brand, to high-end laboratories where we monitor the latest changes in safety standards and always try to meet or exceed them where possible. This is the result of continuous investments in innovation, extensive product testing in all stages and the knowhow of our team.</p>
        </section>

        {{-- 6. ELECTRICAL HAZARD BOOTS --}}
        <section id="panel-electrical-hazard-boots" role="tabpanel" aria-labelledby="tab-electrical-hazard-boots" tabindex="0" x-show="tab === 'electrical-hazard-boots'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-8.jpg" alt="" width="1915" height="593" loading="lazy" decoding="async" class="w-full">
            <h2>Electrical hazard (EH) rated safety boots</h2>
            <h3>What are electrical hazard rated safety boots?</h3>
            <p>Electrical hazard (EH) rated safety boots are ISOLATING safety boots meaning they provide a secondary source of protection for you from electric shocks or electrocution. The idea behind these EH rated safety boots is pretty simple, the entire surface of the boot is made from non-conductive materials and as a result no electrical charge will pass through the body, because you are fully isolated from the ground. EH rated safety boots have an Electrical Hazard (EH) rating on them, meaning they have been tested by the American Society for Testing and Materials <a href="https://www.safetyjogger.com/en/academy/safety-shoes-standards-and-certifications" target="_blank" rel="noopener">(ASTM)</a> for their protection against an electrical shock.</p>
            <div class="tf-note">
                <p><strong>IMPORTANT NOTE:</strong></p>
                <p class="mt-2">In some cases, EH rated safety boots can be affected by certain factors which decreases their potential to keep the wearer safe. The excessive wear of the soles or exposure of these EH boots to wet conditions, humid conditions or both can reduce the effectiveness. EH rated safety boots are usually worn when working in dry areas where the potential for electrocution is at high risk, do not wear these in wet, hazardous or explosive areas!</p>
            </div>
            <p>EH rated safety shoes are isolating safety shoes and cannot be confused with conductive safety shoes, like ESD or antistatic safety shoes, which have a whole other purpose.</p>
            <h3>Protect yourself from electrical hazards</h3>
            <p>Protection from electrocution or any other electrical hazard can be obtained in many ways. One of the most direct and efficient features to prevent electrical hazard is the use of insulating protective equipment inside safety footwear.</p>
            <p><a href="https://www.safetyjogger.com/en/catalog#!/list?safety_maincategory=Safety%20Shoes" target="_blank" rel="noopener">Safety Jogger offers EH rated boots</a> which protect from electrical hazard by insulation :</p>
            <ul>
                <li>according to <a href="https://www.safetyjogger.com/en/academy/safety-shoes-standards-and-certifications" target="_blank" rel="noopener">ASTM-F2412</a></li>
                <li>tested in production for 1minute at 18.000V, 60Hz, AC</li>
                <li>outsoles made in specific insulating rubber or PU compound</li>
            </ul>
            <img src="{{ $wp }}2021/12/unnamed-9-300x203.jpg" alt="Insulating outsole" width="300" height="203" loading="lazy" decoding="async">
            <h3>Health risks from electric contact</h3>
            <p>Electrocutions are one of the most frequent electrical hazards in construction sites. When in direct contact with electric high voltage equipment, such as overhead powerlines or transformer cabins, electrocution can be fatal by cardiac arrest. Each year they cause more than 300 deaths and 4,000 injuries. Electricity flows through conductors including metals, water, earth, but also the human body. When electrical equipment is functioning properly, the circuit is maintained between the tool, the wires and the electric source.</p>
            <p>However, in case of a malfunctioning machine or circuit, the operator can become part of the electric circuit. When in direct contact with a “live” wire, a person can feel a tingling sensation, over an intense electric shock, to ultimately a fatal electrocution, depending on the power, frequency and type (AC/DC) of electric source. Voltages over 50V AC or 120V DC are considered hazardous.</p>
            <img src="{{ $wp }}2021/12/unnamed-3-300x263.png" alt="Electric shock illustration" width="300" height="263" loading="lazy" decoding="async">
            <div class="overflow-x-auto">
                <table>
                    <tbody>
                        <tr><td>1 mA</td><td>faint tingle</td></tr>
                        <tr><td>5 mA</td><td>slight, not painful but disturbing shock</td></tr>
                        <tr><td>6-25 mA</td><td>painful shock, loss of muscular control</td></tr>
                        <tr><td>9-30 mA</td><td>freezing current, muscular contraction, but can also be pushed away from the circuit</td></tr>
                        <tr><td>50 - 150 mA</td><td>extreme pain, severe muscular contraction, respiratory arrest, death is possible</td></tr>
                        <tr><td>1.000 - 4.300 mA</td><td>heart fibrillation, muscular contraction and nerve damage, death is likely</td></tr>
                        <tr><td>10.000 mA</td><td>severe burns, cardiac arrest, death is probable</td></tr>
                    </tbody>
                </table>
            </div>
            <h3>Main causes of electrical injury or electrocution</h3>
            <p>Electric contact can occur in many ways and situations, we listed the most common below:</p>
            <ul>
                <li>defective or damaged tools, badly maintained equipment</li>
                <li>inadequate wiring, improper grounding</li>
                <li>unsafe work practice or environment</li>
                <li>unexpected exposition to electric parts</li>
                <li>contact with overhead power lines, high voltage circuits</li>
                <li>overloaded circuits, improper insulation</li>
            </ul>
            <img src="{{ $wp }}2021/12/unnamed-10.jpg" alt="Electrical hazard warning" width="535" height="188" loading="lazy" decoding="async">
        </section>

        {{-- 7. PUNCTURE RESISTANT BOOTS --}}
        <section id="panel-puncture-resistant-boots" role="tabpanel" aria-labelledby="tab-puncture-resistant-boots" tabindex="0" x-show="tab === 'puncture-resistant-boots'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-1-2.jpg" alt="" width="1920" height="742" loading="lazy" decoding="async" class="w-full">
            <h2>Puncture resistant boots, steel vs. fabric midsoles</h2>
            <p>Safety footwear and its protective grade is best chosen after evaluation (risk assessment) of the potential dangers in the work environment. The footwear should always be used as a last resort, it is the final line of defense against hazards, after all other reasonable or practical measures have been taken to manage the risks of the workplace. Impact, compression and puncture are the most common types of foot injury.</p>
            <p>Common dangers are related to the kind of materials that are handled by the worker, risks of objects falling on or rolling over the feet, blades that might cut the top of the feet, or sharp objects that could penetrate the outsole of the shoe.</p>
            <p>Below we are going a little deeper into puncture resistant boots and the differences between steel midsoles and woven fabric midsoles in puncture resistant boots.</p>
            <img src="{{ $wp }}2021/12/image_2021_12_27T23_36_29_061Z.png" alt="Puncture resistant midsole" width="408" height="330" loading="lazy" decoding="async" class="mx-auto">
            <h3>Puncture resistant boots</h3>
            <p><strong>Steel vs. woven fabric midsoles</strong></p>
            <p>Puncture flesh wounds are particularly awful because screws, spikes, sharp scrap metal or the famous rusty nail puncturing the foot, can deposit bacteria and debris deep into the body, leading to dangerous infections and/or permanent foot damage.</p>
            <p>The protective midsoles are embedded during the manufacturing process, between the insole and outsole of the puncture resistant boots. The standard specifies that the protective midsole of puncture resistant boots cannot be removed without damaging or destroying the boot. 2 main materials are used for protective midsoles: steel and Aramid (woven fabrics with high tenacity; eg. Kevlar by Dupont, Twaron by Akzo)</p>
            <p>When puncture resistant boots have a woven fabrics midsole, they are most likely to have a composite toe cap too to create a metal-free, <a href="https://www.safetyjogger.com/en/academy/lightweight-work-boots-sensitive-feet" target="_blank" rel="noopener">lightweight work</a> boot. A crucial element on sites where metal detectors are used as employees enter and leave, for example high-security environments, such as airports, governmental buildings and military bases, where the scanning aims to prevent weapons from making their way in.</p>
            <p>We made a list with all the differences you should know about a steel or woven fabric midsole in your search for the puncture resistant boot that suits your work.</p>
            <img src="{{ $wp }}2022/01/unnamed-20.jpg" alt="Steel and woven fabric midsoles" width="462" height="308" loading="lazy" decoding="async" class="mx-auto">
            <h3>Steel midsoles:</h3>
            <ul>
                <li>Made from coated steel or stainless steel</li>
                <li>More economic compared to textile/Aramid materials</li>
                <li>Thinner than textile/Aramid midsoles</li>
                <li>Embedded in the outsole construction, not visible when taking out the comfort insole</li>
                <li>Conducting cold, heat and electricity</li>
                <li>Recommended for protection against very sharp and tiny objects</li>
                <li>Less flexible than textile midsoles, due to the characteristics of metal</li>
                <li>Not protecting the full bottom surface of the footwear, few mm stay unprotected at the sides</li>
                <li>Subject to corrosion after a period of time, when made in coated carbon steel, especially at the flexline area</li>
            </ul>
            <h3>Woven fabric midsoles:</h3>
            <ul>
                <li>Made from Aramid fibers like Kevlar or Twaron</li>
                <li>More flexible compared to steel midsoles</li>
                <li>Less heavy than steel midsoles</li>
                <li>Sewn onto the puncture resistant boots upper construction, visible when removing the comfort insole</li>
                <li>Not conducting cold, heat and electricity</li>
                <li>Recommended for people working in metal scanning environments</li>
                <li>More comfort due to technical evolution towards thinner, more lightweight materials.</li>
                <li>Protecting the full bottom surface of the shoe</li>
                <li>Combined very high strength-to-weight properties, with excellent temperature, abrasion and cut resistance values</li>
            </ul>
        </section>

        {{-- 8. WATERPROOF BOOTS --}}
        <section id="panel-waterproof-boots" role="tabpanel" aria-labelledby="tab-waterproof-boots" tabindex="0" x-show="tab === 'waterproof-boots'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-11.jpg" alt="" width="1920" height="742" loading="lazy" decoding="async" class="w-full">
            <h2>Waterproof work boots &amp; shoes</h2>
            <h3>Waterproof work boots are not just for rain</h3>
            <p>More than likely you’re going to get caught up in the rain at some point on a regular workday. When you do get caught in a downpour, generally you’ll come home taking of those wet socks to spot a pair of wrinkled feet. Safety Jogger waterproof work boots or water-resistant work boots are key to prevent these kinds of situations. They keep your feet nice &amp; dry, without losing any comfort.</p>
            <p>A typical working environment for work boots in contact with liquids is not only limited to ordinary rain. Contact with meltwater, snow, steam, mixtures of water with acids, chemical agents, oil, soaps or organic liquids can occur.</p>
            <p>Work boots labeled “waterproof” provide the highest quality level of protection from liquids. Waterproof work boots do what they say, they keep water out and are leak proof, keeping your feet dry in numerous environments.</p>
            <p>The requirements for water-resistant or waterproof work boots will depend on the application, for example the degree of water resistance for an everyday safety shoe will be different to that required for a military boot, which is mostly completely waterproof.</p>
            <p>Do you need advice or do you have a question about our waterproof work boots? Don’t hesitate to contact us right away, <strong>We're social!</strong></p>
            <p><strong>Contact:</strong> <a href="mailto:info@frontline-footwear.com">info@frontline-footwear.com</a></p>
            <img src="{{ $wp }}2021/12/unnamed-12.jpg" alt="Waterproof work boot" width="567" height="368" loading="lazy" decoding="async" class="mx-auto">
            <h3>Testing the waterproof performance</h3>
            <p>The waterproof performance level of a waterproof work boot is tested on a dynamic (flexion) footwear water resistance machine.</p>
            <p>The waterproof work boot is fixed onto a mechanical foot and set into a container of colored water to a defined water depth (approx. 20mm) above the work boot feather line. It is then flexed at a constant speed and angle, while the (potential) penetration of water into the waterproof work boot is reviewed at intervals.</p>
            <img src="{{ $wp }}2021/12/unnamed-13.jpg" alt="Water resistance test, boot in container" width="560" height="357" loading="lazy" decoding="async" class="mx-auto">
            <img src="{{ $wp }}2021/12/unnamed-14.jpg" alt="Water resistance test, flexing machine" width="501" height="372" loading="lazy" decoding="async" class="mx-auto">
            <p>The flexing action represents a simplified walking action and is designed to simulate flexing stresses in the primary areas known to be weak points. The cyclic uplifting of the toe produces realistic splashing of water over parts of the forepart not immersed.</p>
            <p>Besides testing the waterproof sock construction, the test can reveal any wicking effects that can lead to a leaking path for water penetration through the upper of the lining of the waterproof work boots. <a href="https://www.safetyjogger.com/en/academy/safety-shoes-standards-and-certifications" target="_blank" rel="noopener">EN20345 standard</a> requires a minimum of 4.800 flexes for waterproof requirement.</p>
            <p>Frontline Footwear's waterproof work boots resist water penetration up to 300.000 (!) flexes, that's 6 times stronger than the market standard!</p>
            <h3>Super waterproof yet breathable work boots</h3>
            <p>The main point of buying waterproof work boots is to keep your feet dry and fresh. Our waterproof work boots even allow breathability for averting sweat when the work environment involves high temperatures, along with a firm insulation in snowy, damp, or rainy areas as well.</p>
            <p>When designing waterproof work boots, we enhance water-resistant features by using a waterproof membrane sock, between the lining and upper material.</p>
            <p>There are different fabrics used to make a waterproof lining but the way the fabrics are woven together is what gives it gives a waterproof and breathable element to it.</p>
            <p>Aside from keeping the water out, the waterproof membrane offers climate comfort for the feet, meaning a degree of breathability to avoid the feet to be overheated or becoming humid by locked-in sweat.</p>
            <img src="{{ $wp }}2021/12/unnamed-4.png" alt="Waterproof membrane sock construction" width="595" height="486" loading="lazy" decoding="async" class="mx-auto">
            <img src="{{ $wp }}2021/12/unnamed-15.jpg" alt="Waterproof and breathable membrane" width="529" height="332" loading="lazy" decoding="async" class="mx-auto">
            <p>Here at Frontline Footwear, we don’t think only the waterproof membrane is important. For a perfect comfort performance of the waterproof membrane in the work boot, we like to pay attention for other critical elements resulting in an ideal climate control of the feet in the waterproof work boot:</p>
            <ul>
                <li>the construction of the waterproof sock itself: perfectly sealed</li>
                <li>the assembly of the waterproof sock between lining and upper: without excessive sealing adhesive nor wicking paths from design details such as decorations, foams or other fabrics.</li>
                <li>the correct choice of upper material: breathable, non-wicking and water resistant.</li>
            </ul>
        </section>

        {{-- 9. HEAVY DUTY BOOTS --}}
        <section id="panel-heavy-duty-boots" role="tabpanel" aria-labelledby="tab-heavy-duty-boots" tabindex="0" x-show="tab === 'heavy-duty-boots'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2021/12/unnamed-16.jpg" alt="" width="1920" height="742" loading="lazy" decoding="async" class="w-full">
            <h2>Heavy duty work boots</h2>
            <div class="grid items-start gap-8 md:grid-cols-2">
                <div class="space-y-4">
                    <p>Looking for safe and strong boots for the big works? The safety heavy duty work boots by Safety Jogger fit your needs! Our boots are the combination of safety and comfort you are looking for.</p>
                    <p>Safety Jogger heavy duty work boots are made of PVC, meet the highest safety standards and bear the S-standards S4 and S5. They are not only waterproof, they are also antistatic and have a cleated sole. A steel midsole and steel top give the boot extra strength. With these <a href="https://www.safetyjogger.com/en/catalog#!/list?safety_maincategory=Safety%20Shoes&amp;safety_model=674" target="_blank" rel="noopener">safety boots</a>, you can handle every work environment</p>
                </div>
                <img src="{{ $wp }}2021/12/unnamed-17.jpg" alt="Heavy duty PVC work boot" width="561" height="658" loading="lazy" decoding="async" class="mx-auto">
            </div>
        </section>

        {{-- 10. ESD OR ANTISTATIC? --}}
        <section id="panel-esd-or-antistatic" role="tabpanel" aria-labelledby="tab-esd-or-antistatic" tabindex="0" x-show="tab === 'esd-or-antistatic'" x-cloak class="tf-prose">
            <img src="{{ $wp }}2022/01/Untitled-1.png" alt="ESD or antistatic safety shoes" width="1062" height="539" loading="lazy" decoding="async" class="w-full">
            <p>Antistatic and ESD safety shoes are <strong>both conductive safety footwear.</strong> This means they protect electrical equipment by sending (conducting) electrical charges to the ground, preventing a static shock, charge or spark. Conductive safety shoes are worn in industries in which a static shock is problematic or could trigger a fire or explosion and are not the same as isolating safety shoes, like EH-rated safety shoes, which protect you from completing an electrical circuit to the ground.</p>
            <h3>Antistatic safety shoes vs. ESD safety shoes</h3>
            <p>ESD and antistatic bring a lot of confusion, and not just when it comes to safety shoes. While one includes the other, it's incorrect to say the same in reverse. Although both terms refer to contact resistance, there are <strong>fundamental differences</strong> between the two.</p>
            <ul>
                <li><strong>ANTISTATIC</strong> safety shoes have a low electrical resistance between 0.1 and 1000 MegaOhm (MΩ). The use of antistatic safety shoes prevents a build-up of static electrical charges in the human body by sending these charges to the ground, preventing a sudden flow of electricity between electrically charged objects caused by contact.</li>
                <li><strong>ESD</strong> (or ElectroStatic Discharge) safety shoes on the other hand, have an even lower electrical resistance between 0.1 and 100 (MΩ). The use of ESD safety shoes prevents a build-up of static electrical charges in the human body by sending these charges to the ground in a <strong>very safe and controlled</strong> manner. They <strong>guarantee to prevent</strong> the sudden flow of electricity between electrically charged objects caused by contact.</li>
            </ul>

            <div class="grid items-start gap-6 md:grid-cols-2">
                <div class="space-y-4">
                    <img src="{{ $sj }}image_20.jpg" alt="Antistatic safety footwear" loading="lazy" decoding="async" class="w-full">
                    <div class="tf-card">
                        <h3>Antistatic</h3>
                        <p class="font-bold">Antistatic safety footwear reduces the chance of electrostatic discharges</p>
                        <ul class="tf-plain mt-2">
                            <li>Electrical resistance between 0.1 and 1000 (MΩ)</li>
                            <li>Norm - EN 20344: 2011 5 10</li>
                            <li>Sufficient dissipative capacity</li>
                            <li>100% Tested in production</li>
                        </ul>
                    </div>
                </div>
                <div class="space-y-4">
                    <img src="{{ $sj }}image_21.jpg" alt="ESD safety footwear" loading="lazy" decoding="async" class="w-full">
                    <div class="tf-card">
                        <h3>ESD</h3>
                        <p class="font-bold">ESD safety footwear prevents from uncontrolled electrostatic discharges.</p>
                        <ul class="tf-plain mt-2">
                            <li>Electrical resistance between 0.1 and 100 (MΩ)</li>
                            <li>Norm - BS EN 61340-4-3: 2002 (IEC 61340-4-3:2001)</li>
                            <li>Guaranteed extremely low electrical resistance under any conditions</li>
                            <li>100% Tested in production</li>
                        </ul>
                    </div>
                </div>
            </div>
            <img src="{{ $sj }}antistatic-esd.png" alt="Antistatic compared with ESD" loading="lazy" decoding="async" class="mx-auto">

            <h3>ESD in industries</h3>
            <p>ESD and antistatic safety shoes are used in different type of industries <strong>to protect sensitive equipment or components from electrostatic discharges</strong>, such as aerospace, industrial equipment manufacturing, semiconductor manufacturing, electrical engineers, telecommunications equipment manufacturing, battery manufacturing, computer equipment manufacturing, medical industry, hospitals and many more.</p>
            <p><strong>ESD can prevent a range of harmful effects on worksites</strong>. Uncontrolled release of static charges can cause gas, fuel vapour or coal dust explosions, as well as failure of solid state electronics components such as integrated circuits. Therefore it’s crucial that people who work in these environments <strong>wear ESD or antistatic safety shoes</strong> to protect all electrical components.</p>
            <div class="grid gap-6 sm:grid-cols-2">
                <img src="{{ $sj }}industrial1.jpg" alt="ESD footwear in industry" loading="lazy" decoding="async" class="w-full">
                <img src="{{ $sj }}industrial2.jpg" alt="ESD footwear in industry" loading="lazy" decoding="async" class="w-full">
            </div>
            <h3>ESD technologies</h3>
            <img src="{{ $sj }}technologies.jpg" alt="ESD technologies" loading="lazy" decoding="async" class="w-full">
        </section>

    </div>
    </div>{{-- /data-tf-layout --}}
</div>
</x-layouts.storefront>