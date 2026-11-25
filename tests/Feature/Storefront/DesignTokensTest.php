<?php

declare(strict_types=1);

/*
 * The design tokens are the only colours the templates may use, and the
 * pairs used for text must meet WCAG 2.2 AA. The tokens are read from
 * resources/css/app.css, so changing a colour there is checked here.
 */

/**
 * @return array<string, string> Token name (without --color-) => hex.
 */
function themeTokens(): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));

    preg_match('#@theme\s*\{(.*?)\n\}#s', $css, $theme);
    preg_match_all('#--color-([a-z-]+):\s*(\#[0-9A-Fa-f]{6});#', $theme[1], $matches);

    return array_combine($matches[1], $matches[2]);
}

function luminance(string $hex): float
{
    $linear = fn (int $channel): float => ($channel / 255) <= 0.03928
        ? ($channel / 255) / 12.92
        : ((($channel / 255) + 0.055) / 1.055) ** 2.4;

    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];

    return 0.2126 * $linear((int) $r) + 0.7152 * $linear((int) $g) + 0.0722 * $linear((int) $b);
}

function contrast(string $foreground, string $background): float
{
    $a = luminance($foreground);
    $b = luminance($background);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

it('defines the brand tokens with the agreed colours', function (): void {
    $tokens = themeTokens();

    expect(strtoupper($tokens['brand-blue']))->toBe('#343A40')
        ->and(strtoupper($tokens['brand-teal']))->toBe('#44BAB1')
        ->and(strtoupper($tokens['brand-navy']))->toBe('#001227')
        ->and(strtoupper($tokens['brand-charcoal']))->toBe('#1F2327')
        ->and(contrast($tokens['brand-charcoal'], $tokens['white']))->toBeGreaterThan(contrast($tokens['brand-blue'], $tokens['white']))
        ->and(strtoupper($tokens['ink']))->toBe('#222222')
        ->and(strtoupper($tokens['surface-muted']))->toBe('#F8F8F8')
        ->and(strtoupper($tokens['white']))->toBe('#FFFFFF');
});

it('meets WCAG AA (4.5:1) for every text and background pair the templates use', function (string $text, string $background): void {
    $tokens = themeTokens();

    expect(contrast($tokens[$text], $tokens[$background]))->toBeGreaterThanOrEqual(4.5, "{$text} on {$background}");
})->with([
    'body text' => ['ink', 'white'],
    'body text on alternate sections' => ['ink', 'surface-muted'],
    'muted text' => ['ink-muted', 'white'],
    'muted text on alternate sections' => ['ink-muted', 'surface-muted'],
    'main menu links' => ['brand-navy', 'brand-teal-light'],
    'text in the Products dropdown' => ['ink', 'surface-nav'],
    'main menu focus outline text' => ['brand-blue', 'brand-teal-light'],
    'links and eyebrows' => ['brand-blue', 'white'],
    'links on alternate sections' => ['brand-blue', 'surface-muted'],
    'primary button' => ['brand-navy', 'brand-teal'],
    'primary button on hover' => ['white', 'brand-navy'],
    'footer text' => ['white', 'brand-blue'],
    'top bar and hero text' => ['white', 'brand-navy'],
    'top bar text' => ['white', 'brand-charcoal'],
    'teal accents on the top bar' => ['brand-teal', 'brand-charcoal'],
    'text on the top bar Register button' => ['brand-navy', 'brand-teal'],
    'teal accents on the navy bars' => ['brand-teal', 'brand-navy'],
    'teal accents on the blue footer' => ['brand-teal', 'brand-blue'],
    'text on teal fills' => ['brand-navy', 'brand-teal'],
    'error text' => ['danger', 'white'],
    'success message' => ['success', 'success-surface'],
]);

it('meets 3:1 for the focus ring and the form field border', function (string $ui, string $background): void {
    $tokens = themeTokens();

    expect(contrast($tokens[$ui], $tokens[$background]))->toBeGreaterThanOrEqual(3.0, "{$ui} on {$background}");
})->with([
    'focus ring on light surfaces' => ['brand-blue', 'white'],
    'focus ring on alternate sections' => ['brand-blue', 'surface-muted'],
    'focus ring on the main menu' => ['brand-blue', 'brand-teal-light'],
    'focus ring in the Products dropdown' => ['brand-blue', 'surface-nav'],
    'focus ring on dark surfaces' => ['brand-teal', 'brand-navy'],
    'focus ring on the footer' => ['brand-teal', 'brand-blue'],
    'form field border' => ['border-strong', 'white'],
    'disabled button text' => ['disabled-ink', 'disabled-surface'],
]);

it('documents why teal is never a text colour on light surfaces or behind white text', function (): void {
    $tokens = themeTokens();

    // White on teal and teal on white both fail AA, which is the reason for the rule.
    expect(contrast($tokens['white'], $tokens['brand-teal']))->toBeLessThan(4.5)
        ->and(contrast($tokens['brand-teal'], $tokens['white']))->toBeLessThan(3.0);
});

/**
 * @return list<string>
 */
function bladeTemplates(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if (str_ends_with((string) $file, '.blade.php')) {
            $files[] = (string) $file;
        }
    }

    return $files;
}

