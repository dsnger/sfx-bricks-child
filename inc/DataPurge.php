<?php

declare(strict_types=1);

namespace SFX;

/**
 * Deletes everything this theme stored — settings, not content.
 *
 * WordPress never executes a theme's uninstall.php, so the only moment this
 * can run is while the theme is still active. It is therefore driven from the
 * Danger Zone on the General Theme Options screen, and uninstall.php calls the
 * same code so there is one implementation rather than two.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  ADDING A FEATURE? ADD ITS OPTIONS TO OPTION_NAMES BELOW.
 *
 *  An option this list does not name survives the purge and stays in the
 *  database forever, because nothing else ever deletes it. The list is
 *  hand-written on purpose — see the note on prefixes below — so it can only
 *  stay correct if it is maintained.
 *
 *  tests/data-purge-test.php Case 2 fails when a module declares an
 *  `OPTION_NAME` constant this list does not carry. That catches the module
 *  pattern; an option written as a bare literal is invisible to it, so those
 *  still need adding by hand.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Why a list and not the `sfx_` prefix: the prefix is not evidence of
 * ownership. This site's own plugins use it too — sfx_mailcatch,
 * sfx_animation_options, sfx_company_logo_options and others belong to
 * plugins, not to this theme. Deleting by prefix would take their data with
 * ours. Membership of this list is the only ownership claim that holds.
 */
class DataPurge
{
    /**
     * Options this theme created before it prefixed them. Every other entry
     * in the purge must carry the `sfx_` prefix; these are the named
     * exceptions, and the test enforces that nothing else joins them.
     *
     * @var list<string>
     */
    public const LEGACY_OPTION_NAMES = [
        'github_theme_updater_debug',
        'webp_conversion_complete',
        'webp_conversion_log',
    ];

    /**
     * Every option this theme owns.
     *
     * `thumbnail_size_w` used to sit in here, in uninstall.php, beside a
     * comment explaining that WordPress core options were being excluded to
     * avoid side effects. It was not excluded. Deleting it would reset the
     * site's thumbnail width — a setting this theme did not create and has no
     * business removing. It is gone, and Case 1 keeps it gone.
     *
     * @var list<string>
     */
    private const OPTION_NAMES = [
        // General Theme Options
        'sfx_general_options',

        // Custom Dashboard
        'sfx_custom_dashboard',
        'sfx_custom_dashboard_icon_migration_version',

        // Custom Scripts Manager
        'sfx_custom_scripts_manager_options',

        // WP Optimizer
        'sfx_wpoptimizer_options',
        'sfx_wpoptimizer_extra',
        'sfx_wpoptimizer_migrated_disable_version_numbers_off',

        // Text Snippets
        'sfx_text_snippets_options',
        'sfx_text_snippets_removed',

        // HTML Copy Paste
        'sfx_html_copy_paste_options',

        // Removed modules. The code is gone; the rows are not, and nothing
        // else will ever delete them. Ownership established from this repo's
        // history — CompanyLogo removed in 46d83d0, ContactInfos rebuilt onto
        // a CPT in 8ff1193 — not from a grep of the current tree, which shows
        // a removed module's option as if it were foreign.
        'sfx_company_logo_options',
        'sfx_contact_infos_options',

        // Social Media Accounts
        'sfx_social_media_accounts_options',
        'sfx_social_accounts_cache_gen',

        // GitHub Theme Updater
        'github_theme_updater_debug',
        'sfx_theme_version_stored',
        'sfx_github_updater_last_error',

        // Image Optimizer
        'sfx_webp_max_widths',
        'sfx_webp_max_heights',
        'sfx_webp_resize_mode',
        'sfx_webp_quality',
        'sfx_webp_batch_size',
        'sfx_webp_preserve_originals',
        'sfx_webp_disable_auto_conversion',
        'sfx_webp_min_size_kb',
        'sfx_webp_use_avif',
        'sfx_webp_excluded_images',
        'sfx_webp_conversion_log',
        'sfx_webp_migration_complete',
        'sfx_webp_conversion_complete',
        'sfx_webp_conversion_offset',
        'webp_conversion_complete',
        'webp_conversion_log',

        // Smooth Scroll
        'sfx_smooth_scroll_options',

        // Media Credits
        'sfx_media_credits_options',

        // Security Headers
        'sfx_hsts_max_age',
        'sfx_hsts_include_subdomains',
        'sfx_hsts_preload',
        'sfx_csp',
        'sfx_csp_report_uri',
        'sfx_permissions_policy',
        'sfx_x_frame_options',
        'sfx_x_frame_options_allow_from_url',
        'sfx_disable_hsts_header',
        'sfx_disable_csp_header',
        'sfx_disable_x_content_type_options_header',
        'sfx_disable_x_frame_options_header',
        'sfx_restrict_sensitive_browser_features',

        // Password Protected
        'sfx_password_protected_options',
    ];

    /**
     * Attachment meta the Media Credits module wrote.
     *
     * NOT deleted by default. These are copyright notices and AI markings an
     * editor typed — content, and potentially content with legal weight. They
     * go only when the form asks for them explicitly, which is why run() takes
     * a flag rather than deciding for the caller.
     *
     * When they do go, all three go together: leaving the IPTC marker behind
     * would make a later reinstall skip the prefill for attachments whose
     * copyright had just been deleted.
     *
     * @var list<string>
     */
    private const META_KEYS = [
        '_sfx_media_copyright',
        '_sfx_media_ai',
        '_sfx_media_iptc_prefilled',
    ];

