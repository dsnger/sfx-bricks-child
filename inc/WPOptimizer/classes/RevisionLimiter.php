<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

use SFX\WPOptimizer\Settings;
use WP_Post;

defined('ABSPATH') or die('Pet a cat!');

class RevisionLimiter
{
    private const MIN_REVISIONS = 0;
    private const MAX_REVISIONS = 10;

    public static function register(): void
    {
        add_filter('wp_revisions_to_keep', [self::class, 'filter_revisions_to_keep'], 10, 2);
        add_action('wp_after_insert_post', [self::class, 'maybe_prune_after_insert'], 15, 4);
    }

    public static function applies_to_post(WP_Post $post): bool
    {
        if (!post_type_supports($post->post_type, 'revisions')) {
            return false;
        }

        $enabled_post_types = Settings::get('limit_revisions_post_types', []);

        if (!empty($enabled_post_types) && !in_array($post->post_type, $enabled_post_types, true)) {
            return false;
        }

        return true;
    }

    public static function get_limit_for_post(WP_Post $post): int
    {
        $limit = (int) Settings::get('limit_revisions_number');

        return max(self::MIN_REVISIONS, min(self::MAX_REVISIONS, $limit));
    }

    public static function filter_revisions_to_keep(int $num, WP_Post $post): int
    {
        if (!self::applies_to_post($post)) {
            return $num;
        }

        return self::get_limit_for_post($post);
    }

    /**
     * @param int $post_id
     * @param WP_Post $post
     * @param bool $update
     * @param WP_Post|null $post_before
     */
    public static function maybe_prune_after_insert(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void
    {
        if (!$update) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post->post_type === 'revision' || $post->post_type === 'autosave') {
            return;
        }

        if (!self::applies_to_post($post)) {
            return;
        }

        self::prune_post_revisions($post_id);
    }

    public static function prune_post_revisions(int $post_id): void
    {
        $post = get_post($post_id);

        if (!$post instanceof WP_Post || !self::applies_to_post($post)) {
            return;
        }

        $revisions_to_keep = self::get_limit_for_post($post);

        if ($revisions_to_keep < 0) {
            return;
        }

        $revisions = wp_get_post_revisions($post_id, ['order' => 'ASC']);

        /** @var array<int, WP_Post> $revisions */
        $revisions = apply_filters(
            'wp_save_post_revision_revisions_before_deletion',
            $revisions,
            $post_id
        );

        $delete = count($revisions) - $revisions_to_keep;

        if ($delete < 1) {
            return;
        }

        $revisions = array_slice($revisions, 0, $delete);

        foreach ($revisions as $revision) {
            if (str_contains($revision->post_name, 'autosave')) {
                continue;
            }

            wp_delete_post_revision($revision->ID);
        }
    }
}
