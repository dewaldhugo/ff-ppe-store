<?php

declare(strict_types=1);

// The page width (resources/views/components/ui/container.blade.php): 80rem, and 15% wider at the largest breakpoint only.

it('keeps the page 80rem wide, and makes it 15% wider (92rem) at the largest breakpoint only', function (): void {
    $source = (string) file_get_contents(resource_path('views/components/ui/container.blade.php'));

    preg_match('#class\(\'([^\']*)\'\)#', $source, $classes);

    $tokens = explode(' ', $classes[1] ?? '');

    // 92 / 80 = 1.15. Only the 2xl variant carries the wider width: no other breakpoint changes the maximum.
    expect(92 / 80)->toBe(1.15)
        ->and($tokens)->toContain('max-w-7xl')->toContain('2xl:max-w-[92rem]')
        ->and(array_values(array_filter($tokens, fn (string $token): bool => str_contains($token, 'max-w-'))))->toBe(['max-w-7xl', '2xl:max-w-[92rem]']);
});

it('puts the wider page on every container of the home page, the header and the footer', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();

    // Every container on the page is the wider one at 2xl, and the service strip, which has its own, too.
    preg_match_all('#<[a-z]+ class="[^"]*\bmax-w-7xl\b[^"]*"#', $html, $containers);

    expect($containers[0])->not->toBeEmpty();

    foreach ($containers[0] as $container) {
        expect($container)->toContain('2xl:max-w-[92rem]');
    }
});

it('leaves the default breakpoints alone, so 2xl is the largest', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // No custom breakpoint: Tailwind's 2xl is 96rem (1536px), and nothing larger exists to widen the page at.
    expect($css)->not->toContain('--breakpoint-');
});
