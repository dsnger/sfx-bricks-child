# Password Protected Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Protect the frontend of this WordPress child theme behind a single shared password, configurable from the theme settings, plus a shareable bypass URL.

**Architecture:** A new auto-discovered feature module at `inc/PasswordProtected/`, gated by a checkbox in General Theme Options. All settings live in **one array option** (`sfx_password_protected_options`) written by **one** `admin_post` handler over a validated snapshot — not `register_setting()`. Protection is decided by three predicates split by what each depends on (config / visitor / query), which is what keeps cache suppression correct and keeps REST from calling query-dependent conditionals.

**Tech Stack:** PHP >= 8.0, WordPress, Bricks Builder child theme. No new dependencies. Tests are plain PHP scripts with hand-written stubs (this repo has no PHPUnit).

**Spec:** [`docs/superpowers/specs/2026-07-17-password-protected-design.md`](../specs/2026-07-17-password-protected-design.md) — read it before starting. It records *why* each decision is what it is, and five review passes worth of rejected alternatives.

## Global Constraints

- PHP `>=8.0` (per `composer.json`). The `setcookie()` array-options signature and `SameSite` are available.
- Every **class** file: `<?php`, then `declare(strict_types=1);`, then `namespace SFX\PasswordProtected;`. The two non-class files are the stated exceptions — `index.php` is a bare silence-is-golden stub, and `login-form.php` is a template: strict types, a leading docblock, no namespace.
- Text domain for every user-facing string: `'sfxtheme'`.
- Option name, exact: `sfx_password_protected_options`. One option. Never discrete `sfx_pp_*` options.
- The bypass key is **never** read from the request. Only from stored settings.
- `is_protection_enabled()` is `status` only — it must **not** also require a non-empty password hash.
- Cache suppression (`DONOTCACHEPAGE`) keys off `is_protection_enabled()`, **never** `is_active()`.
- `is_active()` must not be called before the `wp` hook. REST must not call it at all.
- **Every** request value is read through `Settings::has_string()`, `post_string()`, `request_string()` or `post_bool()`. Never `$_POST['x']` directly, never a bare `!empty($_POST['x'])` — `?field[]=x` supplies an array, and `!empty(['x'])` is `true`.
- **Every** redirect: `wp_safe_redirect(wp_validate_redirect($target, home_url('/')))` then `exit`. No exceptions, including targets this module builds itself.
- Admin page capability is `'read'` + `AccessControl::die_if_unauthorized_theme()` — **not** `manage_options`.
- No `delete_all_options()`, and no entry in `GeneralThemeOptions\Controller`'s delete-on-toggle-off chain.
- Run tests with `php tests/<name>.php`. Passing output is `OK`; a failure writes to STDERR and exits 1.
- Baseline: all four existing tests pass today. They must still pass at every commit.

## File Structure

| File | Responsibility |
|---|---|
| `inc/PasswordProtected/Settings.php` | Defaults, typed `get()`, request readers, `validate_snapshot()`, `save_from_request()` |
| `inc/PasswordProtected/Auth.php` | Cookie: generate, validate, set, clear |
| `inc/PasswordProtected/Controller.php` | Predicates, request handlers, hook registration, feature config |
| `inc/PasswordProtected/AdminPage.php` | Submenu page + form |
| `inc/PasswordProtected/login-form.php` | Frontend login template |
| `inc/PasswordProtected/index.php` | Silence-is-golden stub |
| `tests/password-protected-settings-test.php` | `validate_snapshot()` + `Settings::get()` + request readers |
| `tests/password-protected-auth-test.php` | Cookie signing |
| `tests/password-protected-predicates-test.php` | Exemption matrix + fail-closed invariants |

Modified: `inc/GeneralThemeOptions/Settings.php`, `inc/ThemeSettingsOverview/OverviewProvider.php`, `inc/SFXBricksChildTheme.php`, `inc/ImportExport/Controller.php`, `uninstall.php`, `tests/support/overview-general-theme-options-settings-stub.php`.

## Task order is load-bearing — read this before starting

`SFXBricksChildTheme::auto_register_features()` globs `inc/*/Controller.php` and calls `get_feature_config()` on **every** class that has one — at `after_setup_theme`, **before** the activation gate is ever consulted (the gate is checked later, in `load_dependencies()`). A `get_feature_config()` that touches a class which does not exist yet therefore fatals **every request on the site**, whether or not the feature is enabled.

So `Controller::get_feature_config()` and `Controller::__construct()` — the only things referencing `AdminPage` — land **last**, in Task 6, after `AdminPage.php` exists. Tasks 1–3 leave `Controller` with predicates only and no `get_feature_config()`, which `auto_register_features()` skips via its `method_exists()` check. Every commit in this plan leaves the site loadable.

Do not "tidy" this by moving the constructor earlier.

---

### Task 1: Settings — defaults, typed getter, request readers, snapshot validation

The credential logic. Everything security-relevant that can be unit-tested lives here, which is why it comes first.

**Files:**
- Create: `inc/PasswordProtected/Settings.php`
- Create: `inc/PasswordProtected/index.php`
- Test: `tests/password-protected-settings-test.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces:
  - `Settings::OPTION_NAME` — `'sfx_password_protected_options'`
  - `Settings::defaults(): array`
  - `Settings::get(): array` — always fully typed, every key present
  - `Settings::get_password_hash(): string`
  - `Settings::allowed_ips(): array`
  - `Settings::has_string(array $source, string $key): bool` — presence + type, for "was this field submitted at all"
  - `Settings::post_string(array $source, string $key): string` — type guard, does **not** unslash
  - `Settings::request_string(array $source, string $key): string` — type guard **and** unslash; for raw superglobals
  - `Settings::post_bool(array $source, string $key): bool` — type guard for checkboxes
  - `Settings::validate_snapshot(array $post, array $existing): array` — `['values' => array, 'errors' => array<string,string>]`; `values` is always in `defaults()` key order

- [ ] **Step 1: Write the failing test**

Create `tests/password-protected-settings-test.php`:

```php
<?php

declare(strict_types=1);

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function __($text, $domain = null)
{
    return $text;
}

function esc_url_raw($url)
{
    return $url;
}

