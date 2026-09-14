<?php
$view = is_array($args['view'] ?? null) ? $args['view'] : [];
$sections = [];
if (!empty($view['media'])) $sections[] = ['title' => 'Hình ảnh nổi bật', 'items' => $view['media'], 'kind' => 'media'];
if (!empty($view['videos'])) $sections[] = ['title' => 'Video nổi bật', 'items' => $view['videos'], 'kind' => 'video'];
if (!empty($view['articles'])) $sections[] = ['title' => 'Bài viết mới', 'items' => $view['articles'], 'kind' => 'article'];
if ($sections === []) return;
?><aside class="visual-rail" aria-label="Tư liệu trực quan"><?php foreach ($sections as $section): ?><section class="rail-module"><h2><?php echo esc_html($section['title']); ?></h2><?php foreach (array_slice($section['items'], 0, 3) as $item): if (!is_array($item)) continue; $url = nhk_v3_public_url($item['url'] ?? null); $title = trim((string) ($item['title'] ?? $item['name'] ?? '')); if ($title === '') continue; ?><article class="rail-item"><?php if ($section['kind'] === 'media'): get_template_part('template-parts/presentation/media-card', null, ['item' => $item]); elseif ($section['kind'] === 'video'): get_template_part('template-parts/presentation/video-card', null, ['item' => $item]); elseif ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(nhk_v3_public_brand_text($title)); ?></a><?php endif; ?></article><?php endforeach; ?></section><?php endforeach; ?></aside>
