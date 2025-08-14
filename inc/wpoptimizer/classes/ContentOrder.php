<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

use WP_Query;

class ContentOrder
{
	public static function register(array $enabledPostTypes = []): void
	{
		add_action('admin_menu', function () use ($enabledPostTypes) {
			self::add_order_submenus($enabledPostTypes);
		});

		add_action('admin_init', function () {
			self::maybe_perform_menu_link_redirects();
		});

		add_action('wp_ajax_save_custom_order', [self::class, 'save_custom_content_order']);
		add_action('pre_get_posts', [self::class, 'orderby_menu_order']);
		add_action('save_post', [self::class, 'set_menu_order_for_new_posts'], 10, 3);
	}

	private static function get_enabled_post_types(array $configured): array
	{
		if (!empty($configured)) {
			return array_values(array_unique(array_map('sanitize_key', $configured)));
		}
		// Auto-detect: hierarchical or supports page-attributes
		$post_types = get_post_types(['public' => true], 'objects');
		$enabled = [];
		foreach ($post_types as $slug => $obj) {
			if ($obj->hierarchical || post_type_supports($slug, 'page-attributes')) {
				$enabled[] = $slug;
			}
		}
		return $enabled;
	}

	public static function add_order_submenus(array $configured = []): void
	{
		$enabled = self::get_enabled_post_types($configured);
		if (empty($enabled)) {
			return;
		}
		foreach ($enabled as $post_type_slug) {
			$post_type_object = get_post_type_object($post_type_slug);
			if (!$post_type_object || !isset($post_type_object->labels->name)) {
				continue;
			}
			$label_plural = $post_type_object->labels->name;
			$capability = 'edit_others_posts';
			$menu_title = __('Order', 'sfx');
			// Add under the CPT menu
			add_submenu_page(
				'edit.php?post_type=' . $post_type_slug,
				$label_plural . ' ' . $menu_title,
				$label_plural . ' ' . $menu_title,
				$capability,
				'custom-order-' . $post_type_slug,
				[self::class, 'render_custom_order_page'],
				9999
			);
		}
	}