function absint($value): int
{
    return abs((int) $value);
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

// Deterministic stand-ins. We are testing our own logic, not WordPress's hashing.
function wp_hash_password($password)
{
    return 'hashed:' . md5((string) $password);
}

function wp_check_password($password, $hash)
{
    return hash_equals((string) $hash, 'hashed:' . md5((string) $password));
}

$generated_keys = 0;

function wp_generate_password($length = 12, $special_chars = true)
{
    global $generated_keys;
    $generated_keys++;

    return 'generatedkey' . $generated_keys . str_repeat('x', max(0, $length - 13));
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';

use SFX\PasswordProtected\Settings;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function snapshot(array $post, array $existing = []): array
{
    return Settings::validate_snapshot($post, array_merge(Settings::defaults(), $existing));
}

// 1. Empty new password keeps the existing hash.
$r = snapshot(['password_new' => '', 'password_confirm' => ''], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '1: empty new password keeps existing hash');
assert_true($r['errors'] === [], '1: empty new password is not an error');

// 2. Mismatched confirm keeps the existing hash and reports an error.
$r = snapshot(['password_new' => 'abc', 'password_confirm' => 'xyz'], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '2: mismatch keeps existing hash');
assert_true(isset($r['errors']['password']), '2: mismatch reports a password error');

// 3. Matching new password produces a hash wp_check_password() accepts.
$r = snapshot(['password_new' => 'letmein', 'password_confirm' => 'letmein']);
assert_true(wp_check_password('letmein', $r['values']['password']), '3: matching password produces a usable hash');

// 4. Feeding values back as $existing with an empty $post password leaves the hash byte-identical.
$first = snapshot(['password_new' => 'letmein', 'password_confirm' => 'letmein', 'status' => '1']);
$second = Settings::validate_snapshot(['status' => '1'], $first['values']);
assert_true(
    $second['values']['password'] === $first['values']['password'],
    '4: re-validating over its own output does not re-hash or discard the password'
);

// 5. Status on + password set in the SAME snapshot => status stays on.
$r = snapshot(['status' => '1', 'password_new' => 'letmein', 'password_confirm' => 'letmein']);
assert_true($r['values']['status'] === true, '5: status survives when the password is set in the same save');
assert_true(!isset($r['errors']['status']), '5: no status error when password set in same save');

// 6. Status on + no password anywhere => error and status forced off.
$r = snapshot(['status' => '1']);
assert_true($r['values']['status'] === false, '6: status forced off without a password');
assert_true(isset($r['errors']['status']), '6: status without a password reports an error');

// 7. Bypass enabled with an empty stored key => key generated. With a stored key => preserved.
$r = snapshot(['bypass_enabled' => '1'], ['bypass_key' => '']);
assert_true($r['values']['bypass_key'] !== '', '7: enabling bypass with no stored key generates one');

$r = snapshot(['bypass_enabled' => '1'], ['bypass_key' => 'storedkey123']);
assert_true($r['values']['bypass_key'] === 'storedkey123', '7: an existing stored key is preserved byte-identical');

// 8. Rotate intent => new key differing from the old.
$r = snapshot(['bypass_enabled' => '1', 'bypass_rotate' => '1'], ['bypass_key' => 'storedkey123']);
assert_true($r['values']['bypass_key'] !== 'storedkey123', '8: rotate intent replaces the key');
assert_true($r['values']['bypass_key'] !== '', '8: rotate intent produces a non-empty key');

// 9. Stale-form regression: a posted key value cannot change the stored key.
$r = snapshot(
    ['bypass_enabled' => '1', 'bypass_key' => 'STALE_KEY_FROM_OLD_FORM'],
    ['bypass_key' => 'currentkey456']
);
assert_true(
    $r['values']['bypass_key'] === 'currentkey456',
    '9: a posted key is ignored — the stored key survives a stale form'
);

// 10. Disabling bypass preserves the key.
$r = snapshot([], ['bypass_key' => 'currentkey456']);
assert_true($r['values']['bypass_enabled'] === false, '10: absent checkbox disables bypass');
assert_true($r['values']['bypass_key'] === 'currentkey456', '10: disabling bypass preserves the key');

// 11. Unchecked checkboxes => false, not "unchanged".
$r = snapshot([], ['allow_admins' => true, 'allow_users' => true]);
assert_true($r['values']['allow_admins'] === false, '11: absent allow_admins becomes false');
assert_true($r['values']['allow_users'] === false, '11: absent allow_users becomes false');

// 12. remember_me_lifetime clamped into 1..365.
foreach (['0' => 1, '9999' => 365] as $input => $expected) {
    $r = snapshot(['remember_me_lifetime' => (string) $input]);
    assert_true(
        $r['values']['remember_me_lifetime'] === $expected,
        "12: lifetime {$input} clamps to {$expected}"
    );
}
$r = snapshot(['remember_me_lifetime' => '-5']);
assert_true(
    $r['values']['remember_me_lifetime'] >= 1 && $r['values']['remember_me_lifetime'] <= 365,
    '12: negative lifetime lands inside 1..365'
);
$r = snapshot(['remember_me_lifetime' => '']);
assert_true($r['values']['remember_me_lifetime'] === 14, '12: empty lifetime falls back to the default');

// 13. Array-valued inputs are treated as absent, with no TypeError.
$r = snapshot(['password_new' => ['x'], 'password_confirm' => ['x']], ['password' => 'hashed:existing']);
assert_true($r['values']['password'] === 'hashed:existing', '13: array-valued password is treated as absent');

// 13b. Array-valued CHECKBOXES are treated as absent too. `!empty(['x'])` is true,
//      which is exactly the trap: ?status[]=x must not switch protection on.
$r = snapshot(['status' => ['x'], 'password_new' => 'p', 'password_confirm' => 'p']);
assert_true($r['values']['status'] === false, '13b: array-valued status is treated as absent, not as checked');

$r = snapshot(['allow_admins' => ['x']], ['allow_admins' => false]);
assert_true($r['values']['allow_admins'] === false, '13b: array-valued allow_admins is treated as absent');

$r = snapshot(['bypass_enabled' => '1', 'bypass_rotate' => ['x']], ['bypass_key' => 'storedkey123']);
assert_true(
    $r['values']['bypass_key'] === 'storedkey123',
    '13b: array-valued bypass_rotate does not rotate the key'
);

// 13a. Key ORDER matches defaults(), because save_from_request() detects a failed
//      write with `get() !== values` and PHP's === on arrays is order-sensitive.
//      Without this, every successful save reports itself as a database failure.
$r = snapshot(['status' => '1', 'password_new' => 'p', 'password_confirm' => 'p']);
assert_true(
    array_keys($r['values']) === array_keys(Settings::defaults()),
    '13a: values come back in defaults() key order, so === against get() is meaningful'
);

// 13c. The request readers themselves.
assert_true(Settings::has_string(['a' => 'x'], 'a') === true, '13c: has_string true for a present string');
assert_true(Settings::has_string(['a' => ''], 'a') === true, '13c: has_string true for an empty string — presence, not emptiness');
assert_true(Settings::has_string([], 'a') === false, '13c: has_string false when absent');
assert_true(Settings::has_string(['a' => ['x']], 'a') === false, '13c: has_string false for an array');
assert_true(Settings::post_bool(['a' => '1'], 'a') === true, '13c: post_bool true for "1"');
assert_true(Settings::post_bool(['a' => '0'], 'a') === false, '13c: post_bool false for "0"');
assert_true(Settings::post_bool([], 'a') === false, '13c: post_bool false when absent');
assert_true(Settings::post_bool(['a' => ['x']], 'a') === false, '13c: post_bool false for an array');
assert_true(Settings::post_string(['a' => 'x'], 'a') === 'x', '13c: post_string returns the string');
assert_true(Settings::post_string(['a' => ['x']], 'a') === '', '13c: post_string empty for an array');
assert_true(Settings::request_string(['a' => 'a\\"b'], 'a') === 'a"b', '13c: request_string unslashes');
assert_true(Settings::request_string(['a' => ['x']], 'a') === '', '13c: request_string empty for an array');

// 14. Settings::get() normalizes anything the database throws at it.
foreach ([null, 'a scalar', 42] as $junk) {
    $test_options = [Settings::OPTION_NAME => $junk];
    $values = Settings::get();
    assert_true($values === Settings::defaults(), '14: non-array stored option falls back to defaults');
    assert_true(Settings::get_password_hash() === '', '14: get_password_hash() returns a string for junk input');
}

$test_options = [Settings::OPTION_NAME => [
    'status' => '1',
    'password' => ['not', 'a', 'string'],
    'remember_me_lifetime' => '9999',
    'allow_admins' => 0,
]];
$values = Settings::get();
assert_true($values['status'] === true, '14: string "1" casts to bool true');
assert_true($values['password'] === '', '14: array password normalizes to an empty string');
assert_true($values['remember_me_lifetime'] === 365, '14: stored lifetime is clamped on read');
assert_true($values['allow_admins'] === false, '14: stored 0 casts to bool false');
assert_true(Settings::get_password_hash() === '', '14: get_password_hash() is always a string');

// 15. IP allowlist keeps valid entries and drops the rest.
$test_options = [Settings::OPTION_NAME => ['allowed_ips' => "192.168.1.1\nnot-an-ip\n\n2001:db8::1"]];
assert_true(
    Settings::allowed_ips() === ['192.168.1.1', '2001:db8::1'],
    '15: allowed_ips() keeps valid IPv4/IPv6 and drops invalid lines'
);

echo "OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/password-protected-settings-test.php`
Expected: FAIL — `Failed to open stream: No such file or directory ... inc/PasswordProtected/Settings.php`

- [ ] **Step 3: Write the implementation**

Create `inc/PasswordProtected/index.php`:

```php
<?php
// Silence is golden.
```

Create `inc/PasswordProtected/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * All settings for this module live in ONE array option.
 *
 * Not register_setting(): options.php writes a group's options one at a time
 * with no transaction, and this module's fields have cross-field rules
 * (status needs a password, bypass needs a key). A single row write is atomic;
 * twelve sequential ones are not. See the spec for the full reasoning.
 */
class Settings
{
    public const OPTION_NAME = 'sfx_password_protected_options';

    private const TYPES = [
        'status'               => 'bool',
        'allow_admins'         => 'bool',
        'allow_users'          => 'bool',
        'allow_feeds'          => 'bool',
        'allow_rest'           => 'bool',
        'password'             => 'string',
        'allowed_ips'          => 'string',
        'allow_remember_me'    => 'bool',
        'remember_me_lifetime' => 'int',
        'bypass_enabled'       => 'bool',
        'bypass_key'           => 'string',
        'bypass_redirect'      => 'string',
    ];

    private const BOOL_FIELDS = [
        'status',
        'allow_admins',
        'allow_users',
        'allow_feeds',
        'allow_rest',
        'allow_remember_me',
        'bypass_enabled',
    ];

    public static function defaults(): array
    {
        return [
            'status'               => false,
            // On by default: the safe setting for whoever flips the switch.
            'allow_admins'         => true,
            'allow_users'          => false,
            'allow_feeds'          => false,
            'allow_rest'           => false,
            'password'             => '',
            'allowed_ips'          => '',
            'allow_remember_me'    => false,
            'remember_me_lifetime' => 14,
            'bypass_enabled'       => false,
            'bypass_key'           => '',
            'bypass_redirect'      => '',
        ];
    }

    /**
     * Always returns every key, fully typed. The stored option may be null, a
     * scalar or an array of junk after an import or a hand-edited database;
     * none of that may reach wp_check_password() as a non-string.
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $values = self::defaults();
        foreach ($values as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = self::cast($key, $stored[$key], $default);
            }
        }

        $values['remember_me_lifetime'] = self::clamp_lifetime_int($values['remember_me_lifetime']);

        return $values;
    }

    public static function get_password_hash(): string
    {
        return self::get()['password'];
    }

    /**
     * @return array<int, string>
     */
    public static function allowed_ips(): array
    {
        $raw = self::get()['allowed_ips'];
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $raw) ?: []),
            static fn(string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
    }

    /**
     * Is this field present AND a string?
     *
     * Distinct from post_string(), which cannot tell an absent field from an
     * empty one — a login handler needs "was a password field submitted at
     * all", not "is it non-empty".
     */
    public static function has_string(array $source, string $key): bool
    {
        return isset($source[$key]) && is_string($source[$key]);
    }

    /**
     * Type guard for a request string. Does NOT unslash — use request_string()
     * when reading a raw superglobal.
     *
     * Anything not a string is treated as absent: `?field[]=x` supplies an
     * array, and handing that to wp_check_password() or hash_equals() is a
     * TypeError, i.e. a fatal on a public endpoint.
     */
    public static function post_string(array $source, string $key): string
    {
        return self::has_string($source, $key) ? $source[$key] : '';
    }

    /**
     * Type guard + unslash, for reading raw $_GET/$_POST/$_REQUEST.
     * WordPress slashes those, so a URL holding a quote arrives backslashed.
     */
    public static function request_string(array $source, string $key): string
    {
        $value = self::post_string($source, $key);

        return $value === '' ? '' : (string) wp_unslash($value);
    }

    /**
     * Type guard for a checkbox.
     *
     * NOT `!empty($source[$key])`: `!empty(['x'])` is true, so `?status[]=x`
     * would read as "checked" and could switch protection on — or, on the
     * public login form, silently upgrade a session cookie to a persistent one.
     */
    public static function post_bool(array $source, string $key): bool
    {
        return isset($source[$key]) && is_scalar($source[$key]) && (bool) $source[$key];
    }

    /**
     * @param array $post     UNTRUSTED request fields, already unslashed by the
     *                        caller. Never holds a hash or a bypass key.
     * @param array $existing TRUSTED stored values. The only source of the hash
     *                        and the key.
     *
     * @return array{values: array, errors: array<string, string>}
     */
    public static function validate_snapshot(array $post, array $existing): array
    {
        $existing = array_merge(self::defaults(), array_intersect_key($existing, self::defaults()));
        $errors = [];
        $values = [];

        foreach (self::BOOL_FIELDS as $key) {
            $values[$key] = self::post_bool($post, $key);
        }

        $new     = self::post_string($post, 'password_new');
        $confirm = self::post_string($post, 'password_confirm');

        if ($new === '') {
            $values['password'] = (string) $existing['password'];
        } elseif ($new !== $confirm) {
            $errors['password'] = __('The two password fields did not match. The password was left unchanged.', 'sfxtheme');
            $values['password'] = (string) $existing['password'];
        } else {
            $values['password'] = wp_hash_password($new);
        }

        // Checked against the hash resulting from THIS snapshot, not the stored
        // one, so "set a password and switch protection on" in one save works.
        if ($values['status'] && $values['password'] === '') {
            $errors['status'] = __('Set a password before switching protection on. Protection has been left off.', 'sfxtheme');
            $values['status'] = false;
        }

        // The key is never read from $post. A stale form would otherwise
        // silently restore a rotated key and revive links already revoked.
        $stored_key = (string) $existing['bypass_key'];
        if (self::post_bool($post, 'bypass_rotate')) {
            $values['bypass_key'] = wp_generate_password(20, false);
        } elseif ($values['bypass_enabled'] && $stored_key === '') {
            $values['bypass_key'] = wp_generate_password(20, false);
        } else {
            $values['bypass_key'] = $stored_key;
        }

        $values['allowed_ips']          = self::normalize_ip_list(self::post_string($post, 'allowed_ips'));
        $values['bypass_redirect']      = esc_url_raw(self::post_string($post, 'bypass_redirect'));
        $values['remember_me_lifetime'] = self::clamp_lifetime_raw(self::post_string($post, 'remember_me_lifetime'));

        // Canonical key order, matching defaults() and therefore get().
        // Not cosmetic: PHP's === on arrays is order-sensitive, and this array
        // is compared against get() to detect a failed write. Built in the
        // order above it would never compare equal, and every successful save
        // would report itself as a database failure.
        $values = array_replace(self::defaults(), $values);

        return ['values' => $values, 'errors' => $errors];
    }

    public static function normalize_ip_list(string $raw): string
    {
        $lines = array_filter(
            array_map('trim', preg_split('/\R/', $raw) ?: []),
            static fn(string $ip): bool => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
        );

        return implode("\n", $lines);
    }

    private static function cast(string $key, $value, $default)
    {
        switch (self::TYPES[$key]) {
            case 'bool':
                return is_scalar($value) ? (bool) $value : $default;
            case 'int':
                return is_scalar($value) ? (int) $value : $default;
            default:
                return is_string($value) ? $value : $default;
        }
    }

    private static function clamp_lifetime_raw(string $raw): int
    {
        if ($raw === '') {
            return (int) self::defaults()['remember_me_lifetime'];
        }

        return self::clamp_lifetime_int(absint($raw));
    }

    private static function clamp_lifetime_int(int $value): int
    {
        if ($value < 1) {
            return 1;
        }

        return $value > 365 ? 365 : $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/password-protected-settings-test.php`
Expected: `OK`

- [ ] **Step 5: Verify the existing suite still passes**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: five lines, none containing `FAIL`.

- [ ] **Step 6: Commit**

```bash
git add inc/PasswordProtected/Settings.php inc/PasswordProtected/index.php tests/password-protected-settings-test.php
git commit -m "feat(password-protected): settings defaults, typed getter and snapshot validation"
```

---

### Task 2: Auth — cookie signing

**Files:**
- Create: `inc/PasswordProtected/Auth.php`
- Test: `tests/password-protected-auth-test.php`

**Interfaces:**
- Consumes: `Settings::get()`, `Settings::get_password_hash()` from Task 1.
- Produces:
  - `Auth::site_id(): string`
  - `Auth::cookie_name(): string`
  - `Auth::generate_cookie(int $expiration, string $password_hash): string`
  - `Auth::validate_cookie($cookie = null, ?string $password_hash = null): bool`
  - `Auth::set_cookie(bool $remember): void`
  - `Auth::clear_cookie(): void`

- [ ] **Step 1: Write the failing test**

Create `tests/password-protected-auth-test.php`.

Note the two constants at the top. `DAY_IN_SECONDS` is evaluated when `Auth.php` is parsed (it is used in a class constant), and `COOKIEHASH` is reached the moment `validate_cookie(null, …)` falls back to reading `$_COOKIE` — which the malformed-input case below does deliberately. Both are WordPress constants that a bare `php tests/…` run does not have.

```php
<?php

declare(strict_types=1);

define('DAY_IN_SECONDS', 86400);
define('COOKIEHASH', 'testcookiehash');

$test_options = [];

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function wp_salt($scheme = 'auth')
{
    return 'test-salt-for-' . $scheme;
}

function wp_hash($data, $scheme = 'auth')
{
    return hash_hmac('md5', (string) $data, wp_salt($scheme));
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';
require_once __DIR__ . '/../inc/PasswordProtected/Auth.php';

use SFX\PasswordProtected\Auth;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$GLOBALS['blog_id'] = 1;
$hash = 'hashed:letmein';
$future = time() + 3600;

// 1. A cookie generated by Auth validates.
$cookie = Auth::generate_cookie($future, $hash);
assert_true(Auth::validate_cookie($cookie, $hash), '1: a freshly generated cookie validates');

// 2. Tampered HMAC fails.
[$sid, $exp, $hmac] = explode('|', $cookie);
assert_true(
    !Auth::validate_cookie($sid . '|' . $exp . '|' . strrev($hmac), $hash),
    '2: a tampered HMAC fails'
);

// 3. Tampered expiration fails.
assert_true(
    !Auth::validate_cookie($sid . '|' . ($future + 9999) . '|' . $hmac, $hash),
    '3: a tampered expiration fails'
);

// 4. Expired cookie fails.
$expired = Auth::generate_cookie(time() - 10, $hash);
assert_true(!Auth::validate_cookie($expired, $hash), '4: an expired cookie fails');

// 5. Malformed cookies fail. `null` means "read $_COOKIE", which is empty here.
foreach (['', 'garbage', 'a|b', 'a|b|c|d', null, ['array']] as $i => $bad) {
    assert_true(!Auth::validate_cookie($bad, $hash), "5: malformed cookie #{$i} fails");
}

// 6. Non-integer expiration fails.
assert_true(!Auth::validate_cookie($sid . '|9e99|' . $hmac, $hash), '6: a non-integer expiration fails');

// 7. Foreign site_id fails.
assert_true(!Auth::validate_cookie('bid_999|' . $exp . '|' . $hmac, $hash), '7: a foreign site_id fails');

// 8. A cookie signed against a different password hash fails.
assert_true(
    !Auth::validate_cookie($cookie, 'hashed:a-different-password'),
    '8: changing the password invalidates every existing cookie'
);

echo "OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/password-protected-auth-test.php`
Expected: FAIL — `Failed to open stream: No such file or directory ... inc/PasswordProtected/Auth.php`

- [ ] **Step 3: Write the implementation**

Create `inc/PasswordProtected/Auth.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Cookie handling, mirroring WordPress core's own auth cookie scheme.
 *
 * The stored password hash is baked into the signing key, so changing the
 * password invalidates every outstanding cookie for free. That is also the
 * only revocation this module has — see the spec.
 */
class Auth
{
    private const SESSION_WINDOW = 20 * DAY_IN_SECONDS;

    public static function site_id(): string
    {
        $blog_id = $GLOBALS['blog_id'] ?? 1;

        return 'bid_' . $blog_id;
    }

    public static function cookie_name(): string
    {
        return 'sfx_pp_' . COOKIEHASH;
    }

    public static function generate_cookie(int $expiration, string $password_hash): string
    {
        $site_id = self::site_id();
        $key     = wp_hash($site_id . '|' . $password_hash . '|' . $expiration, 'auth');
        $hmac    = hash_hmac('sha256', $site_id . '|' . $expiration, $key);

        return $site_id . '|' . $expiration . '|' . $hmac;
    }

    /**
     * @param mixed $cookie Raw cookie value; may be anything a client sent.
     *                      null means "read it from $_COOKIE".
     */
    public static function validate_cookie($cookie = null, ?string $password_hash = null): bool
    {
        if ($cookie === null) {
            $cookie = $_COOKIE[self::cookie_name()] ?? null;
        }

        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 3) {
            return false;
        }

        [$site_id, $expiration, $hmac] = $parts;

        if (!hash_equals(self::site_id(), $site_id)) {
            return false;
        }

        // An integer, not "9e99" and not padding.
        if (preg_match('/^\d{1,12}$/', $expiration) !== 1) {
            return false;
        }

        // time() is UTC. The plugin's current_time('timestamp') is timezone-
        // shifted and compares wrongly against a UTC lifetime.
        if ((int) $expiration < time()) {
            return false;
        }

        if ($password_hash === null) {
            $password_hash = Settings::get_password_hash();
        }

        $expected = self::generate_cookie((int) $expiration, $password_hash);

        return hash_equals($expected, $cookie);
    }

    public static function set_cookie(bool $remember): void
    {
        $settings = Settings::get();

        if ($remember) {
            $expiration = time() + ($settings['remember_me_lifetime'] * DAY_IN_SECONDS);
            $expire     = $expiration;
        } else {
            $expiration = time() + self::SESSION_WINDOW;
            $expire     = 0; // Browser session cookie.
        }

        $cookie = self::generate_cookie($expiration, $settings['password']);

        foreach (self::cookie_paths() as $path) {
            setcookie(self::cookie_name(), $cookie, self::cookie_options($expire, $path));
        }
    }

    public static function clear_cookie(): void
    {
        // Identical attributes to set_cookie(), for every path it writes.
        // Mismatched attributes leave a live cookie behind, which is a logout
        // that does not log out.
        foreach (self::cookie_paths() as $path) {
            setcookie(self::cookie_name(), '', self::cookie_options(time() - DAY_IN_SECONDS, $path));
        }

        unset($_COOKIE[self::cookie_name()]);
    }

    /**
     * @return array<int, string>
     */
    private static function cookie_paths(): array
    {
        $paths = [COOKIEPATH];

        if (SITECOOKIEPATH !== COOKIEPATH) {
            $paths[] = SITECOOKIEPATH;
        }

        return $paths;
    }

    private static function cookie_options(int $expires, string $path): array
    {
        return [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly' => true,
            // Lax still sets cookies on top-level cross-site navigation, which
            // is exactly what clicking a bypass link in an email is.
            'samesite' => 'Lax',
        ];
    }
}
```

`COOKIEPATH`, `SITECOOKIEPATH`, `COOKIE_DOMAIN` and `is_ssl()` are only reached from `set_cookie()`/`clear_cookie()`, which the test never calls — so the test needs no stubs for them.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/password-protected-auth-test.php`
Expected: `OK`

- [ ] **Step 5: Verify the whole suite**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: six lines, none containing `FAIL`.

- [ ] **Step 6: Commit**

```bash
git add inc/PasswordProtected/Auth.php tests/password-protected-auth-test.php
git commit -m "feat(password-protected): HMAC-SHA256 auth cookie signed against the password hash"
```

---

### Task 3: Controller — the three predicates

Predicates only. No constructor and **no `get_feature_config()`** — see the ordering note at the top of this plan. `auto_register_features()` skips a `Controller` without `get_feature_config()`, so this commit is inert on a live site.

**Files:**
- Create: `inc/PasswordProtected/Controller.php`
- Test: `tests/password-protected-predicates-test.php`

**Interfaces:**
- Consumes: `Settings::get()`, `Settings::allowed_ips()` from Task 1.
- Produces:
  - `Controller::is_protection_enabled(): bool`
  - `Controller::is_configuration_broken(): bool`
  - `Controller::is_visitor_exempt(): bool`
  - `Controller::is_active(): bool`
  - `Controller::bypass_key_matches($submitted): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/password-protected-predicates-test.php`:

```php
<?php

declare(strict_types=1);

$test_options = [];
$test_caps = [];
$test_logged_in = false;
$test_is_feed = false;
$test_is_robots = false;

function get_option($name, $default = false)
{
    global $test_options;

    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}

function current_user_can($cap)
{
    global $test_caps;

    return in_array($cap, $test_caps, true);
}

function is_user_logged_in()
{
    global $test_logged_in;

    return $test_logged_in;
}

function is_feed()
{
    global $test_is_feed;

    return $test_is_feed;
}

function is_robots()
{
    global $test_is_robots;

    return $test_is_robots;
}

function apply_filters($tag, $value, ...$args)
{
    return $value;
}

function __($text, $domain = null)
{
    return $text;
}

function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

require_once __DIR__ . '/../inc/PasswordProtected/Settings.php';
require_once __DIR__ . '/../inc/PasswordProtected/Controller.php';

use SFX\PasswordProtected\Controller;
use SFX\PasswordProtected\Settings;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function configure(array $values): void
{
    global $test_options;

    $test_options = [Settings::OPTION_NAME => array_merge(Settings::defaults(), $values)];
}

function reset_request(): void
{
    global $test_caps, $test_logged_in, $test_is_feed, $test_is_robots;

    $test_caps = [];
    $test_logged_in = false;
    $test_is_feed = false;
    $test_is_robots = false;
    unset($_SERVER['REMOTE_ADDR']);
}

// 1. is_protection_enabled() follows status and nothing else.
reset_request();
configure(['status' => false, 'password' => 'hashed:x']);
assert_true(!Controller::is_protection_enabled(), '1: disabled when status is off');

configure(['status' => true, 'password' => 'hashed:x']);
assert_true(Controller::is_protection_enabled(), '1: enabled when status is on');

// 2. THE FAIL-CLOSED INVARIANT: an empty hash must NOT switch protection off.
configure(['status' => true, 'password' => '']);
assert_true(
    Controller::is_protection_enabled(),
    '2: protection stays enabled with an empty password hash — otherwise a broken credential serves the site publicly'
);

// 3. is_configuration_broken() is exactly status-on plus a normalized-empty hash.
assert_true(Controller::is_configuration_broken(), '3: status on + empty hash is broken');

configure(['status' => true, 'password' => 'total-garbage-not-a-real-hash']);
assert_true(
    !Controller::is_configuration_broken(),
    '3: a non-empty hash of any shape is not "broken" — no format sniffing'
);

configure(['status' => false, 'password' => '']);
assert_true(!Controller::is_configuration_broken(), '3: status off is never broken');

// 4. Each exemption flips only when its own option is on.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
assert_true(Controller::is_visitor_exempt(), '4: an allowlisted IP is exempt');
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
assert_true(!Controller::is_visitor_exempt(), '4: a non-allowlisted IP is not exempt');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => true]);
$test_caps = ['manage_options'];
assert_true(Controller::is_visitor_exempt(), '4: an admin is exempt when allow_admins is on');

configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => false]);
assert_true(!Controller::is_visitor_exempt(), '4: an admin is not exempt when allow_admins is off');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_users' => true]);
$test_logged_in = true;
assert_true(Controller::is_visitor_exempt(), '4: a logged-in user is exempt when allow_users is on');

configure(['status' => true, 'password' => 'hashed:x', 'allow_users' => false]);
assert_true(!Controller::is_visitor_exempt(), '4: a logged-in user is not exempt when allow_users is off');

// 5. Absent / non-string REMOTE_ADDR is no exemption and no error.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
assert_true(!Controller::is_visitor_exempt(), '5: absent REMOTE_ADDR grants no IP exemption');
$_SERVER['REMOTE_ADDR'] = ['203.0.113.7'];
assert_true(!Controller::is_visitor_exempt(), '5: array REMOTE_ADDR grants no IP exemption');

// 6. is_active() is false for an exempt visitor, true otherwise.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_admins' => true]);
$test_caps = ['manage_options'];
assert_true(!Controller::is_active(), '6: an exempt visitor is not gated');

reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
assert_true(Controller::is_active(), '6: an ordinary visitor is gated');

// 7. Query-dependent exemptions.
reset_request();
configure(['status' => true, 'password' => 'hashed:x']);
$test_is_robots = true;
assert_true(!Controller::is_active(), '7: robots.txt is never gated');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => true]);
$test_is_feed = true;
assert_true(!Controller::is_active(), '7: a feed is not gated when allow_feeds is on');

reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allow_feeds' => false]);
$test_is_feed = true;
assert_true(Controller::is_active(), '7: a feed IS gated when allow_feeds is off');

// 8. THE CACHE INVARIANT: an exemption must never switch protection off globally.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'allowed_ips' => '203.0.113.7']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
assert_true(Controller::is_visitor_exempt(), '8: precondition — the visitor is exempt');
assert_true(
    Controller::is_protection_enabled(),
    '8: protection stays enabled for an exempt visitor — this is what keeps DONOTCACHEPAGE on and stops an allowlisted IP warming a public cache with protected content'
);

// 9. Bypass key comparison.
reset_request();
configure(['status' => true, 'password' => 'hashed:x', 'bypass_key' => 'correctkey']);
assert_true(Controller::bypass_key_matches('correctkey'), '9: the correct key matches');
assert_true(!Controller::bypass_key_matches('wrongkey'), '9: a wrong key does not match');
assert_true(!Controller::bypass_key_matches(''), '9: an empty submitted key does not match');
assert_true(!Controller::bypass_key_matches(['correctkey']), '9: an array submitted key does not match');
assert_true(!Controller::bypass_key_matches(null), '9: a null submitted key does not match');

configure(['status' => true, 'password' => 'hashed:x', 'bypass_key' => '']);
assert_true(!Controller::bypass_key_matches(''), '9: empty stored key + empty submitted key does not match');
assert_true(!Controller::bypass_key_matches('anything'), '9: an empty stored key never matches');

echo "OK\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/password-protected-predicates-test.php`
Expected: FAIL — `Failed to open stream: No such file or directory ... inc/PasswordProtected/Controller.php`

- [ ] **Step 3: Write the implementation**

Create `inc/PasswordProtected/Controller.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

/**
 * Three predicates, split by what each one depends on:
 *
 *   is_protection_enabled()  one option        callable anywhere, incl. init
 *   is_visitor_exempt()      options + user    callable after init
 *   is_active()              + query state     callable only after `wp`
 *
 * Collapsing them into one predicate is what an earlier draft did, and it was
 * wrong twice: it let a per-visitor exemption disable cache protection, and it
 * called is_feed() before the query existed. See the spec.
 */
class Controller
{
    /**
     * `status` and nothing else.
     *
     * It deliberately does NOT also require a non-empty hash. Requiring one
     * inverts the failure mode: a corrupted status=on/password='' would make
     * this false, switching protection OFF and serving the site to the world.
     * The broken state is handled by is_configuration_broken() + behaviour.
     */
    public static function is_protection_enabled(): bool
    {
        return Settings::get()['status'];
    }

    /**
     * Status on with a normalized-empty hash. Settings::get() forces every
     * non-string to '', so this one comparison catches null, arrays and a
     * missing key alike.
     *
     * It does not judge whether a non-empty string is a "valid" hash: sniffing
     * for $P$/$wp$/$2y$ would couple this to core's hashing internals. A
     * garbage non-empty hash fails closed silently — wp_check_password() never
     * matches it — it just gets no notice.
     */
    public static function is_configuration_broken(): bool
    {
        $settings = Settings::get();

        return $settings['status'] && $settings['password'] === '';
    }

    public static function is_visitor_exempt(): bool
    {
        $settings = Settings::get();

        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        if ($ip !== '' && in_array($ip, Settings::allowed_ips(), true)) {
            return true;
        }

        if ($settings['allow_admins'] && current_user_can('manage_options')) {
            return true;
        }

        if ($settings['allow_users'] && is_user_logged_in()) {
            return true;
        }

        return false;
    }

    /**
     * Must not be called before `wp`: is_feed() and is_robots() are only
     * reliable once the main query is parsed. REST must not call this at all —
     * rest_authentication_errors fires at parse_request, before the query.
     */
    public static function is_active(): bool
    {
        $active = self::is_protection_enabled() && !self::is_visitor_exempt();

        if ($active && is_robots()) {
            $active = false;
        }

        if ($active && Settings::get()['allow_feeds'] && is_feed()) {
            $active = false;
        }

        return (bool) apply_filters('sfx_pp_is_active', $active);
    }

    /**
     * @param mixed $submitted Raw request value; may be anything.
     */
    public static function bypass_key_matches($submitted): bool
    {
        $stored = Settings::get()['bypass_key'];

        // The empty-stored guard is not padding: without it an empty stored key
        // plus ?sfx_bypass= compares '' to '' and lets the world in.
        if ($stored === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/password-protected-predicates-test.php`
