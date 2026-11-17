{{--
    About page, in the storefront layout.
    Route: /about (route name about), see routes/web.php.

    Copy is supplied by the client (The Bridge Group's group-level "About Us" page).
    Left out on purpose: the sister-site buttons, the hero photo, the "How We Work" photo
    (add them here when the files are supplied), the enquiry form (the Contact page
    will own it) and the link to the group's policies (no policy pages exist yet).
--}}
<x-layouts.storefront title="About The Bridge Group" description="The Bridge Group brings together security, property support and management technology to help clients protect their people, look after their assets and keep everyday operations running smoothly.">
    <article class="w-full" data-about>
        <x-ui.section-heading title="About The Bridge Group" tag="h1">People, expertise and technology. Working together for you.</x-ui.section-heading>

        <div class="mt-8 space-y-4 leading-relaxed text-ink">
            <p>The Bridge Group brings together security, property support and management technology to help clients protect their people, look after their assets and keep everyday operations running smoothly.</p>
            <p>We work with construction companies, managing agents, residential communities, commercial businesses and utility providers. Each has different priorities, so our approach starts with understanding the people, places and practical challenges involved.</p>
            <p>Our purpose is straightforward: to provide dependable services, personal support and clear accountability, building relationships that deliver lasting value.</p>
        </div>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-story">
            <h2 id="about-story" class="text-2xl text-brand-navy sm:text-3xl">Our Story</h2>
            <p class="mt-2 font-semibold text-brand-blue">Built on great service. Grown through trust and enterprise.</p>

            <div class="mt-6 space-y-4 leading-relaxed text-ink">
                <p>The Bridge Group's story began in 1997, providing reinsurance surveys in South America and security services for the scaffolding industry. As individual scaffolding contractors introduced us to new opportunities, we expanded into the wider construction sector, delivering manned guarding, monitored CCTV and surveillance services.</p>
                <p>Our growth has always been closely connected to the clients we serve. By delivering consistently, understanding their operations and responding when they needed more support, we earned the opportunity to develop new services and embed them within existing contracts. Some of those relationships stretch back to our earliest days and have continued through changes in business ownership.</p>
                <p>Alongside that trust, our entrepreneurial leadership has recognised opportunities to bring complementary services into the Group. When clients asked us for vehicle tracking, for example, we initially worked with Siemens. Former Siemens personnel later joined our team, helping establish Bridge Fleet Solutions with its own design capability and proprietary software.</p>
                <p>That combination of client demand and enterprise has continued to shape our development. From our security foundations, we have expanded into fire and alarm systems, access control, electrical services, property maintenance and software that supports everyday operations. Each addition gives clients more of the services they need through a team they already know and trust.</p>
                <p>More recently, parking management has become a growing part of our offer, helping clients manage residential and commercial parking through on-site teams, digital systems, payment services and enforcement, supported by our wider security capabilities. We have also extended our work with the utility industry, providing grounds maintenance, meter reading and back-up driver services to support essential operations. Our current move into traffic management builds on this progress, complementing our security, site support and utilities work. Together, these growing service areas are becoming an increasingly important part of our clients' day-to-day operations and a key part of the Group's future.</p>
                <p>Today, The Bridge Group brings together security, property and operational support services, backed by round-the-clock monitoring and response. As we continue to grow, our approach remains the same: deliver great service, build lasting relationships and find practical ways to do more for our clients.</p>
            </div>
        </section>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-together">
            <h2 id="about-together" class="text-2xl text-brand-navy sm:text-3xl">One Group, Working Together</h2>
            <p class="mt-2 font-semibold text-brand-blue">Specialist services. A coordinated approach.</p>

            <div class="mt-6 space-y-4 leading-relaxed text-ink">
                <p>Through Bridge Security Solutions and Bridge Management Systems, we combine the people delivering services with the technology that helps clients manage and oversee them.</p>
                <p>This gives clients access to complementary expertise within one group. Security, property support and digital systems can be planned together, helping to simplify communication, reduce duplicated effort and establish clear responsibilities.</p>
                <p>A relationship may begin with one specific requirement. As needs change, we can bring in additional support where it provides a practical benefit, building on our understanding of the client's operations.</p>
                <p>At Greenwich Peninsula, our work has developed from security into concierge and parking management, supporting a coordinated approach to estate services. In the utilities sector, our relationship with Thames Water has expanded to include driver support, grounds maintenance and meter reading.</p>
            </div>
        </section>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-people">
            <div class="grid items-start gap-8 md:grid-cols-2 md:gap-12">
                <div>
                    <h2 id="about-people" class="text-2xl text-brand-navy sm:text-3xl">Our People</h2>
                    <p class="mt-2 font-semibold text-brand-blue">The people behind the service.</p>

                    <div class="mt-6 space-y-4 leading-relaxed text-ink">
                        <p>Our services depend on the people who deliver them: security officers, engineers, site teams, operational managers and software specialists.</p>
                        <p>They work in very different environments, from busy construction sites and utility operations to residential buildings where professionalism, discretion and an approachable manner matter every day.</p>
                        <p>We value practical knowledge, clear communication and taking responsibility. Our aim is for clients to feel confident in both the team on site and the people supporting them behind the scenes.</p>
                        <p>Working together across disciplines helps us understand problems more fully and bring the right expertise to each requirement.</p>
                    </div>
                </div>

                <img
                    src="{{ asset('images/about/our-people.webp') }}"
                    alt="A group of The Bridge Group colleagues, including office staff, security officers with a police dog, engineers and site operatives, standing outside an office building beside a company van."
                    width="763"
                    height="500"
                    loading="lazy"
                    decoding="async"
                    class="h-auto w-full rounded-lg"
                    data-about-people-image
                >
            </div>
        </section>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-how">
            <h2 id="about-how" class="text-2xl text-brand-navy sm:text-3xl">How We Work</h2>
            <p class="mt-2 font-semibold text-brand-blue">Understand your needs. Agree the approach. Keep improving.</p>

            <div class="mt-6 space-y-4 leading-relaxed text-ink">
                <p>We begin by listening. We consider how your site or business operates, what matters to the people using it and where support will make the greatest difference.</p>
                <p>From there, we work with you to agree the services, responsibilities and communication arrangements. Whether you need one service or several, the aim is a practical solution that is straightforward to manage.</p>
                <p>Technology supports this approach. Our management systems help record site activity and make information available to support oversight, reporting and informed decisions.</p>
                <p>As requirements change, we review the approach with you, identifying where services can work more effectively together and where additional support may be useful.</p>
            </div>
        </section>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-standards">
            <h2 id="about-standards" class="text-2xl text-brand-navy sm:text-3xl">Our Standards &amp; Commitments</h2>
            <p class="mt-2 font-semibold text-brand-blue">Clear values. Everyday responsibility.</p>

            <p class="mt-6 leading-relaxed text-ink">Our ambition is to be the provider clients choose for dependable delivery, responsive support and lasting relationships. Five values guide that ambition:</p>

            <dl class="mt-6 grid gap-4 sm:grid-cols-2" data-about-values>
                @foreach ([
                    'Quality' => 'Paying attention to the detail and delivering work to agreed standards.',
                    'Care' => 'Respecting our clients, colleagues and the people who live or work at the sites we support.',
                    'Ownership' => 'Taking responsibility, addressing issues and following through on commitments.',
                    'Passion' => 'Taking pride in our work and looking for ways to improve.',
                    'Service' => 'Listening carefully, communicating clearly and making it easier for clients to get the support they need.',
                ] as $value => $meaning)
                    <div class="rounded-lg border border-border bg-surface-muted p-5">
                        <dt class="text-lg font-semibold text-brand-blue">{{ $value }}</dt>
                        <dd class="mt-1 text-ink">{{ $meaning }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-6 leading-relaxed text-ink">These commitments underpin our approach to safe working, professional conduct and responsible business. Our company policies set out the principles that guide us.</p>
        </section>

        <section class="mt-12 border-t border-border pt-10" aria-labelledby="about-talk">
            <h2 id="about-talk" class="text-2xl text-brand-navy sm:text-3xl">Talk To Our Team</h2>
            <p class="mt-2 font-semibold text-brand-blue">A conversation is a good place to start.</p>

            <p class="mt-6 leading-relaxed text-ink">Whether you have an immediate requirement or want to explore a more coordinated approach to your services, we will take the time to understand what you need.</p>

            <x-ui.button class="mt-6" :href="route('contact')">Contact us</x-ui.button>
        </section>
    </article>
</x-layouts.storefront>