    /**
     * What has to be typed to arm the Danger Zone button. Shown on the page,
     * so there is nothing to guess — the point is not secrecy, it is that
     * typing sixteen characters cannot happen by reflex the way clicking can.
     */
    public const CONFIRMATION_PHRASE = 'sfx-bricks-child';

    /**
     * @return list<string>
     */
    public static function option_names(): array
    {
        return self::OPTION_NAMES;
    }

    /**
     * @return list<string>
     */
    public static function meta_keys(): array
    {
        return self::META_KEYS;
    }

    /**
     * Does this input arm the purge?
     *
     * The browser also disables the button until the field matches, but that
     * is convenience: it is gone with JavaScript off and absent from a request
     * someone builds by hand. This check is the one that has to hold, so the
     * handler calls it before deleting anything.
     *
     * Surrounding whitespace is forgiven — a stray space is a typing accident,
     * not a change of intent. Case is not: the phrase is printed on the page.
     */
    public static function confirmed(string $input): bool
    {
        return trim($input) === self::CONFIRMATION_PHRASE;
    }

    /**
     * Delete everything the theme stored.
     *
     * Settings, not content: the Contact Infos, Social Media Accounts and
     * Custom Scripts posts an editor typed are left alone. They survive as
     * ordinary posts and can be read again if the theme comes back.
     */
    /**
     * Delete the theme's settings, and optionally the Media Credits meta.
     *
     * Scope note: this is a SINGLE SITE operation. Options, post meta and the
     * transient rows all belong to the current blog's tables, so on multisite
     * it clears this site and no other. That is the intended meaning, not an
     * oversight — a network-wide purge would need super-admin authorisation
     * and a site loop of its own.
     *
     * @param bool $include_media_credits Also delete the attachment copyright
     *                                    and AI markings. Off by default:
     *                                    that is editor-authored content and
     *                                    needs its own confirmation.
     *
     * @return array{options:int, meta_keys:int, transients:int} what was
     *         actually removed, so the screen can report the real outcome
     *         instead of assuming one. An irreversible operation that always
     *         claims success is worse than one that admits a partial result.
     */
    public static function run(bool $include_media_credits = false): array
    {
        $options = 0;

        foreach (self::OPTION_NAMES as $option) {
            if (delete_option($option)) {
                $options++;
            }
        }

        $meta_keys = 0;

        if ($include_media_credits) {
            // delete_post_meta_by_key() is not scoped to attachments — it
            // removes the key from every post type. Harmless here because the
            // module only ever writes these three keys to attachments, but
            // worth knowing before the keys are reused elsewhere.
            foreach (self::META_KEYS as $meta_key) {
                if (delete_post_meta_by_key($meta_key)) {
                    $meta_keys++;
                }
            }
        }

        return [
            'options'    => $options,
            'meta_keys'  => $meta_keys,
            'transients' => self::delete_transients(),
        ];
    }

    /**
     * Transients the theme sets, by the prefixes it builds its keys from.
     *
     * NOT a blanket `sfx_` sweep, for the same reason the options are listed
     * rather than matched: plugins on this estate share the prefix. SFX
     * Feedback stores `sfx_feedback_shot_rl_<user>` (an abuse rate limit) and
     * `sfx_feedback_loops_form_<user>` (a half-filled form); a `sfx_%` sweep
     * would delete both. Every prefix here was traced to a set_transient()
     * call inside inc/.
     *
     * @var list<string>
     */
    private const TRANSIENT_PREFIXES = [
        'sfx_dashboard_sys_',
        'sfx_dashboard_stat_',
        'sfx_form_submissions_',
        'sfx_contact_info_',
        'sfx_social_account',
        'sfx_custom_scripts_',
        'sfx_wp_optimizer_',
        'sfx_brand_css_',
        'sfx_css_vars_',
    ];

    /**
     * Delete the theme's transients.
     *
     * Direct SQL because WordPress offers no delete-by-prefix API. Two
     * consequences worth knowing rather than discovering:
     *
     * - `_` is a single-character wildcard in SQL LIKE, so every prefix goes
     *   through esc_like() or `sfx_contact_info_` would also match
     *   `sfx-contact-infoX`.
     * - With a persistent object cache (Redis, Memcached) WordPress reads
     *   transients from the cache, not from these rows, so a value can outlive
     *   this delete. They are caches with an expiry, so they go on their own;
     *   the options and post meta that matter use the proper APIs.
     */
    private static function delete_transients(): int
    {
        global $wpdb;

        $deleted = 0;

        foreach (self::TRANSIENT_PREFIXES as $prefix) {
            $like = $wpdb->esc_like($prefix) . '%';

            $result = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $like,
                    '_transient_timeout_' . $like
                )
            );

            if (is_int($result)) {
                $deleted += $result;
            }
        }

        // The DELETE went round get_option(), so WordPress' own copy of the
        // autoloaded set is now stale and would keep serving rows that no
        // longer exist. Dropping that one cache entry is enough; flushing the
        // whole object cache would evict every other plugin's data to fix a
        // problem of ours.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('alloptions', 'options');
        }

        return $deleted;
    }
}
