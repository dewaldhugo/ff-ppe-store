<?php

declare(strict_types=1);

use App\Domain\Accounts\Industries;
use App\Livewire\Contact\EnquiryForm;
use App\Mail\EnquiryReceived;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('store.noindex', true);
    config()->set('store.email', 'info@example.test');
    config()->set('store.enquiry_email', 'enquiries@example.test');
    RateLimiter::clear('enquiry:127.0.0.1');
    Mail::fake();
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function enquiry(array $overrides = []): array
{
    return [
        'name' => 'Sam Jones',
        'email' => 'sam@example.test',
        'phone' => '+44 20 7946 0000',
        'enquirySubject' => 'Safety boots for a team',
        'body' => 'We need twelve pairs of S3 boots for a site team.',
        ...$overrides,
    ];
}

function fillEnquiry(Testable $component, array $values): Testable
{
    foreach ($values as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

it('serves the contact page at /contact in the standard layout', function (): void {
    expect(route('contact', absolute: false))->toBe('/contact');

    $html = $this->get(route('contact'))->assertOk()->getContent();

    expect($html)->toContain('<title>Contact us | ')
        ->and($html)->toContain('id="main"')
        ->and($html)->toContain('<footer')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('>Enquire Now</h2>')
        ->and($html)->toMatch('#<p[^>]*data-contact-tagline[^>]*>Safety Starts With the Right Gear\. Talk to Our Specialists\.</p>#')
        ->and($html)->not->toContain('Your next challenge')
        ->and($html)->not->toContain('Content coming soon.')
        ->and($html)->toContain('data-enquiry-form');
});

it('has the Frontline intro copy and nothing from the previous company', function (): void {
    $html = $this->get(route('contact'))->assertOk()->getContent();

    $copy = 'Whether you need a bulk quote, compliance documentation, or help choosing the right gear for your site, our safety specialists are ready to assist.';

    expect($html)->toMatch('#<p[^>]*data-contact-intro[^>]*>'.preg_quote($copy, '#').'</p>#')
        // The same sentence is the meta description.
        ->and($html)->toContain('<meta name="description" content="'.$copy.'">')
        ->and($html)->not->toContain('Bridge')
        ->and($html)->not->toContain('security, property');
});

it('uses UK English and no em dashes in its copy', function (): void {
    $text = file_get_contents(resource_path('views/pages/contact.blade.php'));

    expect($text)->not->toContain('—')
        ->and($text)->not->toMatch('/\b(organization|program|center|color|behavior)\b/i');
});

it('shows the store email as a mailto link once it is a real address', function (): void {
    $html = $this->get(route('contact'))->assertOk()->getContent();

    expect($html)->toMatch('#data-contact-email.*?href="mailto:info@example\.test"[^>]*>info@example\.test</a>#s');

    config()->set('store.email', 'TBC');

    $html = $this->get(route('contact'))->assertOk()->getContent();

    preg_match('#data-contact-email.*?</p>#s', $html, $block);

    expect($block[0])->toContain('TBC')->not->toContain('mailto:');
});

it('labels every field and marks the optional one', function (): void {
    $html = $this->get(route('contact'))->assertOk()->getContent();

    foreach (['enquiry-name' => 'Full name', 'enquiry-email' => 'Email address', 'enquiry-phone' => 'Phone number', 'enquiry-subject' => 'Enquiry subject', 'enquiry-body' => 'How can we help?'] as $id => $text) {
        expect($html)->toMatch('#<label for="'.$id.'"[^>]*>\s*'.preg_quote($text, '#').'#')
            ->and($html)->toContain('id="'.$id.'"');
    }

    expect($html)->toContain('(optional)')
        ->and($html)->toContain('autocomplete="email"');
});

it('emails the enquiry to the store with the customer as reply-to', function (): void {
    $component = fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())->call('submit');

    $component->assertHasNoErrors()->assertSet('sent', true)->assertSet('name', '');

    Mail::assertSent(EnquiryReceived::class, function (EnquiryReceived $mail): bool {
        return $mail->hasTo('enquiries@example.test')
            && $mail->hasReplyTo('sam@example.test')
            && $mail->hasSubject('Website enquiry: Safety boots for a team')
            && $mail->name === 'Sam Jones'
            && $mail->phone === '+44 20 7946 0000'
            && str_contains($mail->body, 'twelve pairs');
    });
});

it('sends without a phone number', function (): void {
    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry(['phone' => '']))->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    Mail::assertSent(EnquiryReceived::class, fn (EnquiryReceived $mail): bool => $mail->phone === null);
});

