<?php

declare(strict_types=1);

namespace SFX\SiteCheck\Checks;

use SFX\SiteCheck\RunContext;
use SFX\SiteCheck\Status;

/**
 * Housekeeping checks (section Aufräumen): test_content, inactive_plugins,
 * inactive_themes, updates.
 */
final class CleanupChecks
{
    /** WordPress' install strings (wp-admin/includes/upgrade.php), looked up in the site language at run time. */
    private const POST_TITLE = 'Hello world!';
    private const POST_TEXT = 'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!';
    private const PAGE_TITLE = 'Sample Page';
    private const PAGE_TEXT = "This is an example page. It's different from a blog post because it will stay in one place and will show up in your site navigation (in most themes). Most people start with an About page that introduces them to potential site visitors. It might say something like this:";
    private const COMMENT_AUTHOR = 'A WordPress Commenter';
    private const COMMENT_TEXT = "Hi, this is a comment.\nTo get started with moderating, editing, and deleting comments, please visit the Comments screen in the dashboard.\nCommenter avatars come from <a href=\"%s\">Gravatar</a>.";

    /** Fixed markers, so content created under another language is found too (English, German). */
    private const MARKERS = [
        'post_title'     => ['Hello world!', 'Hallo Welt!'],
        'post_text'      => ['This is your first post', 'Dies ist dein erster Beitrag'],
        'page_title'     => ['Sample Page', 'Beispiel-Seite', 'Beispielseite'],
        'page_text'      => ['This is an example page', 'Dies ist eine Beispiel-Seite'],
        'comment_author' => ['A WordPress Commenter', 'Ein WordPress-Kommentator'],
        'comment_text'   => ['Hi, this is a comment', 'Hallo, dies ist ein Kommentar'],
    ];

    public static function observe(string $id, RunContext $ctx): array
    {
        switch ($id) {
            case 'test_content':
                return ['items' => self::test_content()];
            case 'inactive_plugins':
                return ['inactive' => self::inactive_plugins()];
            case 'inactive_themes':
                return self::inactive_themes($ctx->fallback_theme());
            case 'updates':
                return self::updates();
        }
        throw new \InvalidArgumentException('Not a cleanup check: ' . $id);
    }

    public static function grade(string $id, array $obs, RunContext $ctx): array
    {
        $findings = [];
        switch ($id) {
            case 'test_content':
                $labels = [
                    /* translators: %s: post title */
                    'post'    => __('Sample post "%s" is published.', 'sfxtheme'),
                    /* translators: %s: page title */
                    'page'    => __('Sample page "%s" is published.', 'sfxtheme'),
                    /* translators: %s: comment author */
                    'comment' => __('The sample comment by "%s" is approved.', 'sfxtheme'),
                ];
                foreach ($obs['items'] ?? [] as $item) {
                    $findings[] = ServerChecks::finding($id, $item['kind'] . '-' . $item['id'], Status::YELLOW, sprintf($labels[$item['kind']], $item['title']));
                }
                return ServerChecks::result($findings, Status::GREEN, __('Found by ID and by the default title and text, in English, German and the site language.', 'sfxtheme'));

            case 'inactive_plugins':
                foreach ($obs['inactive'] ?? [] as $p) {
                    $findings[] = ServerChecks::finding($id, $p['file'], Status::YELLOW, $p['name']);
                }
                return ServerChecks::result($findings, Status::GREEN);

            case 'inactive_themes':
                foreach ($obs['inactive'] ?? [] as $t) {
                    $findings[] = ServerChecks::finding($id, $t['slug'], Status::YELLOW, $t['name']);
                }
                $note = ($obs['fallback'] ?? '') === ''
                    ? __('Bricks and the active theme are kept. No fallback theme is set.', 'sfxtheme')
                    /* translators: %s: theme slug */
                    : sprintf(__('Bricks, the active theme and the fallback theme "%s" are kept.', 'sfxtheme'), $obs['fallback']);
                return ServerChecks::result($findings, Status::GREEN, $note);

            case 'updates':
                return self::grade_updates($obs);
        }
        throw new \InvalidArgumentException('Not a cleanup check: ' . $id);
    }

    /** @return list<array{kind:string, id:int, title:string}> */
    private static function test_content(): array
    {
        $m = self::MARKERS;
        $m['post_title'][] = translate(self::POST_TITLE, 'default');
        $m['post_text'][] = translate(self::POST_TEXT, 'default');
        $m['page_title'][] = translate(self::PAGE_TITLE, 'default');
        $m['page_text'][] = translate(self::PAGE_TEXT, 'default');
        $m['comment_author'][] = translate(self::COMMENT_AUTHOR, 'default');
        $m['comment_text'][] = strtok(translate(self::COMMENT_TEXT, 'default'), "\n");

        $items = [];
        foreach ([['post', 1], ['page', 2]] as [$type, $post_id]) {
            $post = get_post($post_id);
            if (
                $post && $post->post_type === $type && $post->post_status === 'publish'
                && self::is_one_of(trim((string) $post->post_title), $m[$type . '_title'])
                && self::contains_one_of((string) $post->post_content, $m[$type . '_text'])
            ) {
                $items[] = ['kind' => $type, 'id' => $post_id, 'title' => trim((string) $post->post_title)];
            }
        }

        $comment = get_comment(1);
        if (
            $comment && (string) $comment->comment_approved === '1'
            && self::is_one_of(trim((string) $comment->comment_author), $m['comment_author'])
            && self::contains_one_of((string) $comment->comment_content, $m['comment_text'])
        ) {
            $items[] = ['kind' => 'comment', 'id' => 1, 'title' => trim((string) $comment->comment_author)];
        }
        return $items;
    }

