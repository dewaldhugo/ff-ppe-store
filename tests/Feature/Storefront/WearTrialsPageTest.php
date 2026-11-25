<?php

declare(strict_types=1);

use App\Domain\Accounts\Industries;
use App\Livewire\WearTrials\RequestForm;
use App\Mail\WearTrialRequested;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// The Wear Trials page (/wear-trials) and its request form, which emails the same people as a new order.

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.enquiry_email', 'enquiries@example.test');
    // No order notification recipients unless a test sets them, whatever this machine's .env says.
    config()->set('store.order_notification_emails', '');
    RateLimiter::clear('wear-trial:127.0.0.1');
    Mail::fake();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function wearTrialRequest(array $overrides = []): array
{
    return [
        'name' => 'Sam Jones',
        'company' => 'Jones Groundworks Ltd',
        'email' => 'sam@example.test',
        'phone' => '+44 20 7946 0000',
        'products' => 'S3 safety boots',
        'details' => 'A team of eight on a civils site, mixed sizes.',
        ...$overrides,
    ];
}

function fillWearTrial(Testable $component, array $values): Testable
{
    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

it('serves the page at /wear-trials in the standard layout with one heading and the form', function (): void {
    expect(route('wear-trials', absolute: false))->toBe('/wear-trials');

    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    expect($html)->toContain('<title>Wear Trials | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('<footer')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('data-wear-trial-form')
        ->and($html)->toContain('data-wear-trials-intro');
});

it('has the client copy: the intro, the free offer and the three steps in order', function (): void {
    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    expect($html)->toMatch('#<p[^>]*data-wear-trials-intro[^>]*>Choosing the right ‘head to toe’ PPE for your workforce is a major decision, and specs on a screen only tell half the story\.#u')
        ->and($html)->toContain('That’s why we offer free Wear Trials to approved business customers.')
        ->and($html)->toContain('>How It Works</h2>')
        ->and($html)->toContain('<meta name="description" content="Free wear trials for approved business customers');

    preg_match_all('#<h3[^>]*>(.*?)</h3>#s', $html, $titles);

    expect(array_map(trim(...), $titles[1]))->toBe(['Pick Your Contenders', 'Get Approved', 'Test on the Ground'])
        ->and($html)->toContain('Register for a trade account so we can verify your business details.')
        ->and($html)->toContain('we’ll ship free trial samples straight to your door');
});

it('puts the pitch on the left and the photo on the right, under the heading', function (): void {
    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    preg_match('#<div[^>]*data-wear-trials-hero.*?</div>\s*<img[^>]*>#s', $html, $hero);
    preg_match('#<img[^>]*data-wear-trials-image[^>]*>#s', $html, $image);

    expect($hero[0] ?? '')->toContain('md:grid-cols-[minmax(0,60fr)_minmax(0,40fr)]')
        // The text comes before the image in the markup, so it is the left column.
        ->and(strpos($html, '<h1'))->toBeLessThan(strpos($html, 'data-wear-trials-intro'))
        ->and(strpos($html, 'data-wear-trials-intro'))->toBeLessThan(strpos($html, 'data-wear-trials-image'))
        ->and(strpos($html, 'data-wear-trials-image'))->toBeLessThan(strpos($html, 'data-wear-trials-rule'))
        ->and(strpos($html, 'data-wear-trials-rule'))->toBeLessThan(strpos($html, 'data-wear-trials-steps'))
        ->and($html)->toContain('<hr class="mt-12 border-0 border-t border-border" data-wear-trials-rule>')
        ->and($html)->toMatch('#data-wear-trials-offer[^>]*>That’s why we offer free Wear Trials to approved business customers\.</p>#u')
        ->and($image[0] ?? '')->toContain('src="'.asset('images/wear-trials/head-to-toe-ppe.webp').'"')
        ->toContain('width="1470"')->toContain('height="980"')->toMatch('#alt="[^"]+"#');
});

it('has the photo on disk as a web image of a sensible size', function (): void {
    $path = public_path('images/wear-trials/head-to-toe-ppe.webp');

    expect(is_file($path))->toBeTrue()
        ->and(filesize($path))->toBeLessThan(300_000)
        ->and(getimagesize($path)['mime'])->toBe('image/webp')
        ->and(getimagesize($path)[0])->toBe(1470);
});

it('offers Register to a visitor who is not signed in, and the shortlist email to everyone', function (): void {
    config()->set('store.email', 'info@example.test');

    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    expect($html)->toMatch('#<a\s[^>]*href="'.preg_quote(route('register'), '#').'"[^>]*data-wear-trials-register-link#')
        ->and($html)->toContain('>Register Today</h2>')
        ->and($html)->toMatch('#data-wear-trials-email[^>]*>info@example\.test</a>#')
        ->and($html)->toContain('href="mailto:info@example.test"');
});

it('does not ask a signed-in customer to register', function (): void {
    $this->actingAs(User::factory()->create(), 'web');

    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    expect($html)->not->toContain('data-wear-trials-register-link')
        ->and($html)->toContain('Email your wear trial shortlist</h2>')
        ->and($html)->toContain('data-wear-trials-email');
});

it('is linked from the header and the footer, with the current page marked in the header', function (): void {
    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    preg_match('#<nav aria-label="Main".*?</nav>#s', $html, $header);
    preg_match('#<footer.*?</footer>#s', $html, $footer);

    expect($header[0] ?? '')->toMatch('#<a href="'.preg_quote(route('wear-trials'), '#').'"[^>]*aria-current="page"[^>]*>Wear Trials</a>#')
        ->and($footer[0] ?? '')->toContain('href="'.route('wear-trials').'"');
});

it('labels every field and marks the optional ones', function (): void {
    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    foreach ([
        'trial-name' => 'Full name',
        'trial-company' => 'Company name',
        'trial-email' => 'Email address',
        'trial-phone' => 'Phone number',
        'trial-industry' => 'Industry',
        'trial-products' => 'Products you would like to trial',
        'trial-details' => 'Tell us about your team and what you need',
    ] as $id => $text) {
        expect($html)->toMatch('#<label for="'.$id.'"[^>]*>\s*'.preg_quote($text, '#').'#')
            ->and($html)->toContain('id="'.$id.'"');
    }

    expect(substr_count($html, '(optional)'))->toBe(2)
        ->and($html)->toContain('autocomplete="organization"');
});

it('has an optional industry drop-down with every industry', function (): void {
    $html = $this->get(route('wear-trials'))->assertOk()->getContent();

    preg_match('#<select id="trial-industry"[^>]*>(.*?)</select>#s', $html, $select);

    expect($select[0] ?? '')->not->toContain(' required')
        ->and($select[1] ?? '')->toContain('<option value="">Choose your industry</option>');

    foreach (Industries::all() as $slug => $name) {
        expect($select[1] ?? '')->toContain('<option value="'.$slug.'">'.htmlspecialchars($name, ENT_QUOTES).'</option>');
    }
});

it('emails the request to the store with the customer as reply-to, and clears the form', function (): void {
    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest(['industry' => 'construction']))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSet('name', '')
        ->assertSet('company', '')
        ->assertSet('industry', '');

    Mail::assertSent(WearTrialRequested::class, function (WearTrialRequested $mail): bool {
        $text = $mail->render();

        return $mail->hasTo('enquiries@example.test')
            && $mail->hasReplyTo('sam@example.test')
            && $mail->hasSubject('Wear trial request: Jones Groundworks Ltd')
            && str_contains($text, 'Company: Jones Groundworks Ltd')
            && str_contains($text, 'Industry: Construction')
            && str_contains($text, 'Products to trial: S3 safety boots')
            && str_contains($text, 'A team of eight');
    });
});

it('sends without a phone number or an industry, and says Not given in the email', function (): void {
    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest(['phone' => '']))->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Mail::assertSent(WearTrialRequested::class, fn (WearTrialRequested $mail): bool => $mail->phone === null
        && $mail->industry === null
        && str_contains($mail->render(), 'Phone: Not given')
        && str_contains($mail->render(), 'Industry: Not given'));
});

