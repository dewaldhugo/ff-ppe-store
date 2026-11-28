<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The storefront's colour tokens, for HTML emails. An email cannot use CSS variables or Tailwind, so its
 * colours have to be written into inline styles, but no template may hold a literal colour
 * (DesignTokensTest). So the mailable asks for them here: they are read from the `@theme` block of
 * `resources/css/app.css`, the same file the site uses, and an email follows a change to the brand.
 * If the stylesheet cannot be read, the fallback keeps the email legible.
 */
final class EmailColours
{
    /** Used only when the stylesheet cannot be read, and for a token it does not define. */
    private const array FALLBACK = [
        'white' => '#FFFFFF',
        'ink' => '#111629',
        'ink-muted' => '#475569',
        'surface-muted' => '#F8FAFC',
        'border' => '#E2E8F0',
        'brand-blue' => '#1F2A44',
        'brand-navy' => '#111629',
        'brand-teal' => '#00776E',
    ];

    /** @var array<string, string>|null */
    private static ?array $tokens = null;

    /**
     * Token name (without `--color-`) to its hex value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$tokens !== null) {
            return self::$tokens;
        }

        $tokens = [];
        $css = @file_get_contents(resource_path('css/app.css'));

        if (is_string($css) && preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $theme) === 1) {
            preg_match_all('/--color-([a-z-]+):\s*(#[0-9A-Fa-f]{6});/', $theme[1], $matches);
            $tokens = array_combine($matches[1], $matches[2]);
        }

        return self::$tokens = $tokens + self::FALLBACK;
    }
}