Expected: `OK`

- [ ] **Step 5: Verify the whole suite**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: seven lines, none containing `FAIL`.

- [ ] **Step 6: Commit**

```bash
git add inc/PasswordProtected/Controller.php tests/password-protected-predicates-test.php
git commit -m "feat(password-protected): protection predicates split by lifecycle dependency"
```

---

### Task 4: Admin page and the save handler

Before the Controller, not after: `Controller::get_feature_config()` reads `AdminPage::$menu_slug`, and `auto_register_features()` calls it on every request regardless of the gate. `AdminPage` must exist first. On its own this task is dead code — nothing calls `AdminPage::register()` until Task 6 — which is exactly why it is safe to land here.

**Files:**
- Create: `inc/PasswordProtected/AdminPage.php`
- Modify: `inc/PasswordProtected/Settings.php` (add `save_from_request()`)

**Interfaces:**
- Consumes: `Settings::get()`, `Settings::validate_snapshot()` from Task 1; `\SFX\AccessControl`.
- Produces:
  - `AdminPage::$menu_slug` = `'sfx-password-protected'`
  - `AdminPage::$page_title`, `AdminPage::$description`
  - `AdminPage::register(): void`
  - `AdminPage::page_url(): string`
  - `Settings::save_from_request(): void`

- [ ] **Step 1: Add `save_from_request()` to `Settings.php`**

Append inside the `Settings` class, after `validate_snapshot()`:

```php
    /**
     * Post/Redirect/Get, following exactly what options.php does.
     *
     * The transient and the `settings-updated` query arg are both load-bearing:
     * settings_errors() only consumes the transient when that arg is present.
     * Omit either and the notices silently never appear.
     */
    public static function save_from_request(): void
    {
        check_admin_referer('sfx_pp_save');
        \SFX\AccessControl::die_if_unauthorized_theme();

        // wp_unslash() the whole tree once; the readers below then type-check
        // each field individually.
        $result = self::validate_snapshot(wp_unslash($_POST), self::get());

        update_option(self::OPTION_NAME, $result['values']);

        // update_option() returns false both for "nothing changed" and for
        // "the write failed", so its return value cannot tell us which. Read
        // back instead: on a failed write the cache is not updated and get()
        // still returns the old values.
        $write_failed = self::get() !== $result['values'];

        foreach ($result['errors'] as $field => $message) {
            add_settings_error(self::OPTION_NAME, 'sfx_pp_' . $field, $message, 'error');
        }

        if ($write_failed) {
            add_settings_error(
                self::OPTION_NAME,
                'sfx_pp_write_failed',
                __('Settings could NOT be saved — the database write failed. Nothing was changed.', 'sfxtheme'),
                'error'
            );
        } else {
            // Never an unqualified success next to a red error.
            add_settings_error(
                self::OPTION_NAME,
                'sfx_pp_saved',
                $result['errors'] === []
                    ? __('Settings saved.', 'sfxtheme')
                    : __('Settings saved, but some fields were rejected.', 'sfxtheme'),
                $result['errors'] === [] ? 'success' : 'warning'
            );
        }

        set_transient('settings_errors', get_settings_errors(), 30);

        $target = add_query_arg('settings-updated', 'true', AdminPage::page_url());
        wp_safe_redirect(wp_validate_redirect($target, home_url('/')));
        exit;
    }
```

- [ ] **Step 2: Write `AdminPage.php`**

Create `inc/PasswordProtected/AdminPage.php`:

```php
<?php

declare(strict_types=1);

namespace SFX\PasswordProtected;

class AdminPage
{
    public static $menu_slug = 'sfx-password-protected';
    public static $page_title = 'Password Protection';
    public static $description = 'Protect the frontend of this site with a single shared password. Includes a shareable bypass URL for clients and reviewers.';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function page_url(): string
    {
        return admin_url('admin.php?page=' . self::$menu_slug);
    }

    public static function add_submenu_page(): void
    {
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }

        add_submenu_page(
            'sfx-theme-settings',
            self::$page_title,
            self::$page_title,
            // Broad cap, matching SFXBricksChildAdmin::register_admin_menu():
            // role-based SFX_THEME_ADMINS users without manage_options must
            // still reach the page AccessControl just authorized them for.
            // Render is guarded by die_if_unauthorized_theme() below.
            'read',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        \SFX\AccessControl::die_if_unauthorized_theme();

        $settings = Settings::get();
        $own_ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';
        ?>
        <div class="wrap" style="padding: 0; font-size: 14px;">
            <?php settings_errors(Settings::OPTION_NAME); ?>
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Password Protection', 'sfxtheme'); ?></h1>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="sfx_pp_save" />
                            <?php wp_nonce_field('sfx_pp_save'); ?>

                            <table class="sfx-form-table">
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Protection Status', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="status" value="1" <?php checked($settings['status']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('Switch the password prompt on for the whole frontend. Requires a password to be set.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Permissions', 'sfxtheme'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="allow_admins" value="1" <?php checked($settings['allow_admins']); ?> /> <?php esc_html_e('Allow administrators', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_users" value="1" <?php checked($settings['allow_users']); ?> /> <?php esc_html_e('Allow logged-in users', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_feeds" value="1" <?php checked($settings['allow_feeds']); ?> /> <?php esc_html_e('Allow RSS feeds', 'sfxtheme'); ?></label><br />
                                        <label><input type="checkbox" name="allow_rest" value="1" <?php checked($settings['allow_rest']); ?> /> <?php esc_html_e('Allow REST API', 'sfxtheme'); ?></label>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('New Password', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="password" name="password_new" value="" autocomplete="new-password" />
                                        <div class="sfx-description"><?php esc_html_e('Leave empty to keep the current password.', 'sfxtheme'); ?></div>
                                        <p>
                                            <input type="password" name="password_confirm" value="" autocomplete="new-password" />
                                        </p>
                                        <div class="sfx-description"><?php esc_html_e('Repeat the new password.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Allowed IP Addresses', 'sfxtheme'); ?></th>
                                    <td>
                                        <textarea name="allowed_ips" rows="4" cols="40"><?php echo esc_textarea($settings['allowed_ips']); ?></textarea>
                                        <div class="sfx-description">
                                            <?php
                                            printf(
                                                /* translators: %s: the visitor's own IP address */
                                                esc_html__('One IP address per line. Invalid lines are dropped on save. Your IP address is %s.', 'sfxtheme'),
                                                '<code>' . esc_html($own_ip) . '</code>'
                                            );
                                            ?>
                                        </div>
                                        <div class="sfx-description"><strong><?php esc_html_e('Behind a proxy or CDN this is the proxy address. Allowlisting it allowlists every visitor and silently disables protection.', 'sfxtheme'); ?></strong></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Stay Logged In', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="allow_remember_me" value="1" <?php checked($settings['allow_remember_me']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('Show a "Stay logged in" checkbox on the password prompt.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Keep For (days)', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="number" name="remember_me_lifetime" min="1" max="365" value="<?php echo esc_attr((string) $settings['remember_me_lifetime']); ?>" />
                                    </td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row">&nbsp;</th>
                                    <td><hr class="sfx-hr" /></td>
                                </tr>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Bypass URL', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="checkbox" name="bypass_enabled" value="1" <?php checked($settings['bypass_enabled']); ?> />
                                        <div class="sfx-description"><?php esc_html_e('A shareable link that grants access without the password. A key is generated automatically when you switch this on.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>

                                <?php if ($settings['bypass_key'] !== '') : ?>
                                    <tr valign="top">
                                        <th scope="row"><?php esc_html_e('Bypass Link', 'sfxtheme'); ?></th>
                                        <td>
                                            <?php // No name attribute: the key is never submitted. A stale form
                                                  // would otherwise silently restore a rotated key. ?>
                                            <input type="text" readonly onfocus="this.select();" style="width: 100%;"
                                                   value="<?php echo esc_attr(home_url('/?sfx_bypass=' . rawurlencode($settings['bypass_key']))); ?>" />
                                            <div class="sfx-description"><?php esc_html_e('Anyone with this link gets in. It will end up in browser history and server logs.', 'sfxtheme'); ?></div>
                                        </td>
                                    </tr>
                                    <tr valign="top">
                                        <th scope="row"><?php esc_html_e('Rotate Key', 'sfxtheme'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="bypass_rotate" value="1" />
                                                <?php esc_html_e('Generate a new key on save — the old link stops working immediately.', 'sfxtheme'); ?>
                                            </label>
                                            <div class="sfx-description"><strong><?php esc_html_e('Rotating the key does not log out anyone who already used the old link. Only changing the password does that.', 'sfxtheme'); ?></strong></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e('Redirect To', 'sfxtheme'); ?></th>
                                    <td>
                                        <input type="text" name="bypass_redirect" style="width: 100%;" value="<?php echo esc_attr($settings['bypass_redirect']); ?>" placeholder="<?php echo esc_attr(home_url('/')); ?>" />
                                        <div class="sfx-description"><?php esc_html_e('Where the bypass link sends the visitor. Empty means the home page.', 'sfxtheme'); ?></div>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>

                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('What this does not protect', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><strong><?php esc_html_e('Media and uploads are public.', 'sfxtheme'); ?></strong> <?php esc_html_e('Files under /wp-content/uploads/ are served by the webserver without WordPress running. Every image, PDF and video stays readable to anyone with the URL.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('admin-ajax.php and admin-post.php actions registered for logged-out visitors stay reachable.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('xmlrpc.php and wp-cron.php are not covered.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('There is no brute-force throttling. A shared password is guessable given time.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('The bypass key travels in a URL and will end up in browser history, CDN and server logs.', 'sfxtheme'); ?></li>
                        </ul>

                        <h2 class="sfx-section-title"><?php esc_html_e('Caching', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><strong><?php esc_html_e('Purge your page cache after switching protection on.', 'sfxtheme'); ?></strong> <?php esc_html_e('Pages cached beforehand keep being served without this theme ever running.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Server-level caches (Varnish, host full-page caches) may ignore the theme entirely and are out of reach.', 'sfxtheme'); ?></li>
                        </ul>

                        <h2 class="sfx-section-title"><?php esc_html_e('Logging everyone out', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Changing the password is the only thing that invalidates existing access. Switching protection off and on again does not.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
```