it('validates every field', function (array $overrides, string $field): void {
    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest($overrides))
        ->call('submit')
        ->assertHasErrors($field)
        ->assertSet('sent', false);

    Mail::assertNothingSent();
})->with([
    'no name' => [['name' => ''], 'name'],
    'no company' => [['company' => ''], 'company'],
    'no email' => [['email' => ''], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'bad phone' => [['phone' => 'call me maybe'], 'phone'],
    'unknown industry' => [['industry' => 'pirates'], 'industry'],
    'no products' => [['products' => ''], 'products'],
    'no message' => [['details' => ''], 'details'],
    'short message' => [['details' => 'Hi'], 'details'],
    'long message' => [['details' => str_repeat('a', 5001)], 'details'],
]);

it('shows the error text beside the field it belongs to', function (): void {
    Livewire::test(RequestForm::class)->call('submit')
        ->assertSee('The full name field is required.')
        ->assertSee('The company name field is required.')
        ->assertSee('The products to trial field is required.');
});

it('strips line breaks from the company name in the subject so it cannot add mail headers', function (): void {
    $mail = new WearTrialRequested('Sam', "Acme\r\nBcc: victim@example.test", 'sam@example.test', null, null, 'Boots', 'A message long enough.');

    expect($mail->envelope()->subject)->toBe('Wear trial request: Acme Bcc: victim@example.test');
});

it('pretends to send when the hidden field is filled and sends nothing', function (): void {
    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest(['website' => 'https://spam.example']))
        ->call('submit')
        ->assertSet('sent', true);

    Mail::assertNothingSent();
});

