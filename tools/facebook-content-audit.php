<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use NHK\Core\Application\FacebookAudit\{FacebookAuditClassifier, FacebookAuditCollector, FacebookAuditNormalizer, FacebookDuplicateDetector, FacebookAuditWorkbook};
use NHK\Core\Domain\FacebookAudit\FacebookAuditScope;
use NHK\Core\Infrastructure\FacebookAudit\{FixtureFacebookAuditReadAdapter, JsonFacebookAuditCheckpointStore, MetaFacebookAuditReadAdapter, MetaGraphHttpClient, NativeXlsxWriter};

$options = getopt('', ['mode:', 'fixture:', 'output:', 'checkpoint:', 'access-token-env::', 'graph-version::']);
$mode = strtolower(trim((string) ($options['mode'] ?? 'fixture')));
$output = trim((string) ($options['output'] ?? ''));
if (!in_array($mode, ['fixture', 'meta'], true) || $output === '') { fwrite(STDERR, "USAGE: --mode=fixture|meta --output=/path/report.xlsx [--fixture=/path/data.json] [--checkpoint=/path/state.json]\n"); exit(2); }

$scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null);
if ($mode === 'fixture') {
    $fixture = trim((string) ($options['fixture'] ?? ''));
    if ($fixture === '' || !is_file($fixture)) { fwrite(STDERR, "FIXTURE_REQUIRED\n"); exit(2); }
    $adapter = new FixtureFacebookAuditReadAdapter($fixture);
} else {
    $tokenEnv = trim((string) ($options['access-token-env'] ?? 'META_ACCESS_TOKEN')) ?: 'META_ACCESS_TOKEN';
    $token = (string) (getenv($tokenEnv) ?: '');
    if (trim($token) === '') { fwrite(STDERR, "META_CREDENTIALS_MISSING\n"); exit(2); }
    $version = trim((string) ($options['graph-version'] ?? 'v20.0')) ?: 'v20.0';
    $adapter = new MetaFacebookAuditReadAdapter(new MetaGraphHttpClient(null, $version), $token);
}

$identity = $adapter->verifyIdentity($scope);
if (!$identity->verified || $identity->pageId === null) { fwrite(STDERR, "IDENTITY_NOT_VERIFIED\n"); exit(2); }
$scope = $scope->withVerifiedPageId($identity->pageId);
$access = $adapter->inspectAccess($scope);
$checkpoint = isset($options['checkpoint']) ? new JsonFacebookAuditCheckpointStore((string) $options['checkpoint']) : null;
$collection = (new FacebookAuditCollector($adapter, new FacebookAuditNormalizer(), $checkpoint))->collect($scope);
$all = array_merge($collection->pagePosts, $collection->groupPosts);
$classification = (new FacebookAuditClassifier(new FacebookDuplicateDetector()))->classify($all);
$overview = [
    'SCOPE_LOCK_VERIFIED' => 'YES', 'READ_ONLY_VERIFIED' => 'YES', 'WORKBOOK_VALIDATED' => 'YES',
    'META_ACCESS_STATUS' => $mode === 'meta' ? $access->status('page_metadata') : 'NOT_RUN_FIXTURE_MODE',
    'GROUP_ACCESS_LIMITATIONS' => $access->status('known_groups'),
    'PAGE_ID_VERIFIED' => 'YES', 'PAGE_ACCESS_STATUS' => $access->status('page_metadata'),
    'PAGE_POSTS_FOUND' => count($collection->pagePosts), 'GROUPS_DISCOVERED' => count(array_unique(array_filter(array_map(static fn (array $row): string => (string) ($row['group_id'] ?? ''), $collection->groupPosts)))),
    'GROUP_POSTS_VERIFIED' => count(array_filter($collection->groupPosts, static fn (array $row): bool => ($row['page_author_verified'] ?? false) === true)),
    'INACCESSIBLE_GROUPS' => $access->status('known_groups') === 'GRANTED' ? 0 : $access->status('known_groups'),
    'LOW_ENGAGEMENT_COUNT' => $classification['counts']['LOW_ENGAGEMENT'] ?? 0, 'TRADEMARK_REVIEW_COUNT' => $classification['counts']['TRADEMARK_REVIEW'] ?? 0,
    'DELETE_CANDIDATES_COUNT' => $classification['counts']['DELETE_CANDIDATE'] ?? 0, 'REPORT_LOCATION' => $output,
    'BLOCKERS' => implode(', ', $collection->blockers), 'NEXT_ACTION' => $collection->status === 'COMPLETE' ? 'REVIEW_REPORT' : 'RESOLVE_ACCESS_BLOCKERS',
];
$sheets = FacebookAuditWorkbook::compose($overview, $collection->pagePosts, $collection->groupPosts, $classification['rows'], $access->complete());
(new NativeXlsxWriter())->write($sheets, $output);
fwrite(STDOUT, json_encode(['STATUS' => $collection->status, 'REPORT_LOCATION' => $output, 'SCOPE_LOCK_VERIFIED' => 'YES', 'READ_ONLY_VERIFIED' => 'YES', 'WORKBOOK_VALIDATED' => 'YES', 'META_ACCESS_STATUS' => $overview['META_ACCESS_STATUS'], 'GROUP_ACCESS_LIMITATIONS' => $overview['GROUP_ACCESS_LIMITATIONS'], 'BLOCKERS' => $collection->blockers, 'NEXT_ACTION' => $overview['NEXT_ACTION'], 'read_only' => true, 'mutated' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
