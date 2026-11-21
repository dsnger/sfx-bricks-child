<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * The theme's values written into an SEO plugin's organization entry (C1). The plugin keeps
 * @id, @type, url, logo and image: other graph nodes reference them.
 */
final class PluginMerge
{
    private const THEME_KEYS = ['name', 'address', 'telephone', 'email', 'contactPoint', 'department'];

    public static function merge(array $plugin_entity, array $theme_entity): array
    {
        foreach (self::THEME_KEYS as $key) {
            if (isset($theme_entity[$key]) && !in_array($theme_entity[$key], ['', []], true)) {
                $plugin_entity[$key] = $theme_entity[$key];
            }
        }
        if (!empty($theme_entity['sameAs'])) {
            $plugin_entity['sameAs'] = array_values(array_unique(array_merge(
                (array) ($plugin_entity['sameAs'] ?? []),
                $theme_entity['sameAs']
            )));
        }
        return $plugin_entity;
    }
}