it('stops one address sending more than five requests an hour', function (): void {
    foreach (range(1, 5) as $i) {
        fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())->call('submit')->assertSet('sent', true);
    }

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertHasErrors('form');

    Mail::assertSentCount(5);
});

it('has its own limit, so Contact enquiries do not use up wear trial requests', function (): void {
    foreach (range(1, 5) as $i) {
        RateLimiter::hit('enquiry:127.0.0.1', 3600);
    }

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())->call('submit')->assertSet('sent', true);

    RateLimiter::clear('enquiry:127.0.0.1');
});

it('sends the request to the same people as a new order, each in an email of their own', function (): void {
    config()->set('store.order_notification_emails', 'staff.one@example.test, staff.two@example.test');

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Mail::assertSentCount(2);

    foreach (['staff.one@example.test', 'staff.two@example.test'] as $address) {
        Mail::assertSent(WearTrialRequested::class, fn (WearTrialRequested $mail): bool => $mail->hasTo($address) && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === []);
    }

    Mail::assertNotSent(WearTrialRequested::class, fn (WearTrialRequested $mail): bool => $mail->hasTo('enquiries@example.test'));
});

it('tells the customer it was sent if it reached at least one person, and reports the failure for the others', function (): void {
    Exceptions::fake();
    config()->set('store.order_notification_emails', 'staff.one@example.test, staff.two@example.test');

    $pending = Mockery::mock();
    $pending->shouldReceive('send')->once();
    Mail::shouldReceive('to')->once()->with('staff.one@example.test')->andThrow(new RuntimeException('SMTP down for one'));
    Mail::shouldReceive('to')->once()->with('staff.two@example.test')->andReturn($pending);

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'SMTP down for one');
});

it('does not pretend to send when no valid recipient is set', function (): void {
    config()->set('store.enquiry_email', 'TBC');

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertHasErrors('form');

    Mail::assertNothingSent();
});

it('tells the customer when the mail server fails and keeps what they typed', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    fillWearTrial(Livewire::test(RequestForm::class), wearTrialRequest())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertSet('name', 'Sam Jones')
        ->assertHasErrors('form');
});

it('stays out of search engines only while STORE_NOINDEX is true, with no noindex of its own', function (): void {
    $this->get(route('wear-trials'))->assertOk()->assertSee('<meta name="robots" content="noindex">', false);

    config()->set('store.noindex', false);

    $this->get(route('wear-trials'))->assertOk()->assertDontSee('<meta name="robots" content="noindex">', false);
});

it('uses UK English and no em dashes in its copy', function (): void {
    foreach (['views/pages/wear-trials.blade.php', 'views/livewire/wear-trials/request-form.blade.php', 'views/mail/wear-trial-requested.blade.php'] as $file) {
        // Tags are stripped first: Tailwind class names such as items-center are not copy.
        $text = strip_tags(file_get_contents(resource_path($file)));

        expect($text)->not->toContain('—')
            ->and($text)->not->toMatch('/\b(organization|program|center|color|behavior)\b/i');
    }
});
