<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountVerified;
use App\Http\Middleware\EnsureLoginActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A signed-in customer who opens a guest-only page (login, register) goes to My Account.
        $middleware->redirectUsersTo(fn (): string => route('account'));

        // A guest who opens a signed-in page goes to the log in page. Lunar's admin panel
        // and Horizon have their own sign-in and are not affected.
        $middleware->redirectGuestsTo(fn (): string => route('login'));

        // My Account needs a verified email address while STORE_REQUIRE_EMAIL_VERIFICATION is on.
        $middleware->alias(['account.verified' => EnsureAccountVerified::class]);

        // A customer whose login staff switched off is signed out on their next request. It must
        // run after the session starts, so it is appended to the web group.
        $middleware->appendToGroup('web', EnsureLoginActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
