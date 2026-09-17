<?php
/* Native WordPress posts are the editorial source of truth for this archive. */
get_header();
$archivePage = max(1, (int) get_query_var('paged', 1));
$archiveQuery = new WP_Query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'category__in' => [4],
    'orderby' => 'date',
    'order' => 'DESC',
    'paged' => $archivePage,
    'posts_per_page' => get_option('posts_per_page'),
]);
?><main id="main-content" class="site-main"><section class="archive-intro"><p class="eyebrow">Kho bài viết</p><h1>Tri thức đồng hồ</h1><p class="archive-summary">Những bài viết giúp bạn tra cứu, đối chiếu và hiểu sâu hơn về đồng hồ.</p></section><div class="content-layout"><section class="feed" aria-label="Danh sách bài viết">
<?php if ($archiveQuery->have_posts()): ?><div class="post-grid"><?php while ($archiveQuery->have_posts()): $archiveQuery->the_post(); get_template_part('template-parts/article-card'); endwhile; ?></div><?php if ($archiveQuery->max_num_pages > 1): ?><nav class="navigation pagination" aria-label="Phân trang bài viết"><?php echo wp_kses_post(paginate_links(['base' => trailingslashit(home_url('/tri-thuc/')) . 'page/%#%/', 'format' => '', 'current' => $archivePage, 'total' => (int) $archiveQuery->max_num_pages, 'mid_size' => 1, 'prev_text' => '← Mới hơn', 'next_text' => 'Cũ hơn →', 'type' => 'plain'])); ?></nav><?php endif; ?><?php wp_reset_postdata(); ?>
<?php else: ?><div class="empty"><h2>Chưa tìm thấy nội dung</h2><p>Kho bài viết hiện chưa có nội dung công khai.</p></div><?php endif; ?>
</section><?php get_sidebar(); ?></div></main><?php get_footer(); ?>
