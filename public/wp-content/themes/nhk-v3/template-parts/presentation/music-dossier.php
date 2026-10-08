<?php
declare(strict_types=1);

$args = is_array($args ?? null) ? $args : [];
// music_dossier is the sanitized, read-only projection packet.
$dossier = is_array($args['dossier'] ?? null) ? $args['dossier'] : [];
$identity = is_array($dossier['sections']['identity'] ?? null) ? $dossier['sections']['identity'] : [];
$sections = is_array($dossier['sections'] ?? null) ? $dossier['sections'] : [];
$score = is_array($dossier['score'] ?? null) ? $dossier['score'] : null;
$audioItems = is_array($dossier['audio'] ?? null) ? $dossier['audio'] : [];
$sectionLabels = [
    'introduction' => 'Giới thiệu', 'history' => 'Lịch sử & bối cảnh', 'structure' => 'Cấu trúc âm nhạc',
    'clock_application' => 'Ứng dụng trên đồng hồ', 'verified_clocks' => 'Đồng hồ đã được ghi nhận',
    'library' => 'Thư viện tư liệu', 'research' => 'Nghiên cứu & đối chiếu',
    'sources' => 'Nguồn tham chiếu', 'related_melodies' => 'Giai điệu liên quan',
];
$items = static function (mixed $value): array {
    if (!is_array($value)) return [];
    return array_values(array_filter($value, static fn(mixed $item): bool => is_array($item) && trim((string) ($item['text'] ?? $item['title'] ?? $item['name'] ?? '')) !== ''));
};
$publicUrl = static fn(mixed $value): string => function_exists('nhk_v3_public_url') ? nhk_v3_public_url($value) : (is_string($value) ? trim($value) : '');
?>
<?php if (($dossier['status'] ?? '') === 'AVAILABLE'): ?>
<section id="music-dossier" class="dossier-section music-dossier" aria-labelledby="music-dossier-title">
  <div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h2 id="music-dossier-title"><?php echo esc_html((string) ($identity['name'] ?? 'Bản nhạc')); ?></h2></div></div>
  <?php if (($identity['summary'] ?? '') !== ''): ?><p class="entity-lead music-dossier-summary"><?php echo esc_html(nhk_v3_public_copy((string) $identity['summary'])); ?></p><?php endif; ?>

  <?php if ($score !== null && !empty($score['events'])): ?>
  <section id="music-score" class="music-dossier-section music-score" aria-labelledby="music-score-title">
    <div class="section-head"><div><p class="eyebrow">Bản nhạc</p><h3 id="music-score-title">Điểm nhạc đã kiểm chứng</h3></div><span class="music-reference-status">Có dữ liệu tham chiếu</span></div>
    <p class="music-score-meta"><?php echo esc_html((string) ($score['source'] ?? 'Nguồn tham chiếu')); ?> · <?php echo esc_html((string) ($score['tuning'] ?? 'Không rõ cung chỉnh')); ?> · <?php echo esc_html((string) ($score['tempo_bpm'] ?? '')); ?> BPM</p>
    <ol class="music-score-events" aria-label="Các sự kiện trong bản nhạc">
      <?php foreach ($score['events'] as $event): if (!is_array($event)) continue; $start = (float) ($event['start_ms'] ?? 0); $end = $start + (float) ($event['duration_ms'] ?? 0); ?>
      <li class="music-score-event" data-start-ms="<?php echo esc_attr((string) $start); ?>" data-end-ms="<?php echo esc_attr((string) $end); ?>"><strong><?php echo esc_html((string) ($event['pitch_class'] ?? '')); ?><?php echo esc_html((string) ($event['octave'] ?? '')); ?></strong><span><?php echo esc_html((string) ($event['phrase'] ?? '')); ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php if (!empty($score['segments'])): ?><div class="music-score-segments" aria-label="Các đoạn trong bản nhạc"><?php foreach ($score['segments'] as $segment): if (!is_array($segment)) continue; ?><button type="button" data-music-action="segment" data-start-ms="<?php echo esc_attr((string) ($segment['start_ms'] ?? 0)); ?>" data-end-ms="<?php echo esc_attr((string) ($segment['end_ms'] ?? 0)); ?>"><?php echo esc_html((string) ($segment['label'] ?? 'Đoạn nhạc')); ?></button><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($audioItems !== []): ?>
  <section id="music-audio" class="music-dossier-section music-audio" aria-labelledby="music-audio-title">
    <div class="section-head"><div><p class="eyebrow">Âm thanh</p><h3 id="music-audio-title">Tham chiếu phát lại</h3></div></div>
    <div class="music-audio-list">
      <?php foreach ($audioItems as $audio): if (!is_array($audio)) continue; $url = $publicUrl($audio['url'] ?? null); ?>
      <article class="music-audio-card" data-music-audio>
        <div><h4><?php echo esc_html((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?></h4><p><?php echo esc_html(match ((string) ($audio['mode'] ?? '')) { 'BELL_SIMULATION' => 'Mô phỏng tiếng chuông', 'HISTORICAL_RECORDING' => 'Bản ghi lịch sử', 'PIANO' => 'Piano', default => 'Tham chiếu âm thanh' }); ?> · <?php echo esc_html((string) ($audio['tempo_bpm'] ?? '')); ?> BPM</p></div>
        <?php if ($url !== ''): ?><audio controls preload="none" data-music-audio-source src="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr((string) ($audio['label'] ?? 'Bản phát tham chiếu')); ?>"></audio><div class="music-audio-controls" aria-label="Điều khiển phát lại"><button type="button" data-music-action="play">Phát</button><button type="button" data-music-action="pause">Tạm dừng</button><button type="button" data-music-action="stop">Dừng</button><label><input type="checkbox" data-music-action="repeat"> Lặp lại</label><label>Tốc độ <select data-music-action="rate"><option value="1">1×</option><option value="0.75">0,75×</option><option value="1.25">1,25×</option></select></label><button type="button" data-music-action="reset-rate">Về tốc độ gốc</button></div><progress max="1" value="0" data-music-progress aria-label="Tiến độ phát lại"></progress><?php else: ?><p class="music-audio-unavailable">Tham chiếu đã được kiểm tra nhưng chưa có tệp phát công khai.</p><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($sectionLabels as $key => $heading): $section = is_array($sections[$key] ?? null) ? $sections[$key] : []; if ($section === []) continue; ?>
  <section id="music-<?php echo esc_attr($key); ?>" class="music-dossier-section music-section-<?php echo esc_attr($key); ?>" aria-labelledby="music-<?php echo esc_attr($key); ?>-title"><div class="section-head"><div><p class="eyebrow">Hồ sơ âm nhạc</p><h3 id="music-<?php echo esc_attr($key); ?>-title"><?php echo esc_html($heading); ?></h3></div></div>
    <?php if (!empty($section['claims'])): ?><div class="music-claim-stack"><?php foreach ($items($section['claims']) as $claim): ?><article class="knowledge-claim"><p><?php echo esc_html(nhk_v3_public_copy((string) ($claim['text'] ?? ''))); ?></p><?php if (($claim['status'] ?? '') !== ''): ?><small class="scope-note"><?php echo esc_html((string) $claim['status']); ?></small><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
    <?php foreach (['items' => 'related-grid', 'dictionary' => 'related-grid', 'evidence' => 'evidence-list'] as $field => $class): if (empty($section[$field])) continue; ?><div class="<?php echo esc_attr($class); ?>"><?php foreach ($items($section[$field]) as $item): $url = $publicUrl($item['url'] ?? null); $title = (string) ($item['title'] ?? $item['name'] ?? $item['source_title'] ?? $item['text'] ?? ''); ?><article class="related-card"><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html($title); ?></strong></a><?php else: ?><strong><?php echo esc_html($title); ?></strong><?php endif; ?><?php if (($item['excerpt'] ?? '') !== ''): ?><span><?php echo esc_html((string) $item['excerpt']); ?></span><?php endif; ?></article><?php endforeach; ?></div><?php endforeach; ?>
    <?php if (!empty($section['media'])): ?><div class="media-mosaic music-library-media"><?php foreach ($items($section['media']) as $media): $visual = function_exists('nhk_v3_media_presentation') ? nhk_v3_media_presentation($media) : ['url' => '']; if (($visual['url'] ?? '') === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url((string) $visual['url']); ?>" alt="<?php echo esc_attr((string) ($media['alt'] ?? $identity['name'] ?? '')); ?>" loading="lazy" decoding="async"><figcaption><?php echo esc_html((string) ($media['title'] ?? $identity['name'] ?? 'Tư liệu hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
  </section>
  <?php endforeach; ?>
</section>
<?php endif; ?>