- [ ] **Step 3: Check syntax**

Run: `php -l inc/PasswordProtected/AdminPage.php && php -l inc/PasswordProtected/Settings.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify the unit tests still pass**

`save_from_request()` was added to `Settings.php`, which the settings test requires. It is never called there, but a parse problem would surface now.

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: seven lines, none containing `FAIL`.

- [ ] **Step 5: Commit**

```bash
git add inc/PasswordProtected/AdminPage.php inc/PasswordProtected/Settings.php
git commit -m "feat(password-protected): settings page and atomic admin-post save handler"
```

---

### Task 5: The login template

**Files:**
- Create: `inc/PasswordProtected/login-form.php`

**Interfaces:**
- Consumes: `Controller::$errors` and `Controller::login_url()` — **both arrive in Task 6**. The template is only ever loaded by `Controller::maybe_show_login()`, which also arrives in Task 6, so nothing executes this file in between.
- Produces: nothing other tasks call.

- [ ] **Step 1: Write the template**

Create `inc/PasswordProtected/login-form.php`:

```php
<?php

/**
 * Frontend password prompt, styled as wp-login.php.
 *
 * Deliberately does NOT call wp_head(). That would drag the whole frontend head
 * pipeline onto this page — theme CSS, Bricks assets, analytics, canonical and
 * feed links, every plugin's callbacks — which is both the opposite of the
 * wp-login.php look and a way to leak the protected site's metadata to an
 * unauthenticated visitor.
 *
 * A template, not a class: no namespace here on purpose.
 * Override by placing sfx-password-protected-login.php in the theme root.
 */

declare(strict_types=1);

use SFX\PasswordProtected\Controller;
use SFX\PasswordProtected\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$sfx_pp_settings = Settings::get();
$sfx_pp_errors = Controller::$errors instanceof \WP_Error ? Controller::$errors : new \WP_Error();
$sfx_pp_redirect = Settings::request_string($_REQUEST, 'redirect_to');

nocache_headers();
header('Content-Type: ' . get_bloginfo('html_type') . '; charset=' . get_bloginfo('charset'));

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html(get_bloginfo('name', 'display')); ?></title>
    <?php
    // The `true` is mandatory. Without $force_echo this only ENQUEUES, and
    // since this document calls neither wp_head() nor print_admin_styles(),
    // nothing would ever print: an unstyled login page. $force_echo prints the
    // handle and its dependencies (dashicons, buttons, forms, base styles).
    wp_admin_css('login', true);

    if (function_exists('wp_site_icon')) {
        wp_site_icon();
    }
    ?>
</head>
<?php // The login CSS is written against these classes; a bare #login does not get the look. ?>
<body class="login wp-core-ui">
<div id="login">
    <h1>
        <a href="<?php echo esc_url(home_url('/')); ?>">
            <?php echo esc_html(get_bloginfo('name', 'display')); ?>
        </a>
    </h1>

    <?php if ($sfx_pp_errors->has_errors()) : ?>
        <div id="login_error" role="alert">
            <?php foreach ($sfx_pp_errors->get_error_messages() as $sfx_pp_message) : ?>
                <p><?php echo esc_html($sfx_pp_message); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form name="sfx_pp_form" id="sfx_pp_form" action="<?php echo esc_url(Controller::login_url()); ?>" method="post">
        <p>
            <label for="sfx_pp_pwd"><?php esc_html_e('Password', 'sfxtheme'); ?></label>
            <input type="password"
                   name="sfx_pp_pwd"
                   id="sfx_pp_pwd"
                   class="input"
                   value=""
                   size="20"
                   autocomplete="current-password"
                   autofocus />
        </p>

        <?php if ($sfx_pp_settings['allow_remember_me']) : ?>
            <p class="forgetmenot">
                <input name="sfx_pp_rememberme" type="checkbox" id="sfx_pp_rememberme" value="1" />
                <label for="sfx_pp_rememberme"><?php esc_html_e('Stay logged in', 'sfxtheme'); ?></label>
            </p>
        <?php endif; ?>

        <p class="submit">
            <button type="submit" name="sfx_pp_submit" id="sfx_pp_submit" class="button button-primary button-large">
                <?php esc_html_e('Enter', 'sfxtheme'); ?>
            </button>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($sfx_pp_redirect); ?>" />
            <?php wp_nonce_field('sfx_pp_login'); ?>
        </p>
    </form>
</div>
</body>
</html>
<?php
exit;
```

- [ ] **Step 2: Check syntax**

Run: `php -l inc/PasswordProtected/login-form.php`
Expected: `No syntax errors detected in inc/PasswordProtected/login-form.php`

- [ ] **Step 3: Commit**

```bash
git add inc/PasswordProtected/login-form.php
git commit -m "feat(password-protected): wp-login styled password prompt"
```

---

### Task 6: Controller — handlers, hooks and feature config

The task that switches the module on. `get_feature_config()` lands here, last, because `auto_register_features()` calls it on **every** request before the gate is checked — so everything it names must already exist.

No unit test: every method here touches hooks, headers, redirects or `exit`, none of which this repo's harness can drive. They are covered by the manual checklist in Task 8. The predicates they call are already tested.

**Files:**
- Modify: `inc/PasswordProtected/Controller.php` (add to the class from Task 3)

**Interfaces:**
- Consumes: predicates (Task 3), `Auth::*` (Task 2), `Settings::*` (Task 1), `AdminPage::$menu_slug` / `register()` / `page_url()` (Task 4), `login-form.php` (Task 5).
- Produces:
  - `Controller::__construct()` — registers every hook
  - `Controller::$errors` — static `\WP_Error`, populated at `init`, read by the template at `template_redirect`
  - `Controller::login_url(string $redirect_to = ''): string`
  - `Controller::redirect_to(string $target): void`
  - `Controller::get_feature_config(): array`

- [ ] **Step 1: Add the members and handlers**

Insert `$errors` and `__construct()` at the top of the class body in `inc/PasswordProtected/Controller.php`, above `is_protection_enabled()`:

```php
    /**
     * Populated at `init`, read by login-form.php at `template_redirect`.
     * Same request, so a static property is enough: no transient, no session.
     */
    public static ?\WP_Error $errors = null;

    public function __construct()
    {
        self::$errors = new \WP_Error();

        AdminPage::register();

        // Priority 1, ahead of the handlers at 2: a login or bypass response
        // exits before the rest of the request runs, and must still have
        // DONOTCACHEPAGE defined.
        add_action('init', [self::class, 'disable_caching'], 1);
        // Ungated on purpose: a visitor must be able to clear a stale cookie
        // even while protection is switched off.
        add_action('init', [self::class, 'maybe_process_logout'], 1);
        add_action('init', [self::class, 'maybe_process_bypass'], 2);
        add_action('init', [self::class, 'maybe_process_login'], 2);

        add_action('template_redirect', [self::class, 'maybe_show_login'], -10);
        add_action('wp', [self::class, 'maybe_disable_feeds'], 10);
        add_filter('rest_authentication_errors', [self::class, 'filter_rest_access'], 10);

        add_action('admin_post_sfx_pp_save', [Settings::class, 'save_from_request'], 10);
        add_action('admin_notices', [self::class, 'maybe_render_broken_state_notice'], 10);
    }
