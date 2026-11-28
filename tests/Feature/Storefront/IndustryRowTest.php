<?php

declare(strict_types=1);

function industryRow(string $html): string
{
    preg_match('#<section[^>]*data-industry-row.*?</section>#s', $html, $row);

    return $row[0] ?? '';
}

function industryCss(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

it('shows every industry as a card in a labelled region', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    expect($row)->toContain('aria-label="Industries we supply"')
        ->and(substr_count($row, 'data-industry-card'))->toBe(14);

    foreach (['Industry', 'Logistics', 'Construction', 'Medical', 'Uniform', 'Food &amp; beverages', 'Catering', 'Tactical', 'Assembly', 'Automotive', 'Chemical', 'Cleaning', 'Mining', 'Oil &amp; Gas'] as $label) {
        expect($row)->toContain('>'.$label.'</p>');
    }
});

it('repeats the list once for a seamless loop, and hides the repeat from assistive technology', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li[^>]*data-industry-clone[^>]*>#', $row, $clones);

    expect($clones[0])->toHaveCount(14);

    foreach ($clones[0] as $clone) {
        expect($clone)->toContain('aria-hidden="true"')->not->toContain('data-industry-card');
    }

    // One copy must be exactly half the track, so each card carries its own margin and the track has no flex gap.
    preg_match('#<ul[^>]*data-industry-track[^>]*>#', $row, $track);

    expect($track[0])->toContain('industry-row-track')->not->toContain('gap-')
        ->and($row)->toContain('mr-3');
});

it('drifts by CSS alone, with no buttons, scripts or timers', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());
    $source = (string) file_get_contents(resource_path('views/components/industry-slider.blade.php'));

    expect($row)->not->toContain('<button')
        ->and($row)->not->toContain('x-data')
        ->and($row)->not->toContain('<script')
        ->and($source)->not->toContain('setInterval')
        ->and($source)->not->toContain('setTimeout');
});

it('slides by one copy at a steady speed, does not pause on hover, and keeps moving for everyone, with no scrollbar', function (): void {
    $css = industryCss();

    preg_match('#@keyframes industry-drift\s*\{\s*to\s*\{\s*transform:\s*translateX\(-50%\);#', $css, $keyframes);
    preg_match('#\.industry-row-track\s*\{[^}]*animation:\s*industry-drift\s+(\d+)s\s+linear\s+infinite#', $css, $animation);
    preg_match('#\.industry-row\s*\{([^}]*)\}#', $css, $row);

    expect($keyframes)->not->toBeEmpty()
        // Linear, so the speed never changes; slow enough to read (a full loop takes over a minute).
        ->and((int) ($animation[1] ?? 0))->toBeGreaterThanOrEqual(60)
        // The pointer does not stop it (a hover frames the card and shows an arrow instead); a link with keyboard focus does.
        ->and($css)->not->toContain('.industry-row:hover')
        ->and($css)->toContain('.industry-row:focus-within .industry-row-track')
        ->and($css)->toContain('animation-play-state: paused')
        // Like the home hero slideshow, it ignores "reduce motion": no fallback that shows a scrollbar.
        ->and($css)->not->toContain('[data-industry-clone]')
        ->and($row[1] ?? '')->toContain('overflow: hidden')
        ->and($row[1] ?? '')->not->toContain('overflow-x: auto');
});

it('fills the page container, not the full width of the page', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $source = (string) file_get_contents(resource_path('views/components/industry-slider.blade.php'));

    preg_match('#<section[^>]*data-industry-row[^>]*>\s*<div class="([^"]*)"#', $html, $wrapper);

    // The container is the first element inside the section, and the clipping row sits inside it.
    expect($wrapper[1] ?? '')->toContain('mx-auto')
        ->and(strpos($source, '<x-ui.container>'))->toBeLessThan(strpos($source, 'class="industry-row"'));
});

it('keeps each photo lazy, sized and decorative, with the label as the card text', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<img[^>]*>#', $row, $images);

    expect($images[0])->toHaveCount(28);

    foreach ($images[0] as $image) {
        expect($image)->toContain('alt=""')
            ->toContain('loading="lazy"')
            ->toContain('width="240"')
            ->toContain('height="224"');
    }
});

it('puts the label on a dark gradient from the design tokens, with white text', function (): void {
    $source = (string) file_get_contents(resource_path('views/components/industry-slider.blade.php'));

    expect($source)->toContain('from-brand-charcoal/90')
        ->and($source)->toContain('text-white');
});

