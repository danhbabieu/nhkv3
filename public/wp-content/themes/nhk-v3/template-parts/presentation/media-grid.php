<?php
$items = is_array($args['items'] ?? null) ? $args['items'] : [];
if ($items === []) return;
?><div class="media-grid"><?php foreach ($items as $item): if (is_array($item)) get_template_part('template-parts/presentation/media-card', null, ['item' => $item]); endforeach; ?></div>
