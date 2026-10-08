<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

$packet = is_array($args['packet'] ?? null) ? $args['packet'] : [];
$identity = is_array($packet['identity'] ?? null) ? $packet['identity'] : [];
$lexical = is_array($packet['lexical'] ?? null) ? $packet['lexical'] : [];
$route = is_array($packet['route'] ?? null) ? $packet['route'] : [];
$isDelegated = ($route['delegated'] ?? false) === true;
$canonicalOwner = is_array($packet['canonical_owner'] ?? null) ? $packet['canonical_owner'] : [];
$canonicalOwnerUrl = nhk_v3_public_url($canonicalOwner['url'] ?? null);
$senses = array_values(array_filter((array) ($packet['senses'] ?? []), 'is_array'));
$title = trim((string) ($identity['title'] ?? $lexical['preferred_form'] ?? ''));
$bucketItems = static fn (mixed $bucket): array => is_array($bucket) && ($bucket['status'] ?? '') === 'AVAILABLE_WITH_ITEMS' && is_array($bucket['items'] ?? null) ? $bucket['items'] : [];
$itemTitle = static fn (array $item): string => trim((string) ($item['title'] ?? $item['name'] ?? $item['text'] ?? ''));
$publicUrl = static fn (mixed $url): string => nhk_v3_public_url($url);
$statusRows = [];
foreach ([
    'knowledge' => ['label' => 'Tri thức liên quan', 'bucket' => $packet['knowledge'] ?? []],
    'relations' => ['label' => 'Quan hệ và ngữ cảnh', 'bucket' => $packet['relations'] ?? []],
    'media' => ['label' => 'Hình ảnh', 'bucket' => $packet['media'] ?? []],
    'videos' => ['label' => 'Video', 'bucket' => $packet['videos'] ?? []],
    'articles' => ['label' => 'Bài viết liên quan', 'bucket' => $packet['articles'] ?? []],
] as $row) {
    $bucket = is_array($row['bucket'] ?? null) ? $row['bucket'] : [];
    $status = strtoupper(trim((string) ($bucket['status'] ?? '')));
    if ($status === 'AVAILABLE_EMPTY') $statusRows[] = ['label' => (string) $row['label'], 'message' => 'Chưa có dữ liệu công khai liên quan.'];
    elseif ($status === 'UNAVAILABLE') $statusRows[] = ['label' => (string) $row['label'], 'message' => 'Hiện chưa thể truy vấn dữ liệu này.'];
    elseif ($status === 'BLOCKED') $statusRows[] = ['label' => (string) $row['label'], 'message' => 'Chưa thể hiển thị vì dữ liệu chưa đủ điều kiện.'];
}
$facetLabels = ['brands' => 'Thương hiệu', 'clock_types' => 'Loại đồng hồ', 'configurations' => 'Cấu hình', 'movements' => 'Bộ máy', 'music' => 'Âm nhạc', 'countries' => 'Quốc gia', 'models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'specimens' => 'Hiện vật', 'components' => 'Linh kiện', 'classifications' => 'Phân loại'];
$relationLabels = ['BROADER' => 'Nhóm rộng hơn', 'NARROWER' => 'Dạng cụ thể', 'NEAR_SYNONYM' => 'Gần nghĩa', 'SAME_TERM_FAMILY' => 'Cùng nhóm thuật ngữ', 'RELATED' => 'Thuật ngữ liên quan'];
?>
<article class="dictionary-entry dictionary-unified-detail">
    <header class="dictionary-lexical-hero">
        <p class="eyebrow">Thuật ngữ</p>
        <h1><?php echo esc_html($title); ?></h1>
        <?php $definition = count($senses) === 1 ? (string) ($senses[0]['definition'] ?? '') : ''; if ($definition === '') $definition = (string) ($packet['seo']['meta_description'] ?? ''); if ($definition !== ''): ?><p class="dictionary-definition"><?php echo esc_html($definition); ?></p><?php endif; ?>
    </header>

    <?php if ($isDelegated && $canonicalOwnerUrl !== ''): ?><section class="dictionary-canonical-owner" aria-label="Hồ sơ chính"><p class="eyebrow">Hồ sơ chính</p><p>Thuật ngữ này được trình bày cùng hồ sơ canonical của NHK.</p><a href="<?php echo esc_url($canonicalOwnerUrl); ?>">Mở hồ sơ <?php echo esc_html((string) ($canonicalOwner['title'] ?? $title)); ?> <span aria-hidden="true">→</span></a></section><?php endif; ?>
    <?php if ($statusRows !== []): ?><section class="dictionary-data-status" aria-label="Trạng thái dữ liệu" role="status" aria-live="polite"><h2>Trạng thái dữ liệu liên quan</h2><ul><?php foreach ($statusRows as $statusRow): ?><li><strong><?php echo esc_html((string) $statusRow['label']); ?>:</strong> <?php echo esc_html((string) $statusRow['message']); ?></li><?php endforeach; ?></ul></section><?php endif; ?>

    <?php $forms = array_values(array_filter((array) ($lexical['alternate_forms'] ?? []), 'is_array')); if ($forms !== []): ?><section class="dictionary-detail-section"><h2>Các dạng từ</h2><div class="dictionary-form-list"><?php foreach ($forms as $form): ?><span><?php echo esc_html((string) ($form['form'] ?? '')); ?><?php if (($form['kind'] ?? '') !== ''): ?> <small><?php echo esc_html(nhk_v3_dictionary_label_kind((string) $form['kind'])); ?></small><?php endif; ?></span><?php endforeach; ?></div></section><?php endif; ?>

    <?php if (count($senses) > 1): ?><section class="dictionary-detail-section"><h2>Các nghĩa</h2><?php endif; ?>
    <?php foreach ($senses as $index => $sense): ?>
        <?php $owner = is_array($sense['canonical_owner'] ?? null) ? $sense['canonical_owner'] : []; $ownerUrl = $publicUrl($owner['url'] ?? null); $scope = array_values(array_filter((array) ($sense['usage_scope'] ?? []))); $notes = array_values(array_filter((array) ($sense['usage_notes'] ?? []))); ?>
        <?php if (count($senses) > 1): ?><section class="dictionary-sense dictionary-detail-section"><h3><?php echo esc_html((string) ($sense['title'] ?? 'Nghĩa ' . ($index + 1))); ?></h3><?php if (($sense['definition'] ?? '') !== ''): ?><p class="dictionary-definition"><?php echo esc_html((string) $sense['definition']); ?></p><?php endif; ?>
        <?php else: ?><section class="dictionary-sense dictionary-detail-section">
        <?php endif; ?>
        <?php if ($scope !== []): ?><section class="dictionary-usage"><h3>Phạm vi sử dụng</h3><ul><?php foreach ($scope as $value): ?><li><?php echo esc_html((string) $value); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <?php if ($notes !== []): ?><section class="dictionary-usage"><h3>Lưu ý cách dùng</h3><ul><?php foreach ($notes as $value): ?><li><?php echo esc_html((string) $value); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <?php if (!$isDelegated && $ownerUrl !== ''): ?><div class="dictionary-owner"><p class="eyebrow">Hồ sơ chính</p><a href="<?php echo esc_url($ownerUrl); ?>"><?php echo esc_html((string) ($owner['title'] ?? 'Xem hồ sơ đầy đủ')); ?></a></div><?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if (count($senses) > 1): ?></section><?php endif; ?>

    <?php $knowledge = $bucketItems($packet['knowledge'] ?? []); if ($knowledge !== []): ?><section class="dictionary-detail-section"><h2>Tri thức liên quan</h2><div class="dictionary-knowledge-list"><?php foreach ($knowledge as $claim): $text = $itemTitle(is_array($claim) ? $claim : []); if ($text !== ''): ?><p><?php echo esc_html(nhk_v3_public_copy($text)); ?></p><?php endif; endforeach; ?></div></section><?php endif; ?>

    <?php $relations = is_array($packet['relations'] ?? null) ? $packet['relations'] : []; $relationItems = $bucketItems($relations); $facetItems = []; foreach ((array) ($relations['facets'] ?? []) as $key => $bucket) foreach ($bucketItems($bucket) as $facet) $facetItems[$key][] = $facet; if ($relationItems !== [] || $facetItems !== []): ?><section class="dictionary-detail-section"><h2>Quan hệ và ngữ cảnh</h2><?php if ($relationItems !== []): ?><div class="dictionary-related-grid"><?php foreach ($relationItems as $relation): $relationTitle = $itemTitle(is_array($relation) ? $relation : []); if ($relationTitle === '') continue; $url = $publicUrl($relation['url'] ?? null); $direct = strtoupper((string) ($relation['origin']['kind'] ?? $relation['relationship_class'] ?? '')) === 'DIRECT'; $label = $direct ? 'Liên quan trực tiếp' : 'Liên quan mở rộng'; ?><?php if ($url !== ''): ?><a class="dictionary-related-card" href="<?php echo esc_url($url); ?>"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($relationTitle); ?></strong></a><?php endif; ?><?php endforeach; ?></div><?php endif; ?><?php foreach ($facetLabels as $key => $label): if (empty($facetItems[$key])) continue; ?><section><h3><?php echo esc_html($label); ?></h3><div class="dictionary-related-grid"><?php foreach ($facetItems[$key] as $facet): $facetTitle = $itemTitle(is_array($facet) ? $facet : []); $url = $publicUrl($facet['url'] ?? null); if ($facetTitle === '') continue; ?><?php if ($url !== ''): ?><a class="dictionary-related-card" href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html($facetTitle); ?></strong></a><?php endif; ?><?php endforeach; ?></div></section><?php endforeach; ?></section><?php endif; ?>

    <?php $media = $bucketItems($packet['media'] ?? []); if ($media !== []): ?><section class="dictionary-detail-section"><h2>Hình ảnh</h2><div class="media-mosaic dictionary-gallery"><?php foreach ($media as $mediaItem): if (!is_array($mediaItem)) continue; $visual = nhk_v3_media_presentation($mediaItem); if (($visual['url'] ?? '') === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url($visual['url']); ?>" alt="<?php echo esc_attr((string) ($mediaItem['alt'] ?? $mediaItem['title'] ?? $title)); ?>" loading="lazy" decoding="async"><figcaption><?php echo esc_html((string) ($mediaItem['title'] ?? 'Hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div></section><?php endif; ?>

    <?php $videos = $bucketItems($packet['videos'] ?? []); if ($videos !== []): ?><section class="dictionary-detail-section"><h2>Video</h2><div class="video-card-grid"><?php foreach ($videos as $video): if (is_array($video)) get_template_part('template-parts/presentation/video-card', null, ['item' => $video]); endforeach; ?></div></section><?php endif; ?>
    <?php $articles = $bucketItems($packet['articles'] ?? []); if ($articles !== []): ?><section class="dictionary-detail-section"><h2>Bài viết liên quan</h2><div class="dictionary-related-grid"><?php foreach ($articles as $article): $url = $publicUrl($article['url'] ?? null); $articleTitle = $itemTitle($article); if ($url === '' || $articleTitle === '') continue; ?><a class="dictionary-related-card" href="<?php echo esc_url($url); ?>"><span>Bài viết</span><strong><?php echo esc_html($articleTitle); ?></strong></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php $related = array_values(array_filter((array) ($packet['related_terms'] ?? []), 'is_array')); if ($related !== []): ?><section class="dictionary-detail-section"><h2>Thuật ngữ liên quan</h2><div class="dictionary-related-grid"><?php foreach ($related as $term): $url = $publicUrl($term['url'] ?? null); $termTitle = $itemTitle($term); if ($url === '' || $termTitle === '') continue; ?><a class="dictionary-related-card" href="<?php echo esc_url($url); ?>"><span><?php echo esc_html($relationLabels[strtoupper((string) ($term['relation_kind'] ?? 'RELATED'))] ?? 'Thuật ngữ liên quan'); ?></span><strong><?php echo esc_html($termTitle); ?></strong></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php $mentions = is_array($packet['mentions'] ?? null) ? $packet['mentions'] : []; if (($mentions['status'] ?? '') === 'AVAILABLE_WITH_ITEMS'): ?><section class="dictionary-detail-section dictionary-mentions"><h2>Nội dung có nhắc đến</h2><?php foreach ((array) ($mentions['groups'] ?? []) as $kind => $mentionItems): if (!is_array($mentionItems) || $mentionItems === []) continue; ?><h3><?php echo esc_html(['ARTICLE' => 'Bài viết', 'KNOWLEDGE' => 'Tri thức', 'MEDIA' => 'Hình ảnh', 'VIDEO' => 'Video'][$kind] ?? 'Nội dung'); ?></h3><ul><?php foreach ($mentionItems as $mention): if (!is_array($mention)) continue; $url = $publicUrl($mention['url'] ?? null); $mentionTitle = $itemTitle($mention); if ($mentionTitle === '') continue; ?><li><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($mentionTitle); ?></a><?php else: ?><?php echo esc_html($mentionTitle); ?><?php endif; ?></li><?php endforeach; ?></ul><?php endforeach; ?></section><?php endif; ?>
</article>
