<?php

declare(strict_types=1);

namespace SFX\Redirects;

/**
 * The only file that touches $wpdb for this module.
 *
 * Every decision that can be made without a database lives in Rule; this class
 * adds what needs the database — schema, uniqueness, the save-time loop check's
 * reverse edges, the regex cap, ownership — and owns the write lock that keeps
 * those checks true while the write happens.
 *
 * Error contract (spec "Database error contract"): after every read the result
 * is only trusted when $wpdb->last_error is empty; an unreadable state is never
 * treated as "no rules". Internal readers return null on error.
 */
final class Repository
{
    public const DB_VERSION = '1';

    private const VERSION_OPTION = 'sfx_redirects_db_version';
    private const LOCK_TIMEOUT   = 5;
    private const ORIGINS        = ['manual', 'auto', 'import', '404'];
    private const OPS            = ['enable', 'disable', 'delete', 'reset'];

    /**
     * Every column the code uses, with its type as SHOW COLUMNS reports it after
     * normalise_type(). maybe_install() refuses to mark the schema ready unless
     * the live table matches this list.
     */
    private const RULE_COLUMNS = [
        'id'          => 'bigint unsigned',
        'source_hash' => 'char(40)',
        'source'      => 'varchar(255)',
        'match_type'  => 'varchar(10)',
        'target'      => 'text',
        'status_code' => 'smallint unsigned',
        'enabled'     => 'tinyint',
        'hits'        => 'bigint unsigned',
        'last_hit'    => 'datetime',
        'origin'      => 'varchar(10)',
        'note'        => 'varchar(255)',
        'created_at'  => 'datetime',
    ];

    private const LOG_COLUMNS = [
        'id'         => 'bigint unsigned',
        'path_hash'  => 'char(40)',
        'path'       => 'varchar(255)',
        'hits'       => 'bigint unsigned',
        'first_seen' => 'datetime',
        'last_seen'  => 'datetime',
        'referrer'   => 'varchar(255)',
    ];

    /** Columns a rule row is read with (everything but the hash). */
    private const RULE_SELECT = 'id, source, match_type, target, status_code, enabled, hits, last_hit, origin, note, created_at';

    private const RULE_ORDERBY = ['source' => 'source', 'hits' => 'hits', 'last_hit' => 'last_hit', 'created_at' => 'created_at'];
    private const LOG_ORDERBY  = ['path' => 'path', 'hits' => 'hits', 'last_seen' => 'last_seen'];

    /** Set by maybe_install() for the admin notice of this same request. */
    private static string $install_error = '';

    // ------------------------------------------------------------------
    // Names, readiness, schema
    // ------------------------------------------------------------------

    public static function table(): string
    {
        return self::db()->prefix . 'sfx_redirects';
    }

    public static function log_table(): string
    {
        return self::db()->prefix . 'sfx_redirects_404';
    }

    /**
     * Per database AND per site: two installs sharing a database must not block
     * each other, and one install must never run two writers. DataPurge computes
     * the same string itself (it must not depend on this module) — keep both
     * formulas identical.
     */
    public static function lock_name(): string
    {
        return 'sfx_redirects_' . md5(DB_NAME . self::db()->prefix);
    }

    /**
     * One autoloaded option read, no query — this runs on every front-end
     * request before anything else in the module touches the database.
     */
    public static function ready(): bool
    {
        return get_option(self::VERSION_OPTION) === self::DB_VERSION;
    }

    public static function install_error(): string
    {
        return self::$install_error;
    }

    /**
     * A theme has no activation hook, so this lazy check on admin_init is the
     * install path; it also re-creates the tables after a Data Purge dropped
     * them. The version is written only after the live schema was verified —
     * dbDelta reports nothing useful when it fails, so its return value is not
     * trusted.
     */
    public static function maybe_install(): void
    {
        if (self::ready()) {
            return;
        }

        self::$install_error = '';
        $result = self::with_lock(static function (): string {
            // Another request may have finished the install while we waited.
            if (self::ready()) {
                return '';
            }

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            // dbDelta reads (DESCRIBE, SHOW INDEX) before it writes, and a reconnect
            // during those reads drops our lock. Its DDL goes through the 'query'
            // filter, so each CREATE/ALTER is checked there and dropped (the query
            // becomes '', which wpdb refuses) once the lock is no longer ours.
            $lost  = false;
            $guard = static function ($query) use (&$lost) {
                if (is_string($query) && preg_match('/^\s*(CREATE|ALTER)\s/i', $query) === 1) {
                    if ($lost || !self::lock_is_ours()) {
                        $lost = true;
                        return '';
                    }
                }
                return $query;
            };
            add_filter('query', $guard, PHP_INT_MAX);
            try {
                dbDelta(self::schema());
            } finally {
                remove_filter('query', $guard, PHP_INT_MAX);
            }
            if ($lost) {
                return __('The database connection was interrupted during the install.', 'sfxtheme');
            }

            $error = self::verify_table(self::table(), self::RULE_COLUMNS, 'source_hash');
            if ($error === '') {
                $error = self::verify_table(self::log_table(), self::LOG_COLUMNS, 'path_hash');
            }
            if ($error !== '') {
                return $error;
            }

            if (!self::lock_is_ours()) {
                return __('The database connection was interrupted during the install.', 'sfxtheme');
            }
            update_option(self::VERSION_OPTION, self::DB_VERSION, true);

            return '';
        }, self::locked_message());

        self::$install_error = (string) $result;
    }

