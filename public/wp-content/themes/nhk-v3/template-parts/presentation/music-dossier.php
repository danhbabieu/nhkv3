<?php
declare(strict_types=1);

$args = is_array($args ?? null) ? $args : [];
// music_dossier is a sanitized, read-only projection packet.
$dossier = is_array($args['dossier'] ?? null) ? $args['dossier'] : [];
$sections = is_array($dossier['sections'] ?? null) ? $dossier['sections'] : [];
$sectionOrder = is_array($dossier['section_order'] ?? null) ? array_values(array_filter($dossier['section_order'], 'is_string')) : [];
$sectionHeadings = [
    'identity' => 'Hồ sơ âm nhạc', 'introduction' => 'Giới thiệu', 'history' => 'Lịch sử & bối cảnh',
    'structure' => 'Cấu trúc âm nhạc', 'variants' => 'Biến thể', 'clock_application' => 'Ứng dụng trên đồng hồ',
    'related_entities' => 'Thực thể liên quan', 'score' => 'Bản nhạc', 'audio' => 'Âm thanh',
    'library' => 'Thư viện tư liệu', 'research' => 'Nghiên cứu & đối chiếu', 'sources' => 'Nguồn tham chiếu',
    'related_melodies' => 'Giai điệu liên quan',
];
$items = static function (mixed $value): array {
    if (!is_array($value)) return [];
    return array_values(array_filter($value, static fn(mixed $item): bool => is_array($item) && trim((string) ($item['text'] ?? $item['title'] ?? $item['name'] ?? $item['source_title'] ?? '')) !== ''));
};
$publicUrl = static fn(mixed $value): string => function_exists('nhk_v3_public_url') ? nhk_v3_public_url($value) : (is_string($value) ? trim($value) : '');
$typeLabel = static fn(mixed $type): string => match ((string) $type) {
    'product' => 'Sản phẩm', 'specimen' => 'Hiện vật', 'variant' => 'Biến thể', 'model' => 'Mẫu đồng hồ',
    'movement' => 'Bộ máy', 'brand' => 'Thương hiệu', 'video' => 'Video', 'article' => 'Bài viết',
    default => 'Liên quan',
};
$renderCards = static function (array $values, string $class, callable $items, callable $publicUrl, callable $typeLabel): void {
    $values = $items($values);
    if ($values === []) return;
    echo '<div class="' . esc_attr($class) . '">';
    foreach ($values as $item) {
        $url = $publicUrl($item['url'] ?? null);
        $title = trim((string) ($item['title'] ?? $item['name'] ?? $item['source_title'] ?? $item['text'] ?? $item['term'] ?? ''));
        if ($title === '') continue;
        echo '<article class="related-card">';
        if (($item['type'] ?? '') !== '') echo '<span class="related-type">' . esc_html($typeLabel($item['type'])) . '</span>';
        if ($url !== '') echo '<a href="' . esc_url($url) . '"><strong>' . esc_html($title) . '</strong></a>'; else echo '<strong>' . esc_html($title) . '</strong>';
        if (($item['excerpt'] ?? '') !== '') echo '<span>' . esc_html((string) $item['excerpt']) . '</span>';
        if (($item['definition'] ?? '') !== '') echo '<span>' . esc_html((string) $item['definition']) . '</span>';
        if (($item['locator'] ?? '') !== '') echo '<small>' . esc_html((string) $item['locator']) . '</small>';
        echo '</article>';
    }
    echo '</div>';
};
?>
<?php if (($dossier['status'] ?? '') === 'AVAILABLE'): ?>
<section id="music-dossier" class="dossier-section music-dossier" aria-labelledby="music-dossier-title">
<?php foreach ($sectionOrder as $sectionKey): $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : []; $heading = $sectionHeadings[$sectionKey] ?? 'Hồ sơ âm nhạc'; if ($sectionKey === 'identity'): $identity = $section; $name = (string) ($identity['name'] ?? 'Bản nhạc'); ?>
  <div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h2 id="music-dossier-title"><?php echo esc_html($name); ?></h2></div></div>
  <?php if (($identity['summary'] ?? '') !== ''): ?><p class="entity-lead music-dossier-summary"><?php echo esc_html(nhk_v3_public_copy((string) $identity['summary'])); ?></p><?php endif; ?>
