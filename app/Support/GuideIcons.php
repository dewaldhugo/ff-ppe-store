<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The two outline icons of the buying guides: a ruler for sizes and a shield with a tick for features and standards (Heroicons, MIT,
 * style outline paths on a 24 x 24 grid). One definition, used by the cards of the home page's buying guides (`icon` in
 * config/homepage.php) and by the Tools links beside the header's search box, so the same page has the same icon in both places.
 */
final class GuideIcons
{
    /** @var array<string, list<string>> */
    public const array PATHS = [
        'ruler' => [
            'M3.75 7.5h16.5a.75.75 0 0 1 .75.75v7.5a.75.75 0 0 1-.75.75H3.75a.75.75 0 0 1-.75-.75v-7.5a.75.75 0 0 1 .75-.75Z',
            'M7.5 7.5v3M12 7.5v4.5M16.5 7.5v3',
        ],
        'shield' => [
            'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
        ],
    ];

    /** The icon of each Tools link in the header: the size guide's ruler and the technical features' shield. */
    public const array TOOLS = [
        'size-guide' => 'ruler',
        'technical-features' => 'shield',
    ];

    /**
     * The paths of the icon for a Tools link (`size-guide` or `technical-features`), or none for a name that has no icon.
     *
     * @return list<string>
     */
    public static function forTool(string $name): array
    {
        return self::PATHS[self::TOOLS[$name] ?? ''] ?? [];
    }
}
