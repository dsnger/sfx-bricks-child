<?php

declare(strict_types=1);

/**
 * SiteCheck Task 1: access predicate, settings sanitising/saving, the
 * ImportExport callback, the settings form and the export catalogue entry.
 * Runs on a stubbed $wpdb (same model as site-check-mutex-test.php), so the
 * real Mutex does the writing.
 */

namespace SFX\SiteCheck {
    function time(): int
    {
        return (int) floor(\FakeClock::$now);
    }

    function microtime(bool $as_float = false)
    {
        return \FakeClock::$now;
    }

    function usleep(int $microseconds): void
    {
        \FakeClock::$now += $microseconds / 1000000;
    }
}

namespace SFX {
    class AccessControl
    {
        public static function can_access_theme_settings(): bool
        {
            return !empty($GLOBALS['t_theme_access']);
        }
    }

    /** The theme bootstrap's module switch, which AdminPage asks for a module link. */
    class SFXBricksChildTheme
    {
        public static function is_general_option_enabled(string $key): bool
        {
            return !empty($GLOBALS['t_modules'][$key]);
        }
    }
}

namespace {
    final class FakeClock
    {
        public static float $now = 1800000000.0;
    }

    /** A wpdb double that models exactly the statements Mutex sends. */
    final class FakeWpdb
    {
        public string $options = 'wp_options';
        public array $rows = [];
        public array $autoload = [];
        /** What another process does right before this one takes the mutex (once). */
        public $on_acquire = null;

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            return json_encode([$query, $args]);
        }

        public function query($prepared)
        {
            [$q, $a] = json_decode($prepared, true);
            if (str_starts_with($q, 'INSERT IGNORE')) {
                if ($this->on_acquire !== null) {
                    $other = $this->on_acquire;
                    $this->on_acquire = null;
                    $other();
                }
                [$name, $value, $autoload] = $a;
                if (isset($this->rows[$name])) {
                    return 0;
                }
                $this->rows[$name] = $value;
                $this->autoload[$name] = $autoload;
                return 1;
            }
            if (str_starts_with($q, 'UPDATE')) {
                [$new, $name, $old] = $a;
                if (($this->rows[$name] ?? null) !== $old) {
                    return 0;
                }
                $this->rows[$name] = $new;
                return 1;
            }
            if (str_starts_with($q, 'INSERT INTO')) {
                [$option, $value, $autoload, $mutex, $held, $update_value, $update_autoload] = $a;
                if (($this->rows[$mutex] ?? null) !== $held) {
                    return 0;
                }
                $existed = array_key_exists($option, $this->rows);
                $this->rows[$option] = $update_value;
                $this->autoload[$option] = $update_autoload;
                return $existed ? 2 : 1;
            }
            if (str_starts_with($q, 'DELETE FROM')) {
                [$name, $value] = $a;
                if (($this->rows[$name] ?? null) !== $value) {
                    return 0;
                }
                unset($this->rows[$name]);
                return 1;
            }
            throw new RuntimeException('FakeWpdb: unexpected query ' . $q);
        }

