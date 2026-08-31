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
$posts = $rows($db, 'SELECT ID,post_author,post_date,post_date_gmt,post_modified,post_type,post_status,post_name,post_title,post_excerpt,post_content,guid FROM ' . $table('posts') . ' ORDER BY ID');
foreach ($posts as $post) {
    $id = (string) $post['ID'];
    $postIds[$id] = true;
    $records[] = [
        'type' => 'wp_post',
        'stable_key' => 'wp_post:' . $id,
        'legacy_id' => $id,
        'legacy_type' => (string) $post['post_type'],
        'status' => (string) $post['post_status'],
        'post_author' => (string) $post['post_author'],
        'post_date' => (string) $post['post_date'],
        'post_date_gmt' => (string) $post['post_date_gmt'],
        'post_modified' => (string) $post['post_modified'],
        'post_name' => (string) $post['post_name'],
        'post_title' => (string) $post['post_title'],
        'post_excerpt' => (string) $post['post_excerpt'],
        'post_content' => (string) $post['post_content'],
    ];
    $path = parse_url((string) $post['guid'], PHP_URL_PATH);
    $sourcePath = is_string($path) && $path !== '' ? $path : '/' . trim((string) $post['post_name'], '/') . '/';
    $targetPath = in_array((string) $post['post_type'], ['post', 'page'], true) && (string) $post['post_name'] !== ''
        ? '/' . trim((string) $post['post_name'], '/') . '/'
        : '';
    $records[] = ['type' => 'url', 'source_path' => $sourcePath, 'target_path' => $targetPath, 'legacy_id' => $id];
}

$categories = $rows($db, 'SELECT t.term_id,t.slug,t.name,tt.taxonomy FROM ' . $table('terms') . ' t JOIN ' . $table('term_taxonomy') . ' tt ON tt.term_id=t.term_id ORDER BY t.term_id');
foreach ($categories as $category) {
    $records[] = [
        'type' => 'category',
        'stable_key' => 'category:' . (string) $category['taxonomy'] . ':' . (string) $category['slug'],
        'legacy_id' => (string) $category['term_id'],
        'name' => (string) $category['name'],
        'taxonomy' => (string) $category['taxonomy'],
        'slug' => (string) $category['slug'],
    ];
}

$entityIds = [];
$entityTypesById = [];
$entities = $rows($db, 'SELECT id,stable_key,entity_type,canonical_name,review_state,revision,metadata FROM ' . $table('nhk_entities') . ' ORDER BY id');
$supportedEntityTypes = ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'media', 'video', 'knowledge'];
$endpointType = static function (string $legacyType): string {
    return match ($legacyType) {
        'article' => 'wp_post',
        'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product', 'media', 'video', 'knowledge' => $legacyType,
        default => '',
    };
};
foreach ($entities as $entity) {
    $id = (string) $entity['id'];
    $entityIds[$id] = true;
    $legacyType = (string) $entity['entity_type'];
    $type = in_array($legacyType, $supportedEntityTypes, true) ? $legacyType : 'legacy_' . $legacyType;
    $entityTypesById[$id] = $endpointType($legacyType);
    $metadata = json_decode((string) $entity['metadata'], true);
    $metadata = is_array($metadata) ? $metadata : [];
    unset($metadata['private_notes'], $metadata['token'], $metadata['password'], $metadata['secret']);
    $records[] = [
        'type' => $type,
        'stable_key' => (string) $entity['stable_key'],
        'canonical_uuid' => $id,
        'legacy_type' => $legacyType,
        'canonical_name' => (string) $entity['canonical_name'],
        'review_state' => (string) $entity['review_state'],
        'revision' => (int) $entity['revision'],
        'metadata' => $metadata,
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
        'source_type' => $entityTypesById[$source] ?? '',
        'target_type' => $entityTypesById[$target] ?? '',
        'source_missing' => !isset($entityIds[$source]) && !isset($postIds[$source]),
        'target_missing' => !isset($entityIds[$target]) && !isset($postIds[$target]),
        'relation_type' => (string) $relation['relation_type'],
        'status' => (string) $relation['status'],
    ];
}

$knowledgeRelations = $rows($db, 'SELECT id,relation_type,source_id,source_type,target_id,target_type,lifecycle_status FROM ' . $table('nhk_knowledge_relations') . ' ORDER BY id');
foreach ($knowledgeRelations as $relation) {
    $source = (string) $relation['source_id'];
    $target = (string) $relation['target_id'];
    $records[] = [
        'type' => 'relation',
        'stable_key' => 'knowledge-relation:' . (string) $relation['id'],
        'source_key' => $source,
        'target_key' => $target,
        'source_type' => (string) $relation['source_type'],
        'target_type' => (string) $relation['target_type'],
        'source_missing' => !isset($entityIds[$source]) && !isset($postIds[$source]),
        'target_missing' => !isset($entityIds[$target]) && !isset($postIds[$target]),
        'relation_type' => (string) $relation['relation_type'],
        'status' => (string) $relation['lifecycle_status'],
    ];
}

