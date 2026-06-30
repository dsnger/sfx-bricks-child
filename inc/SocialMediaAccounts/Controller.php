<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

class Controller
{
    public const OPTION_NAME = 'sfx_social_media_accounts_options';

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
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
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
    }

    public static function add_bricks_dynamic_tag(array $tags): array
    {
        $tags[] = [
            'name'  => '{social_accounts}',
            'label' => __('Social Accounts: All', 'sfxtheme'),
            'group' => __('Social Accounts', 'sfxtheme'),
        ];

        $accounts = get_posts([
            'post_type' => PostType::$post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

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
        $account_id = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

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
}
