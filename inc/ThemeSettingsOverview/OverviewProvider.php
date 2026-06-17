<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

use SFX\AccessControl;
use SFX\ImageOptimizer\Constants as ImageConstants;
use SFX\SFXBricksChildTheme;
use SFX\WPOptimizer\Settings as WPOptimizerSettings;

/**
 * Aggregates theme settings state for the overview panel.
 */
final class OverviewProvider
{
    private const WP_OPTIMIZER_GROUP_LABELS = [
        'performance' => 'Performance',
        'admin' => 'Admin Enhancements',
        'security' => 'Security & Privacy',
        'users' => 'Users & Authors',
        'frontend' => 'Frontend Cleanup',
        'media' => 'Media & Uploads',
    ];

    /**
     * @return array{intro_key: string, groups: array<int, array<string, mixed>>}
     */
    public static function get_data(): array
    {
        $groups = [
            self::build_builtin_modules_group(),
            self::build_theme_bricks_group(),
            self::build_content_features_group(),
            self::build_utilities_group(),
        ];

        if (SFXBricksChildTheme::is_general_option_enabled('enable_wp_optimizer')) {
            $groups[] = self::build_wp_optimizer_group();
        }

        if (SFXBricksChildTheme::is_general_option_enabled('enable_security_header')) {
            $groups[] = self::build_security_headers_group();
        }

        if (SFXBricksChildTheme::is_general_option_enabled('enable_image_optimizer')) {
            $groups[] = self::build_image_optimizer_group();
        }

        return [
            'intro_key' => 'built_in_features',
            'groups' => array_values(array_filter($groups)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function build_builtin_modules_group(): ?array
    {
        $modules = [
            'enable_wp_optimizer' => [
                'label' => __('WP Optimizer', 'sfxtheme'),
                'url' => 'admin.php?page=sfx-wp-optimizer',
            ],
            'enable_image_optimizer' => [
                'label' => __('Image Optimizer', 'sfxtheme'),
                'url' => 'admin.php?page=sfx-image-optimizer',
            ],
            'enable_security_header' => [
                'label' => __('Security Header', 'sfxtheme'),
                'url' => 'admin.php?page=sfx-security-header',
            ],
            'enable_smooth_scroll' => [
                'label' => __('Smooth Scroll', 'sfxtheme'),
                'url' => 'admin.php?page=sfx-smooth-scroll',
            ],
        ];

        $items = [];
        foreach ($modules as $id => $meta) {
            $enabled = SFXBricksChildTheme::is_general_option_enabled($id);
            $items[] = [
                'id' => $id,
                'label' => $meta['label'],
                'status' => $enabled ? 'active' : 'inactive',
                'detail' => null,
                'settings_url' => $meta['url'],
            ];
        }

        return [
            'id' => 'builtin_modules',
            'label' => __('Built-in Modules', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_wp_optimizer_group(): array
    {
        $grouped_fields = [];
        foreach (WPOptimizerSettings::get_fields() as $field) {
            if (($field['type'] ?? '') !== 'checkbox') {
                continue;
            }
            $group = $field['group'] ?? 'general';
            $grouped_fields[$group][] = $field;
        }

        $items = [];
        foreach (self::WP_OPTIMIZER_GROUP_LABELS as $group_key => $group_label) {
            if (empty($grouped_fields[$group_key])) {
                continue;
            }

            $fields = $grouped_fields[$group_key];
            $total = count($fields);
            $enabled = 0;
            foreach ($fields as $field) {
                if (! empty(WPOptimizerSettings::get($field['id']))) {
                    $enabled++;
                }
            }

            $status = 'inactive';
            if ($enabled === $total && $total > 0) {
                $status = 'active';
            } elseif ($enabled > 0) {
                $status = 'partial';
            }

            $items[] = [
                'id' => 'wp_optimizer_' . $group_key,
                'label' => __($group_label, 'sfxtheme'),
                'status' => $status,
                'detail' => $enabled . '/' . $total,
                'settings_url' => 'admin.php?page=sfx-wp-optimizer',
            ];
        }

        return [
            'id' => 'wp_optimizer',
            'label' => __('WP Optimizer', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_security_headers_group(): array
    {
        $items = SecurityHeaderStatusResolver::get_header_items();
        foreach ($items as &$item) {
            $item['settings_url'] = 'admin.php?page=sfx-security-header';
        }
        unset($item);

        return [
            'id' => 'security_headers',
            'label' => __('Security Headers', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_image_optimizer_group(): array
    {
        $use_avif = (bool) get_option('sfx_webp_use_avif', false);
        $quality = (int) get_option('sfx_webp_quality', ImageConstants::DEFAULT_QUALITY);
        $auto_conversion = ! (bool) get_option('sfx_webp_disable_auto_conversion', false);
        $preserve_originals = (bool) get_option('sfx_webp_preserve_originals', false);

        return [
            'id' => 'image_optimizer',
            'label' => __('Image Optimizer', 'sfxtheme'),
            'badge' => null,
            'items' => [
                [
                    'id' => 'image_format',
                    'label' => __('Output format', 'sfxtheme'),
                    'status' => 'active',
                    'detail' => $use_avif ? 'AVIF' : 'WebP',
                    'settings_url' => 'admin.php?page=sfx-image-optimizer',
                ],
                [
                    'id' => 'image_quality',
                    'label' => __('Quality', 'sfxtheme'),
                    'status' => 'active',
                    'detail' => (string) $quality,
                    'settings_url' => 'admin.php?page=sfx-image-optimizer',
                ],
                [
                    'id' => 'image_auto_conversion',
                    'label' => __('Auto-conversion', 'sfxtheme'),
                    'status' => $auto_conversion ? 'active' : 'inactive',
                    'detail' => null,
                    'settings_url' => 'admin.php?page=sfx-image-optimizer',
                ],
                [
                    'id' => 'image_preserve_originals',
                    'label' => __('Preserve originals', 'sfxtheme'),
                    'status' => $preserve_originals ? 'active' : 'inactive',
                    'detail' => null,
                    'settings_url' => 'admin.php?page=sfx-image-optimizer',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_theme_bricks_group(): array
    {
        $items = [
            [
                'id' => 'disable_bricks_js',
                'label' => __('Disable Bricks JS', 'sfxtheme'),
                'status' => SFXBricksChildTheme::is_general_option_enabled('disable_bricks_js') ? 'active' : 'inactive',
                'detail' => null,
                'settings_url' => 'admin.php?page=sfx-general-theme-options',
            ],
            [
                'id' => 'disable_bricks_styles',
                'label' => __('Disable Bricks Styling', 'sfxtheme'),
                'status' => SFXBricksChildTheme::is_general_option_enabled('disable_bricks_styles') ? 'active' : 'inactive',
                'detail' => null,
                'settings_url' => 'admin.php?page=sfx-general-theme-options',
            ],
        ];

        if (class_exists('\SFX\GeneralThemeOptions\Settings')) {
            foreach (\SFX\GeneralThemeOptions\Settings::get_style_fields() as $field) {
                $items[] = [
                    'id' => $field['id'],
                    'label' => $field['label'],
                    'status' => SFXBricksChildTheme::is_general_option_enabled($field['id']) ? 'active' : 'inactive',
                    'detail' => null,
                    'settings_url' => 'admin.php?page=sfx-general-theme-options',
                ];
            }
        }

        return [
            'id' => 'theme_bricks',
            'label' => __('Theme & Bricks', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_content_features_group(): array
    {
        $features = [
            [
                'id' => 'contact_infos',
                'label' => __('Contact Infos', 'sfxtheme'),
                'post_type' => 'sfx_contact_info',
                'settings_url' => 'edit.php?post_type=sfx_contact_info',
            ],
            [
                'id' => 'social_accounts',
                'label' => __('Social Media Accounts', 'sfxtheme'),
                'post_type' => 'sfx_social_account',
                'settings_url' => 'edit.php?post_type=sfx_social_account',
            ],
            [
                'id' => 'custom_scripts',
                'label' => __('Custom Scripts', 'sfxtheme'),
                'post_type' => 'sfx_custom_script',
                'settings_url' => 'edit.php?post_type=sfx_custom_script',
            ],
        ];

        $items = [];
        foreach ($features as $feature) {
            $count = self::get_post_count($feature['post_type']);
            $items[] = [
                'id' => $feature['id'],
                'label' => $feature['label'],
                'status' => $count > 0 ? 'active' : 'inactive',
                'detail' => sprintf(
                    /* translators: %d: number of posts */
                    _n('%d entry', '%d entries', $count, 'sfxtheme'),
                    $count
                ),
                'settings_url' => $feature['settings_url'],
            ];
        }

        return [
            'id' => 'content_features',
            'label' => __('Content Features', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_utilities_group(): array
    {
        $items = [
            [
                'id' => 'import_export',
                'label' => __('Import / Export', 'sfxtheme'),
                'status' => 'active',
                'detail' => __('Built-in — no plugin required', 'sfxtheme'),
                'settings_url' => 'admin.php?page=sfx-import-export',
            ],
        ];

        if (AccessControl::can_access_dashboard_settings()) {
            $dashboard_options = get_option('sfx_custom_dashboard', []);
            $dashboard_enabled = ! empty($dashboard_options['enable_custom_dashboard']);
            $items[] = [
                'id' => 'custom_dashboard',
                'label' => __('Custom Dashboard', 'sfxtheme'),
                'status' => $dashboard_enabled ? 'active' : 'inactive',
                'detail' => null,
                'settings_url' => 'admin.php?page=sfx-custom-dashboard',
            ];
        }

        return [
            'id' => 'utilities',
            'label' => __('Utilities', 'sfxtheme'),
            'badge' => null,
            'items' => $items,
        ];
    }

    private static function get_post_count(string $post_type): int
    {
        if (! class_exists('\WP_Query')) {
            return 0;
        }

        $query = new \WP_Query([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);

        return (int) $query->found_posts;
    }

    /**
     * Find an item status by id across all groups (for tests).
     */
    public static function get_item_status(array $data, string $item_id): ?string
    {
        foreach ($data['groups'] ?? [] as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (($item['id'] ?? '') === $item_id) {
                    return $item['status'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Check whether a group exists in data (for tests).
     */
    public static function has_group(array $data, string $group_id): bool
    {
        foreach ($data['groups'] ?? [] as $group) {
            if (($group['id'] ?? '') === $group_id) {
                return true;
            }
        }

        return false;
    }
}