    /**
     * dbDelta's format rules: one field per line, two spaces after PRIMARY KEY,
     * KEY (not INDEX), no spaces inside key column lists.
     *
     * @return list<string>
     */
    private static function schema(): array
    {
        $collate = self::db()->get_charset_collate();
        $rules   = self::table();
        $log     = self::log_table();

        return [
            "CREATE TABLE {$rules} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_hash char(40) NOT NULL,
  source varchar(255) NOT NULL,
  match_type varchar(10) NOT NULL,
  target text NOT NULL,
  status_code smallint(5) unsigned NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  last_hit datetime DEFAULT NULL,
  origin varchar(10) NOT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_hash (source_hash),
  KEY enabled_type (enabled,match_type)
) {$collate};",
            "CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(40) NOT NULL,
  path varchar(255) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 1,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  referrer varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY last_seen (last_seen,id)
) {$collate};",
        ];
    }

    /**
     * Existence, every column with its type, id as auto-increment primary key,
     * and the unique hash key. Returns '' when the table is usable, otherwise a
     * translated message naming the table.
     *
     * @param array<string,string> $columns
     */
    private static function verify_table(string $table, array $columns, string $unique): string
    {
        $wpdb = self::db();
        $fail = static function (string $detail) use ($table): string {
            /* translators: 1: database table name, 2: what is wrong with it */
            return sprintf(__('Database table %1$s could not be installed: %2$s', 'sfxtheme'), $table, $detail);
        };

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '' || $found !== $table) {
            return $fail(__('the table does not exist.', 'sfxtheme'));
        }

        $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            return $fail(__('its columns could not be read.', 'sfxtheme'));
        }
        $live = [];
        foreach ($rows as $row) {
            $live[(string) $row['Field']] = $row;
        }
        foreach ($columns as $name => $type) {
            if (!isset($live[$name])) {
                /* translators: %s: column name */
                return $fail(sprintf(__('column %s is missing.', 'sfxtheme'), $name));
            }
            if (self::normalise_type((string) $live[$name]['Type']) !== $type) {
                /* translators: %s: column name */
                return $fail(sprintf(__('column %s has the wrong type.', 'sfxtheme'), $name));
            }
        }
        if ((string) $live['id']['Key'] !== 'PRI' || stripos((string) $live['id']['Extra'], 'auto_increment') === false) {
            return $fail(__('column id is not an auto-increment primary key.', 'sfxtheme'));
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($indexes)) {
            return $fail(__('its indexes could not be read.', 'sfxtheme'));
        }
        $keys = [];
        foreach ($indexes as $index) {
            $name = (string) $index['Key_name'];
            $keys[$name]['unique'] = (string) $index['Non_unique'] === '0';
            $keys[$name]['columns'][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }
        $has = static function (string $column, bool $primary) use ($keys): bool {
            foreach ($keys as $name => $key) {
                if ($primary && $name !== 'PRIMARY') {
                    continue;
                }
                if ($key['unique'] && array_values($key['columns']) === [$column]) {
                    return true;
                }
            }
            return false;
        };
        if (!$has('id', true)) {
            return $fail(__('the primary key is not on column id.', 'sfxtheme'));
        }
        if (!$has($unique, false)) {
            /* translators: %s: column name */
            return $fail(sprintf(__('the unique key on %s is missing.', 'sfxtheme'), $unique));
        }

        return '';
    }

    /** `bigint(20) unsigned` ≡ `bigint unsigned`: MySQL 8 dropped integer display widths, MariaDB did not. */
    private static function normalise_type(string $type): string
    {
        $type = strtolower(trim($type));
        return (string) preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);
    }

    // ------------------------------------------------------------------
    // Front end: lookup, hit counter
    // ------------------------------------------------------------------

    /**
     * One indexed query per request; Rule::pick() decides in PHP.
     *
     * // ponytail: every enabled regex rule is fetched on every request. Bounded
     * by the 200-rule cap; a cached compiled list is the upgrade path.
     *
     * @return list<array{id:int, source:string, match_type:string, target:string, status_code:int}>|null
     */
    public static function find_candidates(string $path, string $query): ?array
    {
        if (!self::ready()) {
            return null;
        }

        return self::quietly(static function () use ($path, $query): ?array {
            $wpdb  = self::db();
            $table = self::table();
            $with  = Rule::source_hash('exact', $query !== '' ? $path . '?' . $query : $path);
            $bare  = Rule::source_hash('exact', $path);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source, match_type, target, status_code FROM `{$table}`
                 WHERE enabled = 1 AND (source_hash IN (%s, %s) OR match_type = 'regex')
                 ORDER BY id ASC",
                $with,
                $bare
            ), ARRAY_A);
            if (self::failed() || !is_array($rows)) {
                return null;
            }

            return array_map(static fn(array $r): array => [
                'id'          => (int) $r['id'],
                'source'      => (string) $r['source'],
                'match_type'  => (string) $r['match_type'],
                'target'      => (string) $r['target'],
                'status_code' => (int) $r['status_code'],
            ], $rows);
        });
    }

    /** The one rules-table write outside the lock: a counter is not configuration. */
    public static function record_hit(int $id): void
    {
        if (!self::ready()) {
            return;
        }

        self::quietly(static function () use ($id): void {
            $wpdb  = self::db();
            $table = self::table();
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET hits = hits + 1, last_hit = UTC_TIMESTAMP() WHERE id = %d",
                $id
            ));
            self::failed();
        });
    }

    // ------------------------------------------------------------------
    // Rules: read
    // ------------------------------------------------------------------

    /** @return array{status:'found'|'missing'|'error', row?:array} */
    public static function get(int $id): array
    {
        if (!self::ready()) {
            return ['status' => 'error'];
        }

        $row = self::fetch_rule($id);
        if ($row === null) {
            return ['status' => 'error'];
        }
        if ($row === []) {
            return ['status' => 'missing'];
        }

        return ['status' => 'found', 'row' => $row];
    }

    /** @return array{items: list<array>, total:int}|null */
    public static function list_rules(string $search, string $orderby, string $order, int $page, int $per_page): ?array
    {
        if (!self::ready()) {
            return null;
        }

        $wpdb  = self::db();
        $where = '';
        $args  = [];
        if ($search !== '') {
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $where = 'WHERE (source LIKE %s OR target LIKE %s OR note LIKE %s)';
            $args  = [$like, $like, $like];
        }
        $column = self::RULE_ORDERBY[$orderby] ?? 'created_at';

        $result = self::list_page(self::table(), self::RULE_SELECT, $where, $args, $column, $order, $page, $per_page);
        if ($result === null) {
            return null;
        }
        $result['items'] = array_map([self::class, 'rule_row'], $result['items']);

        return $result;
    }

    /**
     * // ponytail: loads every rule into memory at once — fine for the thousands
     * the import limit implies; a batched generator is the upgrade path.
     *
     * @return list<array>|null
     */
    public static function export_rows(): ?iterable
    {
        if (!self::ready()) {
            return null;
        }

        $wpdb  = self::db();
        $table = self::table();
        $rows  = $wpdb->get_results('SELECT ' . self::RULE_SELECT . " FROM `{$table}` ORDER BY id ASC", ARRAY_A);
        if (self::failed() || !is_array($rows)) {
            return null;
        }

        return array_map([self::class, 'rule_row'], $rows);
    }

    // ------------------------------------------------------------------
    // Rules: write (all under the lock)
    // ------------------------------------------------------------------

    /**
     * Receives a rule Rule::validate() already accepted; adds the checks that
     * need the database. $origin is the new owner: 'manual' from the form, '404'
     * when created from the log.
     *
     * @return array{status:'created'|'updated'|'unchanged'|'missing'|'duplicate'|'conflict'|'cap'|'locked'|'error',
     *               id?:int, message?:string}
     */
    public static function save(array $rule, int $id, string $origin): array
    {
        if (!self::ready()) {
            return ['status' => 'error', 'message' => self::not_ready_message()];
        }
        if (!in_array($origin, self::ORIGINS, true)) {
            return ['status' => 'error', 'message' => self::db_error_message()];
        }

        return self::with_lock(static function () use ($rule, $id, $origin): array {
            $wpdb  = self::db();
            $table = self::table();
            $error = ['status' => 'error', 'message' => self::db_error_message()];

            if ($id > 0) {
                $existing = self::fetch_rule($id);
                if ($existing === null) {
                    return $error;
                }
                if ($existing === []) {
                    return ['status' => 'missing', 'id' => $id, 'message' => self::missing_message($id)];
                }
            }

            $hash  = Rule::source_hash($rule['match_type'], $rule['source']);
            $other = self::id_by_hash($hash, $id);
            if ($other === null) {
                return $error;
            }
            if ($other > 0) {
                return ['status' => 'duplicate', 'id' => $other, 'message' => self::duplicate_message($other)];
            }

            $check = self::write_checks($rule, $id);
            if ($check !== null) {
                return $check;
            }

            if (!self::lock_is_ours()) {
                return $error;
            }

            $data = [
                'source_hash' => $hash,
                'source'      => $rule['source'],
                'match_type'  => $rule['match_type'],
                'target'      => $rule['target'],
                'status_code' => (int) $rule['status_code'],
                'enabled'     => $rule['enabled'] ? 1 : 0,
                'note'        => $rule['note'],
                'origin'      => $origin,
            ];
            $formats = ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];

            if ($id > 0) {
                $result = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
                if ($result === false) {
                    return self::write_failure($hash, $id);
                }
                return ['status' => $result === 0 ? 'unchanged' : 'updated', 'id' => $id];
            }

            $data['hits']       = 0;
            $data['created_at'] = gmdate('Y-m-d H:i:s');
            $formats[]          = '%d';
            $formats[]          = '%s';
            if ($wpdb->insert($table, $data, $formats) === false) {
                return self::write_failure($hash, 0);
            }

            return ['status' => 'created', 'id' => (int) $wpdb->insert_id];
        }, ['status' => 'locked', 'message' => self::locked_message()]);
    }

    /**
     * Upsert by source_hash, one lock per record — a 2 MB file must not hold the
     * lock for minutes. An update keeps hits/last_hit and hands ownership to
     * the importer. Rows are written one by one; a request that dies mid-file
     * leaves the earlier rows written, and re-importing is safe.
     *
     * @param iterable<int, array{record:int, rule:array}> $records
     * @return array{created:int, updated:int, unchanged:int, conflicts:list<string>, failed:int}
     */
    public static function import(iterable $records): array
    {
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => [], 'failed' => 0];

        $ready = self::ready();
        foreach ($records as $item) {
            if (!$ready) {
                $out['failed']++;
                continue;
            }
            $record = (int) $item['record'];
            $rule   = $item['rule'];

            $result = self::with_lock(static function () use ($rule): array {
                $wpdb = self::db();
                $hash = Rule::source_hash($rule['match_type'], $rule['source']);
                $id   = self::id_by_hash($hash, 0);
                if ($id === null) {
                    return ['status' => 'error'];
                }

                $check = self::write_checks($rule, $id);
                if ($check !== null) {
                    return $check;
                }
                if (!self::lock_is_ours()) {
                    return ['status' => 'error'];
                }

                $data = [
                    'target'      => $rule['target'],
                    'status_code' => (int) $rule['status_code'],
                    'enabled'     => $rule['enabled'] ? 1 : 0,
                    'note'        => $rule['note'],
                    'origin'      => 'import',
                ];
                $formats = ['%s', '%d', '%d', '%s', '%s'];

                if ($id > 0) {
                    $result = $wpdb->update(self::table(), $data, ['id' => $id], $formats, ['%d']);
                    if ($result === false) {
                        return ['status' => 'error'];
                    }
                    return ['status' => $result === 0 ? 'unchanged' : 'updated'];
                }

                $data += [
                    'source_hash' => $hash,
                    'source'      => $rule['source'],
                    'match_type'  => $rule['match_type'],
                    'hits'        => 0,
                    'created_at'  => gmdate('Y-m-d H:i:s'),
                ];
                array_push($formats, '%s', '%s', '%s', '%d', '%s');
                if ($wpdb->insert(self::table(), $data, $formats) === false) {
                    return ['status' => 'error'];
                }

                return ['status' => 'created'];
            }, ['status' => 'locked', 'message' => self::locked_message()]);

            switch ($result['status']) {
                case 'created':
                case 'updated':
                case 'unchanged':
                    $out[$result['status']]++;
                    break;
                case 'conflict':
                case 'cap':
                case 'locked':
                    /* translators: 1: CSV record number, 2: reason the record was skipped */
                    $out['conflicts'][] = sprintf(__('Record %1$d: %2$s', 'sfxtheme'), $record, $result['message']);
                    break;
                default:
                    $out['failed']++;
            }
        }

        return $out;
    }

    /**
     * Single and bulk row actions. One lock for the whole batch (a page of rows,
     * milliseconds). enable/disable make the acting person the owner ('manual');
     * reset and delete leave ownership alone.
     *
     * @return array{done:int, unchanged:int, missing:int, conflicts:list<string>, failed:int, locked:bool}
     */
    public static function apply_op(string $op, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $out = ['done' => 0, 'unchanged' => 0, 'missing' => 0, 'conflicts' => [], 'failed' => 0, 'locked' => false];

        if (!self::ready() || !in_array($op, self::OPS, true)) {
            $out['failed'] = count($ids);
            return $out;
        }

        $done = self::with_lock(static function () use ($op, $ids, &$out): bool {
            $wpdb  = self::db();
            $table = self::table();

            foreach ($ids as $position => $id) {
                $row = self::fetch_rule($id);
                if ($row === null) {
                    $out['failed']++;
                    continue;
                }
                if ($row === []) {
                    $out['missing']++;
                    continue;
                }

                if ($op === 'enable' && !$row['enabled']) {
                    $rule  = ['enabled' => true] + $row;
                    $check = self::write_checks($rule, $id);
                    if ($check !== null) {
                        if ($check['status'] === 'error') {
                            $out['failed']++;
                        } else {
                            /* translators: 1: rule id, 2: reason the rule was not enabled */
                            $out['conflicts'][] = sprintf(__('Rule #%1$d: %2$s', 'sfxtheme'), $id, $check['message']);
                        }
                        continue;
                    }
                }

                // A reconnect drops the lock silently: stop rather than write unserialised.
                if (!self::lock_is_ours()) {
                    $out['failed'] += count($ids) - $position;
                    return true;
                }

                switch ($op) {
                    case 'enable':
                    case 'disable':
                        $result = $wpdb->update(
                            $table,
                            ['enabled' => $op === 'enable' ? 1 : 0, 'origin' => 'manual'],
                            ['id' => $id],
                            ['%d', '%s'],
                            ['%d']
                        );
                        break;
                    case 'reset':
                        $result = $wpdb->update($table, ['hits' => 0, 'last_hit' => null], ['id' => $id], ['%d', '%s'], ['%d']);
                        break;
                    default: // delete
                        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
                        if ($result === 0) {
                            $out['missing']++;
                            continue 2;
                        }
                }

                if ($result === false) {
                    $out['failed']++;
                } elseif ($result === 0) {
                    $out['unchanged']++;
                } else {
                    $out['done']++;
                }
            }

            return true;
        }, false);

        if ($done !== true) {
            $out['locked'] = true;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 404 log
    // ------------------------------------------------------------------

    /**
     * One write per 404, whatever the traffic: a row per path, upserted. UTC
     * via gmdate so the value does not depend on the MySQL session time zone.
     */
    public static function log_404(string $path, string $referrer): void
    {
        if (!self::ready() || strlen($path) > 255) {
            return;
        }
        // Stored as '' rather than truncated or mangled (spec "404 log").
        if (strlen($referrer) > 255 || preg_match('//u', $referrer) !== 1) {
            $referrer = '';
        }

        self::quietly(static function () use ($path, $referrer): void {
            $wpdb  = self::db();
            $table = self::log_table();
            $now   = gmdate('Y-m-d H:i:s');
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (path_hash, path, hits, first_seen, last_seen, referrer)
                 VALUES (%s, %s, 1, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s, referrer = %s",
                sha1($path),
                $path,
                $now,
                $now,
                $referrer,
                $now,
                $referrer
            ));
            self::failed();
        });

        self::maybe_cleanup();
    }

    /**
     * Runs from the daily cron (forced) and on 1 in 100 logged 404s, so the table
     * stays near log_max_rows even where wp-cron never fires.
     *
     * // ponytail: at most 5 batches of 1000 per phase per run; the rest is left
     * to the next run.
     */
    public static function maybe_cleanup(bool $force = false): void
    {
        if (!self::ready()) {
            return;
        }
        if (!$force && wp_rand(1, 100) !== 1) {
            return;
        }

        $settings = Settings::get();
        $days     = (int) $settings['log_retention_days'];
        $max_rows = (int) $settings['log_max_rows'];

        self::quietly(static function () use ($days, $max_rows): void {
            $wpdb   = self::db();
            $table  = self::log_table();
            $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

            for ($batch = 0; $batch < 5; $batch++) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM `{$table}` WHERE last_seen < %s LIMIT 1000",
                    $cutoff
                ));
                if ($deleted === false || self::failed()) {
                    return;
                }
                if ($deleted < 1000) {
                    break;
                }
            }

            $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if (self::failed() || $count === null) {
                return;
            }
            $excess = (int) $count - $max_rows;

            for ($batch = 0; $batch < 5 && $excess > 0; $batch++) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM `{$table}` ORDER BY last_seen ASC, id ASC LIMIT %d",
                    min(1000, $excess)
                ));
                if ($deleted === false || self::failed() || (int) $deleted === 0) {
                    return;
                }
                $excess -= (int) $deleted;
            }
        });
    }

    /** @return array{items: list<array>, total:int}|null */
    public static function list_404(string $search, string $orderby, string $order, int $page, int $per_page): ?array
    {
        if (!self::ready()) {
            return null;
        }

        $wpdb  = self::db();
        $where = '';
        $args  = [];
        if ($search !== '') {
            $where = 'WHERE path LIKE %s';
            $args  = ['%' . $wpdb->esc_like($search) . '%'];
        }
        $column = self::LOG_ORDERBY[$orderby] ?? 'last_seen';

        $result = self::list_page(
            self::log_table(),
            'id, path, hits, first_seen, last_seen, referrer',
            $where,
            $args,
            $column,
            $order,
            $page,
            $per_page
        );
        if ($result === null) {
            return null;
        }
        $result['items'] = array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'path'       => (string) $r['path'],
            'hits'       => (int) $r['hits'],
            'first_seen' => (string) $r['first_seen'],
            'last_seen'  => (string) $r['last_seen'],
            'referrer'   => (string) $r['referrer'],
        ], $result['items']);

        return $result;
    }

    /**
     * The logged path of one 404 row.
     *
     * @return string|false|null  the path; null when the row does not exist;
     *         false when it could not be read (never mistaken for "missing")
     */
    public static function log_path(int $id): string|false|null
    {
        if (!self::ready()) {
            return false;
        }
        if ($id <= 0) {
            return null;
        }
        $wpdb  = self::db();
        $table = self::log_table();
        $path  = $wpdb->get_var($wpdb->prepare("SELECT path FROM `{$table}` WHERE id = %d", $id));
        if (self::failed()) {
            return false;
        }

        return is_string($path) ? $path : null;
    }

    public static function delete_404(array $ids): int|false
    {
        if (!self::ready()) {
            return false;
        }
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if ($ids === []) {
            return 0;
        }

        $wpdb         = self::db();
        $table        = self::log_table();
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $result       = $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id IN ({$placeholders})", ...$ids));
        if ($result === false || self::failed()) {
            return false;
        }

        return (int) $result;
    }

    /** DELETE, not TRUNCATE: TRUNCATE is DDL and needs the DROP privilege. */
    public static function clear_404(): bool
    {
        if (!self::ready()) {
            return false;
        }

        $table  = self::log_table();
        $result = self::db()->query("DELETE FROM `{$table}`");

        return $result !== false && !self::failed();
    }

    // ------------------------------------------------------------------
    // Slug monitor
    // ------------------------------------------------------------------

    /**
     * Spec "Automatic redirect on slug change". The target is the post's CURRENT
     * permalink, re-read under the lock, so callbacks that run out of order
     * reconcile (A→B, B→C saved quickly still yields A→C). Only ever touches
     * rules with origin 'auto'. Any failure rolls the whole operation back and
     * goes to error_log; the post save itself is never affected.
     *
     * Runs quietly too: it fires inside REST saves, where a printed database
     * error would corrupt the JSON response.
     */
    public static function on_slug_change(int $post_id, string $old_source): void
    {
        if (!self::ready()) {
            return;
        }

        self::quietly(static function () use ($post_id, $old_source): void {
            $done = self::with_lock(static function () use ($post_id, $old_source): bool {
                clean_post_cache($post_id);
                $post = get_post($post_id);
                if (!$post || $post->post_status !== 'publish') {
                    return true;
                }
                $permalink = get_permalink($post_id);
                if (!is_string($permalink) || $permalink === '' || str_contains($old_source, '?')) {
                    return true;
                }
                $parts = wp_parse_url($permalink);
                if (!is_array($parts) || isset($parts['query'])) {
                    return true;
                }

                $home      = home_url();
                $home_path = (string) wp_parse_url($home, PHP_URL_PATH);
                // As WordPress writes it (trailing slash kept): no second hop through redirect_canonical.
                $target    = Rule::home_relative((string) ($parts['path'] ?? '/'), $home_path);
                $new_canon = Rule::canonical_path($target);
                if ($new_canon === $old_source) {
                    return true;
                }

                $wpdb = self::db();
                if ($wpdb->query('START TRANSACTION') === false) {
                    error_log(sprintf('SFX Redirects: slug change for post %d not applied: %s', $post_id, $wpdb->last_error));
                    return true;
                }

                $error = self::slug_change_steps($old_source, $target, $new_canon, $home);
                if ($error === null && !self::lock_is_ours()) {
                    $error = 'write lock lost (connection interrupted)';
                }
                if ($error === null && $wpdb->query('COMMIT') === false) {
                    $error = 'COMMIT failed: ' . $wpdb->last_error;
                }
                if ($error !== null) {
                    $wpdb->query('ROLLBACK');
                    error_log(sprintf('SFX Redirects: slug change for post %d rolled back: %s', $post_id, $error));
                }

                return true;
            }, false);

            if ($done !== true) {
                error_log(sprintf('SFX Redirects: slug change for post %d not applied: write lock not obtained', $post_id));
            }
        });
    }

    /**
     * The three steps, in order, inside the open transaction. Every rule steps 2
     * and 3 create, re-point or re-enable is validated by Rule::validate() and
     * passes the loop check against the in-transaction state.
     *
     * @return string|null  error for the log, null = all steps succeeded
     */
    private static function slug_change_steps(string $old_source, string $target, string $new_canon, string $home): ?string
    {
        $wpdb  = self::db();
        $table = self::table();

        // 1. Clear the new address: the page is back where an auto rule sent it away from.
        $at_new = self::fetch_by_hash(Rule::source_hash('exact', $new_canon));
        if ($at_new === null) {
            return 'reading the rule at the new address failed: ' . $wpdb->last_error;
        }
        if ($at_new !== [] && $at_new['origin'] === 'auto') {
            // A reconnect during the read above would have dropped the transaction
            // and the lock; a write now would autocommit outside both.
            if (!self::lock_is_ours()) {
                return 'write lock lost (connection interrupted)';
            }
            if ($wpdb->delete($table, ['id' => $at_new['id']], ['%d']) === false) {
                return 'deleting the rule at the new address failed: ' . $wpdb->last_error;
            }
        }

        // 2. Flatten chains: auto rules pointing at the old address now point at the new one.
        // ponytail: every enabled auto exact rule is scanned per rename; fine for the thousands.
        $autos = $wpdb->get_results(
            'SELECT ' . self::RULE_SELECT . " FROM `{$table}` WHERE enabled = 1 AND match_type = 'exact' AND origin = 'auto' ORDER BY id ASC",
            ARRAY_A
        );
        if (self::failed() || !is_array($autos)) {
            return 'reading auto rules failed: ' . $wpdb->last_error;
        }
        foreach ($autos as $raw) {
            $row = self::rule_row($raw);
            if (Rule::target_path($row['target'], $home) !== $old_source) {
                continue;
            }
            $error = self::slug_write($row['id'], $row['source'], $target, $row['status_code']);
            if ($error !== null) {
                return $error;
            }
        }

        // 3. The old address.
        $at_old = self::fetch_by_hash(Rule::source_hash('exact', $old_source));
        if ($at_old === null) {
            return 'reading the rule at the old address failed: ' . $wpdb->last_error;
        }
        if ($at_old === []) {
            return self::slug_write(0, $old_source, $target, 301);
        }
        if ($at_old['origin'] === 'auto') {
            return self::slug_write($at_old['id'], $old_source, $target, 301);
        }

        return null; // A non-auto rule at the old address: the editor's rule wins.
    }

    /**
     * Validate, loop-check and write one auto rule (insert when $id is 0,
     * otherwise re-point and re-enable).
     */
    private static function slug_write(int $id, string $source, string $target, int $status): ?string
    {
        $validated = Rule::validate([
            'source'      => $source,
            'match_type'  => 'exact',
            'target'      => $target,
            'status_code' => (string) $status,
            'enabled'     => true,
            'note'        => '',
        ]);
        if ($validated['errors'] !== []) {
            return sprintf('rule %s → %s is invalid: %s', $source, $target, implode('; ', $validated['errors']));
        }
        $rule = $validated['rule'];

        $conflict = self::loop_error($rule, $id);
        if ($conflict === false) {
            return 'loop check read failed: ' . self::db()->last_error;
        }
        if ($conflict !== null) {
            return sprintf('rule %s → %s: %s', $source, $target, $conflict);
        }

        if (!self::lock_is_ours()) {
            return 'write lock lost (connection interrupted)';
        }

        $wpdb  = self::db();
        $table = self::table();
        if ($id > 0) {
            $result = $wpdb->update(
                $table,
                ['target' => $rule['target'], 'status_code' => $rule['status_code'], 'enabled' => 1],
                ['id' => $id],
                ['%s', '%d', '%d'],
                ['%d']
            );
        } else {
            $result = $wpdb->insert($table, [
                'source_hash' => Rule::source_hash('exact', $rule['source']),
                'source'      => $rule['source'],
                'match_type'  => 'exact',
                'target'      => $rule['target'],
                'status_code' => $rule['status_code'],
                'enabled'     => 1,
                'hits'        => 0,
                'origin'      => 'auto',
                'note'        => '',
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']);
        }

        return $result === false ? 'writing rule ' . $source . ' failed: ' . $wpdb->last_error : null;
    }

    // ------------------------------------------------------------------
    // Shared checks
    // ------------------------------------------------------------------

    /**
     * Loop check and regex cap for a rule about to be written. Callers hold the
     * lock, so what these read cannot change before the write.
     *
     * @return array{status:'conflict'|'cap'|'error', message:string}|null  null = may be written
     */
    private static function write_checks(array $rule, int $id): ?array
    {
        $conflict = self::loop_error($rule, $id);
        if ($conflict === false) {
            return ['status' => 'error', 'message' => self::db_error_message()];
        }
        if ($conflict !== null) {
            return ['status' => 'conflict', 'message' => $conflict];
        }

        if ($rule['enabled'] && $rule['match_type'] === 'regex') {
            $wpdb  = self::db();
            $table = self::table();
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE enabled = 1 AND match_type = 'regex' AND id <> %d",
                $id
            ));
            if (self::failed() || $count === null) {
                return ['status' => 'error', 'message' => self::db_error_message()];
            }
            if ((int) $count >= Rule::MAX_ENABLED_REGEX) {
                return ['status' => 'cap', 'message' => sprintf(
                    /* translators: %d: maximum number of enabled regular-expression rules */
                    __('At most %d enabled regular-expression rules are allowed. Disable or delete one first.', 'sfxtheme'),
                    Rule::MAX_ENABLED_REGEX
                )];
            }
        }

        return null;
    }

    /**
     * Save-time loop check (spec "Loop prevention") for every transition to an
     * enabled rule. Exact rules first get the conservative reverse-edge check
     * (enabled exact rules whose source PATH equals this rule's target path,
     * decided by Rule::loop_conflict()); every rule, exact or regex, then gets
     * the matcher-based chain simulation in matcher_cycle().
     *
     * @return string|false|null  message on conflict, false on a read error, null = no loop
     */
    private static function loop_error(array $rule, int $id): string|false|null
    {
        if (!$rule['enabled']) {
            return null;
        }
        if ($rule['match_type'] !== 'exact') {
            return self::matcher_cycle($rule, $id);
        }

        $home    = home_url();
        $reverse = [];
        $target  = Rule::target_path((string) $rule['target'], $home);
        if ($target !== null) {
            $path  = explode('?', $target, 2)[0];
            $wpdb  = self::db();
            $table = self::table();
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT id, source, target, status_code FROM `{$table}`
                 WHERE enabled = 1 AND match_type = 'exact' AND id <> %d AND (source = %s OR source LIKE %s)",
                $id,
                $path,
                $wpdb->esc_like($path . '?') . '%'
            ), ARRAY_A);
            if (self::failed() || !is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                $reverse[] = [
                    'id'          => (int) $row['id'],
                    'source'      => (string) $row['source'],
                    'target'      => (string) $row['target'],
                    'status_code' => (int) $row['status_code'],
                ];
            }
        }

        $conflict = Rule::loop_conflict($rule, $id, $reverse, $home);

        return $conflict ?? self::matcher_cycle($rule, $id);
    }

    /** Redirect hops followed when simulating a chain; beyond this browsers stop it anyway. */
    private const MAX_SIMULATED_HOPS = 10;

    /**
     * Rule lookups one save may spend on the simulation, across all start points.
     * It runs under the write lock; unbounded, a path with hundreds of
     * query-keyed rules would hold the lock long enough to time out other
     * writers. Exhausted budget = no loop found (best effort, see the spec).
     */
    private const MAX_SIMULATED_LOOKUPS = 200;

    /**
     * Cycles the exact reverse-edge check cannot see — through regex rules,
     * through query passthrough (^/b$ → /a?x=1 beside /a?x=1 → /b): the rule set
     * WITH this rule in place is run through the real matcher, hop by hop, from
     * where this rule applies. A revisited address on a chain this rule is part
     * of is a loop. Runtime semantics are kept: a rule whose target is the
     * current URL is skipped, and a chain ending elsewhere is fine however it
     * gets there. Best effort, not complete — the stated gaps are in the spec
     * ("Loop prevention"); where it errs, it errs towards refusing a save.
     *
     * Starting points: an exact rule's own source; for a regex rule its target
     * (it cannot be enumerated which paths it matches) plus that target with each
     * query an exact rule keys on, because a regex passes the query through.
     *
     * ponytail: a regex target with $n placeholders depends on the request and
     * is not followed; chains longer than MAX_SIMULATED_HOPS count as ending.
     *
     * @return string|false|null  message, false on a read error, null = no cycle
     */
    private static function matcher_cycle(array $rule, int $id): string|false|null
    {
        $target = (string) $rule['target'];
        if ((int) $rule['status_code'] === 410) {
            return null;
        }

        $home = home_url();
        $self = [
            'id'          => $id > 0 ? $id : PHP_INT_MAX, // a new rule sorts last, as it will
            'source'      => (string) $rule['source'],
            'match_type'  => (string) $rule['match_type'],
            'target'      => $target,
            'status_code' => (int) $rule['status_code'],
        ];

        if ($rule['match_type'] === 'exact') {
            $seeds = [(string) $rule['source']];
        } elseif (preg_match('/\$[1-9]/', $target) === 1) {
            return null;
        } else {
            $first = Rule::target_path($target, $home);
            if ($first === null) {
                return null; // external: the chain leaves this site
            }
            $seeds = [$target]; // as written: its scheme is part of what the runtime compares
            $path  = explode('?', $first, 2)[0];
            $wpdb  = self::db();
            $table = self::table();
            $keyed = $wpdb->get_col($wpdb->prepare(
                // Each query-keyed exact rule on that path seeds a walk. More seeds
                // than the lookup budget could never all be walked, so no more are
                // fetched (best effort, see MAX_SIMULATED_LOOKUPS).
                "SELECT source FROM `{$table}` WHERE enabled = 1 AND match_type = 'exact' AND source LIKE %s ORDER BY id ASC LIMIT %d",
                $wpdb->esc_like($path . '?') . '%',
                self::MAX_SIMULATED_LOOKUPS
            ));
            if (self::failed() || !is_array($keyed)) {
                return false;
            }
            // Same address as the target, with each keyed query — and the
            // target's own scheme and host, not the home URL's.
            $base = strtok($target, '?#');
            foreach ($keyed as $source) {
                $seeds[] = $base . '?' . explode('?', (string) $source, 2)[1];
            }
        }

        $budget = self::MAX_SIMULATED_LOOKUPS;
        foreach ($seeds as $seed) {
            if ($budget <= 0) {
                break;
            }
            $result = self::follow_chain($seed, $self, $home, $budget);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param string $start a home-relative source or an absolute target URL
     *                      (its scheme is kept), optionally with "?query"
     * @return string|false|null  message, false on a read error, null = the chain ends
     */
    private static function follow_chain(string $start, array $self, string $home, int &$budget): string|false|null
    {
        $seen    = [];
        $via     = [];
        // Absolute between hops: the scheme a target names is part of what the
        // runtime compares (an https site may redirect /a to http://…/a once).
        $current = Rule::absolute_target($start, $home);

        // One more iteration than hops, so the address reached by the last hop
        // is still checked against the visited set.
        for ($hop = 0; $hop <= self::MAX_SIMULATED_HOPS; $hop++) {
            $local = Rule::target_path($current, $home);
            if ($local === null) {
                return null; // left the site
            }
            [$path, $query] = array_pad(explode('?', $local, 2), 2, '');
            // Lookup uses the canonical query; passthrough uses the query as sent,
            // exactly as the Controller does.
            $raw_query = (string) (wp_parse_url($current, PHP_URL_QUERY) ?? '');
            $identity  = Rule::url_identity($current);

            if (isset($seen[$identity])) {
                if (!in_array($self['id'], $via, true)) {
                    return null; // an existing loop elsewhere, not this rule's doing
                }
                $others = array_values(array_unique(array_diff($via, [$self['id']])));
                if ($others === []) {
                    return __('The target leads back to the source; the redirect would loop.', 'sfxtheme');
                }

                return sprintf(
                    /* translators: %d: id of the other redirect rule */
                    __('This would create a redirect loop with rule #%d.', 'sfxtheme'),
                    $others[0]
                );
            }
            $seen[$identity] = true;
            if ($hop === self::MAX_SIMULATED_HOPS) {
                return null; // longer than this: counted as ending (browsers stop it)
            }

            if ($budget <= 0) {
                return null; // lookup budget spent: counted as ending
            }
            $budget--;
            $candidates = self::find_candidates($path, $query);
            if ($candidates === null) {
                return false;
            }
            $candidates   = array_values(array_filter($candidates, static fn(array $c): bool => $c['id'] !== $self['id']));
            $candidates[] = $self;
            usort($candidates, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

            $hit = Rule::pick($candidates, $path, $query, $raw_query, $home);
            if ($hit === null || $hit['url'] === null) {
                return null; // no rule, or 410: the chain ends
            }
            // The runtime skips a redirect to the URL being requested.
            if (Rule::url_identity($hit['url']) === $identity) {
                return null;
            }
            $via[]   = $hit['id'];
            $current = $hit['url'];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Internal readers (null = database error)
    // ------------------------------------------------------------------

    /** @return array|null  [] when the row does not exist, null on error */
    private static function fetch_rule(int $id): ?array
    {
        $wpdb  = self::db();
        $table = self::table();
        $row   = $wpdb->get_row($wpdb->prepare('SELECT ' . self::RULE_SELECT . " FROM `{$table}` WHERE id = %d", $id), ARRAY_A);
        if (self::failed()) {
            return null;
        }

        return is_array($row) ? self::rule_row($row) : [];
    }

    /** @return array|null  [] when no rule has this hash, null on error */
    private static function fetch_by_hash(string $hash): ?array
    {
        $wpdb  = self::db();
        $table = self::table();
        $row   = $wpdb->get_row($wpdb->prepare('SELECT ' . self::RULE_SELECT . " FROM `{$table}` WHERE source_hash = %s", $hash), ARRAY_A);
        if (self::failed()) {
            return null;
        }

        return is_array($row) ? self::rule_row($row) : [];
    }

    /** @return int|null  id of another rule with this hash (0 = none), null on error */
    private static function id_by_hash(string $hash, int $exclude_id): ?int
    {
        $wpdb  = self::db();
        $table = self::table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE source_hash = %s AND id <> %d",
            $hash,
            $exclude_id
        ));
        if (self::failed()) {
            return null;
        }

        return (int) $found;
    }

    /**
     * Shared by both list screens. $column comes from an allowlist and $order is
     * reduced to ASC|DESC here, so neither can carry SQL.
     *
     * @return array{items: list<array>, total:int}|null
     */
    private static function list_page(string $table, string $select, string $where, array $args, string $column, string $order, int $page, int $per_page): ?array
    {
        $wpdb      = self::db();
        $direction = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $per_page  = max(1, $per_page);
        $offset    = (max(1, $page) - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM `{$table}` {$where}";
        $total     = $wpdb->get_var($args === [] ? $count_sql : $wpdb->prepare($count_sql, ...$args));
        if (self::failed() || $total === null) {
            return null;
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT {$select} FROM `{$table}` {$where} ORDER BY {$column} {$direction}, id {$direction} LIMIT %d OFFSET %d",
            ...array_merge($args, [$per_page, $offset])
        ), ARRAY_A);
        if (self::failed() || !is_array($items)) {
            return null;
        }

        return ['items' => $items, 'total' => (int) $total];
    }

    /** Typed rule row, the shape every public reader returns. */
    private static function rule_row(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'source'      => (string) $r['source'],
            'match_type'  => (string) $r['match_type'],
            'target'      => (string) $r['target'],
            'status_code' => (int) $r['status_code'],
            'enabled'     => (int) $r['enabled'] === 1,
            'hits'        => (int) $r['hits'],
            'last_hit'    => $r['last_hit'] === null ? null : (string) $r['last_hit'],
            'origin'      => (string) $r['origin'],
            'note'        => (string) $r['note'],
            'created_at'  => (string) $r['created_at'],
        ];
    }

    /**
     * A failed insert/update under the lock: the unique key is the only thing
     * that should still reject it, so name the rule that owns the source.
     */
    private static function write_failure(string $hash, int $id): array
    {
        if (str_contains(self::db()->last_error, 'Duplicate entry')) {
            $other = self::id_by_hash($hash, $id);
            if ($other !== null && $other > 0) {
                return ['status' => 'duplicate', 'id' => $other, 'message' => self::duplicate_message($other)];
            }
        }

        return ['status' => 'error', 'message' => self::db_error_message()];
    }

    // ------------------------------------------------------------------
    // Plumbing: error check, self-heal, quiet mode, lock
    // ------------------------------------------------------------------

    /**
     * True when the last query failed. Self-heal: MySQL 1146 on one of our
     * tables ("Table '…' doesn't exist") deletes the version option, so ready()
     * turns false and the next admin request re-installs — a version that says
     * "ready" over missing tables lasts at most until the next failing request.
     */
    private static function failed(): bool
    {
        $error = self::db()->last_error;
        if ($error === '') {
            return false;
        }
        if (str_contains($error, "doesn't exist")
            && (str_contains($error, self::table()) || str_contains($error, self::log_table()))) {
            delete_option(self::VERSION_OPTION);
        }

        return true;
    }

    /**
     * A table dropped mid-request (purge race) must never print a database error
     * into a page; the failed query is simply treated as "no match".
     */
    private static function quietly(callable $fn): mixed
    {
        $wpdb = self::db();
        $prev = $wpdb->suppress_errors(true);
        try {
            return $fn();
        } finally {
            $wpdb->suppress_errors($prev);
        }
    }

    /**
     * Runs $fn inside the MySQL named lock that serialises every rules-table
     * write except the hit counter (spec "Write serialisation"). Returns
     * $on_locked when the lock is not obtained within 5 s.
     */
    private static function with_lock(callable $fn, mixed $on_locked): mixed
    {
        $wpdb = self::db();
        $name = self::lock_name();
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT)) !== '1') {
            return $on_locked;
        }
        try {
            return $fn();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * wpdb reconnects silently after "server has gone away", which drops the lock
     * and any open transaction. Checked before every final write/COMMIT. Stated
     * limit: a reconnect between this check and the write is not detected.
     */
    private static function lock_is_ours(): bool
    {
        $wpdb = self::db();
        return (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name())) === '1';
    }

    private static function db(): \wpdb
    {
        global $wpdb;
        return $wpdb;
    }

    // ------------------------------------------------------------------
    // Messages
    // ------------------------------------------------------------------

    private static function not_ready_message(): string
    {
        return __('The redirect database tables are not installed.', 'sfxtheme');
    }

    private static function locked_message(): string
    {
        return __('Another redirect change is in progress, try again.', 'sfxtheme');
    }

    private static function db_error_message(): string
    {
        return __('A database error occurred; the change was not saved.', 'sfxtheme');
    }

    private static function missing_message(int $id): string
    {
        /* translators: %d: rule id */
        return sprintf(__('Rule #%d no longer exists.', 'sfxtheme'), $id);
    }

    private static function duplicate_message(int $id): string
    {
        /* translators: %d: id of the existing rule */
        return sprintf(__('A rule for this source already exists (#%d).', 'sfxtheme'), $id);
    }
}
