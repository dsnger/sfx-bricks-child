<?php

declare(strict_types=1);

/**
 * SiteCheck critical section (spec "Storage" → Writes) against a stubbed
 * $wpdb, plus the option namespace it lives in.
 *
 * The fake $wpdb understands exactly the statements Mutex sends and models
 * what MySQL does with them; tests/support/site-check-mutex-live-check.php
 * runs the same statements against the real database. Time is a fake clock:
 * Mutex calls time(), microtime() and usleep() unqualified, so the functions
 * below in its namespace replace the global ones and the 5 s wait costs no
 * real time.
 *
 * "Another process" is simulated by resetting Mutex's private holder through
 * reflection — what a second PHP process would start with.
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
    final class FakeClock
    {
        public static float $now = 1800000000.0;
    }

    final class FakeWpdb
    {
        public string $options = 'wp_options';
        /** @var array<string,string> option_name => option_value */
        public array $rows = [];
        /** @var array<string,string> option_name => autoload */
        public array $autoload = [];
        public array $log = [];

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
            $this->log[] = $q;
            if (str_starts_with($q, 'INSERT IGNORE')) {
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
                if (!str_contains($q, 'autoload = %s')) {
                    throw new RuntimeException('FakeWpdb: the upsert must also reset autoload');
                }
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
                    return 0; // MySQL: unchanged row, 0 affected
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
            $this->log[] = $q;
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
    $GLOBALS['cache'] = [];

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

    require_once __DIR__ . '/../inc/SiteCheck/Options.php';
    require_once __DIR__ . '/../inc/SiteCheck/Mutex.php';

    use SFX\SiteCheck\Mutex;
    use SFX\SiteCheck\Options;

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

    /** Pretend to be a different PHP process: swap Mutex's private holder. */
    function swap_holder(?string $value): ?string
    {
        $prop = new ReflectionProperty(Mutex::class, 'held');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $old = $prop->getValue();
        $prop->setValue(null, $value);
        return $old;
    }

    $db = $GLOBALS['wpdb'];
    $mutex = 'sfx_site_check_mutex';
    $settings = 'sfx_site_check_settings';

    // ------------------------------------------------------------ option namespace

    assert_same('sfx_site_check_mutex', Options::name('mutex'), 'default option prefix');
    assert_same('sfx_site_check_probe_cleanup', Options::hook('probe_cleanup'), 'default hook prefix');
    foreach (['sfx_site_check_', 'sfx_other_h123456_', 'sfx_site_check_hABC_', 'sfx_site_check_h12_'] as $bad) {
        $refused = false;
        try {
            Options::use_harness_prefix($bad);
        } catch (InvalidArgumentException $e) {
            $refused = true;
        }
        assert_true($refused, "harness prefix {$bad} refused");
    }
    assert_same('sfx_site_check_mutex', Options::name('mutex'), 'a refused prefix changes nothing');

    // ------------------------------------------------------------ plain use

    assert_same(false, Mutex::write($settings, 'x'), 'write outside with() is refused');
    assert_same([], $db->rows, 'refused write touched nothing');

    $GLOBALS['cache']['options'][$settings] = 'stale cached value';
    $GLOBALS['cache']['options']['notoptions'] = ['sfx_site_check_items' => true, 'unrelated' => true];
    $result = Mutex::with(static function () use ($settings, $db, $mutex) {
        assert_true(isset($db->rows[$mutex]), 'mutex row exists while held');
        assert_same(false, wp_cache_get($settings, 'options'), 'entering the section drops the cached option');
        assert_same(['unrelated' => true], wp_cache_get('notoptions', 'options'), 'entering the section drops notoptions entries of module options only');
        assert_same(true, Mutex::write($settings, ['profile' => 'live']), 'write creates an absent option');
        assert_same(serialize(['profile' => 'live']), $db->rows[$settings], 'written serialized');
        assert_same(true, Mutex::write($settings, ['profile' => 'staging']), 'write updates an existing option');
        assert_same(true, Mutex::write($settings, ['profile' => 'staging']), 'writing an unchanged value still succeeds while held');
        assert_same('nested', Mutex::with(static fn () => 'nested'), 'a nested with() runs inside the held section');
        return 'done';
    });
    assert_same('done', $result, 'with() returns the callback result');
    assert_true(!isset($db->rows[$mutex]), 'mutex released after with()');
    assert_same(serialize(['profile' => 'staging']), $db->rows[$settings], 'value kept');

    // A row that existed with autoload on is left autoload off by a write.
    $db->rows['sfx_site_check_items'] = 'old';
    $db->autoload['sfx_site_check_items'] = 'yes';
    assert_same(true, Mutex::with(static fn () => Mutex::write('sfx_site_check_items', 'old')), 'write over an autoloaded row with the same value');
    assert_same('no', $db->autoload['sfx_site_check_items'], 'write resets autoload of an existing row');
    unset($db->rows['sfx_site_check_items'], $db->autoload['sfx_site_check_items']);

    $result = Mutex::with(static function () use ($settings) {
        assert_same(true, Mutex::delete($settings), 'conditional delete while held');
        assert_same(true, Mutex::delete($settings), 'deleting an absent option while held succeeds');
        return 1;
    });
    assert_true(!isset($db->rows[$settings]), 'deleted');

    $threw = false;
    try {
        Mutex::with(static function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'boom';
    }
    assert_true($threw, 'exception propagates');
    assert_true(!isset($db->rows[$mutex]), 'mutex released after an exception');

    // ------------------------------------------------------------ busy

    $db->rows[$mutex] = 'otherprocess:' . (int) FakeClock::$now;
    $start = FakeClock::$now;
    $called = false;
    $result = Mutex::with(static function () use (&$called) {
        $called = true;
    });
    assert_same(Mutex::Busy, $result, 'held by another process → Busy');
    assert_true(!$called, 'callback not run when busy');
    $waited = FakeClock::$now - $start;
    assert_true($waited >= 5.0 && $waited < 5.5, "waited about 5 s before giving up (waited {$waited})");
    assert_same('otherprocess:' . (int) $start, $db->rows[$mutex], 'busy caller left the other lock alone');

    // ------------------------------------------------------------ stale lock

    $db->rows[$mutex] = 'otherprocess:' . ((int) FakeClock::$now - 31);
    $start = FakeClock::$now;
    $result = Mutex::with(static fn () => Mutex::write('sfx_site_check_items', [1]));
    assert_same(true, $result, 'a 31 s old lock is taken over and the write lands');
    assert_true(FakeClock::$now - $start < 0.5, 'takeover of a stale lock does not wait');
    assert_true(!isset($db->rows[$mutex]), 'taken-over lock released by its new owner');

    $db->rows[$mutex] = 'otherprocess:' . ((int) FakeClock::$now - 27);
    $result = Mutex::with(static fn () => 'late');
    assert_same('late', $result, 'a lock that turns stale while waiting is taken over');
    unset($db->rows[$mutex]);

    $db->rows[$mutex] = 'garbage';
    assert_same('ok', Mutex::with(static fn () => 'ok'), 'an unreadable lock value counts as stale');

    // ------------------------------------------------------------ takeover fences the old owner

    $db->rows[$settings] = 'before';
    $outcome = Mutex::with(static function () use ($db, $mutex, $settings) {
        // Process A holds the section and stalls past 30 s.
        $db->rows[$mutex] = substr($db->rows[$mutex], 0, (int) strrpos($db->rows[$mutex], ':')) . ':' . ((int) FakeClock::$now - 31);
        $a = swap_holder(null);
        // Process B arrives, takes over, writes, leaves.
        $b = Mutex::with(static fn () => Mutex::write($settings, 'from B'));
        assert_same(true, $b, 'process B took over and wrote');
        // Process B is now gone; process C holds a fresh lock.
        $db->rows[$mutex] = 'processC:' . (int) FakeClock::$now;
        swap_holder($a);
        // A wakes up.
        assert_same(false, Mutex::write($settings, 'from A, too late'), 'old owner write refused after takeover');
        assert_same(false, Mutex::delete($settings), 'old owner delete refused after takeover');
        return 'A finished';
    });
    assert_same('A finished', $outcome, 'old owner callback still returns');
    assert_same('from B', $db->rows[$settings], 'option unchanged by the old owner');
    assert_same('processC:' . (int) FakeClock::$now, $db->rows[$mutex], 'old owner release does not remove the current owner lock');

    echo "site-check-mutex: PASS\n";
}
