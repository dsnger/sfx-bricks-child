<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

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
        ];

        if (SFXBricksChildTheme::is_general_option_enabled('enable_wp_optimizer')) {
            $groups[] = self::build_wp_optimizer_group();
        }

        if (SFXBricksChildTheme::is_general_option_enabled('enable_security_header')) {
            $groups[] = self::build_security_headers_group();
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
            ],
            'enable_image_optimizer' => [
                'label' => __('Image Optimizer', 'sfxtheme'),
            ],
            'enable_security_header' => [
                'label' => __('Security Header', 'sfxtheme'),
            ],
            'enable_smooth_scroll' => [
                'label' => __('Smooth Scroll', 'sfxtheme'),
            ],
        ];

        $items = [];
        $active_count = 0;

        foreach ($modules as $id => $meta) {
            $enabled = SFXBricksChildTheme::is_general_option_enabled($id);
            if ($enabled) {
                $active_count++;
            }

            $items[] = [
                'id' => $id,
                'label' => $meta['label'],
                'status' => $enabled ? 'active' : 'inactive',
                'detail' => null,
            ];
        }

        return [
            'id' => 'builtin_modules',
            'label' => __('Built-in Modules', 'sfxtheme'),
            'settings_url' => 'admin.php?page=sfx-general-theme-options',
            'active_count' => $active_count,
            'total_count' => count($modules),
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

        $sections = [];
        $active_count = 0;
        $total_count = 0;

        foreach (self::WP_OPTIMIZER_GROUP_LABELS as $group_key => $group_label) {
            if (empty($grouped_fields[$group_key])) {
                continue;
            }

            $fields = $grouped_fields[$group_key];
            $total = count($fields);
            $enabled = 0;
            $children = [];

            foreach ($fields as $field) {
                $is_enabled = ! empty(WPOptimizerSettings::get($field['id']));
                if ($is_enabled) {
                    $enabled++;
                }

                $children[] = [
                    'id' => (string) $field['id'],
                    'label' => (string) ($field['label'] ?? $field['id'] ?? ''),
                    'status' => $is_enabled ? 'active' : 'inactive',
                    'detail' => null,
                ];
            }

            $active_count += $enabled;
            $total_count += $total;

            $status = 'inactive';
            if ($enabled === $total && $total > 0) {
                $status = 'active';
            } elseif ($enabled > 0) {
                $status = 'partial';
            }

            $sections[] = [
                'id' => 'wp_optimizer_' . $group_key,
                'label' => __($group_label, 'sfxtheme'),
                'status' => $status,
                'detail' => $enabled . '/' . $total,
                'items' => $children,
            ];
        }

        return [
            'id' => 'wp_optimizer',
            'label' => __('WP Optimizer', 'sfxtheme'),
            'settings_url' => 'admin.php?page=sfx-wp-optimizer',
            'layout' => 'sections',
            'active_count' => $active_count,
            'total_count' => $total_count,
            'sections' => $sections,
            'items' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_security_headers_group(): array
    {
        $items = SecurityHeaderStatusResolver::get_header_items();
        foreach ($items as &$item) {
            unset($item['settings_url']);
        }
        unset($item);

        return self::with_status_summary([
            'id' => 'security_headers',
            'label' => __('Security Headers', 'sfxtheme'),
            'settings_url' => 'admin.php?page=sfx-security-header',
            'items' => $items,
        ]);
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function with_status_summary(array $group): array
    {
        $active_count = 0;
        $items = $group['items'] ?? [];

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'active') {
                $active_count++;
            }
        }

        $group['active_count'] = $active_count;
        $group['total_count'] = count($items);

        return $group;
    }

    /**
     * Find an item status by id across all groups (for tests).
     */
    public static function get_item_status(array $data, string $item_id): ?string
    {
        foreach ($data['groups'] ?? [] as $group) {
            $status = self::find_item_status_in_list($group['items'] ?? [], $item_id);
            if ($status !== null) {
                return $status;
            }

            foreach ($group['sections'] ?? [] as $section) {
                if (($section['id'] ?? '') === $item_id) {
                    return $section['status'] ?? null;
                }

                $status = self::find_item_status_in_list($section['items'] ?? [], $item_id);
                if ($status !== null) {
                    return $status;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function find_item_status_in_list(array $items, string $item_id): ?string
    {
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $item_id) {
                return $item['status'] ?? null;
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
