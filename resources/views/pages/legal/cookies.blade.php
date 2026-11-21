{{--
    Cookie Policy. Route: /cookies (route name cookies), see routes/web.php.

    Describes the cookies the site sets today: the Laravel session, its security token and the optional
    "keep me logged in" cookie, plus Stripe on the checkout. There is no analytics, advertising or social
    media tracking, so there is no consent banner. If any is added, this page and the banner must change
    first (docs/launch-checklist.md). It has not had legal review.
--}}
@php
    $email = \App\Support\Store::email();
@endphp

<x-storefront.legal-page
    title="Cookie Policy"
    description="The cookies Frontline Safety Gear uses, what each one does and how to control them."
    updated="21 November 2026"
>
    <h2>1. What cookies are</h2>
    <p>Cookies are small text files that a website puts on your device. Some are needed for a website to work. Others are used to measure visits or to show advertising.</p>

    <h2>2. The cookies we use</h2>
    <p>We use only the cookies that the website needs to work. We do not use cookies for analytics, advertising or social media.</p>
    <div class="legal-table-wrap">
        <table>
            <thead>
                <tr><th>Cookie</th><th>What it does</th><th>How long it lasts</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Session cookie</td>
                    <td>Keeps you signed in as you move between pages, and remembers your cart and your progress through the checkout.</td>
                    <td>Until you have been inactive for two hours, or you sign out.</td>
                </tr>
                <tr>
                    <td>XSRF-TOKEN</td>
                    <td>A security token that checks a form or request really comes from our website, to protect your account against forged requests.</td>
                    <td>Until you have been inactive for two hours.</td>
                </tr>
                <tr>
                    <td>Remember me</td>
                    <td>Only set if you tick &ldquo;keep me logged in&rdquo; when you sign in. It keeps you signed in on that device.</td>
                    <td>Until you sign out, or it expires.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h2>3. Card payments</h2>
    <p>When you pay by card, the checkout loads a payment form from Stripe, our payment provider. Stripe may set its own cookies or use similar technology to take your payment securely and to prevent fraud. We do not control these. Stripe explains how it uses them in its own privacy information.</p>

    <h2>4. Why we do not ask you to accept cookies</h2>
    <p>The cookies above are strictly necessary: the website cannot sign you in, keep your cart or take your payment without them. UK law allows us to use cookies like this without asking for your consent. If we ever add cookies that are not strictly necessary, we will ask for your consent first and update this page.</p>

    <h2>5. Controlling cookies</h2>
    <p>You can delete or block cookies in your browser settings. Your browser&rsquo;s help pages explain how. If you block the cookies above, you will not be able to sign in, use your cart or place an order.</p>

    <h2>6. More information</h2>
    <p>Our <a href="{{ route('privacy') }}">Privacy Policy</a> explains how we use your personal information. If you have a question about cookies, email <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
</x-storefront.legal-page>
