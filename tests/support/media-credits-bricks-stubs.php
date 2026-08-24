<?php

declare(strict_types=1);

/**
 * Bricks doubles for the MediaCredits suite.
 *
 * Separate file because PHP does not allow a bracketed namespace block
 * alongside non-namespaced code.
 */

namespace Bricks {
    class Query
    {
        public static bool $looping = false;

        /** @var mixed returned by get_loop_object() */
        public static $loop_object = null;

        public static function is_looping($id = ''): bool
        {
            return self::$looping;
        }

        public static function get_loop_object($id = '')
        {
            return self::$loop_object;
        }

        public static function reset(): void
        {
            self::$looping     = false;
            self::$loop_object = null;
        }
    }
}

namespace {
    /**
     * Stand-in for a Bricks element instance: the three things our filters
     * touch, and the one method they call.
     */
    class Test_Bricks_Element
    {
        public string $name = 'image';

        /** @var array<string, mixed> */
        public array $settings = [];

        public string $tag = 'figure';

        /** @var array<string, mixed>|null what get_normalized_image_settings() returns */
        public ?array $normalized = null;

        /**
         * @param array<string, mixed> $settings
         * @param array<string, mixed>|null $normalized
         */
        public function __construct(array $settings = [], ?array $normalized = null, string $name = 'image')
        {
            $this->settings   = $settings;
            $this->normalized = $normalized;
            $this->name       = $name;
        }

        /**
         * Mirrors Bricks: numeric dynamic results become an id, everything
         * else leaves id at 0 (image.php:738-760).
         *
         * @param array<string, mixed> $settings
         * @return array<string, mixed>|null
         */
        public function get_normalized_image_settings($settings)
        {
            return $this->normalized;
        }
    }

    function get_post_thumbnail_id($post = null)
    {
        global $test_thumbnail_ids;

        $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;

        return $test_thumbnail_ids[$id] ?? 0;
    }

    function get_the_ID()
    {
        global $test_current_post_id;

        return $test_current_post_id ?? 0;
    }

    function get_post($id = null)
    {
        global $test_posts;

        return $test_posts[(int) $id] ?? null;
    }

    if (!class_exists('WP_Post')) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = 'post';
            public string $post_excerpt = '';

            /** @param array<string, mixed> $props */
            public function __construct(array $props = [])
            {
                foreach ($props as $key => $value) {
                    $this->$key = $value;
                }
            }
        }
    }
}