```

Append below `bypass_key_matches()`:

```php
    /**
     * Keyed off is_protection_enabled(), NOT is_active(). If a per-visitor
     * exemption could switch this off, an anonymous visitor from an allowlisted
     * IP would get an exempt AND cacheable response, and a URL-keyed page cache
     * would then serve that protected page to everyone.
     */
    public static function disable_caching(): void
    {
        if (is_admin() || !self::is_protection_enabled()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    public static function maybe_process_logout(): void
    {
        if (Settings::request_string($_REQUEST, 'sfx-protected') !== 'logout') {
            return;
        }

        if (!wp_verify_nonce(Settings::request_string($_REQUEST, '_wpnonce'), 'sfx_pp_logout')) {
            return;
        }

        Auth::clear_cookie();

        self::redirect_to(Settings::request_string($_REQUEST, 'redirect_to'));
    }

    public static function maybe_process_login(): void
    {
        if (!self::is_protection_enabled() || !Settings::has_string($_POST, 'sfx_pp_pwd')) {
            return;
        }

        // Nonce first, and it is a hard stop. Extracted as a scalar before
        // wp_verify_nonce(), which would string-cast an array and warn.
        if (!wp_verify_nonce(Settings::request_string($_POST, '_wpnonce'), 'sfx_pp_login')) {
            self::$errors->add('expired_nonce', __('Your session expired. Please try again.', 'sfxtheme'));

            return;
        }

        if (!wp_check_password(Settings::request_string($_POST, 'sfx_pp_pwd'), Settings::get_password_hash())) {
            Auth::clear_cookie();
            self::$errors->add('incorrect_password', __('Incorrect password.', 'sfxtheme'));

            return;
        }

        $remember = Settings::get()['allow_remember_me'] && Settings::post_bool($_POST, 'sfx_pp_rememberme');

        nocache_headers();
        Auth::set_cookie($remember);

        self::redirect_to(Settings::request_string($_POST, 'redirect_to'));
    }

    public static function maybe_process_bypass(): void
    {
        if (!self::is_protection_enabled() || !Settings::get()['bypass_enabled']) {
            return;
        }

        $submitted = Settings::request_string($_GET, 'sfx_bypass');

        if ($submitted === '' || !self::bypass_key_matches($submitted)) {
            return; // Silently continue to the normal login gate.
        }

        // The bypass is a GET carrying a Set-Cookie. Without this an
        // intermediary may cache a response holding a working auth cookie and
        // keep handing out access after the key is rotated.
        nocache_headers();
        Auth::set_cookie(false);

        self::redirect_to(Settings::get()['bypass_redirect']);
    }

    public static function maybe_show_login(): void
    {
        if (!self::is_active() || Auth::validate_cookie()) {
            return;
        }

        // A gated feed is maybe_disable_feeds()'s job, and it already hooked
        // do_feed at `wp`. Without this early return, template_redirect (-10)
        // fires first and 302s the feed reader to an HTML login form — the
        // do_feed callback would never run and its message would be dead code.
        if (is_feed()) {
            return;
        }

        if (Settings::request_string($_REQUEST, 'sfx-protected') === 'login') {
            // load_template() performs no discovery of its own.
            $file = locate_template(['sfx-password-protected-login.php']);
            if (!$file) {
                $file = __DIR__ . '/login-form.php';
            }

            $file = apply_filters('sfx_pp_login_template', $file);
            if (!is_string($file) || !file_exists($file)) {
                $file = __DIR__ . '/login-form.php';
            }

            load_template($file);
            exit;
        }

        nocache_headers();
        self::redirect_to(self::login_url(self::current_url()));
    }

    public static function maybe_disable_feeds(): void
    {
        if (!self::is_active()) {
            return;
        }

        foreach (['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'] as $hook) {
            add_action($hook, [self::class, 'disable_feed'], 1);
        }
    }

    public static function disable_feed(): void
    {
        wp_die(
            sprintf(
                /* translators: %s: a link to the website */
                esc_html__('Feeds are not available for this site. Please visit the %s.', 'sfxtheme'),
                '<a href="' . esc_url(home_url('/')) . '">' . esc_html__('website', 'sfxtheme') . '</a>'
            )
        );
    }

    /**
     * @param mixed $access
     * @return mixed
     */
    public static function filter_rest_access($access)
    {
        // Never mask another plugin's more specific authentication failure.
        if (is_wp_error($access)) {
            return $access;
        }

        // Deliberately NOT is_active(): this fires at parse_request, before the
        // main query, so is_feed()/is_robots() are meaningless here.
        if (!self::is_protection_enabled() || self::is_visitor_exempt()) {
            return $access;
        }

        // For the block editor, which runs in wp-admin and calls /wp-json/.
        if (is_user_logged_in() && (current_user_can('edit_posts') || current_user_can('edit_pages'))) {
            return $access;
        }

        if (Auth::validate_cookie()) {
            return $access;
        }

        if (Settings::get()['allow_rest']) {
            return $access;
        }

        return new \WP_Error(
            'rest_cannot_access',
            __('Only authenticated users can access the REST API.', 'sfxtheme'),
            ['status' => rest_authorization_required_code()]
        );
    }

    public static function maybe_render_broken_state_notice(): void
    {
        if (!self::is_configuration_broken()) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Password Protection is switched on but has no password.', 'sfxtheme'),
            esc_html__('The site is showing the login screen and no password will open it. Set a password on the Password Protection settings page to restore access.', 'sfxtheme')
        );
    }

    public static function login_url(string $redirect_to = ''): string
    {
        $url = add_query_arg('sfx-protected', 'login', home_url('/'));

        if ($redirect_to !== '') {
            // The urlencode() is required, not redundant. add_query_arg() does
            // NOT encode the value you hand it: its urlencode_deep() runs over
            // the pre-existing query string, before `$qs[$args[0]] = $args[1]`,
            // and build_query() calls _http_build_query() with $urlencode=false.
            // Drop it and the target's own ? and & land raw in the query string
            // and tear it apart. (Verified against wp-includes/functions.php.)
            $url = add_query_arg('redirect_to', urlencode($redirect_to), $url);
        }

        return $url;
    }

    /**
     * The one redirect path in this module.
     *
     * wp_safe_redirect() alone is not enough: its fallback for a rejected host
     * is admin_url(), which would bounce a protected visitor into wp-admin.
     * $target is often attacker-controllable — this is a security boundary.
     */
    public static function redirect_to(string $target): void
    {
        // An empty target must become home BEFORE validation, not via the
        // fallback argument. wp_validate_redirect('') does not fall back: ''
        // parses as a relative path, so it is returned unchanged, and
        // wp_redirect('') then bails on `if ( ! $location )` without sending
        // a header — leaving the exit below to serve a blank page. That is the
        // DEFAULT bypass configuration (empty "Redirect To"), not a corner case.
        //
        // trim() first, because core trims too: "   " would slip past a bare
        // === '' check and reach wp_redirect() as '' anyway, same blank page.
        $target = trim($target);
        if ($target === '') {
            $target = home_url('/');
        }

        wp_safe_redirect(wp_validate_redirect($target, home_url('/')));
        exit;
    }

    private static function current_url(): string
    {
        $host = Settings::request_string($_SERVER, 'HTTP_HOST');
        $uri  = Settings::request_string($_SERVER, 'REQUEST_URI');

        if ($host === '' || $uri === '') {
            return home_url('/');
        }

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }

    public static function get_feature_config(): array
    {
        return [
            'class' => self::class,
            'menu_slug' => AdminPage::$menu_slug,
            'page_title' => AdminPage::$page_title,
            'description' => AdminPage::$description,
            'activation_option_name' => 'sfx_general_options',
            'activation_option_key' => 'enable_password_protected',
            'option_value' => true,
            'hook' => null,
            'error' => 'Missing PasswordProtected Controller class in theme',
        ];
    }
```

- [ ] **Step 2: Verify the predicates test still passes**

The test requires `Controller.php` but never constructs the class and never calls `get_feature_config()`, so neither `AdminPage` nor `Auth` needs loading. It must still pass unchanged.

Run: `php tests/password-protected-predicates-test.php`
Expected: `OK`

If it fails with `Class "SFX\PasswordProtected\AdminPage" not found`, the test is calling the constructor or the feature config — it must only touch the static predicates.

- [ ] **Step 3: Check syntax**

Run: `php -l inc/PasswordProtected/Controller.php`
Expected: `No syntax errors detected in inc/PasswordProtected/Controller.php`

- [ ] **Step 4: Confirm the site still loads**

The module is not gated on yet (Task 7 adds the field; an absent key reads as off), but `get_feature_config()` now runs on every request. Load any page of the site.

Expected: the site renders normally, no fatal, no `AdminPage not found`. If this breaks, Task 4 was skipped.

- [ ] **Step 5: Run the whole suite**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: seven lines, none containing `FAIL`.

- [ ] **Step 6: Commit**

```bash
git add inc/PasswordProtected/Controller.php
git commit -m "feat(password-protected): login, logout, bypass, REST and cache handlers"
```

---

### Task 7: Wire the module into the theme

Six small edits across six files. Until this lands, the module exists but no switch loads it.

**Files:**
- Modify: `inc/GeneralThemeOptions/Settings.php` (the gate field)
- Modify: `tests/support/overview-general-theme-options-settings-stub.php` (mirror the field)
- Modify: `inc/ThemeSettingsOverview/OverviewProvider.php` (list the module)
- Modify: `inc/SFXBricksChildTheme.php` (admin CSS hook suffix)
- Modify: `uninstall.php` (the option name)
- Modify: `inc/ImportExport/Controller.php` (exclusion comment only — no registration)

**Interfaces:**
- Consumes: `Controller::get_feature_config()` from Task 6, which `auto_register_features()` discovers by globbing `inc/*/Controller.php`. No registration code needed.
- Produces: a loadable feature.

- [ ] **Step 1: Add the gate field**

In `inc/GeneralThemeOptions/Settings.php`, inside `get_fields()`, insert after the `enable_security_header` entry (it ends with `],` on the line before `'id' => 'enable_smooth_scroll'`):

```php
            [
                'id'          => 'enable_password_protected',
                'label'       => __('Enable Password Protection module', 'sfxtheme'),
                'description' => __('Loads the password protection module and adds its settings page. The protection itself is switched on inside that page.', 'sfxtheme'),
                'type'        => 'checkbox',
                'default'     => 0,
                'group'       => 'general',
            ],
```

The label says *module*, not "Protect the frontend". This checkbox loads code; it protects nothing on its own. Two switches that both sound like "protection is on" is how someone ends up believing a public site is covered.

- [ ] **Step 2: Mirror it in the test stub**

In `tests/support/overview-general-theme-options-settings-stub.php`, inside `get_fields()`, after the `enable_security_header` line:

```php
            ['id' => 'enable_password_protected', 'default' => 0],
```

- [ ] **Step 3: List the module on the overview page**

In `inc/ThemeSettingsOverview/OverviewProvider.php`, inside `build_builtin_modules_group()`, after the `enable_security_header` entry:

```php
            'enable_password_protected' => [
                'label' => __('Password Protection (module loaded)', 'sfxtheme'),
            ],
```

The `(module loaded)` suffix is deliberate: this overview reports the gate, and must not read as "the curtain is up" when `status` is off.

- [ ] **Step 4: Let the admin CSS load on the new page**

In `inc/SFXBricksChildTheme.php`, in `enqueue_admin_scripts()`, the condition is a chain of `strpos(...) === false` tests ending with `strpos($hook_suffix, 'security-header') === false`. Add one more term so the tail reads:

```php
        strpos($hook_suffix, 'security-header') === false &&
        strpos($hook_suffix, 'sfx-password-protected') === false) {
        return;
    }
```

Without this the settings page renders unstyled.

- [ ] **Step 5: Add the option to the uninstall list**

In `uninstall.php`, in the `$options_to_delete` array, after the Security Headers block:

```php
    // Password Protected
    'sfx_password_protected_options',
```

One entry, because there is one option. This is the only deletion path: the module deliberately does **not** join `GeneralThemeOptions\Controller`'s delete-on-toggle-off chain, because these options are a credential and a checkbox is not a delete button.

- [ ] **Step 6: Document the import/export exclusion**

In `inc/ImportExport/Controller.php`, in `get_settings_groups()`, immediately before the closing `];` of the returned array:

```php
            // Note: sfx_password_protected_options is deliberately NOT exportable.
            // The array holds a password hash and a plaintext bearer token (the
            // bypass key); export would write both into a portable JSON file.
            // Import also writes options wholesale, bypassing
            // Settings::validate_snapshot() — the one thing enforcing
            // "status is never on without a password".
```

A comment, not a registration. This is the change that is easiest to "helpfully" undo later, which is why the reason sits next to it.

- [ ] **Step 7: Verify every test still passes**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: seven lines, none containing `FAIL`. The overview test asserts group presence only, not counts, so the new module does not disturb it.

- [ ] **Step 8: Verify syntax across everything touched**

Run: `for f in inc/PasswordProtected/*.php inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php inc/SFXBricksChildTheme.php inc/ImportExport/Controller.php uninstall.php; do php -l "$f"; done`
Expected: `No syntax errors detected` for every file.

- [ ] **Step 9: Commit**

```bash
git add inc/GeneralThemeOptions/Settings.php inc/ThemeSettingsOverview/OverviewProvider.php inc/SFXBricksChildTheme.php inc/ImportExport/Controller.php uninstall.php tests/support/overview-general-theme-options-settings-stub.php
git commit -m "feat(password-protected): wire module into theme options, overview and uninstall"
```

---

### Task 8: Manual verification

The unit tests cover isolated logic. Rendering, hook lifecycle and real HTTP need a live WordPress, which this repo's harness cannot drive — so they get verified by hand rather than pretended away.

**Files:** none. This task produces evidence, not code.

**Interfaces:**
- Consumes: everything from Tasks 1–7.
- Produces: a verified feature, ready to merge.

- [ ] **Step 1: Enable the module**

Global Theme Settings → General Theme Options → check **Enable Password Protection module** → Save. A **Password Protection** entry appears in the Global Theme Settings submenu.

Expected: the page renders **styled** (proves Task 7 Step 4). The frontend is still public — the gate loads code, it does not protect.

- [ ] **Step 2: Refuse to enable without a password**

Check **Protection Status**, leave the password empty, Save.

Expected: a red error, *"Set a password before switching protection on"*, status back to off, frontend still public.

- [ ] **Step 3: Set a password and enable in one save**

Enter the same password in both fields, check **Protection Status**, Save.

Expected: *"Settings saved."* — status stays **on**. (This is the case that would have silently failed under the `register_setting()` design: the double-sanitize path broke the password on its very first save.)

- [ ] **Step 4: The curtain, in a logged-out browser**

Open the site in a private window.

Expected, in order:
1. Any frontend URL redirects to `?sfx-protected=login`.
2. The login page **is styled** like wp-login.php — this is the `wp_admin_css('login', true)` regression; unstyled means the `true` was dropped.
3. A wrong password re-renders the form with *"Incorrect password"* and does **not** let you in.
4. The correct password redirects to the URL you originally asked for, not the home page.
5. wp-admin and wp-login.php were reachable throughout.

- [ ] **Step 5: Logout**

While logged in through the curtain, visit `/?sfx-protected=logout&_wpnonce=<a valid sfx_pp_logout nonce>`. Generate the nonce from the browser console on any protected page is not possible — instead, temporarily add `wp_nonce_url(home_url('/?sfx-protected=logout'), 'sfx_pp_logout')` output to the login template, or generate one with WP-CLI: `wp eval 'echo wp_create_nonce("sfx_pp_logout");'` while logged in as the same user context.

Expected: the cookie is cleared and the next frontend request shows the password prompt again.

Then repeat **without** a nonce (`/?sfx-protected=logout`).

Expected: nothing happens — you stay logged in. Logout is nonce-guarded.

- [ ] **Step 6: Malformed input never fatals and never authenticates**

With protection on, from a logged-out private window, submit each of these and confirm you get an ordinary login screen or error — never a PHP fatal, a warning, or access:

1. `POST` the login form with a **correct** password but a missing `_wpnonce`. ⇒ *"Your session expired"*, not logged in.
2. `curl -s -o /dev/null -w '%{http_code}' 'https://<site>/?sfx-protected=login&redirect_to[]=x'` ⇒ `200`, no fatal.
3. `curl -sL 'https://<site>/?sfx_bypass[]=x'` ⇒ the login page, no fatal. (`-L` matters: the malformed key is ignored, so the request 302s to the login URL and curl must follow it to show anything.)
4. `curl -sLX POST -d 'sfx_pp_pwd[]=x' 'https://<site>/?sfx-protected=login'` ⇒ the login page, no fatal.

Any PHP notice, warning or `TypeError` here is a failure: the request-reader rule exists exactly for this.

- [ ] **Step 7: The bypass link**

Enable **Bypass URL**, Save. A key is generated and the link appears. Copy it, then open it in a fresh private window.

Expected: access granted, redirected to **Redirect To** (or the home page when empty).

Now check **Generate a new key**, Save, and open the **old** link in another fresh private window.

Expected: the old link no longer works — it lands on the password prompt. The new link does work.

- [ ] **Step 8: The stale-form race**

Open the settings page in two tabs. In tab A, rotate the key and save. In tab B — still showing the **old** key — toggle any unrelated checkbox and save. Reload tab A.

Expected: the key is still the **new** one from tab A. Tab B's stale form did **not** restore the old key, because no key is ever read from the request. Any link handed out before the rotation stays dead.

- [ ] **Step 9: Exemptions and the Bricks builder**

With protection on and **Allow administrators** checked, browse the frontend as an admin.

Expected: no prompt. The Bricks builder opens directly. Uncheck it and reload: the admin now meets the prompt.

As a **non-admin editor** with `allow_users` off, open the Bricks builder.

Expected: the password prompt appears once; after entering it, the builder loads normally. (This is the design, not a bug — see the spec.)

- [ ] **Step 10: Feeds and REST**

- `/?feed=rss2` with **Allow RSS feeds** off ⇒ blocked. With it on ⇒ the feed renders.
- `/wp-json/wp/v2/posts` logged out with **Allow REST API** off ⇒ `401`. With it on ⇒ posts.
- The block editor still saves a post while protection is on.

- [ ] **Step 11: The broken state fails closed**

In the database, blank the `password` member of `sfx_password_protected_options` while leaving `status` on:

```bash
wp option pluck sfx_password_protected_options password   # note the current value
wp option patch update sfx_password_protected_options password ''
```

Expected: the curtain **stays up**, no password opens it, and a red admin notice explains the problem and the fix. It must **not** turn the site public — that inversion was the bug found in Gate A pass 3 on the spec, and this step is the check that it stays fixed.

Restore a password afterwards and confirm the notice disappears.

- [ ] **Step 12: Record the results**

Report which steps passed and which did not, quoting what you actually saw. Any step you did not run is reported as not run — not as passed.

- [ ] **Step 13: Final full test run**

Run: `for f in tests/*-test.php; do printf "%s: " "$f"; php "$f" 2>&1 | tail -1; done`
Expected: seven lines, none containing `FAIL`.

---

## Self-Review

**Spec coverage.** Every section maps to a task: threat model → Task 4 tips card + Task 8; two switches → Task 7 Steps 1–3; three predicates → Task 3; cache suppression → Task 6 `disable_caching()` + Task 3 test 8; protection flow and handlers → Task 6; the request-reader rule → `Settings::post_string()` / `request_string()` / `post_bool()` (Task 1, tested at 13/13b/13c), used by every handler; Auth → Task 2; revocation → Task 2 comments + Task 4 tips; single array option → Task 1; no `register_setting()` → Task 1 + Task 4; `Settings::get()` normalization → Task 1 test 14; `validate_snapshot` contract → Task 1; bypass key semantics → Task 1 tests 7–10 + Task 8 Step 8; PRG → Task 4 Step 1; options table → Task 1 `defaults()`; IP allowlist → Task 1 test 15; import/export exclusion → Task 7 Step 6; data deletion → Task 7 Step 5 (and the absence of a `delete_all_options()`, which is the point); admin page → Task 4; login template → Task 5; the three test files → Tasks 1–3; manual checklist → Task 8.

**Placeholders.** None. Every code step carries the actual code; every command carries its expected output.

**Type consistency.** `Settings::OPTION_NAME`, `Settings::get()`, `Settings::get_password_hash()`, `Settings::has_string()`, `Settings::post_string()`, `Settings::request_string()`, `Settings::post_bool()`, `Settings::allowed_ips()`, `Settings::normalize_ip_list()`, `Settings::validate_snapshot()`, `Settings::save_from_request()`, `Auth::generate_cookie()`, `Auth::validate_cookie()`, `Auth::set_cookie()`, `Auth::clear_cookie()`, `Auth::cookie_name()`, `Auth::site_id()`, `Controller::is_protection_enabled()`, `Controller::is_configuration_broken()`, `Controller::is_visitor_exempt()`, `Controller::is_active()`, `Controller::bypass_key_matches()`, `Controller::login_url()`, `Controller::redirect_to()`, `Controller::$errors`, `AdminPage::$menu_slug`, `AdminPage::page_url()` — each defined once, used with the same name and signature everywhere.

**Ordering, restated because it is the thing most likely to be "tidied" into a broken site.** `auto_register_features()` calls `get_feature_config()` on every `inc/*/Controller.php` class that has one, on every request, *before* the gate is consulted. So `AdminPage` (Task 4) must exist before `Controller::get_feature_config()` (Task 6). Tasks 1–3 have no `get_feature_config()` at all and are skipped by the `method_exists()` check, so every commit in this sequence leaves the site loadable. Task 6 Step 4 is the check that this held.