it('makes each card a link to the search filtered by that industry', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    $industries = ['Industry', 'Logistics', 'Construction', 'Medical', 'Uniform', 'Food & beverages', 'Catering', 'Tactical', 'Assembly', 'Automotive', 'Chemical', 'Cleaning', 'Mining', 'Oil & Gas'];

    preg_match_all('#<li[^>]*data-industry-card[^>]*>\s*<a\s([^>]*)>#', $row, $links);

    expect($links[1])->toHaveCount(14);

    foreach ($industries as $index => $label) {
        // The same address the product page's industry links use: /search?industry[]=Label.
        expect($links[1][$index])->toContain('href="'.route('search', ['industry' => [$label]]).'"')
            ->toContain('data-industry-link')
            ->toContain('aria-label="Shop products for '.htmlspecialchars($label, ENT_QUOTES).'"');
    }
});

it('keeps the repeated cards out of the tab order and out of assistive technology', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<li[^>]*data-industry-clone[^>]*>\s*<a\s([^>]*)>#', $row, $links);

    expect($links[1])->toHaveCount(14);

    foreach ($links[1] as $link) {
        // The copy is aria-hidden, so its links must not take focus either.
        expect($link)->toContain('tabindex="-1"')->toContain('data-industry-link')->not->toContain('aria-label=');
    }
});

it('does not zoom the photo on hover or focus, and sets the link up as the group the frame and arrow follow', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<a\s[^>]*data-industry-link[^>]*>#', $row, $links);
    preg_match_all('#<img[^>]*>#', $row, $images);

    expect($links[0])->toHaveCount(28);

    foreach ($links[0] as $link) {
        // The link is the group the frame and the arrow follow, clips what sticks out to the rounded card, and keeps the focus ring inside it.
        expect($link)->toContain('group')->toContain('relative')->toContain('overflow-hidden')->toContain('rounded-lg')
            ->toContain('focus-visible:outline-offset-[-4px]')->toContain('focus-visible:shadow-none');
    }

    foreach ($images[0] as $image) {
        // The photo stays as it is: no scale, no transition.
        expect($image)->not->toContain('scale-')->not->toContain('transition')->not->toContain('duration-')->not->toContain('group-hover:');
    }
});

it('keeps the label inside the link, on the card, so the link has words', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<a\s[^>]*data-industry-link[^>]*>(.*?)</a>#s', $row, $links);

    expect($links[1])->toHaveCount(28);

    foreach ($links[1] as $inside) {
        expect($inside)->toContain('<picture>')->toMatch('#<p class="[^"]*text-white[^"]*">[^<]+</p>#');
    }
});

it('frames the card in teal on hover and on focus, drawn inside the card on top of the photo', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<span[^>]*data-industry-ring[^>]*>#', $row, $rings);

    expect($rings[0])->toHaveCount(28);

    foreach ($rings[0] as $ring) {
        // Inside the card (ring-inset, so the clipping row cannot cut it), on top of it, no clicks of its own, and decoration.
        expect($ring)->toContain('absolute inset-0')->toContain('ring-2')->toContain('ring-inset')->toContain('ring-transparent')
            ->toContain('group-hover:ring-brand-teal')->toContain('group-focus-visible:ring-brand-teal')
            ->toContain('pointer-events-none')->toContain('rounded-lg')->toContain('aria-hidden="true"');
    }
});

it('slides an arrow in beside the label on hover and on focus, as decoration', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<svg[^>]*data-industry-arrow[^>]*>#', $row, $arrows);

    expect($arrows[0])->toHaveCount(28);

    foreach ($arrows[0] as $arrow) {
        // Hidden until hover or focus; moves in only for people who have not asked for less motion; teal on the charcoal gradient.
        expect($arrow)->toContain('opacity-0')->toContain('group-hover:opacity-100')->toContain('group-focus-visible:opacity-100')
            ->toContain('-translate-x-2')->toContain('group-hover:translate-x-0')->toContain('group-focus-visible:translate-x-0')->not->toContain('motion-safe:')
            ->toContain('text-brand-teal')->toContain('aria-hidden="true"');
    }
});

it('keeps the label and the arrow on one row at the foot of the card, with the label as the only text', function (): void {
    $row = industryRow($this->get(route('home'))->assertOk()->getContent());

    preg_match_all('#<div class="([^"]*items-end[^"]*)">\s*<p class="([^"]*)">([^<]+)</p>\s*<svg#', $row, $foot, PREG_SET_ORDER);

    expect($foot)->toHaveCount(28);

    foreach ($foot as $match) {
        expect($match[1])->toContain('absolute inset-x-0 bottom-0')->toContain('justify-between')->toContain('from-brand-charcoal/90')
            ->and($match[2])->toContain('text-white')->toContain('uppercase');
    }
});
