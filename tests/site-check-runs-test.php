<?php

declare(strict_types=1);

/**
 * SiteCheck manual run (spec "Manual run", "Storage" → Writes, "Uploads
 * probe" consent, "Manual items") — Runs and its AJAX endpoints.
 *
 * A stubbed $wpdb that understands the Mutex statements (as in
 * site-check-mutex-test.php and site-check-probe-test.php), real files in a
 * temporary uploads folder for the probe, and a stubbed HTTP layer. Every
 * fixture lives under one temporary tree, removed by the single teardown
 * declared below before the first fixture.
 *
 * Run: php tests/site-check-runs-test.php
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

namespace {
    use SFX\SiteCheck\Catalogue;
    use SFX\SiteCheck\Checks\OutsideChecks;
    use SFX\SiteCheck\Options;
    use SFX\SiteCheck\OutsideEndpoints;
    use SFX\SiteCheck\Purge;
    use SFX\SiteCheck\RunContext;
    use SFX\SiteCheck\Runs;
    use SFX\SiteCheck\Status;

    final class FakeClock
    {
        public static float $now = 0.0;
    }
    FakeClock::$now = (float) \time();

    $tmp = sys_get_temp_dir() . '/sfx-site-check-runs-' . bin2hex(\random_bytes(6));

    function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    // One teardown, declared before the first fixture is created.
    register_shutdown_function(static function () use ($tmp): void {
        remove_tree($tmp);
    });

    if (!mkdir($tmp, 0700)) {
        fwrite(STDERR, "error: cannot create {$tmp}\n");
        exit(2);
    }
    $tmp = realpath($tmp);
    if ($tmp === false || strpos($tmp, realpath(sys_get_temp_dir())) !== 0) {
        fwrite(STDERR, "error: temp dir is not under the system temp dir\n");
        exit(2);
    }
    foreach (['/site/wp-content/plugins', '/site/wp-content/uploads'] as $dir) {
        mkdir($tmp . $dir, 0700, true);
    }
    file_put_contents($tmp . '/site/wp-config.php', "<?php\n");

    define('ABSPATH', $tmp . '/site/');
    define('WP_CONTENT_DIR', $tmp . '/site/wp-content');
    define('WP_PLUGIN_DIR', $tmp . '/site/wp-content/plugins');
    $probe_dir = WP_CONTENT_DIR . '/uploads/sfx-site-check';

    // ------------------------------------------------------------ $wpdb double

    final class FakeWpdb
    {
        public string $options = 'wp_options';
        public string $base_prefix = 'wp_';
        /** @var array<string,string> */
        public array $rows = [];
        /** @var array<string,string> */
        public array $autoload = [];
        /** @var callable|null what another process does just before the next mutex acquire */
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
                [$name, $value] = $a;
                if (isset($this->rows[$name])) {
                    return 0;
                }
                $this->rows[$name] = $value;
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
                if (!array_key_exists($option, $this->rows)) {
                    $this->rows[$option] = $value;
                    $this->autoload[$option] = $autoload;
                    return 1;
                }
                if ($this->rows[$option] === $update_value && ($this->autoload[$option] ?? null) === $update_autoload) {
                    return 0;
                }
                $this->rows[$option] = $update_value;
                $this->autoload[$option] = $update_autoload;
                return 2;
            }
            if (str_starts_with($q, 'DELETE o')) {
                [$mutex, $held, $option] = $a;
                if (($this->rows[$mutex] ?? null) !== $held || !array_key_exists($option, $this->rows)) {
                    return 0;
                }
                unset($this->rows[$option]);
                return 1;
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
            if (str_starts_with($q, 'SELECT COUNT(*)') && count($a) === 2) {
                [$mutex, $held] = $a;
                return (($this->rows[$mutex] ?? null) === $held) ? '1' : '0';
            }
            throw new RuntimeException('FakeWpdb: unexpected get_var ' . $q);
        }
    }

    $GLOBALS['wpdb'] = new FakeWpdb();
    $db = $GLOBALS['wpdb'];

    // ---------------------------------------------------------------- stubs

    $GLOBALS['stub'] = ['access' => true, 'nonce' => true, 'user' => 7, 'answer' => null, 'requests' => []];
    $GLOBALS['cache'] = [];

    // Test-only: declares the theme's access gate in its own namespace from this one-namespace file; fixed source, no input.
    eval('namespace SFX; class AccessControl { public static function can_access_theme_settings(): bool { return !empty($GLOBALS["stub"]["access"]); } }');

    function __($text, $domain = null)
    {
        return $text;
    }

    function maybe_serialize($data)
    {
        return (is_array($data) || is_object($data)) ? serialize($data) : $data;
    }

    function maybe_unserialize($data)
    {
        if (!is_string($data)) {
            return $data;
        }
        $value = @unserialize($data);
        return ($value !== false || $data === 'b:0;') ? $value : $data;
    }

    function get_option($name, $default = false)
    {
        $rows = $GLOBALS['wpdb']->rows;
        // The site's date settings (Task 11): a non-UTC zone and a non-default format.
        $site = ['date_format' => 'd.m.Y', 'time_format' => 'H:i', 'timezone_string' => 'Europe/Berlin'];
        if (!array_key_exists($name, $rows) && isset($site[$name])) {
            return $site[$name];
        }
        return array_key_exists($name, $rows) ? maybe_unserialize($rows[$name]) : $default;
    }

    function wp_timezone()
    {
        return new DateTimeZone((string) get_option('timezone_string', '') ?: 'UTC');
    }

    function wp_date($format, $timestamp = null, $timezone = null)
    {
        return (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone ?? wp_timezone())->format($format);
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

    function wp_clear_scheduled_hook($hook, $args = [])
    {
        return 0;
    }

    function wp_salt($scheme = 'auth')
    {
        return 'test-salt';
    }

    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }

    function get_current_user_id()
    {
        return $GLOBALS['stub']['user'];
    }

    function current_user_can($cap)
    {
        return !empty($GLOBALS['stub']['access']);
    }

    function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
    {
        return !empty($GLOBALS['stub']['nonce']) && $action === 'sfx_site_check' && $query_arg === '_ajax_nonce' && $stop === false ? 1 : false;
    }

    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }

    function wp_normalize_path($path)
    {
        $path = str_replace('\\', '/', $path);
        return preg_replace('|(?<=.)/+|', '/', $path);
    }

    function trailingslashit($value)
    {
        return untrailingslashit($value) . '/';
    }

    function untrailingslashit($value)
    {
        return rtrim($value, '/\\');
    }

    function home_url($path = '')
    {
        return 'https://ex.test/' . ltrim($path, '/');
    }

    function site_url($path = '')
    {
        return 'https://ex.test/' . ltrim($path, '/');
    }

    function content_url($path = '')
    {
        return 'https://ex.test/wp-content';
    }

    function plugins_url($path = '', $plugin = '')
    {
        return 'https://ex.test/wp-content/plugins';
    }

    function wp_get_upload_dir()
    {
        return ['basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://ex.test/wp-content/uploads', 'error' => false];
    }

    final class WP_Error
    {
        private $message;

        public function __construct($code = '', $message = '')
        {
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }

    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }

    function wp_remote_get($url, $args = [])
    {
        $GLOBALS['stub']['requests'][] = $url;
        $answer = $GLOBALS['stub']['answer'];
        [$status, $body, $headers] = ($answer === null ? [404, 'Not found', []] : $answer($url)) + [2 => []];
        return ['response' => ['code' => $status], 'headers' => array_change_key_case($headers), 'body' => $body];
    }

    function wp_remote_retrieve_response_code($r)
    {
        return $r['response']['code'];
    }

    function wp_remote_retrieve_header($r, $name)
    {
        return $r['headers'][strtolower($name)] ?? '';
    }

    function wp_remote_retrieve_body($r)
    {
        return $r['body'];
    }

    spl_autoload_register(static function (string $class): void {
        if (strpos($class, 'SFX\\SiteCheck\\Checks\\') === 0) {
            require_once __DIR__ . '/../inc/SiteCheck/Checks/' . substr($class, 21) . '.php';
        } elseif (strpos($class, 'SFX\\SiteCheck\\') === 0) {
            require_once __DIR__ . '/../inc/SiteCheck/' . substr($class, 14) . '.php';
        }
    });

    // -------------------------------------------------------------- helpers

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

    function manual(): array
    {
        $value = get_option(Options::name('manual'), []);
        return is_array($value) ? $value : [];
    }

    function set_row(string $key, $value): void
    {
        $GLOBALS['wpdb']->rows[Options::name($key)] = maybe_serialize($value);
    }

    function post(array $fields): void
    {
        $_POST = $fields + ['_ajax_nonce' => 'n'];
    }

    function start(bool $probe = false): string
    {
        post(['probe' => $probe ? '1' : '0']);
        [$code, $body] = Runs::start_request();
        assert_same(200, $code, 'start answers 200');
        return $body['data']['run'];
    }

    /** @return array{graded:array, observation:array, token:string} */
    function server(string $run, string $check): array
    {
        post(['run' => $run, 'check' => $check]);
        [$code, $body] = Runs::server_request();
        assert_same(200, $code, "server check {$check} answers 200");
        return $body['data'];
    }

    /** @return array{0:int, 1:array} */
    function save(string $run, array $results): array
    {
        post(['run' => $run, 'results' => json_encode($results)]);
        return Runs::save_request();
    }

    function saved(): ?array
    {
        return Runs::last();
    }

    // =========================================================== start

    set_row('settings', ['profile' => 'live', 'indexability_paths' => ['/ok/', '/bad.php', 'nope'], 'sitemap_allow' => ['authors'], 'fallback_theme' => 'twentytwentyfive']);
    $run1 = start();
    $issued = manual()['issued'];
    assert_same($run1, $issued['run'], 'start writes the issued run');
    assert_true(preg_match('/^[0-9a-f]{32}$/', $run1) === 1, 'run ID is 32 random hex');
    assert_same(['/ok/'], $issued['indexability_paths'], 'invalid stored paths never reach the issued run');
    assert_same(false, $issued['probe'], 'probe consent off by default');
    assert_same(array_keys(Catalogue::all()), $issued['checks'], 'the issued run covers the catalogue');
    assert_same(7, $issued['user'], 'issued run records the user');
    assert_true(!array_key_exists('saved', manual()), 'nothing saved yet');
    $ctx = Runs::context($run1);
    assert_same(['/ok/'], $ctx->indexability_paths(), 'RunContext built from the issued run');
    assert_same(['authors'], $ctx->sitemap_allow(), 'sitemap allow list in the snapshot');
    assert_same('twentytwentyfive', $ctx->fallback_theme(), 'fallback theme in the snapshot');
    assert_same(null, Runs::context('other'), 'a run that is not issued has no context');
    assert_same($ctx->profile(), OutsideEndpoints::context($run1)->profile(), 'OutsideEndpoints reads the same issued run');
    post(['probe' => '0']);
    $checks = Runs::start_request()[1]['data']['checks'];
    assert_same('logs_public', $checks[0]['id'], 'start returns the checks with their IDs');
    assert_same('S+B', $checks[0]['how'], 'start returns how each check runs');
    assert_same('browser', $checks[0]['perspective'], 'start returns each check\'s perspective');
    assert_same(['https' => 'loopback'], array_intersect_key(array_column($checks, 'perspective', 'id'), ['https' => 1]), 'https runs by Loopback');

    // =========================================================== first save on a fresh site
    $db->rows = [];
    $run = start();
    $s = server($run, 'allow_url_include');
    assert_same(Status::GREEN, $s['graded']['status'], 'allow_url_include off → Grün');
    assert_true(is_string($s['token']) && $s['token'] !== '', 'server check returns a token');
    [$code, $body] = save($run, ['allow_url_include' => ['token' => $s['token']]]);
    assert_same(200, $code, 'first save on a fresh site → stored');
    $last = saved();
    assert_same($run, $last['run'], 'saved run ID');
    assert_same(7, $last['user'], 'saved user');
    assert_same('live', $last['profile'], 'saved profile');
    assert_same((int) FakeClock::$now, $last['date'], 'saved date');
    assert_same($s['graded'], $last['results']['allow_url_include'], 'stored grade = the grade of the returned facts');
    assert_same($last + ['date_display' => wp_date('d.m.Y H:i', $last['date'])], $body['data']['last'], 'save answers with the saved run and its date formatted by the server');
    assert_same('11.10.2026 00:30', Runs::date_display(gmmktime(22, 30, 0, 10, 10, 2026)), 'date_display: site zone and format, also near midnight');
    assert_true(!isset(manual()['issued']), 'the save consumes the issued run');
    [$code] = save($run, ['allow_url_include' => ['token' => $s['token']]]);
    assert_same(409, $code, 'a second save of a consumed run → refused');

    // Re-graded from the facts, not from the browser's grade.
    $run = start();
    $db->rows['blog_public'] = '0';
    $s = server($run, 'search_visibility');
    unset($db->rows['blog_public']);
    assert_same(Status::RED, $s['graded']['status'], 'discouraging search engines under Live → Rot');
    save($run, ['search_visibility' => ['token' => $s['token'], 'graded' => ['status' => 'green']]]);
    assert_same(Status::RED, saved()['results']['search_visibility']['status'], 'a browser-sent grade is ignored; the sealed facts decide');

    // =========================================================== start leaves the saved run untouched
    $before = saved();
    $run2 = start(true);
    assert_same($before, saved(), 'start leaves the saved results untouched');
    assert_same(true, manual()['issued']['probe'], 'consent stored in the issued run');

    // =========================================================== probe consent
    remove_tree($probe_dir);
    $GLOBALS['stub']['requests'] = [];
    $run = start(false);
    $p = server($run, 'php_in_uploads');
    assert_true(!file_exists($probe_dir), 'default start: probe creates no folder');
    assert_same([], $GLOBALS['stub']['requests'], 'default start: no request');
    assert_same('consent', $p['observation']['step'], 'default start: not requested');
    assert_same(Status::UNKNOWN, $p['graded']['status'], 'not requested → Nicht prüfbar');

    // Superseded consent does not carry over.
    $consented = start(true);
    $plain = start(false);
    post(['run' => $consented, 'check' => 'php_in_uploads']);
    assert_same(409, Runs::server_request()[0], 'the superseded run with consent → refused');
    $p = server($plain, 'php_in_uploads');
    assert_same('consent', $p['observation']['step'], 'the new run without consent → not requested');
    assert_true(!file_exists($probe_dir), 'superseded consent creates nothing');

    // A new start or a purge between the consent check and the probe's create: nothing is created.
    foreach (['new start' => static function (): void { start(false); }, 'purge' => static function (): void { Purge::run(); }] as $what => $race) {
        $run = start(true);
        $GLOBALS['stub']['requests'] = [];
        $db->on_acquire = $race;
        $p = server($run, 'php_in_uploads');
        assert_same('run', $p['observation']['step'], "{$what} before create → the probe stops at the run check");
        assert_true(!file_exists($probe_dir), "{$what} before create → no folder, no file");
        assert_same([], $GLOBALS['stub']['requests'], "{$what} before create → no request");
        assert_same(Status::UNKNOWN, $p['graded']['status'], "{$what} before create → Nicht prüfbar");
        assert_same(null, get_option(Options::name('probes'), null), "{$what} before create → no probe entry");
    }

    // Checked start → the probe runs once; save does not run it again.
    $run = start(true);
    $GLOBALS['stub']['answer'] = static fn(string $url): array => [403, 'Forbidden'];
    $GLOBALS['stub']['requests'] = [];
    $p = server($run, 'php_in_uploads');
    $probe_requests = static fn(): array => array_values(array_filter($GLOBALS['stub']['requests'], static fn($u) => strpos((string) (is_array($u) ? ($u['url'] ?? '') : $u), '/sfx-site-check-missing-') === false));
    assert_same(1, count($probe_requests()), 'checked start: the probe fetched once (plus its comparison URL, rule 2)');
    assert_same('', $p['observation']['step'], 'checked start: every probe step ran');
    assert_same(Status::GREEN, $p['graded']['status'], 'probe answering 403 → Grün');
    assert_true(!file_exists($probe_dir), 'probe file and its then empty folder removed in the same request');
    [$code] = save($run, ['php_in_uploads' => ['token' => $p['token']]]);
    assert_same(200, $code, 'probe run saved');
    assert_same(1, count($probe_requests()), 'save never re-runs the probe');
    assert_same($p['graded'], saved()['results']['php_in_uploads'], 'stored probe grade = its own returned facts');
    $GLOBALS['stub']['answer'] = null;

    // =========================================================== browser observe → save
    $run = start();
    post(['run' => $run, 'check' => 'security_headers']);
    [$code, $body] = OutsideEndpoints::targets_request();
    assert_same(200, $code, 'targets for the issued run');
    $observations = [];
    foreach ($body['data']['targets'] as $t) {
        foreach ([$t['url'] . '?sfxcb=x1', $t['url']] as $url) {
            $observations[] = [
                'url' => $url, 'status' => 200, 'type' => 'basic',
                'headers' => ['content-type' => 'text/html', 'x-content-type-options' => 'nosniff'],
                'body' => '<html>BODYMARKER-secret</html>', 'head_hex' => bin2hex('<html>'), 'truncated' => false, 'redirect' => false, 'error' => '',
            ];
        }
    }
    post(['run' => $run, 'check' => 'security_headers', 'observations' => json_encode($observations)]);
    [$code, $body] = OutsideEndpoints::observe_request();
    assert_same(200, $code, 'observe answers 200');
    $token = $body['data']['token'];
    assert_same($body['data']['facts'], Runs::unseal($run, 'security_headers', $token), 'the token seals exactly the returned facts');
    assert_true(strpos(json_encode(Runs::unseal($run, 'security_headers', $token)), 'BODYMARKER') === false, 'the save carries facts, no body text');
    save($run, ['security_headers' => ['token' => $token]]);
    assert_same($body['data']['graded'], saved()['results']['security_headers'], 'stored browser grade re-graded from the facts');
    assert_true(strpos(implode('', $db->rows), 'BODYMARKER') === false, 'nothing of the raw observation is stored');

    // =========================================================== save validation
    $run = start();
    $s = server($run, 'allow_url_include');
    $t = server($run, 'table_prefix');
    $other = start();
    $foreign = server($other, 'allow_url_include')['token'];
    $run = start();
    $valid = server($run, 'allow_url_include')['token'];
    [$payload, $mac] = explode('.', server($run, 'table_prefix')['token']);
    $tampered_facts = rtrim(strtr(base64_encode(json_encode(['prefix' => 'xyz_'])), '+/', '-_'), '=');
    [$code] = save($run, [
        'table_prefix'      => ['token' => $tampered_facts . '.' . $mac],
        'allow_url_include' => ['token' => $foreign],
        'file_editor'       => ['token' => $valid],
        'admin_email'       => 'garbage',
        'permalinks'        => ['error' => 'request_failed'],
        'auto_updates'      => ['error' => 'timeout'],
        'php_version'       => ['error' => '<script>'],
        'not_a_check'       => ['token' => $valid],
    ]);
    assert_same(200, $code, 'a save with bad entries is still stored');
    $r = saved()['results'];
    $note = static fn(string $id): string => $r[$id]['status'] . ' / ' . $r[$id]['note'];
    $unverified = Status::UNKNOWN . ' / The result could not be verified and was not used.';
    assert_same($unverified, $note('table_prefix'), 'altered facts → Nicht prüfbar');
    assert_same($unverified, $note('allow_url_include'), 'another run\'s token for the same check → Nicht prüfbar');
    assert_same($unverified, $note('file_editor'), 'another check\'s token → Nicht prüfbar');
    assert_same($unverified, $note('admin_email'), 'malformed entry → Nicht prüfbar');
    assert_same(Status::UNKNOWN . ' / Request failed.', $note('permalinks'), 'failed request → Nicht prüfbar "Anfrage fehlgeschlagen"');
    assert_same(Status::UNKNOWN . ' / Request failed.', $note('auto_updates'), 'timeout → Nicht prüfbar "Anfrage fehlgeschlagen"');
    assert_same($unverified, $note('php_version'), 'an unknown failure kind is malformed');
    assert_same(Status::UNKNOWN . ' / No result was sent for this check.', $note('logs_public'), 'missing check → Nicht prüfbar');
    assert_true(!isset($r['not_a_check']), 'an ID not issued is not stored');
    assert_same(array_keys(Catalogue::all()), array_keys($r), 'every issued check is stored, nothing else');
    assert_same('server', $r['permalinks']['perspective'], 'Nicht prüfbar keeps the perspective (server)');
    assert_same('browser', $r['logs_public']['perspective'], 'Nicht prüfbar keeps the perspective (browser)');
    foreach (['https', 'xmlrpc', 'indexability', 'sitemap', 'sitemap_entries', 'php_in_uploads'] as $id) {
        assert_same('loopback', $r[$id]['perspective'], "Nicht prüfbar keeps the perspective (loopback: {$id})");
        assert_same('loopback', Catalogue::perspective($id), "catalogue names {$id} as Loopback");
    }
    assert_same('browser', $r['security_headers']['perspective'], 'Nicht prüfbar keeps the perspective (browser: security_headers)');
    post(['run' => $run, 'results' => 'not json']);
    assert_same(400, Runs::save_request()[0], 'unreadable results → refused');

    // =========================================================== save size
    $previous = saved();
    $run = start();
    $plugins = [];
    for ($i = 0; $i < 150; $i++) {
        $plugins[] = ['file' => "plugin-{$i}/plugin-{$i}.php", 'name' => str_repeat('n', 400) . $i];
    }
    $ctx = Runs::context($run);
    [$code] = save($run, ['inactive_plugins' => ['token' => Runs::seal($run, 'inactive_plugins', ['inactive' => $plugins])]]);
    assert_same(200, $code, '150 targets → saved');
    $bytes = strlen($db->rows[Options::name('manual')]);
    assert_true($bytes < Runs::MAX_BYTES, "150 targets save under 512 KB ({$bytes} bytes)");
    assert_same(51, count(saved()['results']['inactive_plugins']['findings']), '150 findings → 50 kept and one overflow line');
    assert_true(strlen(saved()['results']['inactive_plugins']['findings'][0]['label']) <= 304, 'stored labels are size-limited');

    // Too large even after compaction: several checks at their cap whose IDs carry ~2.5 KB target paths.
    $previous = saved();
    $run = start();
    $long = static fn(string $prefix, int $i): string => $prefix . str_repeat('d', 2500) . sprintf('-%03d', $i);
    $exposure = static function (string $ext) use ($long): array {
        $targets = [];
        for ($i = 0; $i < 60; $i++) {
            $targets[] = ['target' => $long('/', $i) . $ext, 'path' => '/x', 'url' => 'https://ex.test/x', 'disk' => 'present', 'size' => 1, 'reason' => '', 'kind' => 'sql', 'outside' => 'unreachable'];
        }
        return ['targets' => $targets];
    };
    $items = [];
    $plugins = [];
    for ($i = 0; $i < 60; $i++) {
        $items[] = ['target' => $long('/wp-content/uploads/', $i) . '.php', 'state' => 'other', 'signature' => ''];
        $plugins[] = ['file' => $long('p', $i) . '/p.php', 'name' => 'Plugin ' . $i];
    }
    $facts = [
        'backups_public' => $exposure('.sql'),
        'logs_public' => $exposure('.log'),
        'vcs_env' => $exposure('.env'),
        'php_files_uploads' => ['reason' => '', 'items' => $items, 'unreadable' => [], 'limit' => null],
        'inactive_plugins' => ['inactive' => $plugins],
    ];
    $tokens = [];
    foreach ($facts as $check => $f) {
        $tokens[$check] = ['token' => Runs::seal($run, $check, $f)];
    }
    [$code, $body] = save($run, $tokens);
    assert_same(413, $code, 'a run too large even after compaction → refused with 413');
    assert_same('The results are too large to be saved. The previous results stay.', $body['data']['message'] ?? null, 'the refusal says the results are too large and the previous ones stay');
    assert_same($previous, saved(), 'too large: the previous saved run is kept');
    assert_same($run, manual()['issued']['run'] ?? null, 'too large: the issued run stays issued');

    // =========================================================== compaction (Task 10): manual-run storage and display only
    /** @return array{0:array, 1:array} the full grade and the stored (compacted) one, after a save that must succeed */
    $compacted = static function (string $check, array $facts) use ($db): array {
        $run = start();
        $full = Catalogue::grade($check, $facts, Runs::context($run));
        [$code] = save($run, [$check => ['token' => Runs::seal($run, $check, $facts)]]);
        assert_same(200, $code, "{$check}: a large result saves");
        $bytes = strlen($db->rows[Options::name('manual')]);
        assert_true($bytes < Runs::MAX_BYTES, "{$check}: the saved run stays within Runs::MAX_BYTES ({$bytes} bytes)");
        return [$full, saved()['results'][$check]];
    };
    $statuses_of = static fn(array $graded): array => array_column($graded['findings'], 'status', 'id');

    // 3000 files, the one Rot sorting last by path.
    $targets = [];
    for ($i = 0; $i < 3000; $i++) {
        $targets[] = ['target' => sprintf('/backups/a%04d.sql', $i), 'path' => '/x', 'url' => 'https://ex.test/b', 'disk' => 'present', 'size' => 1, 'reason' => '', 'kind' => 'sql', 'outside' => 'unreachable'];
    }
    $targets[] = ['target' => '/backups/zzz.sql', 'path' => '/x', 'url' => 'https://ex.test/z', 'disk' => 'present', 'size' => 1, 'reason' => '', 'kind' => 'sql', 'outside' => 'signature', 'outside_signature' => 'sql'];
    [$full, $stored] = $compacted('backups_public', ['targets' => $targets]);
    assert_same(3001, count($full['findings']), 'Catalogue::grade stays complete (plan 2\'s monitor reads it)');
    assert_same(Status::RED, $full['status'], 'fixture: the full grade is Rot');
    assert_same($full['status'], $stored['status'], '3000 files: the check grades the same (status before compaction)');
    assert_same(51, count($stored['findings']), '3000 files: 50 file findings and one overflow line');
    assert_same(['backups_public:/backups/zzz.sql', Status::RED], [$stored['findings'][0]['id'], $stored['findings'][0]['status']], '3000 files: the Rot beyond the cap is kept, first');
    $more = $stored['findings'][50];
    assert_same(['backups_public:more-files', Status::YELLOW], [$more['id'], $more['status']], '3000 files: the overflow line carries the worst dropped status');
    assert_true(str_contains($more['label'], '2951 more'), '3000 files: "… und N weitere" (' . $more['label'] . ')');
    assert_same('backups_public:/backups/a0000.sql', $stored['findings'][1]['id'], '3000 files: then by path');

    // A shell pattern whose path sorts last among 60 Gelb files is kept.
    $items = [];
    for ($i = 0; $i < 59; $i++) {
        $items[] = ['target' => sprintf('/wp-content/uploads/a%02d.php', $i), 'state' => 'other', 'signature' => ''];
    }
    $items[] = ['target' => '/wp-content/uploads/zzz.php', 'state' => 'shell', 'signature' => 'eval_encoded'];
    [$full, $stored] = $compacted('php_files_uploads', ['reason' => '', 'items' => $items, 'unreadable' => [], 'limit' => null]);
    assert_same($full['status'], $stored['status'], '60 Gelb files: graded the same');
    assert_same('php_files_uploads:/wp-content/uploads/zzz.php', $stored['findings'][0]['id'], '60 Gelb files: the shell pattern (priority) is kept first');
    assert_same(51, count($stored['findings']), '60 Gelb files: 50 kept and one overflow line');
    assert_true(str_contains($stored['findings'][50]['label'], '10 more'), '60 Gelb files: ten more');

    // Coverage-dominated: 1800 unreadable folders.
    $folders = [];
    for ($i = 0; $i < 1800; $i++) {
        $folders[] = sprintf('/wp-content/uploads/locked-%04d', $i);
    }
    [$full, $stored] = $compacted('php_files_uploads', ['reason' => '', 'items' => [['target' => '/wp-content/uploads/a.php', 'state' => 'other', 'signature' => '']], 'unreadable' => $folders, 'limit' => null]);
    assert_same($full['status'], $stored['status'], '1800 unreadable folders: graded the same');
    $ids = array_column($stored['findings'], 'id');
    assert_same(1 + 20 + 1, count($ids), '1800 unreadable folders: the file, 20 coverage findings and one overflow line');
    assert_true(in_array('php_files_uploads:/wp-content/uploads/a.php', $ids, true), '1800 unreadable folders: the file finding is kept');
    assert_same(Status::UNKNOWN, $statuses_of($stored)['php_files_uploads:more-coverage'] ?? null, '1800 unreadable folders: one overflow line for coverage');

    // A small result is not changed.
    [$full, $stored] = $compacted('php_files_uploads', ['reason' => '', 'items' => array_slice($items, 50), 'unreadable' => ['/wp-content/uploads/locked'], 'limit' => null]);
    assert_same(array_column($full['findings'], 'id'), array_column($stored['findings'], 'id'), 'under the caps: every finding kept, in the check\'s own order');

    // Incoming rows are compacted too (shown while the run is in progress, and kept after a refused save).
    $uploads_dir = WP_CONTENT_DIR . '/uploads';
    for ($i = 0; $i < 60; $i++) {
        file_put_contents(sprintf('%s/a%02d.php', $uploads_dir, $i), '<?php echo 1;');
    }
    file_put_contents($uploads_dir . '/zzz.php', "<?php eval(base64_decode('eA=='));");
    // Unreadable folders cannot be staged as root (root reads them anyway).
    $as_root = function_exists('posix_geteuid') && posix_geteuid() === 0;
    for ($i = 0; $i < ($as_root ? 0 : 30); $i++) {
        mkdir(sprintf('%s/locked-%02d', $uploads_dir, $i), 0000);
    }
    if ($as_root) {
        echo "skip: running as root, unreadable folders cannot be staged\n";
    }
    $run = start();
    $s = server($run, 'php_files_uploads');
    $ids = array_column($s['graded']['findings'], 'id');
    assert_same(Status::YELLOW, $s['graded']['status'], 'server: incoming row keeps its status');
    assert_same('php_files_uploads:/wp-content/uploads/zzz.php', $ids[0], 'server: incoming row keeps the shell pattern first');
    assert_same(50 + 1 + ($as_root ? 0 : 20 + 1), count($ids), 'server: incoming row compacted (files and coverage)');
    start();
    [$code] = save($run, ['php_files_uploads' => ['token' => $s['token']]]);
    assert_same(409, $code, 'fixture: the save is refused; the page keeps showing the compacted incoming row');
    $full = Catalogue::grade('php_files_uploads', Runs::unseal($run, 'php_files_uploads', $s['token']), Runs::context(start()));
    assert_same(61 + ($as_root ? 0 : 30), count($full['findings']), 'server: the sealed facts stay complete');
    remove_tree($uploads_dir);
    mkdir($uploads_dir, 0700);

    $backups = WP_CONTENT_DIR . '/backups';
    mkdir($backups, 0700);
    for ($i = 0; $i < 70; $i++) {
        file_put_contents(sprintf('%s/b%02d.sql', $backups, $i), '-- MySQL dump');
    }
    $run = start();
    post(['run' => $run, 'check' => 'backups_public']);
    $plan = OutsideEndpoints::targets_request()[1]['data'];
    $observations = [];
    $seen = static fn(string $url, int $status): array => ['url' => $url, 'status' => $status, 'type' => 'basic', 'headers' => [], 'body' => 'x', 'head_hex' => '', 'truncated' => false, 'redirect' => false, 'error' => ''];
    foreach ($plan['targets'] as $t) {
        $observations[] = $seen($t['url'] . '?sfxcb=x1', 403);
        $observations[] = $seen($t['url'], 403);
    }
    $observations[] = $seen($plan['comparison'], 404);
    post(['run' => $run, 'check' => 'backups_public', 'observations' => json_encode($observations)]);
    [$code, $body] = OutsideEndpoints::observe_request();
    assert_same(200, $code, 'observe: 200');
    assert_same(Status::YELLOW, $body['data']['graded']['status'], 'observe: incoming row keeps its status');
    assert_same(51, count($body['data']['graded']['findings']), 'observe: incoming row compacted');
    assert_same(Status::YELLOW, $body['data']['graded']['findings'][0]['status'], 'observe: worst status first');
    remove_tree($backups);

    // =========================================================== two starts
    $first = start();
    $a = server($first, 'allow_url_include')['token'];
    $second = start();
    $b = server($second, 'allow_url_include')['token'];
    [$code] = save($first, ['allow_url_include' => ['token' => $a]]);
    assert_same(409, $code, 'save of the first of two starts → refused');
    assert_same('superseded', Runs::save($first, []), 'refused as superseded');
    [$code] = save($second, ['allow_url_include' => ['token' => $b]]);
    assert_same(200, $code, 'save of the second → stored');
    assert_same($second, saved()['run'], 'the second run is the saved one');

    // =========================================================== purge
    $previous = saved();
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    $purged = Purge::run();
    assert_same(false, $purged['busy'], 'purge ran');
    assert_same('no_run', Runs::save($run, ['allow_url_include' => ['token' => $token]]), 'save after purge → refused as no issued run');
    assert_same([], $db->rows, 'nothing written after purge');

    // State changes after save's first look but before it enters the section: the section re-checks.
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    $newer = null;
    $db->on_acquire = static function () use (&$newer): void {
        $newer = start();
    };
    assert_same('superseded', Runs::save($run, ['allow_url_include' => ['token' => $token]]), 'a run issued while save waited for the section → refused');
    assert_same($newer, manual()['issued']['run'], 'the newer issued run is untouched');
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    $db->on_acquire = static function (): void {
        assert_same(false, Purge::run()['busy'], 'purge ran in between');
    };
    assert_same('no_run', Runs::save($run, ['allow_url_include' => ['token' => $token]]), 'a save whose check passed just before purge started → refused');
    assert_same([], $db->rows, 'nothing written after that purge');

    // Purge between save's validity check and its write: the fenced write is refused.
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    Runs::before_write(static function () use ($db): void {
        // Another process takes the stale mutex over and purges.
        $db->rows[Options::name('mutex')] = 'other-owner:' . (int) FakeClock::$now;
        unset($db->rows[Options::name('manual')]);
    });
    assert_same('refused', Runs::save($run, ['allow_url_include' => ['token' => $token]]), 'save whose mutex was taken over mid-write → refused');
    Runs::before_write(null);
    assert_true(!isset($db->rows[Options::name('manual')]), 'the refused write left nothing');
    unset($db->rows[Options::name('mutex')]);

    // A plain takeover (no purge) also refuses the write; the previous run stays.
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    save($run, ['allow_url_include' => ['token' => $token]]);
    $previous = saved();
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    Runs::before_write(static function () use ($db): void {
        $db->rows[Options::name('mutex')] = 'other-owner:' . (int) FakeClock::$now;
    });
    post(['run' => $run, 'results' => json_encode(['allow_url_include' => ['token' => $token]])]);
    [$code] = Runs::save_request();
    Runs::before_write(null);
    unset($db->rows[Options::name('mutex')]);
    assert_same(500, $code, 'a write lost to a takeover → refused visibly');
    assert_same($previous, saved(), 'the previous run stays');

    // =========================================================== snapshot
    $db->rows['blog_public'] = '0';
    set_row('settings', ['profile' => 'live']);
    $run = start();
    $token = server($run, 'search_visibility')['token'];
    set_row('settings', ['profile' => 'staging']);
    save($run, ['search_visibility' => ['token' => $token]]);
    assert_same(Status::RED, saved()['results']['search_visibility']['status'], 'profile changed after start → graded by the run\'s snapshot (Live: Rot)');
    assert_same('live', saved()['profile'], 'saved profile is the run\'s');

    // =========================================================== manual items
    $previous = saved();
    foreach (Runs::ITEMS as $i => $item) {
        FakeClock::$now += 60;
        $GLOBALS['stub']['user'] = 10 + $i;
        post(['item' => $item, 'done' => '1']);
        [$code, $body] = Runs::items_request();
        assert_same(200, $code, "tick {$item}");
        assert_same(['user' => 10 + $i, 'date' => (int) FakeClock::$now], Runs::items()[$item], "{$item} stored with user and date");
    }
    $GLOBALS['cache'] = [];
    assert_same(4, count(Runs::items()), 'all four survive a reload');
    $with_dates = array_map(static fn(array $i): array => $i + ['date_display' => Runs::date_display($i['date'])], Runs::items());
    assert_same($with_dates, $body['data']['items'], 'items endpoint answers with the stored items and their dates formatted by the server');
    post(['item' => 'two_factor', 'done' => '0']);
    assert_same(200, Runs::items_request()[0], 'untick');
    assert_true(!isset(Runs::items()['two_factor']), 'untick clears the item');
    post(['item' => 'backdoor', 'done' => '1']);
    assert_same(400, Runs::items_request()[0], 'unknown item ID → refused');
    assert_true(!isset(Runs::items()['backdoor']), 'unknown item not stored');
    post(['reset' => '1']);
    assert_same(200, Runs::items_request()[0], 'reset');
    assert_same([], Runs::items(), 'reset clears all four');
    assert_true(!isset($db->rows[Options::name('items')]), 'reset removes the option');
    assert_same($previous, saved(), 'items never touch the saved run');
    $GLOBALS['stub']['user'] = 7;

    // =========================================================== access and nonce, reads included
    post(['item' => 'contact_form', 'done' => '1']);
    Runs::items_request();
    $run = start();
    $token = server($run, 'allow_url_include')['token'];
    $snapshot = $db->rows;
    $requests = [
        'start'  => static fn() => Runs::start_request(),
        'server' => static fn() => Runs::server_request(),
        'save'   => static fn() => Runs::save_request(),
        'items'  => static fn() => Runs::items_request(),
        'reset'  => static fn() => Runs::items_request(),
    ];
    foreach ([['access' => false, 'nonce' => true], ['access' => true, 'nonce' => false]] as $gate) {
        $GLOBALS['stub']['access'] = $gate['access'];
        $GLOBALS['stub']['nonce'] = $gate['nonce'];
        $without = $gate['access'] ? 'nonce' : 'access';
        foreach ($requests as $name => $call) {
            $_POST = ['run' => $run, 'check' => 'php_in_uploads', 'probe' => '1', 'item' => 'offsite_backup', 'done' => '1',
                'reset' => $name === 'reset' ? '1' : '0', 'results' => json_encode(['allow_url_include' => ['token' => $token]])];
            [$code] = $call();
            assert_same(403, $code, "{$name} refused without {$without}");
        }
        assert_same($snapshot, $db->rows, "nothing written without {$without}");
    }
    $GLOBALS['stub']['access'] = true;
    $GLOBALS['stub']['nonce'] = true;

    // Server endpoint takes only S checks from the catalogue.
    foreach (['logs_public', 'security_headers', '../x', ''] as $bad) {
        post(['run' => $run, 'check' => $bad]);
        assert_same(400, Runs::server_request()[0], "server endpoint refuses '{$bad}'");
    }

    echo "site-check runs: ok\n";
}
