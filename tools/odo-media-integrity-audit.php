<?php
declare(strict_types=1);

use NHK\Core\Application\Media\OdoMediaIntegrityAuditor;

require dirname(__DIR__) . '/public/wp-load.php';

$upload = wp_upload_dir();
$baseDir = (string) ($upload['basedir'] ?? '');
$attachments = [];
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
    $id = (int) $id;
    $attachments[] = ['attachment_id' => $id, 'attached_file' => (string) get_post_meta($id, '_wp_attached_file', true), 'metadata' => wp_get_attachment_metadata($id) ?: []];
}
$files = [];
if ($baseDir !== '' && is_dir($baseDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile()) $files[] = ltrim(str_replace($baseDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
}
$inline = [];
$featured = [];
$referencedAttachmentIds = [];
foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $postId) {
    $postId = (int) $postId;
    $featuredId = (int) get_post_thumbnail_id($postId);
    if ($featuredId > 0) $featured[] = $featuredId;
    if ($featuredId > 0) $referencedAttachmentIds[] = $featuredId;
    $content = (string) get_post_field('post_content', $postId);
    if (preg_match_all('/wp-image-(\d+)/i', $content, $ids)) $referencedAttachmentIds = array_merge($referencedAttachmentIds, array_map('intval', $ids[1]));
    if (preg_match_all('/https?:[^"\'\s>]+/i', $content, $matches)) $inline = array_merge($inline, $matches[0]);
}
echo wp_json_encode((new OdoMediaIntegrityAuditor())->audit($attachments, $files, array_values(array_unique($inline)), array_values(array_unique($featured)), array_values(array_unique($referencedAttachmentIds))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
