<?php

declare(strict_types=1);

namespace Orchestra\Projects\Ui;

use Orchestra\Core\Ui\Assets as CoreAssets;

/**
 * The stylesheet of orchestra-projects, on top of orchestra-core's, and the script of the project
 * form. They are enqueued by the markup that needs them, so they are only loaded where they are used.
 */
final class Assets
{
    public const HANDLE = 'orchestra-projects';

    private static string $url = '';
    private static string $version = '';
    private static string $scriptUrl = '';
    private static string $scriptVersion = '';

    public static function register(string $pluginFile): void
    {
        self::$url = \plugins_url('assets/orchestra-projects.css', $pluginFile);
        // Changes with every release, so browsers load the new file.
        self::$version = (string) @\filemtime(\dirname($pluginFile) . '/assets/orchestra-projects.css');
        self::$scriptUrl = \plugins_url('assets/orchestra-projects.js', $pluginFile);
        self::$scriptVersion = (string) @\filemtime(\dirname($pluginFile) . '/assets/orchestra-projects.js');
    }

    /**
     * The script of the project form. Everything also works without it.
     */
    public static function enqueueScript(): void
    {
        if (self::$scriptUrl !== '') {
            \wp_enqueue_script(self::HANDLE, self::$scriptUrl, [], self::$scriptVersion, ['in_footer' => true]);
        }
    }

    public static function enqueue(): void
    {
        if (self::$url !== '') {
            \wp_enqueue_style(self::HANDLE, self::$url, [CoreAssets::HANDLE], self::$version);
        }
    }
}