<?php elseif ($sectionKey === 'score' && is_array($section['reference'] ?? null) && !empty($section['reference']['events'])): $score = $section['reference']; ?>
  <section id="music-score" class="music-dossier-section music-score" aria-labelledby="music-score-title">
    <div class="section-head"><div><p class="eyebrow">Bản nhạc</p><h3 id="music-score-title"><?php echo esc_html($heading); ?></h3></div><span class="music-reference-status">Có dữ liệu tham chiếu</span></div>
    <p class="music-score-meta"><?php echo esc_html((string) ($score['source'] ?? 'Nguồn tham chiếu')); ?> · <?php echo esc_html((string) ($score['tuning'] ?? 'Không rõ cung chỉnh')); ?> · <?php echo esc_html((string) ($score['tempo_bpm'] ?? '')); ?> BPM</p>
    <ol class="music-score-events" aria-label="Các sự kiện trong bản nhạc">
      <?php foreach ((array) $score['events'] as $event): if (!is_array($event)) continue; $start = (float) ($event['start_ms'] ?? 0); $end = $start + (float) ($event['duration_ms'] ?? 0); ?>
      <li class="music-score-event" data-start-ms="<?php echo esc_attr((string) $start); ?>" data-end-ms="<?php echo esc_attr((string) $end); ?>"><strong><?php echo esc_html((string) ($event['pitch_class'] ?? '')); ?><?php echo esc_html((string) ($event['octave'] ?? '')); ?></strong><span><?php echo esc_html((string) ($event['phrase'] ?? '')); ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php if (!empty($score['segments'])): ?><div class="music-score-segments" aria-label="Các đoạn trong bản nhạc"><?php foreach ((array) $score['segments'] as $segment): if (!is_array($segment)) continue; ?><button type="button" data-music-action="segment" data-start-ms="<?php echo esc_attr((string) ($segment['start_ms'] ?? 0)); ?>" data-end-ms="<?php echo esc_attr((string) ($segment['end_ms'] ?? 0)); ?>"><?php echo esc_html((string) ($segment['label'] ?? 'Đoạn nhạc')); ?></button><?php endforeach; ?></div><?php endif; ?>
  </section>
<?php elseif ($sectionKey === 'audio' && !empty($section['items'])): ?>
  <section id="music-audio" class="music-dossier-section music-audio" aria-labelledby="music-audio-title">
    <div class="section-head"><div><p class="eyebrow">Âm thanh</p><h3 id="music-audio-title"><?php echo esc_html($heading); ?></h3></div></div>
    <div class="music-audio-list">
      <?php foreach ($items($section['items']) as $audio): $delivery = is_array($audio['delivery'] ?? null) ? $audio['delivery'] : []; $deliveryUrl = ($delivery['status'] ?? '') === 'AVAILABLE' && ($delivery['source'] ?? '') === 'MEDIA_ASSET' ? $publicUrl($delivery['public_url'] ?? null) : ''; ?>
      <article class="music-audio-card" data-music-audio>
        <div><h4><?php echo esc_html((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?></h4><p><?php echo esc_html(match ((string) ($audio['mode'] ?? '')) { 'BELL_SIMULATION' => 'Mô phỏng tiếng chuông', 'HISTORICAL_RECORDING' => 'Bản ghi lịch sử', 'PIANO' => 'Piano', default => 'Tham chiếu âm thanh' }); ?> · <?php echo esc_html((string) ($audio['tempo_bpm'] ?? '')); ?> BPM</p></div>
        <?php if ($deliveryUrl !== ''): ?><audio controls preload="none" data-music-audio-source src="<?php echo esc_url($deliveryUrl); ?>" aria-label="<?php echo esc_attr((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?>"></audio><div class="music-audio-controls" aria-label="Điều khiển phát lại"><button type="button" data-music-action="play">Phát</button><button type="button" data-music-action="pause">Tạm dừng</button><button type="button" data-music-action="stop">Dừng</button><label><input type="checkbox" data-music-action="repeat"> Lặp lại</label><label>Tốc độ <select data-music-action="rate"><option value="1">1×</option><option value="0.75">0,75×</option><option value="1.25">1,25×</option></select></label><button type="button" data-music-action="reset-rate">Về tốc độ gốc</button></div><progress max="1" value="0" data-music-progress aria-label="Tiến độ phát lại"></progress><?php else: ?><p class="music-audio-unavailable">Tham chiếu đã được kiểm tra nhưng chưa có tệp Media phát công khai.</p><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php elseif ($sectionKey === 'library'): ?>
  <section id="music-library" class="music-dossier-section music-section-library" aria-labelledby="music-library-title"><div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h3 id="music-library-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php $renderCards($section['videos'] ?? [], 'related-grid music-library-videos', $items, $publicUrl, $typeLabel); $renderCards($section['articles'] ?? [], 'related-grid music-library-articles', $items, $publicUrl, $typeLabel); if (!empty($section['media'])): ?><div class="media-mosaic music-library-media"><?php foreach ($items($section['media']) as $media): $visual = function_exists('nhk_v3_media_presentation') ? nhk_v3_media_presentation($media) : ['url' => '']; if (($visual['url'] ?? '') === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url((string) $visual['url']); ?>" alt="<?php echo esc_attr((string) ($media['alt'] ?? 'Tư liệu hình ảnh')); ?>" loading="lazy" decoding="async"><figcaption><?php echo esc_html((string) ($media['title'] ?? 'Tư liệu hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
  </section>
<?php elseif ($section !== []): ?>
  <section id="music-<?php echo esc_attr($sectionKey); ?>" class="music-dossier-section music-section-<?php echo esc_attr($sectionKey); ?>" aria-labelledby="music-<?php echo esc_attr($sectionKey); ?>-title"><div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h3 id="music-<?php echo esc_attr($sectionKey); ?>-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php $renderCards($section['claims'] ?? [], 'music-claim-stack', $items, $publicUrl, $typeLabel); $renderCards($section['items'] ?? [], 'related-grid', $items, $publicUrl, $typeLabel); $renderCards($section['dictionary'] ?? [], 'related-grid', $items, $publicUrl, $typeLabel); $renderCards($section['evidence'] ?? [], 'evidence-list', $items, $publicUrl, $typeLabel); ?>
  </section>
<?php endif; endforeach; ?>
</section>
<?php endif; ?>
