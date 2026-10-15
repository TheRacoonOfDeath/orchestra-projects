<?php

/**
 * Test data for e2e.py, run with `wp eval-file`: an inactive member, pieces with parts and sheet
 * music in orchestra-library, and a page with the member dashboard. Prints the IDs as JSON.
 */

declare(strict_types=1);

use Orchestra\Library\Database\Schema;
use Orchestra\Library\File\FileRepository;
use Orchestra\Library\File\FileService;
use Orchestra\Library\File\FileStorage;
use Orchestra\Library\File\UploadedFile;
use Orchestra\Library\Part\PartRepository;
use Orchestra\Library\Piece\PieceRepository;

global $wpdb;

update_user_meta(get_user_by('login', 'ina')->ID, 'orchestra_members_inactive', '1');

$schema = new Schema($wpdb);
$parts = new PartRepository($wpdb, $schema);
// Files are moved with rename(), because move_uploaded_file() only accepts HTTP uploads.
$storage = new FileStorage(
    FileStorage::fromWordPress()->directory(),
    static fn (string $from, string $to): bool => rename($from, $to)
);
$files = new FileService(new FileRepository($wpdb, $schema), $storage);
$pieceRepository = new PieceRepository();

$pieces = [
    'pirates' => ['Pirates of the Caribbean', 'Klaus Badelt', ['Horn 1', 'Horn 2']],
    'dance' => ['Lord of the Dance', 'Ronan Hardiman', ['Violin 1', 'Violin 2']],
    'march' => ['Old March', 'Anonymous', ['Drum']],
];
$ids = [];
foreach ($pieces as $key => [$title, $composer, $partNames]) {
    $pieceId = wp_insert_post(['post_type' => 'orchestra_piece', 'post_status' => 'publish', 'post_title' => $title]);
    $pieceRepository->setComposer($pieceId, $composer);
    $ids[$key] = $pieceId;
    foreach ($partNames as $position => $name) {
        $part = $parts->insert($pieceId, $name, '', $position);
        $ids[$key . ':' . $name] = $part->id;
        $temporary = (string) tempnam(sys_get_temp_dir(), 'orchestra');
        file_put_contents($temporary, "%PDF-1.4\n% {$title} {$name}\n%%EOF\n");
        $upload = new UploadedFile($temporary, "{$name}.pdf", 'application/pdf', (int) filesize($temporary));
        $files->attachToPart($part, $upload, 1);
    }
}
$pieceRepository->setArchived($ids['march'], true);

$ids['page'] = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Projects',
    'post_name' => 'projects',
    'post_content' => '[orchestra_projects]',
]);

echo json_encode($ids);