        public function get_var($prepared)
        {
            [$q, $a] = json_decode($prepared, true);
            if (str_starts_with($q, 'SELECT option_value')) {
                return $this->rows[$a[0]] ?? null;
            }
            if (str_starts_with($q, 'SELECT COUNT(*)') && count($a) === 4) {
                [$mutex, $held, $option, $value] = $a;
                return (($this->rows[$mutex] ?? null) === $held && ($this->rows[$option] ?? null) === $value) ? '1' : '0';
            }
            throw new RuntimeException('FakeWpdb: unexpected get_var ' . $q);
        }
    }

    $GLOBALS['wpdb'] = new FakeWpdb();
    $GLOBALS['cache'] = [];
    $GLOBALS['t_theme_access'] = false;
    $GLOBALS['t_caps'] = [];
    $GLOBALS['t_logged_in'] = false;

    function maybe_serialize($data)
    {
        return (is_array($data) || is_object($data)) ? serialize($data) : $data;
    }

    function wp_cache_get($key, $group = '')
    {
        return $GLOBALS['cache'][$group][$key] ?? false;
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0)
    {
        $GLOBALS['cache'][$group][$key] = $data;
        return true;
    }

    function wp_cache_delete($key, $group = '')
    {
        unset($GLOBALS['cache'][$group][$key]);
        return true;
    }

    /** get_option reads the fake table, like WordPress reads wp_options. */
    function get_option($name, $default = false)
    {
        $rows = $GLOBALS['wpdb']->rows;
        if (!array_key_exists($name, $rows)) {
            return $default;
        }
        $value = $rows[$name];
        return is_string($value) && str_starts_with($value, 'a:') ? unserialize($value) : $value;
    }

    function check_admin_referer($action = -1)
    {
        if (empty($GLOBALS['t_nonce_ok'])) {
            throw new RuntimeException('nonce-failed');
        }
    }

    function wp_die($message = '', $title = '', $args = [])
    {
        throw new RuntimeException('die:' . ($args['response'] ?? 500));
    }

    function wp_safe_redirect($url)
    {
        throw new RuntimeException('redirect:' . $url);
    }

    function add_query_arg($key, $value, $url)
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value;
    }

    function wp_unslash($v)
    {
        return $v;
    }

    function current_user_can($cap)
    {
        return in_array($cap, $GLOBALS['t_caps'], true);
    }

    function __($text, $domain = 'default')
    {
        return $text;
    }

    function esc_html($t)
    {
        return htmlspecialchars((string) $t, ENT_QUOTES);
    }

    function esc_attr($t)
    {
        return htmlspecialchars((string) $t, ENT_QUOTES);
    }

    function esc_html__($t, $d = '')
    {
        return esc_html($t);
    }

    function esc_html_e($t, $d = '')
    {
        echo esc_html($t);
    }

    function esc_url($t)
    {
        return (string) $t;
    }

    function _x($text, $context, $domain = 'default')
    {
        return $text;
    }

    function admin_url($p = '')
    {
        return 'https://example.test/wp-admin/' . $p;
    }

    function wp_nonce_field($action = -1, $name = '_wpnonce')
    {
        echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
    }

    require_once __DIR__ . '/../inc/SiteCheck/Options.php';
    require_once __DIR__ . '/../inc/SiteCheck/Mutex.php';
    require_once __DIR__ . '/../inc/SiteCheck/Access.php';
    require_once __DIR__ . '/../inc/SiteCheck/Checks/FileChecks.php';
    require_once __DIR__ . '/../inc/SiteCheck/Settings.php';
    require_once __DIR__ . '/../inc/SiteCheck/AdminPage.php';
    require_once __DIR__ . '/../inc/SiteCheck/Status.php';
    require_once __DIR__ . '/../inc/SiteCheck/Catalogue.php';

    use SFX\SiteCheck\AdminPage;
    use SFX\SiteCheck\Access;
    use SFX\SiteCheck\Catalogue;
    use SFX\SiteCheck\Mutex;
    use SFX\SiteCheck\Settings;

    function assert_true($condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function assert_same($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    $db = $GLOBALS['wpdb'];
    $opt = 'sfx_site_check_settings';
    $mutex = 'sfx_site_check_mutex';

    // ------------------------------------------------------------ access

    assert_same('sfx_site_check', Access::NONCE, 'nonce action');
    $matrix = [
        // logged in, theme access, manage_options => allowed
        [false, false, false, false],
        [true, false, false, false],
        [true, false, true, false],
        [true, true, false, false],
        [true, true, true, true],
    ];
    foreach ($matrix as $i => [$in, $theme, $manage, $expected]) {
        $GLOBALS['t_logged_in'] = $in;
        $GLOBALS['t_theme_access'] = $in && $theme; // the real gate refuses guests itself
        $GLOBALS['t_caps'] = $in && $manage ? ['manage_options'] : [];
        assert_same($expected, Access::allowed(), "access row {$i}");
    }

    // ------------------------------------------------------------ valid_path

    foreach (['/kontakt/', '/', '/a/b-c_d', '/über-uns/'] as $ok) {
        assert_same(true, Settings::valid_path($ok), "valid path {$ok}");
    }
    foreach (['https://x/a', '//evil.test/a', '/a?b=1', '/a#f', '/../x', '/a/../x', '/%2e%2e/x', '/x.php', '/x.PHP', '/x.php/y', '/x.phtml', '/x.PHAR', '/x.pht', '/x.php7', '/x.pht/y', '/x%2Ephtml', 'x', '', '/a b', "/a\n", '/a\\b'] as $bad) {
        assert_same(false, Settings::valid_path($bad), 'invalid path ' . json_encode($bad));
    }
    assert_same(false, Settings::valid_path('/' . str_repeat('a', 300)), 'overlong path');

    // ------------------------------------------------------------ sanitize

    $s = Settings::sanitize(['profile' => 'nonsense']);
    assert_same(['profile' => 'live'], $s, 'unknown profile -> live, absent keys stay absent');
    assert_same('private', Settings::sanitize(['profile' => 'private'])['profile'], 'private profile kept');
    assert_same('live', Settings::sanitize(['profile' => ['x']])['profile'], 'array profile -> live');

    $s = Settings::sanitize(['indexability_paths' => ['/kontakt/', 'https://x/a', '/a?b=1', '/../x', '/x.php', 'x', ' /ok/ ', 5, null, '/kontakt/']]);
    assert_same(['/kontakt/', '/ok/'], $s['indexability_paths'], 'invalid and duplicate paths dropped, trimmed');
    $s = Settings::sanitize(['indexability_paths' => ['/1', '/2', '/3', '/4', '/5', '/6', '/7']]);
    assert_same(['/1', '/2', '/3', '/4', '/5'], $s['indexability_paths'], 'first 5 kept');
    assert_same([], Settings::sanitize(['indexability_paths' => 'oops'])['indexability_paths'], 'non-array paths -> empty');
    assert_same([], Settings::sanitize(['sitemap_allow' => 'oops'])['sitemap_allow'], 'non-array allow -> empty');
    $s = Settings::sanitize(['sitemap_allow' => ['attachments', 'authors', 'post_type:bricks_template', 'taxonomy:post_tag', 'bogus', 'post_type:', 'post_type:A B', 7, 'attachments']]);
    assert_same(['attachments', 'authors', 'post_type:bricks_template', 'taxonomy:post_tag'], $s['sitemap_allow'], 'only well-formed entry types, unique');
    assert_same('twentytwentyfive', Settings::sanitize(['fallback_theme' => ' twentytwentyfive '])['fallback_theme'], 'fallback theme trimmed');
    assert_same('', Settings::sanitize(['fallback_theme' => '../x'])['fallback_theme'], 'bad fallback theme -> empty');
    assert_same('', Settings::sanitize(['fallback_theme' => ['a']])['fallback_theme'], 'array fallback theme -> empty');

    // ------------------------------------------------------------ get

    $defaults = ['profile' => 'live', 'indexability_paths' => [], 'sitemap_allow' => [], 'fallback_theme' => ''];
    assert_same($defaults, Settings::get(), 'defaults without an option');
    $db->rows[$opt] = 'garbage';
    assert_same($defaults, Settings::get(), 'non-array option -> defaults');
    $db->rows[$opt] = serialize(['profile' => 'bogus', 'indexability_paths' => ['/ok/', '/x.php', 3], 'monitor_interval' => 'daily']);
    $got = Settings::get();
    assert_same('live', $got['profile'], 'stored bad profile reads as live');
    assert_same(['/ok/', '/x.php'], $got['indexability_paths'], 'stored invalid path stays visible (shown as rejected), non-strings go');
    assert_true(!array_key_exists('monitor_interval', $got), 'monitor keys are not part of get() in plan 1');
    unset($db->rows[$opt]);

    // ------------------------------------------------------------ save

    assert_same(true, Settings::save(['profile' => 'staging', 'indexability_paths' => ['/kontakt/', '/x.php']]), 'save succeeds');
    assert_same('no', $db->autoload[$opt], 'option created with autoload = no');
    assert_same('staging', Settings::get()['profile'], 'profile saved');
    assert_same(['/kontakt/'], Settings::get()['indexability_paths'], 'invalid path dropped on save');
    assert_true(!isset($db->rows[$mutex]), 'mutex released after save');

    // Unknown stored keys (plan 2's monitor keys) survive a save.
    $stored = unserialize($db->rows[$opt]);
    $stored['monitor_interval'] = 'weekly';
    $db->rows[$opt] = serialize($stored);
    Settings::save(['fallback_theme' => 'twentytwentyfive']);
    assert_same('weekly', unserialize($db->rows[$opt])['monitor_interval'], 'save keeps keys it does not own');
    assert_same('twentytwentyfive', Settings::get()['fallback_theme'], 'fallback theme saved');

    // Busy: nothing written, status says so.
    $before = $db->rows[$opt];
    $db->rows[$mutex] = 'otherprocess:' . (int) FakeClock::$now;
    assert_same(false, Settings::save(['profile' => 'private']), 'busy mutex -> save false');
    assert_same('busy', Settings::save_status(['profile' => 'private']), 'busy mutex -> status busy');
    assert_same($before, $db->rows[$opt], 'busy save wrote nothing');
    unset($db->rows[$mutex]);
    assert_same('saved', Settings::save_status(['profile' => 'private']), 'status saved');

    // ------------------------------------------------------------ import

    $reset = static function () use ($db, $opt, $mutex): void {
        unset($db->rows[$opt], $db->autoload[$opt], $db->rows[$mutex]);
    };

    // first import creates the option, autoload no, touches only the two keys
    $reset();
    $r = Settings::import_fields(['indexability_paths' => ['/kontakt/', 'https://x', '/x.php'], 'sitemap_allow' => ['attachments', 'bogus']], 'merge');
    assert_same('success', $r['status'], 'first import ok');
    assert_true($r['message'] !== '', 'import has a message');
    assert_same('no', $db->autoload[$opt], 'import creates the option with autoload = no');
    $stored = unserialize($db->rows[$opt]);
    assert_same(['indexability_paths' => ['/kontakt/'], 'sitemap_allow' => ['attachments']], $stored, 'first import stores only the two exported keys, cleaned');

    // profile, fallback and foreign keys unchanged; merge unions, replace replaces
    $db->rows[$opt] = serialize(['profile' => 'private', 'fallback_theme' => 'mytheme', 'monitor_interval' => 'weekly', 'indexability_paths' => ['/a/', '/b/'], 'sitemap_allow' => ['authors']]);
    Settings::import_fields(['profile' => 'staging', 'fallback_theme' => 'evil', 'monitor_interval' => 'x', 'indexability_paths' => ['/c/', '/a/'], 'sitemap_allow' => ['attachments']], 'merge');
    $stored = unserialize($db->rows[$opt]);
    assert_same('private', $stored['profile'], 'import never changes profile');
    assert_same('mytheme', $stored['fallback_theme'], 'import never changes fallback theme');
    assert_same('weekly', $stored['monitor_interval'], 'import never changes other keys');
    assert_same(['/a/', '/b/', '/c/'], $stored['indexability_paths'], 'merge: union, existing first');
    assert_same(['authors', 'attachments'], $stored['sitemap_allow'], 'merge: allow list union');

    Settings::import_fields(['indexability_paths' => ['/z/'], 'sitemap_allow' => []], 'replace');
    $stored = unserialize($db->rows[$opt]);
    assert_same(['/z/'], $stored['indexability_paths'], 'replace: paths replaced');
    assert_same([], $stored['sitemap_allow'], 'replace: allow list replaced');
    assert_same('private', $stored['profile'], 'replace keeps profile');

    // excess paths in merge capped at 5
    $db->rows[$opt] = serialize(['indexability_paths' => ['/1', '/2', '/3', '/4']]);
    Settings::import_fields(['indexability_paths' => ['/5', '/6', '/7']], 'merge');
    assert_same(['/1', '/2', '/3', '/4', '/5'], unserialize($db->rows[$opt])['indexability_paths'], 'merge capped at 5');

    // malformed types are rejected: field untouched
    $db->rows[$opt] = serialize(['indexability_paths' => ['/keep/'], 'sitemap_allow' => ['authors']]);
    $r = Settings::import_fields(['indexability_paths' => 'oops', 'sitemap_allow' => 5], 'replace');
    $stored = unserialize($db->rows[$opt]);
    assert_same(['/keep/'], $stored['indexability_paths'], 'malformed paths leave the field alone');
    assert_same(['authors'], $stored['sitemap_allow'], 'malformed allow list leaves the field alone');
    assert_true($r['status'] !== 'success', 'all fields malformed -> not reported as success');

    // busy: waits, reports, writes nothing
    $before = $db->rows[$opt];
    $db->rows[$mutex] = 'otherprocess:' . (int) FakeClock::$now;
    $start = FakeClock::$now;
    $r = Settings::import_fields(['indexability_paths' => ['/new/']], 'replace');
    assert_same('error', $r['status'], 'import while busy -> error');
    assert_true(FakeClock::$now - $start >= 5.0, 'import waited for the mutex');
    assert_same($before, $db->rows[$opt], 'busy import wrote nothing');
    unset($db->rows[$mutex]);

    // ------------------------------------------------------------ admin page

    assert_same('sfx-site-check', AdminPage::$menu_slug, 'menu slug');

    $changes = AdminPage::collect([
        'profile' => 'staging',
        'indexability_paths' => ['/a/', '', '/x.php'],
        'sitemap_allow' => ['attachments', 'post_type:bricks_template'],
        'fallback_theme' => 'mytheme',
    ]);
    assert_same(['profile' => 'staging', 'indexability_paths' => ['/a/', '', '/x.php'], 'sitemap_allow' => ['attachments', 'post_type:bricks_template'], 'fallback_theme' => 'mytheme'], $changes, 'collect passes the four controls');
    $changes = AdminPage::collect([]);
    assert_same(['indexability_paths' => [], 'sitemap_allow' => []], $changes, 'unchecked boxes and empty path list are real values; absent scalars are omitted');

    // round trip: each control saves and reloads
    $reset();
    $entry_types = ['attachments' => 'Attachments', 'authors' => 'Author sitemap', 'post_type:bricks_template' => 'Bricks template'];
    $themes = ['twentytwentyfive' => 'Twenty Twenty-Five', 'mytheme' => 'My Theme'];
    Settings::save(AdminPage::collect([
        'profile' => 'private',
        'indexability_paths' => ['/kontakt/', '/x.php'],
        'sitemap_allow' => ['authors', 'post_type:bricks_template'],
        'fallback_theme' => 'mytheme',
    ]));
    $got = Settings::get();
    assert_same('private', $got['profile'], 'round trip profile');
    assert_same(['/kontakt/'], $got['indexability_paths'], 'round trip paths');
    assert_same(['authors', 'post_type:bricks_template'], $got['sitemap_allow'], 'round trip allow list');
    assert_same('mytheme', $got['fallback_theme'], 'round trip fallback theme');

    ob_start();
    AdminPage::render_form($got, $entry_types, $themes);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'name="_wpnonce" value="nonce-sfx_site_check"'), 'form carries the nonce');
    for ($i = 1; $i <= 5; $i++) {
        assert_true(str_contains($html, '<label for="sfx-sc-path-' . $i . '"') && str_contains($html, 'id="sfx-sc-path-' . $i . '"') && str_contains($html, 'Page ' . $i . ' for the indexing check'), "Gate B pass 6: path input {$i} has its own label");
    }
    assert_true(str_contains($html, 'value="sfx_site_check_save"'), 'form posts to the admin-post action');
    assert_true(preg_match('/<option value="private"[^>]*selected/', $html) === 1, 'profile selected on reload');
    assert_true(str_contains($html, 'value="/kontakt/"'), 'path shown on reload');
    assert_true(preg_match('/value="authors"[^>]*checked/', $html) === 1, 'allow-list box checked on reload');
    assert_true(preg_match('/value="attachments"[^>]*checked/', $html) === 0, 'unchecked box not checked');
    assert_true(preg_match('/<option value="mytheme"[^>]*selected/', $html) === 1, 'fallback theme selected on reload');
    assert_true(!str_contains($html, 'Rejected'), 'no rejected marker when every path is valid');

    assert_true(str_contains($html, 'id="sfx-sc-settings"') && str_contains($html, 'id="sfx-sc-settings-save"') && str_contains($html, 'id="sfx-sc-settings-dirty"'), 'the form, its save button and its unsaved marker carry the IDs the script wires');

    // page-load probe cleanup: kept and unregistered files are reported, paths escaped
    ob_start();
    AdminPage::render_cleanup(['busy' => false, 'deleted' => 0, 'kept' => ['/up/sfx-site-check/<b>k.php'], 'unregistered' => ['/up/sfx-site-check/"u".php']]);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, '/up/sfx-site-check/&lt;b&gt;k.php') && !str_contains($html, '<b>'), 'kept probe file reported, escaped');
    assert_true(str_contains($html, '/up/sfx-site-check/&quot;u&quot;.php'), 'unregistered probe file reported, escaped');
    ob_start();
    AdminPage::render_cleanup(['busy' => false, 'deleted' => 2, 'kept' => [], 'unregistered' => []]);
    assert_same('', (string) ob_get_clean(), 'nothing to report → no notice');
    ob_start();
    AdminPage::render_cleanup(null);
    assert_same('', (string) ob_get_clean(), 'no cleanup ran → no notice');
    // Gate B pass 1: a registry the cleanup could not rewrite is reported.
    ob_start();
    AdminPage::render_cleanup(['busy' => false, 'deleted' => 1, 'kept' => [], 'unregistered' => [], 'store_failed' => true]);
    assert_true(str_contains((string) ob_get_clean(), 'could not be updated'), 'failed registry write → notice');

    // template: block text and paths escaped at output; nginx note only on nginx
    require_once __DIR__ . '/../inc/SiteCheck/Template.php';
    $blocks = [['id' => 'uploads', 'title' => 'T', 'file' => '/srv/<i>up/.htaccess', 'note' => 'Note <b>sub</b>', 'text' => "# BEGIN sfx-x\n<FilesMatch \"a\">\n# END sfx-x\n"]];
    ob_start();
    AdminPage::render_template($blocks, false);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'The security check never writes these files') && !str_contains($html, 'The theme never writes'), 'Gate B pass 5: the promise is the module\'s, not the theme\'s');
    assert_true(str_contains($html, '&lt;FilesMatch &quot;a&quot;&gt;') && !str_contains($html, '<FilesMatch'), 'block text escaped');
    assert_true(str_contains($html, '/srv/&lt;i&gt;up/.htaccess'), 'file path escaped');
    assert_true(str_contains($html, 'data-target="sfx-sc-tpl-uploads"') && str_contains($html, 'id="sfx-sc-tpl-uploads"'), 'copy button targets its block');
    assert_true(!str_contains($html, 'nginx'), 'no nginx note on Apache');
    assert_true(str_contains($html, 'Note &lt;b&gt;sub&lt;/b&gt;'), 'block note shown, escaped');
    ob_start();
    AdminPage::render_template([['id' => 'git', 'title' => 'G', 'file' => null, 'note' => '', 'text' => "# BEGIN sfx-git\n# END sfx-git\n"]], false);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'could not be determined') && !str_contains($html, '<code>'), 'unknown location said so, no bare path');
    ob_start();
    AdminPage::render_template($blocks, true);
    assert_true(str_contains((string) ob_get_clean(), 'nginx'), 'nginx note when the server looks like nginx');
    // Gate B pass 1 (spec-14): the full steps per destination.
    ob_start();
    AdminPage::render_template($blocks, false);
    $html = (string) ob_get_clean();
    foreach ([
        'or note that none exists' => 'backup step names the case without a file',
        'at the top of the file when that line is missing' => 'root: at the top when # BEGIN WordPress is absent',
        'at the top of the .htaccess in their own folder; create that file if it does not exist' => 'uploads and .git: top of their own file, created if missing',
        'delete the file if you created it' => 'recovery: delete a newly created file',
    ] as $needle => $what) {
        assert_true(str_contains($html, $needle), "template steps: {$what}");
    }
    assert_true(str_contains($html, 'Where: at the top of this folder'), 'the uploads block says where it goes');

    // a stored invalid path is shown as rejected, with its text escaped
    $bad = $got;
    $bad['indexability_paths'] = ['/kontakt/', '/x.php<b>'];
    ob_start();
    AdminPage::render_form($bad, $entry_types, $themes);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'Rejected'), 'invalid stored path marked rejected');
    assert_true(str_contains($html, '/x.php&lt;b&gt;') && !str_contains($html, '<b>'), 'path escaped at output');

    // a stored allow entry that is not offered any more is listed separately, still checked: unticking removes it
    $odd = $got;
    $odd['sitemap_allow'] = ['post_type:gone', 'authors'];
    ob_start();
    AdminPage::render_form($odd, $entry_types, $themes);
    $html = (string) ob_get_clean();
    $split = strpos($html, 'Stored, currently not offered');
    assert_true($split !== false, 'stored but no longer offered entries have their own list');
    assert_true(preg_match('/value="post_type:gone"[^>]*checked/', substr($html, $split)) === 1, 'the stored entry is listed there, still ticked (it keeps silencing its type)');
    assert_true(preg_match('/value="post_type:gone"/', substr($html, 0, $split)) === 0, 'and not among the offered ones');
    assert_true(str_contains(substr($html, $split), 'Untick and save to remove'), 'with a way to remove it');
    $reset();
    Settings::save(['sitemap_allow' => ['post_type:gone', 'authors']]);
    Settings::save(AdminPage::collect(['sitemap_allow' => ['authors']]));
    assert_same(['authors'], Settings::get()['sitemap_allow'], 'unticked and saved → removed');
    $hostile = ['taxonomy:x' => 'Tax <script>x</script>'];
    ob_start();
    AdminPage::render_form(Settings::defaults(), $hostile, ['t<b>' => 'Theme <i>']);
    $html = (string) ob_get_clean();
    assert_true(!str_contains($html, '<script>') && !str_contains($html, '<i>') && str_contains($html, 'Tax &lt;script&gt;'), 'allow-list and theme labels escaped at output');

    // ------------------------------------------------------------ Task 11: allow-list eligibility

    $type = static fn(string $label, bool $public, bool $queryable): object => (object) ['label' => $label, 'public' => $public, 'publicly_queryable' => $queryable];
    $post_types = [
        'post' => $type('Posts', true, true), 'page' => $type('Pages', true, false), 'attachment' => $type('Media', true, true),
        'bricks_template' => $type('Templates', false, true), 'secret' => $type('Secret', false, false), 'members' => $type('Members', false, false),
        'revision' => $type('Revisions', false, false), 'nav_menu_item' => $type('Menu items', false, false), 'custom_css' => $type('CSS', false, false),
        'customize_changeset' => $type('Changesets', false, false), 'oembed_cache' => $type('oEmbed', false, false), 'user_request' => $type('Requests', false, false),
        'wp_block' => $type('Patterns', false, false), 'wp_template' => $type('Templates', false, true), 'wp_template_part' => $type('Parts', false, true),
        'wp_global_styles' => $type('Styles', false, false), 'wp_navigation' => $type('Navigation', false, false), 'wp_font_family' => $type('Fonts', false, false),
        'wp_font_face' => $type('Faces', false, false),
    ];
    $taxonomies = [
        'category' => $type('Categories', true, true), 'hidden_tax' => $type('Hidden', false, false), 'nav_menu' => $type('Menus', false, false),
        'wp_theme' => $type('Themes', false, false), 'wp_template_part_area' => $type('Areas', false, false), 'wp_pattern_category' => $type('Pattern categories', false, false),
    ];
    $reported = ['results' => ['sitemap_entries' => ['findings' => [
        ['id' => 'sitemap_entries:post_type:secret', 'status' => 'yellow', 'label' => 'x'],
        ['id' => 'sitemap_entries:taxonomy:hidden_tax', 'status' => 'yellow', 'label' => 'x'],
        ['id' => 'sitemap_entries:post_type:revision', 'status' => 'yellow', 'label' => 'x'],
        ['id' => 'sitemap_entries:unmatched', 'status' => 'hint', 'label' => 'x'],
    ]]]];
    $offered = AdminPage::entry_types($post_types, $taxonomies, $reported);
    assert_same(['attachments', 'authors', 'post_type:post', 'post_type:page', 'post_type:bricks_template', 'post_type:secret', 'taxonomy:category', 'taxonomy:hidden_tax'], array_keys($offered),
        'offered: attachments, author sitemap, public or publicly queryable types, and types the last sitemap_entries reported — never WordPress\' internal types');
    assert_same(['attachments', 'authors', 'post_type:post', 'post_type:page', 'post_type:bricks_template', 'taxonomy:category'], array_keys(AdminPage::entry_types($post_types, $taxonomies, null)), 'without a saved run only the eligible types');

    // ------------------------------------------------------------ Task 11: guidance in the catalogue

    $catalogue = Catalogue::all();
    assert_same(35, count($catalogue), 'all catalogue checks');
    $tab_fragments = ['#uebersicht', '#sicherheit', '#live-gang', '#aufraeumen', '#htaccess', '#einstellungen'];
    foreach ($catalogue as $id => $row) {
        assert_true(!isset($row['tip']), "{$id}: the old tip is gone");
        assert_true(is_string($row['why']) && trim($row['why']) !== '', "{$id}: Warum");
        assert_true(is_string($row['recommendation']) && trim($row['recommendation']) !== '', "{$id}: Empfehlung");
        assert_true(is_array($row['steps']) && array_filter($row['steps'], static fn($x) => !is_string($x) || trim($x) === '') === [], "{$id}: steps are texts");
        foreach ($row['links'] as $link) {
            assert_true(trim($link['label']) !== '', "{$id}: link label");
            $url = $link['url'];
            assert_true(in_array($url, $tab_fragments, true) || preg_match('/^[a-z-]+\.php(\?[A-Za-z0-9_=&.-]+)?$/', $url) === 1, "{$id}: link is a tab fragment or a wp-admin path, not a site URL: {$url}");
        }
    }
    $in = $catalogue['php_in_uploads'];
    assert_true(count($in['steps']) >= 3 && in_array('#htaccess', array_column($in['links'], 'url'), true), 'php_in_uploads: numbered steps and a link to the .htaccess template');
    assert_same([], $catalogue['table_prefix']['steps'], 'table_prefix is informational: no steps');
    foreach ($catalogue as $id => $row) {
        assert_same(Catalogue::perspective($id), Catalogue::perspective_for($id, $row['how']), "{$id}: perspective_for() agrees with perspective()");
    }
    assert_true(str_contains(implode(' ', $catalogue['bricks_permissions']['steps']), 'General → SVG uploads'), 'bricks_permissions: SVG uploads are under Bricks → Settings → General');
    assert_true(str_contains(implode(' ', $catalogue['usernames_public']['steps']) . implode(' ', $catalogue['app_passwords']['steps']), 'Users → All Users → Edit') && !str_contains(implode(' ', $catalogue['app_passwords']['steps']), 'Profile'), 'other users are edited under Users → All Users → Edit');
    // No site data: the guidance is the same whatever the site holds.
    $GLOBALS['wpdb']->rows['blogname'] = 'Kunde GmbH';
    $GLOBALS['wpdb']->rows['admin_email'] = 'chef@kunde.test';
    assert_same($catalogue, Catalogue::all(), 'guidance does not depend on site data');
    assert_true(!str_contains(json_encode($catalogue), 'kunde') && !str_contains(json_encode($catalogue), 'example.test'), 'guidance carries no site data');

    // What the page gets: links resolved, a theme module's link by its admin URL, or the theme settings when it is off.
    $GLOBALS['t_modules'] = ['enable_security_header' => true, 'enable_wp_optimizer' => true];
    $data = array_column(AdminPage::catalogue_data(), null, 'id');
    assert_same(['id', 'section', 'how', 'perspective', 'title', 'why', 'recommendation', 'steps', 'links'], array_keys($data['security_headers']), 'the script gets the guidance per check');
    assert_same([['label' => 'Open Security Header', 'url' => 'https://example.test/wp-admin/admin.php?page=sfx-security-header']], $data['security_headers']['links'], 'module on: its page');
    assert_same('#htaccess', $data['php_in_uploads']['links'][0]['url'], 'a tab fragment stays a fragment');
    assert_same('https://example.test/wp-admin/options-reading.php', $data['search_visibility']['links'][0]['url'], 'a WordPress screen by its admin URL');
    $GLOBALS['t_modules'] = ['enable_wp_optimizer' => true];
    $data = array_column(AdminPage::catalogue_data(), null, 'id');
    assert_same([['label' => 'Switch on Security Header in the theme settings', 'url' => 'https://example.test/wp-admin/admin.php?page=sfx-general-theme-options']], $data['security_headers']['links'], 'module off: the theme settings page');
    assert_true(!str_contains((string) file_get_contents(__DIR__ . '/../inc/SiteCheck/Catalogue.php') . file_get_contents(__DIR__ . '/../inc/SiteCheck/AdminPage.php'), 'SecurityHeader\\'), 'no SecurityHeader class referenced');
    unset($GLOBALS['wpdb']->rows['blogname'], $GLOBALS['wpdb']->rows['admin_email']);

    // ------------------------------------------------------------ Task 11: page skeleton

    ob_start();
    AdminPage::render_panels([['id' => 'x', 'title' => 'T <b>', 'file' => '/srv/.htaccess', 'note' => '', 'text' => '# x']], false, Settings::get(), ['attachments' => 'Att <i>'], ['tt' => 'TT']);
    $html = (string) ob_get_clean();
    assert_true(str_contains($html, 'id="sfx-sc-app"') && str_contains($html, 'id="sfx-sc-panel-htaccess"') && str_contains($html, 'id="sfx-sc-panel-einstellungen"'), 'the app container holds the template and settings panels the script adopts');
    assert_true(!str_contains($html, '<b>') && !str_contains($html, '<i>'), 'panel content escaped at output');

    // ------------------------------------------------------------ export catalogue

    require_once __DIR__ . '/../inc/ImportExport/Controller.php';
    $groups = \SFX\ImportExport\Controller::get_settings_groups();
    assert_true(isset($groups['site_check']), 'site_check export group exists');
    $g = $groups['site_check'];
    assert_same('subset', $g['type'], 'site_check is a subset group');
    assert_same('sfx_site_check_settings', $g['option_key'], 'site_check option key');
    assert_same(['indexability_paths', 'sitemap_allow'], $g['fields'], 'site_check exports exactly the two fields');
    assert_same([Settings::class, 'import_fields'], $g['import'], 'site_check owns its import');
    assert_true(is_callable($g['import']), 'import callable resolves');
    $exported = [];
    foreach ($groups as $group) {
        if (($group['option_key'] ?? '') === 'sfx_site_check_settings') {
            $exported = array_merge($exported, $group['fields'] ?? []);
        }
        if (isset($group['option_keys'])) {
            assert_true(!in_array('sfx_site_check_settings', $group['option_keys'], true), 'no group exports the whole settings option');
        }
    }
    foreach (['profile', 'fallback_theme'] as $local) {
        assert_true(!in_array($local, $exported, true), "{$local} is never exported");
    }
    foreach ($groups as $key => $group) {
        if ($key !== 'site_check') {
            assert_true(!isset($group['import']), "group {$key} keeps the generic importer");
        }
    }

    // The importer hands the group's slice to the callback and keeps its result.
    $importer = (new ReflectionClass(\SFX\ImportExport\Controller::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($importer, 'import_settings_data');
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $reset();
    $db->rows[$opt] = serialize(['profile' => 'private']);
    $results = $method->invoke($importer, ['site_check' => ['profile' => 'staging', 'indexability_paths' => ['/ok/', '/x.php']]], ['site_check'], 'replace');
    assert_same('success', $results['site_check']['status'] ?? null, 'importer reports the callback result');
    $stored = unserialize($db->rows[$opt]);
    assert_same(['/ok/'], $stored['indexability_paths'], 'importer path went through the callback');
    assert_same('private', $stored['profile'], 'importer left the profile alone');
    $db->rows[$mutex] = 'otherprocess:' . (int) FakeClock::$now;
    $results = $method->invoke($importer, ['site_check' => ['indexability_paths' => ['/new/']]], ['site_check'], 'replace');
    assert_same('error', $results['site_check']['status'] ?? null, 'importer reports busy as error');
    unset($db->rows[$mutex]);

    // ------------------------------------------------------------ fix round 1

    // Gate B pass 1 (spec-12): the stored-profile fallback is read inside the
    // section — a writer that saved meanwhile is not overwritten with a stale value.
    $reset();
    Settings::save(['profile' => 'staging']);
    $db->on_acquire = static function () use ($db, $opt): void {
        $db->rows[$opt] = serialize(['profile' => 'live']);
    };
    Settings::save(['profile' => 'bogus']);
    assert_same('live', Settings::get()['profile'], 'invalid profile keeps what is stored when the section is entered, not a value read before');

    // An invalid submitted profile keeps the stored one.
    $reset();
    Settings::save(['profile' => 'private']);
    Settings::save(['profile' => 'bogus']);
    assert_same('private', Settings::get()['profile'], 'invalid profile keeps stored private');
    assert_same('private', Settings::sanitize(['profile' => ['x']])['profile'], 'array profile keeps stored private');
    $reset();
    assert_same('live', Settings::sanitize(['profile' => 'bogus'])['profile'], 'invalid profile with nothing stored -> live');

    // Import message reports what survived sanitising.
    $reset();
    $r = Settings::import_fields(['indexability_paths' => ['/ok/', '/x.php', 'https://x'], 'sitemap_allow' => ['attachments', 'bogus']], 'replace');
    assert_same('success', $r['status'], 'partial import still success');
    assert_true(str_contains($r['message'], '2 entries imported') && str_contains($r['message'], '3 rejected'), 'message counts surviving and rejected: ' . $r['message']);
    $r = Settings::import_fields(['indexability_paths' => ['/a/', '/b/']], 'replace');
    assert_true(str_contains($r['message'], '2 entries imported') && !str_contains($r['message'], 'rejected'), 'clean import message: ' . $r['message']);
    $r = Settings::import_fields(['indexability_paths' => ['/x.php']], 'replace');
    assert_true(str_contains($r['message'], '0 entries imported') && str_contains($r['message'], '1 rejected'), 'emptied field says so: ' . $r['message']);
    assert_same([], Settings::get()['indexability_paths'], 'replace with only invalid paths empties the list');

    // Gate B pass 4: the message counts what was stored after merge and the 5-path cap.
    $reset();
    Settings::import_fields(['indexability_paths' => ['/1/', '/2/', '/3/', '/4/', '/5/']], 'replace');
    $r = Settings::import_fields(['indexability_paths' => ['/six/']], 'merge');
    assert_same(['/1/', '/2/', '/3/', '/4/', '/5/'], Settings::get()['indexability_paths'], 'fixture: the full list stays');
    assert_true(str_contains($r['message'], '0 entries imported') && str_contains($r['message'], '1 not stored'), 'capacity omission reported separately: ' . $r['message']);

    // Gate B pass 5: capacity is counted on the full incoming list, never as "invalid".
    $reset();
    $r = Settings::import_fields(['indexability_paths' => ['/1/', '/2/', '/3/', '/4/', '/5/', '/6/']], 'replace');
    assert_same(5, count(Settings::get()['indexability_paths']), 'fixture: five stored');
    assert_true(str_contains($r['message'], '5 entries imported') && str_contains($r['message'], '1 not stored') && !str_contains($r['message'], 'rejected'), 'replace with six valid paths: ' . $r['message']);
    $reset();
    Settings::import_fields(['indexability_paths' => ['/1/', '/2/']], 'replace');
    $r = Settings::import_fields(['indexability_paths' => ['/3/', '/4/', '/5/', '/6/', '/7/', 'bad']], 'merge');
    assert_true(str_contains($r['message'], '3 entries imported') && str_contains($r['message'], '1 rejected') && str_contains($r['message'], '2 not stored'), 'merge over the cap: ' . $r['message']);

    // Gate B pass 4: a busy page-load cleanup says so.
    ob_start();
    AdminPage::render_cleanup(['busy' => true, 'deleted' => 0, 'kept' => [], 'unregistered' => []]);
    assert_true(str_contains((string) ob_get_clean(), 'busy'), 'busy cleanup → notice');

    // handle_save: the only write path.
    $run_save = static function (bool $nonce, bool $theme, bool $manage, string $method, array $post) use ($db) {
        $GLOBALS['t_nonce_ok'] = $nonce;
        $GLOBALS['t_theme_access'] = $theme;
        $GLOBALS['t_caps'] = $manage ? ['manage_options'] : [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        try {
            AdminPage::handle_save();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return 'returned';
    };
    $post = ['profile' => 'staging', 'indexability_paths' => ['/h/'], 'sitemap_allow' => ['authors'], 'fallback_theme' => 'mytheme'];

    $reset();
    assert_same('nonce-failed', $run_save(false, true, true, 'POST', $post), 'failed nonce stops the request');
    assert_true(!isset($db->rows[$opt]), 'failed nonce wrote nothing');
    assert_same('die:403', $run_save(true, true, false, 'POST', $post), 'theme access without manage_options -> 403');
    assert_true(!isset($db->rows[$opt]), '403 wrote nothing');
    assert_same('die:403', $run_save(true, false, true, 'POST', $post), 'manage_options without theme access -> 403');
    assert_same('die:405', $run_save(true, true, true, 'GET', $post), 'GET -> 405');
    assert_true(!isset($db->rows[$opt]), '405 wrote nothing');
    assert_same('redirect:https://example.test/wp-admin/tools.php?page=sfx-site-check&sfx_sc=saved#einstellungen', $run_save(true, true, true, 'POST', $post), 'valid POST redirects with saved, back to the Einstellungen tab');
    $got = Settings::get();
    assert_same(['staging', ['/h/'], ['authors'], 'mytheme'], [$got['profile'], $got['indexability_paths'], $got['sitemap_allow'], $got['fallback_theme']], 'valid POST stored all four controls');
    $before = $db->rows[$opt];
    $db->rows[$mutex] = 'otherprocess:' . (int) FakeClock::$now;
    assert_true(str_ends_with($run_save(true, true, true, 'POST', ['profile' => 'private'] + $post), 'sfx_sc=busy#einstellungen'), 'held mutex redirects with busy');
    assert_same($before, $db->rows[$opt], 'busy POST wrote nothing');
    unset($db->rows[$mutex]);

    echo "site-check-settings: PASS\n";
}
