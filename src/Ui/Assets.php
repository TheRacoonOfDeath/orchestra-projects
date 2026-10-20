<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Assets as CoreAssets;

/**
 * The stylesheet of orchestra-projects, on top of orchestra-core's. It is enqueued by the markup
 * that needs it, so it is only loaded where it is used.
 */
final class Assets
{
    public const HANDLE = 'orchestra-projects';

    private static string $url = '';
    private static string $version = '';

    public static function register(string $pluginFile): void
    {
        self::$url = \plugins_url('assets/orchestra-projects.css', $pluginFile);
        // Changes with every release, so browsers load the new file.
        self::$version = (string) @\filemtime(\dirname($pluginFile) . '/assets/orchestra-projects.css');
    }

    public static function enqueue(): void
    {
        if (self::$url !== '') {
            \wp_enqueue_style(self::HANDLE, self::$url, [CoreAssets::HANDLE], self::$version);
        }
    }
}
