<?php

declare(strict_types=1);

use App\Domain\VendorSync\Exceptions\SafetyJoggerApiException;
use App\Domain\VendorSync\SafetyJogger\ApiClient;
use App\Domain\VendorSync\SafetyJogger\Endpoint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\VendorSync;

beforeEach(function (): void {
    VendorSync::boot();
});

it('logs in once, caches the hash and sends the required headers', function (): void {
    VendorSync::fakeHttp([['id' => 1]], [1 => ['id' => 1]], [['skuId' => 1, 'stock' => 2]]);

    $client = app(ApiClient::class);
    $client->products();
    $client->product(1);
    app(ApiClient::class)->stock();

    Http::assertSentCount(4);

    Http::assertSent(fn (Request $request): bool => $request->url() === VendorSync::SJ_BASE.'/login'
        && $request->method() === 'POST'
        && $request['username'] === 'tester@example.test'
        && $request['password'] === 'test-password'
        && $request['validity'] === 30
        && ! isset($request['token']));

    Http::assertSent(fn (Request $request): bool => $request->url() === VendorSync::SJ_BASE.'/product/1'
        && $request->method() === 'GET'
        && $request->hasHeader('username', 'tester@example.test')
        && $request->hasHeader('secret', 'test-hash')
        && $request->hasHeader('language', 'en'));
});

it('uses the token instead of the password when one is set', function (): void {
    config()->set('vendorsync.safety_jogger.token', 'test-token');
    VendorSync::fakeHttp();

    app(ApiClient::class)->products();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/login')
        && $request['token'] === 'test-token'
        && ! isset($request['password']));
});

it('authenticates again once on a 401 and retries', function (): void {
    $calls = 0;

    Http::fake(function (Request $request) use (&$calls) {
        if (str_ends_with($request->url(), '/login')) {
            return Http::response(['hash' => 'hash-'.(++$calls), 'expirationDate' => now()->addDays(30)->toIso8601String()]);
        }

        return $request->header('secret')[0] === 'hash-1'
            ? Http::response(['error' => 'expired'], 401)
            : Http::response([['id' => 7]]);
    });

    expect(app(ApiClient::class)->products())->toBe([['id' => 7]])
        ->and($calls)->toBe(2);

    Http::assertSentCount(4);
});

it('retries with backoff on 429 and 5xx, then gives up without leaking credentials', function (): void {
    $attempts = 0;
    $down = false;

    Http::fake(function (Request $request) use (&$attempts, &$down) {
        if ($down) {
            return Http::response('down', 500);
        }

        if (str_ends_with($request->url(), '/login')) {
            return Http::response(['hash' => 'test-hash', 'expirationDate' => now()->addDay()->toIso8601String()]);
        }

        return ++$attempts < 3 ? Http::response('busy', $attempts === 1 ? 429 : 503) : Http::response([['id' => 1]]);
    });

    expect(app(ApiClient::class)->products())->toBe([['id' => 1]])
        ->and($attempts)->toBe(3);

    Sleep::assertSleptTimes(2 + 3);

    $down = true;
    cache()->flush();

    try {
        app(ApiClient::class)->products();
        $this->fail('Expected an exception.');
    } catch (SafetyJoggerApiException $exception) {
        expect($exception->getMessage())->toBe('Safety Jogger API POST /login failed with status 500.')
            ->and($exception->getMessage())->not->toContain('test-password')
            ->and($exception->getMessage())->not->toContain('tester@example.test');
    }
});

it('throttles to two requests per second', function (): void {
    VendorSync::fakeHttp([['id' => 1]]);

    $client = app(ApiClient::class);
    $client->products();
    $client->products();
    $client->products();

    // Four requests (login plus three), so three waits of about half a second.
    Sleep::assertSleptTimes(3);
});

it('refuses to run without credentials', function (): void {
    config()->set('vendorsync.safety_jogger.password', null);
    Http::fake();

    expect(fn () => app(ApiClient::class)->products())->toThrow(SafetyJoggerApiException::class, 'credentials are not configured');

    Http::assertNothingSent();
});

it('cannot reach any endpoint outside the allowlist', function (): void {
    // The allowlist is exactly these four endpoints.
    expect(array_map(fn (Endpoint $endpoint): string => $endpoint->method().' '.$endpoint->value, Endpoint::cases()))
        ->toBe(['POST login', 'GET product', 'GET product/{id}', 'GET stock']);

    // The only public methods are the three read calls: there is no generic
    // request method to aim at another path.
    $public = collect((new ReflectionClass(ApiClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isConstructor())
        ->map(fn (ReflectionMethod $method): string => $method->getName().'('.implode(',', array_map(
            fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        )).')')
        ->sort()->values()->all();

    expect($public)->toBe(['product(int)', 'products()', 'stock()']);

    // A product id cannot steer the path anywhere else.
    expect(fn () => Endpoint::ProductDetail->path(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Endpoint::ProductDetail->path(-1))->toThrow(InvalidArgumentException::class);

    // Exercise everything the client can do and check what left the building.
    VendorSync::fakeHttp([['id' => 1]], [1 => ['id' => 1]], []);
    $client = app(ApiClient::class);
    $client->products();
    $client->product(1);
    $client->stock();

    $allowed = [
        'POST '.VendorSync::SJ_BASE.'/login',
        'GET '.VendorSync::SJ_BASE.'/product',
        'GET '.VendorSync::SJ_BASE.'/product/1',
        'GET '.VendorSync::SJ_BASE.'/stock',
    ];

    Http::assertSent(fn (Request $request): bool => in_array($request->method().' '.$request->url(), $allowed, true));
    Http::assertNotSent(fn (Request $request): bool => ! in_array($request->method().' '.$request->url(), $allowed, true));
    Http::assertNotSent(fn (Request $request): bool => preg_match('#/(logout|profile|order|address|shoppingcart)#', $request->url()) === 1);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['DELETE', 'PUT', 'PATCH'], true));
});