it('has no hard-coded colour in any template', function (): void {
    $palette = '#(?<![\w:-])(?:bg|text|border|ring|divide|outline|decoration|fill|stroke|from|to|via|accent|caret|shadow|placeholder)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|black)(?:-\d{2,3})?(?![\w-])#';
    $offenders = [];

    foreach (bladeTemplates() as $file) {
        $source = (string) file_get_contents($file);

        // Data-driven colours (a colour swatch from the catalogue) are not design colours.
        $source = str_replace('#{{', '', $source);
        $name = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);

        if (preg_match($palette, $source, $match) === 1) {
            $offenders[] = "{$name}: {$match[0]}";
        }

        if (preg_match('/(?<![\w&\/])#[0-9a-fA-F]{3,8}\b(?![\w-])/', $source, $match) === 1) {
            $offenders[] = "{$name}: {$match[0]}";
        }

        if (preg_match('/\b(?:rgb|rgba|hsl|hsla|oklch)\(/', $source, $match) === 1) {
            $offenders[] = "{$name}: {$match[0]}";
        }
    }

    expect($offenders)->toBe([]);
});

it('never puts white text on a teal fill or teal text on a light surface', function (): void {
    $offenders = [];

    foreach (bladeTemplates() as $file) {
        $source = (string) file_get_contents($file);
        $name = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file);

        preg_match_all('/class="([^"]*)"/', $source, $classes);

        foreach ($classes[1] as $class) {
            $tokens = preg_split('/\s+/', trim($class));

            if (in_array('bg-brand-teal', $tokens, true) && ! in_array('text-brand-navy', $tokens, true)) {
                $offenders[] = "{$name}: teal fill without brand-navy text ({$class})";
            }

            if (in_array('bg-brand-teal', $tokens, true) && in_array('text-white', $tokens, true)) {
                $offenders[] = "{$name}: white text on teal ({$class})";
            }
        }

        // Teal text is only for dark surfaces: the eyebrows on the navy bands of the home and Health Check pages.
        if (str_contains($source, 'text-brand-teal') && ! in_array(str_replace('\\', '/', $name), ['components/home/hero.blade.php', 'components/home/health-check.blade.php', 'pages/ppe-health-check.blade.php'], true)) {
            $offenders[] = "{$name}: text-brand-teal outside a dark band";
        }
    }

    expect($offenders)->toBe([]);
});

it('loads the font from the application, not a CDN', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain("font-family: 'Poppins'")
        ->and($css)->toContain("--font-sans: 'Poppins'")
        ->and($css)->not->toContain('fonts.googleapis.com')
        ->and($css)->not->toContain('fonts.gstatic.com');

    preg_match_all("~url\\('([^']+\\.woff2)'\\)~", $css, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $path) {
        expect(file_exists(resource_path('css/'.$path)))->toBeTrue("Missing font file {$path}");
    }

    foreach (bladeTemplates() as $file) {
        expect((string) file_get_contents($file))->not->toContain('fonts.googleapis.com')
            ->and((string) file_get_contents($file))->not->toContain('fonts.bunny.net');
    }
});
