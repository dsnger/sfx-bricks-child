<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * Which way the schema reaches the page on this request (spec § "Mode selection"): the first
 * matching row decides. A plugin counts as active when its version constant is defined.
 */
final class Mode
{
    /** Any of these keeps the theme out (D4). */
    private const OTHER_PLUGINS = [
        'SEOPRESS_VERSION' => 'SEOPress',
        'AIOSEO_VERSION'   => 'All in One SEO',
        'SASWP_VERSION'    => 'Schema & Structured Data for WP & AMP',
    ];

    /** More than one of these at once keeps the theme out, too. */
    private const SUPPORTED_PLUGINS = [
        'WPSEO_VERSION'             => 'Yoast SEO',
        'RANK_MATH_VERSION'         => 'Rank Math',
        'THE_SEO_FRAMEWORK_VERSION' => 'The SEO Framework',
    ];

    /**
     * @return array{mode:string, reason:string, plugins:list<string>}
     *         mode: off|conflict|yoast|rank_math|tsf_suppressed|own;
     *         reason: disabled | other_plugin | several_seo_plugins | yoast_company | yoast_not_company
     *         | rank_math_company | rank_math_not_company | rank_math_schema_off
     *         | tsf_knowledge_output_on | tsf_knowledge_output_off | no_seo_plugin
     */
    public static function detect(): array
    {
        if (!Settings::get()['enabled']) {
            return self::result('off', 'disabled', []);
        }
        $others = self::active(self::OTHER_PLUGINS);
        if ($others !== []) {
            return self::result('conflict', 'other_plugin', $others);
        }
        $supported = self::active(self::SUPPORTED_PLUGINS);
        if (count($supported) > 1) {
            return self::result('conflict', 'several_seo_plugins', $supported);
        }

        if (defined('WPSEO_VERSION')) {
            $titles = get_option('wpseo_titles', []); // Yoast's default is "company"
            $company = ((is_array($titles) ? $titles['company_or_person'] ?? 'company' : 'company')) === 'company';
            return self::result('yoast', $company ? 'yoast_company' : 'yoast_not_company', $supported);
        }
        if (defined('RANK_MATH_VERSION')) {
            $helper = '\RankMath\Helper';
            if (method_exists($helper, 'is_module_active') && !$helper::is_module_active('rich-snippet')) {
                return self::result('rank_math', 'rank_math_schema_off', $supported);
            }
            return self::result('rank_math', self::rank_math_is_company() ? 'rank_math_company' : 'rank_math_not_company', $supported);
        }
        if (defined('THE_SEO_FRAMEWORK_VERSION')) {
            $tsf = get_option('autodescription-site-settings', []);
            $on = !is_array($tsf) || !array_key_exists('knowledge_output', $tsf) || (bool) $tsf['knowledge_output'];
            return $on
                ? self::result('tsf_suppressed', 'tsf_knowledge_output_on', $supported)
                : self::result('own', 'tsf_knowledge_output_off', $supported);
        }
        return self::result('own', 'no_seo_plugin', []);
    }

    /** Rank Math's knowledge graph type is "company"; unreadable counts as not (nothing is added). */
    public static function rank_math_is_company(): bool
    {
        $helper = '\RankMath\Helper';
        return class_exists($helper) && method_exists($helper, 'get_settings')
            && $helper::get_settings('titles.knowledgegraph_type') === 'company';
    }

    /** @return list<string> names of the active plugins of $plugins */
    private static function active(array $plugins): array
    {
        return array_values(array_filter($plugins, 'defined', ARRAY_FILTER_USE_KEY));
    }

    private static function result(string $mode, string $reason, array $plugins): array
    {
        return ['mode' => $mode, 'reason' => $reason, 'plugins' => $plugins];
    }
}