    /** @return list<array{file:string, name:string}> */
    private static function inactive_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $inactive = [];
        foreach (get_plugins() as $file => $data) {
            if (!in_array($file, $active, true)) {
                $inactive[] = ['file' => (string) $file, 'name' => (string) ($data['Name'] ?? $file)];
            }
        }
        return $inactive;
    }

    /** The fallback comes from the run snapshot, never from the live setting. */
    private static function inactive_themes(string $fallback): array
    {
        $keep = array_filter([get_stylesheet(), get_template(), 'bricks', $fallback]);
        $inactive = [];
        foreach (wp_get_themes() as $slug => $theme) {
            if (!in_array((string) $slug, $keep, true)) {
                $inactive[] = ['slug' => (string) $slug, 'name' => (string) $theme->get('Name')];
            }
        }
        return ['inactive' => $inactive, 'fallback' => $fallback];
    }

    private static function updates(): array
    {
        $core = [];
        $plugins = [];
        $themes = [];
        $checked = [];

        $t = get_site_transient('update_core');
        if (is_object($t)) {
            $checked[] = $t->last_checked ?? null;
            foreach ((array) ($t->updates ?? []) as $offer) {
                $offer = (object) $offer;
                if (($offer->response ?? '') === 'upgrade' && !empty($offer->current)) {
                    $core[] = (string) $offer->current;
                }
            }
        }

        $t = get_site_transient('update_plugins');
        if (is_object($t)) {
            $checked[] = $t->last_checked ?? null;
            $installed = function_exists('get_plugins') ? get_plugins() : [];
            foreach ((array) ($t->response ?? []) as $file => $offer) {
                $offer = (object) $offer;
                $plugins[] = ['file' => (string) $file, 'name' => (string) ($installed[$file]['Name'] ?? $file), 'version' => (string) ($offer->new_version ?? '')];
            }
        }

        $t = get_site_transient('update_themes');
        if (is_object($t)) {
            $checked[] = $t->last_checked ?? null;
            foreach ((array) ($t->response ?? []) as $slug => $offer) {
                $offer = (object) $offer;
                $themes[] = ['slug' => (string) $slug, 'version' => (string) ($offer->new_version ?? '')];
            }
        }

        $checked = array_filter($checked, 'is_int');
        return ['core' => array_values(array_unique($core)), 'plugins' => $plugins, 'themes' => $themes, 'checked' => $checked === [] ? null : min($checked)];
    }

    private static function grade_updates(array $obs): array
    {
        $findings = [];
        foreach ($obs['core'] ?? [] as $version) {
            /* translators: %s: version */
            $findings[] = ServerChecks::finding('updates', 'core', Status::YELLOW, sprintf(__('WordPress %s', 'sfxtheme'), $version));
        }
        foreach ($obs['plugins'] ?? [] as $p) {
            $findings[] = ServerChecks::finding('updates', 'plugin:' . $p['file'], Status::YELLOW, trim($p['name'] . ' ' . $p['version']));
        }
        foreach ($obs['themes'] ?? [] as $t) {
            $findings[] = ServerChecks::finding('updates', 'theme:' . $t['slug'], Status::YELLOW, trim($t['slug'] . ' ' . $t['version']));
        }
        // A core update can appear twice (several locales); one finding per ID.
        $findings = array_values(array_column($findings, null, 'id'));

        if ($findings === []) {
            $label = is_int($obs['checked'] ?? null)
                /* translators: %s: date of WordPress' last update check */
                ? sprintf(__('No updates offered (as of %s).', 'sfxtheme'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $obs['checked']))
                : __('WordPress has never checked for updates here, so no offers are known.', 'sfxtheme');
            $findings[] = ServerChecks::finding('updates', 'offers', Status::HINT, $label);
        }
        return ServerChecks::result($findings, Status::HINT, __('WordPress keeps old data when an update check fails, and premium updaters without a licence show no offer.', 'sfxtheme'));
    }

    private static function is_one_of(string $value, array $candidates): bool
    {
        return $value !== '' && in_array($value, array_filter($candidates), true);
    }

    private static function contains_one_of(string $haystack, array $needles): bool
    {
        foreach (array_filter($needles) as $needle) {
            if (strpos($haystack, (string) $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
