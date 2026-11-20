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

it('shows every industry as a plain card in a labelled region', function (): void {
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

it('slides by one copy at a steady speed, pauses on hover and keeps moving for everyone, with no scrollbar', function (): void {
    $css = industryCss();

    preg_match('#@keyframes industry-drift\s*\{\s*to\s*\{\s*transform:\s*translateX\(-50%\);#', $css, $keyframes);
    preg_match('#\.industry-row-track\s*\{[^}]*animation:\s*industry-drift\s+(\d+)s\s+linear\s+infinite#', $css, $animation);
    preg_match('#\.industry-row\s*\{([^}]*)\}#', $css, $row);

    expect($keyframes)->not->toBeEmpty()
        // Linear, so the speed never changes; slow enough to read (a full loop takes over a minute).
        ->and((int) ($animation[1] ?? 0))->toBeGreaterThanOrEqual(60)
        ->and($css)->toContain('.industry-row:hover .industry-row-track')
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
