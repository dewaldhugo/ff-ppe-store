<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * The admin menu group that holds the pages for the storefront's own words and pictures: Hero content, Spotlight banners and Home
 * videos. One name, so the three resources always sit together under one menu item.
 */
final class WebsiteContent
{
    public const string GROUP = 'Website Content';
}
