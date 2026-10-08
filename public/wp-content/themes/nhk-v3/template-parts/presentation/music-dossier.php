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
    return array_values(array_filter($value, static fn(mixed $item): bool => is_array($item) && trim((string) ($item['text'] ?? $item['title'] ?? $item['name'] ?? $item['source_title'] ?? $item['term'] ?? $item['image_url'] ?? $item['thumbnail_url'] ?? '')) !== ''));
};
$hasMusicAudioSection = false;
$publicUrl = static fn(mixed $value): string => function_exists('nhk_v3_public_url') ? nhk_v3_public_url($value) : (is_string($value) ? trim($value) : '');
$typeLabel = static fn(mixed $type): string => match ((string) $type) {
    'product' => 'Sản phẩm', 'specimen' => 'Hiện vật', 'variant' => 'Biến thể', 'model' => 'Mẫu đồng hồ',
    'movement' => 'Bộ máy', 'brand' => 'Thương hiệu', 'video' => 'Video', 'article' => 'Bài viết',
    default => 'Liên quan',
};
$relationContext = static function (array $item): string {
    $type = (string) ($item['type'] ?? '');
    if ($type !== '' && !in_array($type, ['variant', 'movement', 'model', 'brand', 'specimen', 'product', 'article', 'video'], true) && !isset($item['origin'])) return '';
    $predicates = is_array($item['origin']['predicates'] ?? null) ? $item['origin']['predicates'] : [];
    $context = match (true) {
        $type === 'variant' && in_array('configured_with_music', $predicates, true) => 'Cấu hình âm nhạc của biến thể',
        $type === 'movement' && in_array('supports_music', $predicates, true) => 'Bộ máy hỗ trợ giai điệu',
        $type === 'model' => 'Mẫu đồng hồ liên quan',
        $type === 'brand' => 'Thương hiệu liên quan',
        $type === 'specimen' => 'Hiện vật được ghi nhận',
        $type === 'product' => 'Sản phẩm/listing liên quan',
        $type === 'article' => 'Bài viết liên quan',
        $type === 'video' => 'Video liên quan',
        $type === 'variant' => 'Biến thể liên quan',
        default => 'Liên quan',
    };
    $origin = ($item['origin']['kind'] ?? '') === 'DERIVED' ? 'Liên hệ suy ra' : (($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên hệ trực tiếp' : '');
    return trim($origin . ($origin !== '' ? ' · ' : '') . $context);
};
$renderCards = static function (array $values, string $class, callable $items, callable $publicUrl, callable $typeLabel, callable $relationContext): void {
    $values = $items($values);
    if ($values === []) return;
    echo '<div class="' . esc_attr($class) . '">';
    foreach ($values as $item) {
        $url = $publicUrl($item['url'] ?? null);
        $title = trim((string) ($item['title'] ?? $item['name'] ?? $item['source_title'] ?? $item['text'] ?? $item['term'] ?? ''));
        if ($title === '') continue;
        echo '<article class="related-card">';
        if (($item['type'] ?? '') !== '') echo '<span class="related-type">' . esc_html($typeLabel($item['type'])) . '</span>';
        $context = $relationContext($item);
        if ($context !== '') echo '<span class="related-context">' . esc_html($context) . '</span>';
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
<section id="music-dossier" class="dossier-section music-dossier" data-local-preview="false" aria-labelledby="music-dossier-title">
<?php foreach ($sectionOrder as $sectionKey): $section = is_array($sections[$sectionKey] ?? null) ? $sections[$sectionKey] : []; $heading = $sectionHeadings[$sectionKey] ?? 'Hồ sơ âm nhạc'; if ($sectionKey === 'identity'): $identity = $section; $name = (string) ($identity['name'] ?? 'Bản nhạc'); ?>
  <div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h2 id="music-dossier-title"><?php echo esc_html($name); ?></h2></div></div>
  <?php if (($identity['summary'] ?? '') !== ''): ?><p class="entity-lead music-dossier-summary"><?php echo esc_html(nhk_v3_public_copy((string) $identity['summary'])); ?></p><?php endif; ?>
<?php elseif ($sectionKey === 'score' && is_array($section['reference'] ?? null) && !empty($section['reference']['events'])): $score = $section['reference']; ?>
  <section id="music-score" class="music-dossier-section music-score" aria-labelledby="music-score-title">
    <div class="section-head"><div><p class="eyebrow">Bản nhạc</p><h3 id="music-score-title"><?php echo esc_html($heading); ?></h3></div><span class="music-reference-status">Có dữ liệu tham chiếu</span></div>
    <p class="music-score-meta"><?php echo esc_html((string) ($score['version'] ?? 'Bản tham chiếu')); ?> · <?php echo esc_html((string) ($score['source'] ?? 'Nguồn tham chiếu')); ?> · <?php echo esc_html((string) ($score['tuning'] ?? 'Không rõ cung chỉnh')); ?> · <?php echo esc_html((string) ($score['tempo_bpm'] ?? '')); ?> BPM</p>
    <?php $scoreEvents = array_values(array_filter((array) ($score['events'] ?? []), static fn(mixed $event): bool => is_array($event))); $scoreEventsJson = function_exists('wp_json_encode') ? wp_json_encode($scoreEvents) : json_encode($scoreEvents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    <div class="music-score-staff" data-music-score data-score-events="<?php echo esc_attr(is_string($scoreEventsJson) ? $scoreEventsJson : '[]'); ?>" role="region" aria-label="Bản xem dạng khuông nhạc chuẩn hóa theo dữ liệu tham chiếu"></div>
    <p class="music-score-note">Bản xem dạng khuông nhạc chuẩn hóa; không thay thế bản khắc lịch sử hoặc xác nhận cao độ chuông.</p>
    <ol class="music-score-events" aria-label="Các sự kiện trong bản nhạc">
      <?php foreach ((array) $score['events'] as $event): if (!is_array($event)) continue; $start = (float) ($event['start_ms'] ?? 0); $end = $start + (float) ($event['duration_ms'] ?? 0); ?>
      <li><button type="button" class="music-score-event" data-start-ms="<?php echo esc_attr((string) $start); ?>" data-end-ms="<?php echo esc_attr((string) $end); ?>" aria-label="<?php echo esc_attr((string) ($event['pitch_class'] ?? '') . (string) ($event['octave'] ?? '') . ', ' . (string) ($event['phrase'] ?? '')); ?>"><strong><?php echo esc_html((string) ($event['pitch_class'] ?? '')); ?><?php echo esc_html((string) ($event['octave'] ?? '')); ?></strong><span><?php echo esc_html((string) ($event['phrase'] ?? '')); ?></span></button></li>
      <?php endforeach; ?>
    </ol>
    <?php $hasPlayableAudio = false; foreach ((array) ($sections['audio']['items'] ?? []) as $audioItem) { if (!is_array($audioItem)) continue; $audioDelivery = is_array($audioItem['delivery'] ?? null) ? $audioItem['delivery'] : []; if (($audioDelivery['status'] ?? '') === 'AVAILABLE' && ($audioDelivery['source'] ?? '') === 'MEDIA_ASSET' && $publicUrl($audioDelivery['public_url'] ?? null) !== '') { $hasPlayableAudio = true; break; } } $firstScoreEvent = $scoreEvents[0] ?? null; $lastScoreEvent = $scoreEvents[count($scoreEvents) - 1] ?? null; $demoStart = is_array($firstScoreEvent) && is_numeric($firstScoreEvent['start_ms'] ?? null) ? (float) $firstScoreEvent['start_ms'] : null; $demoEnd = is_array($lastScoreEvent) && is_numeric($lastScoreEvent['start_ms'] ?? null) && is_numeric($lastScoreEvent['duration_ms'] ?? null) ? (float) $lastScoreEvent['start_ms'] + (float) $lastScoreEvent['duration_ms'] : null; ?>
    <?php if (!empty($score['segments']) || ($hasPlayableAudio && $demoStart !== null && $demoEnd !== null && $demoEnd > $demoStart)): ?><div class="music-score-segments" aria-label="Các đoạn trong bản nhạc"><?php foreach ((array) $score['segments'] as $segment): if (!is_array($segment)) continue; ?><button type="button" data-music-action="segment" data-start-ms="<?php echo esc_attr((string) ($segment['start_ms'] ?? 0)); ?>" data-end-ms="<?php echo esc_attr((string) ($segment['end_ms'] ?? 0)); ?>"><?php echo esc_html((string) ($segment['label'] ?? 'Đoạn nhạc')); ?></button><?php endforeach; ?><?php if ($hasPlayableAudio && $demoStart !== null && $demoEnd !== null && $demoEnd > $demoStart): ?><button type="button" data-music-action="segment" data-start-ms="<?php echo esc_attr((string) $demoStart); ?>" data-end-ms="<?php echo esc_attr((string) $demoEnd); ?>">Toàn bộ bản trình diễn giáo dục</button><?php endif; ?></div><?php endif; ?>
  </section>
<?php elseif ($sectionKey === 'audio' && !empty($section['items'])): $hasMusicAudioSection = true; ?>
  <section id="music-audio" class="music-dossier-section music-audio" aria-labelledby="music-audio-title">
    <div class="section-head"><div><p class="eyebrow">Âm thanh</p><h3 id="music-audio-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php $audioItems = $items($section['items']); ?><label class="music-instrument-selector">Nhạc cụ <select data-music-instrument aria-label="Chọn nhạc cụ"><?php foreach ($audioItems as $instrumentAudio): $instrumentLabel = (string) ($instrumentAudio['label'] ?? 'Bản phát tham chiếu'); ?><option value="<?php echo esc_attr(md5($instrumentLabel)); ?>"><?php echo esc_html($instrumentLabel); ?></option><?php endforeach; ?></select></label>
    <div class="music-audio-list">
      <?php foreach ($audioItems as $audio): $audioLabel = (string) ($audio['label'] ?? 'Bản phát tham chiếu'); $delivery = is_array($audio['delivery'] ?? null) ? $audio['delivery'] : []; $deliveryUrl = ($delivery['status'] ?? '') === 'AVAILABLE' && ($delivery['source'] ?? '') === 'MEDIA_ASSET' ? $publicUrl($delivery['public_url'] ?? null) : ''; ?>
      <article class="music-audio-card" data-music-audio data-music-instrument="<?php echo esc_attr(md5($audioLabel)); ?>">
        <div><h4><?php echo esc_html((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?></h4><p><?php echo esc_html(match ((string) ($audio['mode'] ?? '')) { 'BELL_SIMULATION' => 'Mô phỏng tiếng chuông', 'HISTORICAL_RECORDING' => 'Bản ghi lịch sử', 'PIANO' => 'Piano', default => 'Tham chiếu âm thanh' }); ?> · <?php echo esc_html((string) ($audio['tempo_bpm'] ?? '')); ?> BPM</p></div>
        <?php if ($deliveryUrl !== ''): ?><audio controls preload="metadata" data-music-audio-source src="<?php echo esc_url($deliveryUrl); ?>" aria-label="<?php echo esc_attr((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?>"></audio><div class="music-audio-controls" aria-label="Điều khiển phát lại"><button type="button" data-music-action="play">Phát</button><button type="button" data-music-action="pause">Tạm dừng</button><button type="button" data-music-action="stop">Dừng</button><label><input type="checkbox" data-music-action="repeat"> Lặp lại</label><label>Tốc độ <select data-music-action="rate"><option value="0.5">0,5×</option><option value="0.75">0,75×</option><option value="1" selected>1×</option><option value="1.25">1,25×</option><option value="1.5">1,5×</option><option value="2">2×</option></select></label><button type="button" data-music-action="reset-rate">Về tốc độ gốc</button></div><div class="music-audio-seek-row"><label for="music-seek-<?php echo esc_attr((string) md5((string) ($audio['label'] ?? 'audio'))); ?>">Tua tới</label><input id="music-seek-<?php echo esc_attr((string) md5((string) ($audio['label'] ?? 'audio'))); ?>" type="range" min="0" max="1" step="0.001" value="0" data-music-seek aria-label="Tua tới trong bản phát"></div><progress max="1" value="0" data-music-progress aria-label="Tiến độ phát lại"></progress><span class="music-audio-time" data-music-time aria-live="off">0:00 / 0:00</span><?php else: ?><p class="music-audio-unavailable">Tham chiếu đã được kiểm tra nhưng chưa có tệp Media phát công khai.</p><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php elseif ($sectionKey === 'library'): ?>
  <section id="music-library" class="music-dossier-section music-section-library" aria-labelledby="music-library-title"><div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h3 id="music-library-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php $renderCards($section['videos'] ?? [], 'related-grid music-library-videos', $items, $publicUrl, $typeLabel, $relationContext); $renderCards($section['articles'] ?? [], 'related-grid music-library-articles', $items, $publicUrl, $typeLabel, $relationContext); if (!empty($section['media'])): ?><div class="media-mosaic music-library-media"><?php foreach ($items($section['media']) as $media): $visual = function_exists('nhk_v3_media_presentation') ? nhk_v3_media_presentation($media) : ['url' => '']; if (($visual['url'] ?? '') === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url((string) $visual['url']); ?>" alt="<?php echo esc_attr((string) ($media['alt'] ?? 'Tư liệu hình ảnh')); ?>" loading="lazy" decoding="async"><figcaption><?php echo esc_html((string) ($media['title'] ?? 'Tư liệu hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
  </section>
<?php elseif ($section !== []): ?>
  <section id="music-<?php echo esc_attr($sectionKey); ?>" class="music-dossier-section music-section-<?php echo esc_attr($sectionKey); ?>" aria-labelledby="music-<?php echo esc_attr($sectionKey); ?>-title"><div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h3 id="music-<?php echo esc_attr($sectionKey); ?>-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php $renderCards($section['claims'] ?? [], 'music-claim-stack', $items, $publicUrl, $typeLabel, $relationContext); $renderCards($section['items'] ?? [], 'related-grid', $items, $publicUrl, $typeLabel, $relationContext); $renderCards($section['dictionary'] ?? [], 'related-grid', $items, $publicUrl, $typeLabel, $relationContext); $renderCards($section['evidence'] ?? [], 'evidence-list', $items, $publicUrl, $typeLabel, $relationContext); ?>
  </section>
<?php endif; endforeach; ?>
<?php if (!$hasMusicAudioSection): ?>
  <section id="music-audio" class="music-dossier-section music-audio" aria-labelledby="music-audio-title">
    <div class="section-head"><div><p class="eyebrow">Âm thanh</p><h3 id="music-audio-title">Nghe tham chiếu</h3></div></div>
    <div class="music-audio-list"><article class="music-audio-card music-audio-card--unavailable"><h4>Bản phát tham chiếu</h4><p class="music-listening-unavailable">Chưa có tệp âm thanh công khai để phát. Tư liệu âm thanh sẽ xuất hiện sau khi được kiểm tra và công bố theo quy trình tư liệu của NHK.</p></article></div>
  </section>
<?php endif; ?>
</section>
<?php endif; ?>