	public static function render_custom_order_page(): void
	{
		$post_type_slug = sanitize_key($_GET['post_type'] ?? '');
		if (empty($post_type_slug)) {
			$parent_slug = get_admin_page_parent();
			if (is_string($parent_slug) && str_starts_with($parent_slug, 'edit.php?post_type=')) {
				$post_type_slug = str_replace('edit.php?post_type=', '', $parent_slug);
			}
		}
		$post_status = ['publish','future','draft','pending','private'];
		if ($post_type_slug === 'attachment') {
			$post_status = ['inherit','private'];
		}
		$args = [
			'post_type'      => $post_type_slug,
			'numberposts'    => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'post_status'    => $post_status,
		];
		if ($post_type_slug !== 'attachment' && is_post_type_hierarchical($post_type_slug)) {
			$args['post_parent'] = 0;
		}
		$posts = get_posts($args);
		?>
		<div class="wrap">
			<h1><?php echo esc_html(sprintf(__('%s Order', 'sfx'), get_post_type_object($post_type_slug)->labels->name ?? '')); ?></h1>
			
			<!-- Updating order notice template (hidden by default) -->
			<div id="updating-order-notice" class="updating-order-notice" style="display: none;">
				<img id="spinner-img" class="spinner-img" src="<?php echo admin_url('images/spinner.gif'); ?>" alt="Loading...">
				<span class="dashicons dashicons-saved"></span>
				<?php _e('Order updated!', 'sfx'); ?>
			</div>
			
			<?php if (!empty($posts)) : ?>
				<ul id="sfx-content-order-list" class="sfx-content-order-list">
					<?php foreach ($posts as $post) : ?>
						<li id="list_<?php echo esc_attr((string) $post->ID); ?>" data-id="<?php echo esc_attr((string) $post->ID); ?>" data-menu-order="<?php echo esc_attr((string) $post->menu_order); ?>" data-parent="<?php echo esc_attr((string) $post->post_parent); ?>" data-post-type="<?php echo esc_attr($post_type_slug); ?>">
							<div class="row">
								<div class="row-content">
									<span class="dashicons dashicons-menu"></span>
									<a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>" class="item-title"><?php echo esc_html(get_the_title($post)); ?></a>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<h3><?php esc_html_e('There is nothing to sort for this post type.', 'sfx'); ?></h3>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function maybe_perform_menu_link_redirects(): void
	{
		$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) $_SERVER['REQUEST_URI']) : '';
		if (strpos($request_uri, 'admin.php?page=custom-order-') !== false && isset($_GET['post_type'])) {
			$post_type = sanitize_key($_GET['post_type']);
			wp_safe_redirect(admin_url('edit.php?post_type=' . $post_type . '&page=custom-order-' . $post_type));
			exit;
		}
	}

	public static function save_custom_content_order(): void
	{
		if (!current_user_can('edit_others_posts')) {
			wp_send_json_error('permission_denied');
		}
		check_ajax_referer('order_sorting_nonce', 'nonce');
		global $wpdb;
		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		$item_parent = isset($_POST['item_parent']) ? (int) $_POST['item_parent'] : 0;
		$menu_order_start = isset($_POST['start']) ? (int) $_POST['start'] : 0;
		$items_to_exclude = isset($_POST['excluded_items']) && is_array($_POST['excluded_items']) ? array_map('intval', $_POST['excluded_items']) : [];
		$post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';

		if ($post_id > 0 && empty($_POST['more_posts'])) {
			$wpdb->update($wpdb->posts, ['menu_order' => (int) ($_POST['menu_order'] ?? 0)], ['ID' => $post_id]);
			clean_post_cache($post_id);
			$items_to_exclude[] = $post_id;
		}

		$post_status = $post_type === 'attachment' ? ['inherit','private'] : ['publish','future','draft','pending','private'];
		$query_args = [
			'post_type'              => $post_type,
			'orderby'                => 'menu_order title',
			'order'                  => 'ASC',
			'posts_per_page'         => -1,
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
			'post_status'            => $post_status,
			'post__not_in'           => $items_to_exclude,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		];
		if ($post_type !== 'attachment') {
			$query_args['post_parent'] = $item_parent;
		}
		$posts = new WP_Query($query_args);
		$start = $menu_order_start;
		if ($posts->have_posts()) {
			foreach ($posts->posts as $post) {
				if ($menu_order_start == ($_POST['menu_order'] ?? 0) && $post_id > 0) {
					$menu_order_start++;
				}
				if ($post_id != $post->ID) {
					$wpdb->update($wpdb->posts, ['menu_order' => $menu_order_start], ['ID' => $post->ID]);
					clean_post_cache($post->ID);
				}
				$items_to_exclude[] = $post->ID;
				$menu_order_start++;
			}
		}
		wp_send_json_success(['start' => $start]);
	}

	public static function orderby_menu_order($query): void
	{
		if (!is_admin()) {
			return;
		}
		global $pagenow, $typenow;
		if (($pagenow === 'edit.php' || $pagenow === 'upload.php') && !isset($_GET['orderby']) && !empty($typenow)) {
			$query->set('orderby', 'menu_order title');
			$query->set('order', 'ASC');
		}
	}

	public static function set_menu_order_for_new_posts($post_id, $post, $update): void
	{
		if ($update || !is_object($post)) {
			return;
		}
		if (!post_type_supports($post->post_type, 'page-attributes') && !is_post_type_hierarchical($post->post_type)) {
			return;
		}
		if ($post->menu_order !== 0) {
			return;
		}
		$latest = get_posts([
			'post_type'      => $post->post_type,
			'posts_per_page' => 1,
			'orderby'        => 'menu_order',
			'order'          => 'DESC',
		]);
		if ($latest) {
			$new_order = (int) $latest[0]->menu_order + 1;
			wp_update_post([
				'ID' => $post_id,
				'menu_order' => $new_order,
			]);
		}
	}
}


