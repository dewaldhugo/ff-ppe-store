<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catalogue text for the storefront as plain text. The data contract says a description is plain text, but some vendor
 * records carry a little HTML (`<p>...</p>`, `<br>`, list items), and Blade escapes it, so a visitor would see the tags
 * themselves. This turns the line-ending tags into line breaks, drops every other tag and decodes the entities. Text with no
 * tags passes through unchanged (apart from trimming).
 */
final class PlainText
{
    public static function from(mixed $text): string
    {
        $text = (string) $text;

        if (! str_contains($text, '<') && ! str_contains($text, '&')) {
            return trim($text);
        }

        $text = preg_replace('#<\s*br\s*/?\s*>|</\s*(p|div|li|ul|ol|h[1-6]|tr)\s*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\R */', "\n", $text) ?? $text;

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }
}
