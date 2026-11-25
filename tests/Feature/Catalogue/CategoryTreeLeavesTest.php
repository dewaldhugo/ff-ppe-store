<?php

declare(strict_types=1);

use App\Domain\Catalogue\Collections\CategoryTree;

// A product is in a leaf category, never a top level one, so "everything under safety-footwear" is its leaves.

it('lists the leaf categories under a top level category', function (): void {
    expect(CategoryTree::leavesUnder('safety-footwear'))->toBe([
        'safety-boots', 'safety-shoes', 'safety-trainers', 'safety-wellingtons', 'occupational-footwear', 'metal-free-metatarsal',
    ]);
});

it('gives a leaf itself, and nothing for an unknown handle', function (): void {
    expect(CategoryTree::leavesUnder('safety-boots'))->toBe(['safety-boots'])
        ->and(CategoryTree::leavesUnder('no-such-category'))->toBe([])
        ->and(CategoryTree::leavesUnder(''))->toBe([]);
});

it('keeps the footwear tops apart', function (): void {
    expect(CategoryTree::leavesUnder('non-safety-footwear'))->not->toContain('safety-boots')
        ->and(CategoryTree::leavesUnder('non-safety-footwear'))->toContain('casual-footwear');
});
