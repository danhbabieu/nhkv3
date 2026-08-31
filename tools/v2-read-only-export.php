<?php
declare(strict_types=1);

/**
 * Export a bounded V2 inventory without bootstrapping WordPress or writing to
 * the source database. Credentials are supplied by the local environment.
 */
$databaseName = getenv('NHK_V2_DB') ?: 'nhk_v2_dev';
$host = getenv('NHK_V2_DB_HOST') ?: '127.0.0.1';
$user = getenv('NHK_V2_DB_USER') ?: 'root';
$password = getenv('NHK_V2_DB_PASSWORD') ?: '';
$prefix = getenv('NHK_V2_DB_PREFIX') ?: 'nhkv2_';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
    fwrite(STDERR, "Invalid table prefix.\n");
    exit(2);
}

$db = mysqli_init();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db->real_connect($host, $user, $password, $databaseName, 3306);
$db->set_charset('utf8mb4');

$table = static fn (string $name): string => '`' . $prefix . $name . '`';
$rows = static function (mysqli $db, string $query): array {
    $result = $db->query($query);
    $items = [];
    while ($row = $result->fetch_assoc()) $items[] = $row;
    $result->free();
    return $items;
};

$records = [];
$postIds = [];
$posts = $rows($db, 'SELECT ID,post_type,post_status,post_name,guid FROM ' . $table('posts') . ' ORDER BY ID');
foreach ($posts as $post) {
    $id = (string) $post['ID'];
    $postIds[$id] = true;
    $records[] = [
        'type' => 'wp_post',
        'stable_key' => 'wp_post:' . $id,
        'legacy_id' => $id,
        'legacy_type' => (string) $post['post_type'],
        'status' => (string) $post['post_status'],
    ];
    $path = parse_url((string) $post['guid'], PHP_URL_PATH);
    $sourcePath = is_string($path) && $path !== '' ? $path : '/' . trim((string) $post['post_name'], '/') . '/';
    $targetPath = in_array((string) $post['post_type'], ['post', 'page'], true) && (string) $post['post_name'] !== ''
        ? '/' . trim((string) $post['post_name'], '/') . '/'
        : '';
    $records[] = ['type' => 'url', 'source_path' => $sourcePath, 'target_path' => $targetPath, 'legacy_id' => $id];
}

$entityIds = [];
$entities = $rows($db, 'SELECT id,stable_key,entity_type,canonical_name,review_state,revision FROM ' . $table('nhk_entities') . ' ORDER BY id');
$supportedEntityTypes = ['brand', 'model', 'product', 'media', 'video', 'knowledge'];
foreach ($entities as $entity) {
    $id = (string) $entity['id'];
    $entityIds[$id] = true;
    $legacyType = (string) $entity['entity_type'];
    $type = in_array($legacyType, $supportedEntityTypes, true) ? $legacyType : 'legacy_' . $legacyType;
    $records[] = [
        'type' => $type,
        'stable_key' => (string) $entity['stable_key'],
        'canonical_uuid' => $id,
        'legacy_type' => $legacyType,
        'canonical_name' => (string) $entity['canonical_name'],
        'review_state' => (string) $entity['review_state'],
        'revision' => (int) $entity['revision'],
        'conflict' => strtoupper((string) $entity['review_state']) === 'CONFLICT',
    ];
}

$relations = $rows($db, 'SELECT id,relation_type,source_id,target_id,status FROM ' . $table('nhk_relations') . ' ORDER BY id');
foreach ($relations as $relation) {
    $source = (string) $relation['source_id'];
    $target = (string) $relation['target_id'];
    $records[] = [
        'type' => 'relation',
        'stable_key' => 'relation:' . (string) $relation['id'],
        'source_key' => $source,
        'target_key' => $target,
        'source_missing' => !isset($entityIds[$source]) && !isset($postIds[$source]),
        'target_missing' => !isset($entityIds[$target]) && !isset($postIds[$target]),
        'relation_type' => (string) $relation['relation_type'],
        'status' => (string) $relation['status'],
    ];
}

echo json_encode(['records' => $records], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
