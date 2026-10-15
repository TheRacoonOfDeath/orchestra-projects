<?php

/**
 * Removes all projects, programs, participation and assignments when the plugin is deleted.
 *
 * Deactivating the plugin intentionally keeps all data.
 */

declare(strict_types=1);

use Orchestra\Projects\Database\Schema;
use Orchestra\Projects\Project\ProjectPostType;

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

$orchestraProjectsDatabase = $GLOBALS['wpdb'];
if (! $orchestraProjectsDatabase instanceof wpdb) {
    exit;
}

$orchestraProjectsIds = get_posts([
    'post_type' => ProjectPostType::NAME,
    'post_status' => array_keys(get_post_stati()),
    'numberposts' => -1,
    'fields' => 'ids',
]);
foreach ($orchestraProjectsIds as $orchestraProjectsId) {
    wp_delete_post((int) $orchestraProjectsId, true);
}

(new Schema($orchestraProjectsDatabase))->drop();