$evidence = $rows($db, 'SELECT id,evidence_type,title,source_identity,source_location,creator_publisher,source_date,media_entity_id,notes,verification_state,visibility,revision FROM ' . $table('nhk_knowledge_evidence') . ' ORDER BY id');
foreach ($evidence as $item) {
    $records[] = [
        'type' => 'source',
        'stable_key' => 'v2:evidence:' . (string) $item['id'],
        'canonical_uuid' => (string) $item['id'],
        'legacy_type' => (string) $item['evidence_type'],
        'canonical_name' => (string) ($item['source_identity'] !== '' ? $item['source_identity'] : $item['title']),
        'locator' => (string) $item['source_location'],
        'visibility' => (string) $item['visibility'],
        'verification_state' => (string) $item['verification_state'],
        'metadata' => [
            'legacy_evidence_id' => (string) $item['id'],
            'evidence_title' => (string) $item['title'],
            'creator_publisher' => (string) $item['creator_publisher'],
            'source_date' => (string) $item['source_date'],
            'media_entity_id' => (string) $item['media_entity_id'],
            'notes' => (string) $item['notes'],
            'verification_state' => (string) $item['verification_state'],
            'visibility' => (string) $item['visibility'],
            'revision' => (int) $item['revision'],
        ],
    ];
}

$citations = $rows($db, 'SELECT id,evidence_id,target_type,target_id,citation_role,locator,excerpt_metadata,verification_state FROM ' . $table('nhk_knowledge_citations') . ' ORDER BY id');
foreach ($citations as $citation) {
    $records[] = [
        'type' => 'evidence',
        'stable_key' => 'v2:citation:' . (string) $citation['id'],
        'legacy_id' => (string) $citation['id'],
        'canonical_uuid' => (string) $citation['id'],
        'source_id' => (string) $citation['evidence_id'],
        'claim_id' => (string) $citation['target_id'],
        'target_type' => (string) $citation['target_type'],
        'citation_role' => (string) $citation['citation_role'],
        'excerpt' => (string) $citation['locator'],
        'locator' => '',
        'verification_state' => (string) $citation['verification_state'],
        'visibility' => 'PRIVATE',
        'excerpt_metadata' => (string) $citation['excerpt_metadata'],
    ];
}

$mediaAssets = $rows($db, 'SELECT id,public_id,attachment_id,status,visibility,title,mime_type,checksum,web_path,file_size,width,height,primary_subject_id FROM ' . $table('nhk_media_assets') . ' ORDER BY id');
foreach ($mediaAssets as $asset) {
    $records[] = [
        'type' => 'legacy_media_asset',
        'stable_key' => 'media-asset:' . (string) $asset['public_id'],
        'canonical_uuid' => (string) $asset['id'],
        'legacy_type' => 'media_asset',
        'status' => (string) $asset['status'],
        'visibility' => (string) $asset['visibility'],
        'mime_type' => (string) $asset['mime_type'],
        'checksum' => (string) $asset['checksum'],
        'storage_key' => (string) $asset['web_path'],
        'byte_size' => (int) $asset['file_size'],
        'width' => (int) $asset['width'],
        'height' => (int) $asset['height'],
        'media_id' => (string) $asset['primary_subject_id'],
    ];
}

$projections = $rows($db, 'SELECT projection_id,semantic_id,canonical_object_id,canonical_object_type,projection_type,visibility,quality_state,seo_ready,ai_ready,stale FROM ' . $table('nhk_semantic_projections') . ' ORDER BY projection_id');
foreach ($projections as $projection) {
    $records[] = [
        'type' => 'legacy_semantic_projection',
        'stable_key' => (string) $projection['semantic_id'],
        'legacy_type' => (string) $projection['projection_type'],
        'canonical_object_type' => (string) $projection['canonical_object_type'],
        'canonical_object_id' => (string) $projection['canonical_object_id'],
        'visibility' => (string) $projection['visibility'],
        'quality_state' => (string) $projection['quality_state'],
        'seo_ready' => (int) $projection['seo_ready'],
        'ai_ready' => (int) $projection['ai_ready'],
        'stale' => (int) $projection['stale'],
    ];
}

echo json_encode(['records' => $records], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
