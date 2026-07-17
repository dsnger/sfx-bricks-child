<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

class Controller
{
    public const OPTION_NAME = 'sfx_social_media_accounts_options';

    /**
     * Marker property used to select post types for Bricks' post type lists.
     * Inert: nothing in WordPress or Bricks reads it apart from our own filter.
     */
    private const BRICKS_SELECTABLE_PROP = 'sfx_bricks_selectable';

    private static ?Shortcode\SC_SocialAccounts $shortcode_instance = null;

    public function __construct()
    {
        AssetManager::register();
        PostType::init();
        self::$shortcode_instance = new Shortcode\SC_SocialAccounts();

        add_action('sfx_init_advanced_features', [$this, 'register_bricks_dynamic_tag']);
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => __(AdminPage::$page_title, 'sfxtheme'),
            'description' => __(AdminPage::$description, 'sfxtheme'),
            'url' => admin_url('edit.php?post_type=' . PostType::$post_type),
            'show_in_theme_settings' => false,
            'error' => 'Missing SocialMediaAccountsController class in theme',
            'hook'  => null,
        ];
    }

    public function register_bricks_dynamic_tag(): void
    {
        add_filter('bricks/dynamic_tags_list', [self::class, 'add_bricks_dynamic_tag'], 20);
        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_bricks_dynamic_content'], 20, 3);
        add_filter('bricks/frontend/render_data', [self::class, 'render_bricks_frontend_data'], 20, 2);

        // Late (20) so we consume whatever args other plugins have already asked for.
        add_filter('bricks/registered_post_types_args', [self::class, 'allow_bricks_post_type_selection'], 20);
    }

    /**
     * Let the (non-public) social account CPT appear in Bricks' post type lists, e.g. the
     * query loop dropdown, without making the CPT public.
     *
     * Bricks feeds these args to get_post_types(), which AND-matches every pair against the
     * post type object's properties. "public OR sfx_social_account" is therefore not
     * expressible as args, and simply widening them would expose every internal CPT
     * (sfx_custom_script and friends register with an identical public/show_ui/show_in_rest
     * profile, so no args expression can tell them apart).
     *
     * Instead we compute the union ourselves and select it via a marker property, which
     * WP_Post_Type supports by design (#[AllowDynamicProperties] + set_props()). No existing
     * property is touched, so sitemap exclusion and the noindex header keep working.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function allow_bricks_post_type_selection(array $args): array
    {
        global $wp_post_types;

        if (!is_array($wp_post_types)) {
            return $args;
        }

        $selectable = get_post_types($args);
        $selectable[PostType::$post_type] = PostType::$post_type;

        foreach ($wp_post_types as $name => $object) {
            $object->{self::BRICKS_SELECTABLE_PROP} = isset($selectable[$name]);
        }

        return [self::BRICKS_SELECTABLE_PROP => true];
    }

    public static function add_bricks_dynamic_tag(array $tags): array
    {
        $tags[] = [
            'name'  => '{social_accounts}',
            'label' => __('Social Accounts: All', 'sfxtheme'),
            'group' => __('Social Accounts', 'sfxtheme'),
        ];

        $accounts = self::get_published_accounts_for_bricks_tags();

        foreach ($accounts as $account) {
            $account_title = sanitize_text_field($account->post_title);
            foreach (FieldRegistry::get_fields() as $field => $label) {
                $tags[] = [
                    'name'  => '{social_account:' . $field . ':' . $account->ID . '}',
                    'label' => $account_title . ': ' . $label,
                    'group' => __('Social Accounts', 'sfxtheme'),
                ];
            }
        }

        return $tags;
    }

    public static function render_bricks_dynamic_tag($tag, $post = null, $context = 'text'): string
    {
        if (is_array($tag)) {
            if (isset($tag['tag'])) {
                $tag = $tag['tag'];
            } elseif (isset($tag['name'])) {
                $tag = $tag['name'];
            } elseif (isset($tag['value'])) {
                $tag = $tag['value'];
            } else {
                return '';
            }
        }

        if (!is_string($tag)) {
            return '';
        }

        if ($tag === '{social_accounts}') {
            return self::get_shortcode_instance()->render_all_accounts([]);
        }

        if (strpos($tag, '{social_account:') !== 0) {
            return $tag;
        }

        if (!preg_match('/\{social_account:([a-zA-Z0-9_\-]+)(?::(\d+))?(?:\s*[@\|]\s*([^}]+))?\}/', $tag, $m)) {
            return '';
        }

        $field = $m[1];
        $has_explicit_id = isset($m[2]) && $m[2] !== '';

        // Only an omitted ID falls back to the current loop/post context, so that
        // {social_account:url} resolves per loop item. An ID that was supplied but is invalid
        // (e.g. {social_account:url:0}) keeps returning '' as it always has, rather than
        // silently resolving to whatever post happens to be in context.
        $account_id = $has_explicit_id
            ? (int) $m[2]
            : self::resolve_context_account_id($post);

        if ($account_id <= 0) {
            return '';
        }

        $atts = [
            'id' => (string) $account_id,
            'field' => $field,
            'context' => (string) $context,
        ];

        if (!empty($m[3])) {
            $attr_pairs = preg_split('/[\|@]/', $m[3]);
            foreach ($attr_pairs as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }

                if (strpos($pair, '=') !== false) {
                    [$key, $value] = explode('=', $pair, 2);
                    $atts[trim($key)] = trim($value, '"\'');
                } elseif (strpos($pair, ':') !== false) {
                    [$key, $value] = explode(':', $pair, 2);
                    $atts[trim($key)] = trim($value, '"\'');
                } else {
                    $atts[trim($pair)] = true;
                }
            }
        }

        try {
            return self::get_shortcode_instance()->render_account_field($atts);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve the contextual post to a published social account ID, or 0.
     *
     * Bricks hands us the resolved loop post, so prefer it and only fall back to the
     * global current post when it is absent.
     *
     * @param \WP_Post|int|null $post
     */
    private static function resolve_context_account_id($post): int
    {
        // Bricks only ever hands these filters a WP_Post or null.
        if ($post instanceof \WP_Post) {
            $post_id = $post->ID;
        } elseif ($post === null) {
            $post_id = (int) (get_the_ID() ?: 0);
        } else {
            // Context was supplied but is not a post: do not guess.
            return 0;
        }

        if ($post_id <= 0) {
            return 0;
        }

        // Reuse the shortcode's post-type + publish check rather than duplicating it.
        return self::get_shortcode_instance()->resolve_published_account($post_id) !== null ? $post_id : 0;
    }

    public static function render_bricks_dynamic_content($content, $post = null, $context = 'text')
    {
        return self::process_dynamic_tags_in_content($content, $post, $context);
    }

    public static function render_bricks_frontend_data($content, $post = null)
    {
        return self::process_dynamic_tags_in_content($content, $post, 'text');
    }

    private static function process_dynamic_tags_in_content($content, $post = null, $context = 'text')
    {
        if (!is_string($content)) {
            return $content;
        }

        if (strpos($content, '{social_account') === false && strpos($content, '{social_accounts}') === false) {
            return $content;
        }

        if (strpos($content, '{social_accounts}') !== false) {
            $value = self::render_bricks_dynamic_tag('{social_accounts}', $post, $context);
            $content = str_replace('{social_accounts}', $value, $content);
        }

        if (!preg_match_all('/\{(social_account:[^}]+)\}/', $content, $matches)) {
            return $content;
        }

        if (empty($matches[0])) {
            return $content;
        }

        foreach ($matches[1] as $key => $match) {
            $tag = $matches[0][$key];
            $value = self::render_bricks_dynamic_tag('{' . $match . '}', $post, $context);
            $content = str_replace($tag, $value, $content);
        }

        return $content;
    }

    private static function get_shortcode_instance(): Shortcode\SC_SocialAccounts
    {
        if (self::$shortcode_instance === null) {
            self::$shortcode_instance = new Shortcode\SC_SocialAccounts();
        }

        return self::$shortcode_instance;
    }

    /**
     * @return list<\WP_Post>
     */
    private static function get_published_accounts_for_bricks_tags(): array
    {
        $cache_gen = (int) get_option('sfx_social_accounts_cache_gen', 0);
        $cache_key = 'sfx_social_accounts_bricks_tags_' . $cache_gen;
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $accounts = get_posts([
            'post_type' => PostType::$post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        set_transient($cache_key, $accounts, HOUR_IN_SECONDS);

        return $accounts;
    }
}
