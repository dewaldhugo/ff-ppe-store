<?php

declare(strict_types=1);

use App\Support\PlainText;

// Vendor text that carries a little HTML is shown as plain text (App\Support\PlainText).

it('strips the paragraph tags from a description', function (): void {
    expect(PlainText::from('<p>Lightweight, metal-free safety shoe with EH protection.</p>'))->toBe('Lightweight, metal-free safety shoe with EH protection.');
});

it('turns paragraphs, line breaks and list items into lines', function (): void {
    expect(PlainText::from('<p>One.</p><p>Two.<br>Three.</p><ul><li>Four</li><li>Five</li></ul>'))->toBe("One.\nTwo.\nThree.\nFour\nFive");
});

it('drops other tags, decodes entities and collapses spaces', function (): void {
    expect(PlainText::from('<p>Tough &amp; <strong>light</strong>&nbsp;boot   for  work</p>'))->toBe("Tough & light\u{A0}boot for work");
});

it('leaves plain text alone, apart from trimming', function (): void {
    expect(PlainText::from("  Plain text, no tags.\n\nSecond paragraph.  "))->toBe("Plain text, no tags.\n\nSecond paragraph.")
        ->and(PlainText::from(null))->toBe('')
        ->and(PlainText::from('Price < £50'))->toBe('Price < £50');
});
