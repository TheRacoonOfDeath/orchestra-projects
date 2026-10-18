<?php

/**
 * Plugin Name:      Orchestra Projects
 * Plugin URI:       https://github.com/TheRacoonOfDeath/orchestra-projects
 * Description:      Projects, programs, participation and assignments for the Orchestra plugin suite.
 * Version:          0.12.0
 * Author:           Julian Nickerl
 * License:          MIT
 * Text Domain:      orchestra-projects
 * Domain Path:      /languages
 * Requires at least: 6.5
 * Requires PHP:     8.3
 * Requires Plugins: orchestra-core, orchestra-library, orchestra-members
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

Orchestra\Projects\Plugin::boot(__FILE__);