it('has an optional industry drop-down with every industry, before the subject', function (): void {
    $html = $this->get(route('contact'))->assertOk()->getContent();

    preg_match('#<label for="enquiry-industry"[^>]*>(.*?)</label>#s', $html, $label);
    preg_match('#<select id="enquiry-industry"[^>]*>(.*?)</select>#s', $html, $select);

    expect(trim(strip_tags($label[1] ?? '')))->toBe('Industry (optional)')
        ->and($html)->toMatch('#<select id="enquiry-industry"[^>]*wire:model="industry"#')
        ->and($select[0] ?? '')->not->toContain(' required')
        ->and($select[1] ?? '')->toContain('<option value="">Choose your industry</option>')
        ->and(strpos($html, 'id="enquiry-industry"'))->toBeLessThan(strpos($html, 'id="enquiry-subject"'));

    foreach (Industries::all() as $slug => $name) {
        expect($select[1] ?? '')->toContain('<option value="'.$slug.'">'.htmlspecialchars($name, ENT_QUOTES).'</option>');
    }
});

it('puts the chosen industry in the email, and says Not given when there is none', function (): void {
    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry(['industry' => 'construction']))->call('submit')->assertHasNoErrors()->assertSet('sent', true)->assertSet('industry', '');

    Mail::assertSent(EnquiryReceived::class, fn (EnquiryReceived $mail): bool => $mail->industry === 'construction' && str_contains($mail->render(), 'Industry: Construction'));

    Mail::fake();

    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())->call('submit')->assertHasNoErrors();

    Mail::assertSent(EnquiryReceived::class, fn (EnquiryReceived $mail): bool => $mail->industry === null && str_contains($mail->render(), 'Industry: Not given'));
});

it('only accepts an industry from the list', function (): void {
    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry(['industry' => 'pirates']))
        ->call('submit')
        ->assertHasErrors('industry')
        ->assertSet('sent', false);

    Mail::assertNothingSent();
});

it('renders the plain text email with the details and no HTML escaping', function (): void {
    $mail = new EnquiryReceived('Sam Jones', 'sam@example.test', null, 'Boots & gloves', 'Quote for S3 boots & gloves');

    $text = $mail->render();

    expect($text)->toContain('Name: Sam Jones')
        ->and($text)->toContain('Phone: Not given')
        ->and($text)->toContain('Subject: Boots & gloves')
        ->and($text)->toContain('Quote for S3 boots & gloves');
});

it('strips line breaks from the subject so it cannot add mail headers', function (): void {
    $mail = new EnquiryReceived('Sam', 'sam@example.test', null, "Hello\r\nBcc: victim@example.test", 'A message long enough.');

    expect($mail->envelope()->subject)->toBe('Website enquiry: Hello Bcc: victim@example.test');
});

it('validates every field', function (array $overrides, string $field): void {
    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry($overrides))
        ->call('submit')
        ->assertHasErrors($field)
        ->assertSet('sent', false);

    Mail::assertNothingSent();
})->with([
    'no name' => [['name' => ''], 'name'],
    'no email' => [['email' => ''], 'email'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'bad phone' => [['phone' => 'call me maybe'], 'phone'],
    'no subject' => [['enquirySubject' => ''], 'enquirySubject'],
    'no message' => [['body' => ''], 'body'],
    'short message' => [['body' => 'Hi'], 'body'],
    'long message' => [['body' => str_repeat('a', 5001)], 'body'],
]);

it('shows the error text beside the field it belongs to', function (): void {
    Livewire::test(EnquiryForm::class)->call('submit')
        ->assertSee('The full name field is required.')
        ->assertSee('The email address field is required.')
        ->assertSee('The message field is required.');
});

it('pretends to send when the hidden field is filled and sends nothing', function (): void {
    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry(['website' => 'https://spam.example']))
        ->call('submit')
        ->assertSet('sent', true);

    Mail::assertNothingSent();
});

it('stops one address sending more than five enquiries an hour', function (): void {
    foreach (range(1, 5) as $i) {
        fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())->call('submit')->assertSet('sent', true);
    }

    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertHasErrors('form');

    Mail::assertSentCount(5);
});

it('does not pretend to send when no valid recipient is set', function (): void {
    config()->set('store.enquiry_email', 'TBC');

    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertHasErrors('form');

    Mail::assertNothingSent();
});

it('tells the customer when the mail server fails and keeps what they typed', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));

    fillEnquiry(Livewire::test(EnquiryForm::class), enquiry())
        ->call('submit')
        ->assertSet('sent', false)
        ->assertSet('name', 'Sam Jones')
        ->assertHasErrors('form');
});

it('stays out of search engines only while STORE_NOINDEX is true, with no noindex of its own', function (): void {
    $this->get(route('contact'))->assertOk()->assertSee('<meta name="robots" content="noindex">', false);

    config()->set('store.noindex', false);

    $this->get(route('contact'))->assertOk()->assertDontSee('<meta name="robots" content="noindex">', false);
});

it('shows the address on the contact page', function (): void {
    config()->set('store.address', '7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA');

    $html = $this->get(route('contact'))->assertOk()->getContent();

    expect($html)->toMatch('#<address[^>]*data-contact-address[^>]*>7 Lynnwood Court, Priestlands Place, Lymington, Hampshire, SO41 9GA</address>#');

    config()->set('store.address', 'TBC');

    expect($this->get(route('contact'))->assertOk()->getContent())->toMatch('#data-contact-address[^>]*>TBC</address>#');
});
