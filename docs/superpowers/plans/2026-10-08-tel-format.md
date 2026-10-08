# `@format:tel` for simple Bricks tags — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `tel:{acf_phone @format:tel}` renders `tel:+4915115921554` for every simple Bricks tag, with one shared normaliser and one uniform attribute spelling, without changing output that works today — within the spec's compatibility table and its owner-accepted limits (quoted/`:raw` syntax, `cf_` suffix keys).

**Architecture:** `normalize_tel()` moves to a root-level `SFX\TelNormalizer` (ContactInfos keeps a delegating method). A new always-on module `SFX\TelFormat` hooks the two documented Bricks filters at priority 9, resolves `{name @format:tel}` through `bricks_render_dynamic_data('{name}')` and normalises the result. ContactInfos/SocialMediaAccounts help texts, picker entries and README switch to the space spelling `{tag @key:value}`.

**Tech Stack:** PHP 8 (WordPress, Bricks 2.4.2 public hooks); tests in plain PHP (`tests/*-test.php`, run by `./quality.sh`); `msgfmt` for the `.mo`.

**Spec:** `docs/superpowers/specs/2026-10-08-tel-format-design.md` — read it before any task; this plan implements it and does not restate its reasoning. Where they differ, the spec wins.

## Global Constraints

- **Working directory:** every path and command is relative to the theme root `wp-content/themes/sfx-bricks-child`; `cd` there first. Branch: `feature/tel-links` (exists).
- **Local PHP:** run once per shell: `PHP="${PHP:-$(command -v php || echo /Applications/MAMP/bin/php/php8.5.2/bin/php)}"`; every command below calls `"$PHP"`. Quality battery: `./quality.sh`.
- **Commits:** every task commits a `WIP: …` snapshot (CLAUDE.md §5 Mechanics). The real commit message is written once, after Gate B is clean (Task 5).
- No new Composer dependency; no new option key; no database writes outside the live harness's own teardown.
- No module-to-module edge: `TelFormat` and `ContactInfos` both depend only on the root-level `SFX\TelNormalizer`.
- `SC_ContactInfos::normalize_tel()` stays public and static (delegates). Filter name `sfx_contact_info_default_country_code` unchanged.
- Hooks, verbatim: `add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 9, 3);` and `add_filter('bricks/frontend/render_data', [self::class, 'render_data'], 9, 2);` — nothing on `render_tag`, `format_value` or `allowed_keys`.
- Match pattern, verbatim: `/\{([a-zA-Z0-9_-]+)\s*@format:tel\}/` (case-sensitive).
- Spelling everywhere user-facing: a space before each `@` attribute (`{contact_info:phone @format:tel}`). Parsers unchanged.
- Escaping at output (invariant 3): the replacement passes through `esc_html()`. New strings use text domain `sfxtheme` (invariant 4).
- PSR-4 case (invariant 1): `inc/TelNormalizer.php` → `SFX\TelNormalizer`; `inc/TelFormat/Controller.php` → `SFX\TelFormat\Controller`.

## Review Focus

1. **A field that already holds `tel:+49 171 1700557`** — the `+` must survive (`+491711700557`), not become `491711700557`. Pinned in Task 2 (test 5).
2. **An empty ACF phone in a button link** — `tel:` with nothing after it, never digits from the tag name. Pinned in Task 2 (test 4) and the Task 4 live loop (empty item).
3. **A query loop over several coaches** — each item dials its own number, never the page's. Pinned in Task 4 (live loop with distinct numbers and a page number).
4. **Pages built before this change with `{contact_info:phone@format:tel}` (no space)** — output byte-identical after the picker switches to the space form. Pinned in Task 3 (both spellings asserted) and Task 4 (full-pipeline compatibility).
5. **A phone field carrying `&nbsp;` or `&#160;`** (pasted from a document) — the full number, not a number cut at the `;`. Pinned in Task 2 (test 5).

---

### Task 1: Shared `SFX\TelNormalizer`

**Files:**
- Create: `inc/TelNormalizer.php`
- Modify: `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (method `normalize_tel` at ~374, call sites at ~138 and ~438)
- Modify: `tests/contact-info-tel-test.php`, `tests/social-bricks-dynamic-data-test.php`

**Interfaces:**
- Produces: `SFX\TelNormalizer::normalize_tel(string $value): string` — behaviour identical to today's `SC_ContactInfos::normalize_tel()`.
- Produces: `SC_ContactInfos::normalize_tel(string $value): string` — one-line delegation, kept for callers outside the theme.

- [ ] **Step 1: Point the existing tests at the new class (failing)**

In `tests/contact-info-tel-test.php`, after the `require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';` line add:

```php
require dirname(__DIR__) . '/inc/TelNormalizer.php';
```

add `use SFX\TelNormalizer;` next to the other `use` lines, and replace every `SC_ContactInfos::normalize_tel(` in sections 1 and 2 with `TelNormalizer::normalize_tel(`. Then, directly after the section-2 block (after `$test_filter_callbacks = [];`), add:

```php
// 2b. The old public method still works for code outside the theme: it delegates.
assert_same('+492082076580', SC_ContactInfos::normalize_tel('0208 207658 0'), '2b: SC_ContactInfos::normalize_tel delegates');
```

In `tests/social-bricks-dynamic-data-test.php`, before `require dirname(__DIR__) . '/inc/ContactInfos/FieldRegistry.php';` add:

```php
require dirname(__DIR__) . '/inc/TelNormalizer.php';
```

- [ ] **Step 2: Run, expect failure**

Run: `"$PHP" tests/contact-info-tel-test.php`
Expected: fatal `Failed opening required '.../inc/TelNormalizer.php'`.

- [ ] **Step 3: Create `inc/TelNormalizer.php`**

Cut the whole `normalize_tel()` method (docblock included) out of `SC_ContactInfos` and paste it into the new class **unchanged**, except: delete the two dead lines at its top —

```php
        // An RFC 3966 extension (";ext=123") is kept apart, so its digits never join the number.
        $ext = '';
```

(the next comment, `// An extension is kept apart, …`, and `$ext_raw = '';` stay; `$ext` is assigned before any read further down). File frame:

```php
<?php

declare(strict_types=1);

namespace SFX;

/**
 * Phone number normalisation for tel: URIs, shared by ContactInfos and TelFormat.
 */
class TelNormalizer
{
    // ← the moved docblock + method go here, unchanged apart from the two deleted lines
}
```

- [ ] **Step 4: Delegate and switch the call sites in `SC_ContactInfos`**

Where the method was, put:

```php
    /**
     * Kept for code outside the theme that calls it; the logic lives in \SFX\TelNormalizer.
     */
    public static function normalize_tel(string $value): string
    {
        return \SFX\TelNormalizer::normalize_tel($value);
    }
```

and change the two internal calls `self::normalize_tel(` (in `render_contact_info()` for `format === 'tel'`, and in `render_phone_field()`) to `\SFX\TelNormalizer::normalize_tel(`.

- [ ] **Step 5: Run the battery**

Run: `git add inc/TelNormalizer.php` first (`tests/psr4-path-case-test.php` reads the git index), then `./quality.sh`.
Expected: all PHP and JS tests PASS, 0 syntax errors.

- [ ] **Step 6: WIP commit**

```bash
git add inc/TelNormalizer.php inc/ContactInfos/Shortcode/SC_ContactInfos.php tests/contact-info-tel-test.php tests/social-bricks-dynamic-data-test.php
git commit -m "WIP: move normalize_tel to SFX\\TelNormalizer"
```

---

### Task 2: Module `SFX\TelFormat`

**Files:**
- Create: `inc/TelFormat/Controller.php`
- Test: `tests/tel-format-test.php`

**Interfaces:**
- Consumes: `SFX\TelNormalizer::normalize_tel(string): string` (Task 1); Bricks' global `bricks_render_dynamic_data(string $content, int $post_id = 0, string $context = 'text')`.
- Produces: `SFX\TelFormat\Controller::render_content(mixed $content, mixed $post = null, mixed $context = 'text'): mixed`, `::render_data(mixed $content, mixed $post = null): mixed`, `::get_feature_config(): array`.

- [ ] **Step 1: Write the failing test `tests/tel-format-test.php`**

```php
<?php

declare(strict_types=1);

// Self-contained: TelFormat needs only these WordPress/Bricks functions.
define('ABSPATH', __DIR__ . '/');

$failures = 0;
function assert_true(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}
function assert_same(mixed $expected, mixed $actual, string $message): void
{
    assert_true($expected === $actual, "{$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function apply_filters(string $hook, $value, ...$args)
{
    return $value; // country code stays 49
}

$test_hooks = [];
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): bool
{
    global $test_hooks;
    $test_hooks[] = [$hook, $callback, $priority, $accepted_args];
    return true;
}

// esc_html: identity by default; test 5a switches on a visible wrapper.
$test_wrap_escape = false;
function esc_html($text): string
{
    global $test_wrap_escape;
    return $test_wrap_escape ? '[e]' . $text . '[/e]' : (string) $text;
}

// Bricks resolver stub: records calls, returns fixture values per tag.
$test_calls = [];
$test_values = [];
$test_resolver = null; // optional callable(string $tag, int $post_id, string $context)
function bricks_render_dynamic_data($content, $post_id = 0, $context = 'text')
{
    global $test_calls, $test_values, $test_resolver;
    $test_calls[] = [$content, $post_id, $context];
    if ($test_resolver !== null) {
        return ($test_resolver)($content, $post_id, $context);
    }
    return $test_values[$content] ?? '';
}

require dirname(__DIR__) . '/inc/TelNormalizer.php';
require dirname(__DIR__) . '/inc/TelFormat/Controller.php';

use SFX\TelFormat\Controller;

$post = (object) ['ID' => 135];
function reset_stub(array $values = []): void
{
    global $test_calls, $test_values, $test_resolver;
    $test_calls = [];
    $test_values = $values;
    $test_resolver = null;
}

// 0. Registration: exactly the two documented hooks, priority 9, nothing else; config has no activation key.
new Controller();
assert_same([
    ['bricks/dynamic_data/render_content', [Controller::class, 'render_content'], 9, 3],
    ['bricks/frontend/render_data', [Controller::class, 'render_data'], 9, 2],
], $test_hooks, '0: hooks');
$config = Controller::get_feature_config();
assert_same(Controller::class, $config['class'], '0: config class');
assert_true(!isset($config['activation_option_key']), '0: always on');

// 1. The task's examples, with and without the space.
reset_stub(['{acf_phone}' => '0151 15921554', '{acf_job_general_contacts_phone}' => '0208 / 207 658 0']);
assert_same('tel:+4915115921554', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '1: space');
assert_same('tel:+4915115921554', Controller::render_content('tel:{acf_phone@format:tel}', $post, 'link'), '1: no space');
assert_same('tel:+492082076580', Controller::render_content('tel:{acf_job_general_contacts_phone @format:tel}', $post, 'text'), '1: group field');

// 2. The resolver gets the bare tag, the post ID and the context; render_data passes 'text'.
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_content('{acf_phone @format:tel}', $post, 'link');
assert_same([['{acf_phone}', 135, 'link']], $test_calls, '2: resolver args (render_content)');
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_data('{acf_phone @format:tel}', $post);
assert_same([['{acf_phone}', 135, 'text']], $test_calls, '2: resolver args (render_data)');
reset_stub(['{acf_phone}' => '0151 1']);
Controller::render_content('{acf_phone @format:tel}', null, 'text');
assert_same([['{acf_phone}', 0, 'text']], $test_calls, '2: no post -> 0');

// 3. Not matched: byte-identical, resolver not called.
foreach ([
    "{acf_phone @fallback:'x' @format:tel}",
    '{acf_phone:plain @format:tel}',
    '{contact_info:phone @format:tel}',
    '{social_account:url:1 @format:tel}',
    '{acf_phone @format:telefax}',
    '{acf_phone @FORMAT:TEL}',
    '{acf_phone @format:tel @format:tel}',
    'tel:{acf_phone}',
    'no tag here',
] as $input) {
    reset_stub(['{acf_phone}' => '0151 1']);
    assert_same($input, Controller::render_content($input, $post, 'text'), "3: untouched {$input}");
    assert_same([], $test_calls, "3: resolver not called for {$input}");
}

// 3a. Accepted limit: an inner simple tag is resolved; the outer text stays.
reset_stub(['{acf_phone}' => '0151 15921554']);
assert_same('{echo:fn(+4915115921554)}', Controller::render_content('{echo:fn({acf_phone @format:tel})}', $post, 'text'), '3a: inner tag resolved');

// 4. Rejections give '' — never the tag, never digits from a name or from markup.
foreach ([
    'empty'           => '',
    'unresolved'      => '{acf_phone}',
    'encoded tag'     => '&#123;acf_phone2&#125;',
    'markup'          => '<a href="tel:1">1</a>',
    'encoded markup'  => '&lt;a href="tel:123"&gt;456&lt;/a&gt;',
] as $label => $value) {
    reset_stub(['{acf_phone}' => $value]);
    assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), "4: {$label} -> empty");
}
reset_stub();
$test_resolver = static fn() => ['0151 1'];
assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '4: array -> empty');

// 5. Entities and an existing tel: prefix.
foreach ([
    '0151&nbsp;15921554'         => '+4915115921554',
    '0151&#160;15921554'         => '+4915115921554',
    'tel:+49 (0) 208 2076580'    => '+492082076580',
    'TEL: 0208 2076580'          => '+492082076580',
    'tel:+49 171 1700557'        => '+491711700557',
    '&nbsp;tel:+49 171 1700557'  => '+491711700557',
    "\u{00A0}tel:\u{202F}+49 171 1700557" => '+491711700557',
] as $value => $expected) {
    reset_stub(['{acf_phone}' => $value]);
    assert_same($expected, Controller::render_content('{acf_phone @format:tel}', $post, 'text'), "5: {$value}");
}

// 5a. Escaping is applied to every successful replacement; rejections stay bare ''.
$test_wrap_escape = true;
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('tel:[e]+491511[/e]', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '5a: escaped');
reset_stub(['{acf_phone}' => '']);
assert_same('tel:', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '5a: rejection not wrapped');
$test_wrap_escape = false;

// 5b. Re-entry: same key yields '' and terminates; different post or context resolves; guard released after a throw.
reset_stub();
$depth = 0;
$inner = [];
$test_resolver = static function (string $tag, int $post_id, string $context) use (&$depth, &$inner, $post): string {
    $depth++;
    if ($depth > 5) {
        return 'RUNAWAY';
    }
    if ($post_id !== 135 || $context !== 'text') {
        return '0151 1'; // the legitimate inner resolutions end here
    }
    // Outer resolution (135, text) asks for the same tag three ways while it is in flight.
    $inner['same']    = Controller::render_content('{acf_phone @format:tel}', $post, 'text');
    $inner['post']    = Controller::render_content('{acf_phone @format:tel}', (object) ['ID' => 200], 'text');
    $inner['context'] = Controller::render_content('{acf_phone @format:tel}', $post, 'link');
    return '0151 1';
};
assert_same('+491511', Controller::render_content('{acf_phone @format:tel}', $post, 'text'), '5b: outer value');
assert_same(3, $depth, '5b: outer + two legitimate inner resolutions; the same key is rejected without resolving');
assert_same(['same' => '', 'post' => '+491511', 'context' => '+491511'], $inner, '5b: inner results');
$test_resolver = static function () {
    throw new RuntimeException('boom');
};
try {
    Controller::render_content('{acf_phone @format:tel}', $post, 'text');
} catch (RuntimeException $e) {
}
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('+491511', Controller::render_content('{acf_phone @format:tel}', $post, 'text'), '5b: guard released after throw');

// 5c. Accepted limit pinned: inside a :raw fallback the inner tag is resolved too.
reset_stub(['{acf_phone}' => '0151 1']);
assert_same("{post_title:raw @fallback:'+491511'}", Controller::render_content("{post_title:raw @fallback:'{acf_phone @format:tel}'}", $post, 'text'), '5c: :raw limit');

// 6. Two tags, text around them byte-identical.
reset_stub(['{acf_phone}' => '0151 1', '{acf_fax}' => '0208 2']);
assert_same(
    '<a href="tel:+491511">Ruf an</a> · Fax tel:+492082 – Ende',
    Controller::render_content('<a href="tel:{acf_phone @format:tel}">Ruf an</a> · Fax tel:{acf_fax @format:tel} – Ende', $post, 'text'),
    '6: two tags'
);

// 7. Non-string content passes through untouched.
assert_same(['x'], Controller::render_content(['x'], $post, 'text'), '7: array');
assert_same(null, Controller::render_content(null, $post, 'text'), '7: null');

// 8. PCRE failure returns the original content (control run first).
reset_stub(['{acf_phone}' => '0151 1']);
assert_same('tel:+491511', Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link'), '8: control');
$limit = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '1');
    $out = Controller::render_content('tel:{acf_phone @format:tel}', $post, 'link');
    $err = preg_last_error();
} finally {
    ini_set('pcre.backtrack_limit', (string) $limit);
}
assert_same(PREG_BACKTRACK_LIMIT_ERROR, $err, '8: PCRE actually failed');
assert_same('tel:{acf_phone @format:tel}', $out, '8: original returned');

// 9. Output alphabet of the normaliser.
$alphabet = '/\A\+?[0-9]*(;ext=[0-9]+)?\z/';
foreach (['0151 15921554', '0208 / 207 658 0', '0151&nbsp;15921554', 'tel:+49 171 1700557', '0208 2076580 x 12'] as $value) {
    $normalised = \SFX\TelNormalizer::normalize_tel(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    assert_true(preg_match($alphabet, $normalised) === 1, "9: alphabet {$value} -> {$normalised}");
}
foreach (["+49123\n", '+49"1', '+49<1', '+49;ext=1a'] as $bad) {
    assert_true(preg_match($alphabet, $bad) !== 1, '9: alphabet rejects ' . json_encode($bad));
}

if ($failures > 0) {
    echo "Tests failed: {$failures}\n";
    exit(1);
}
echo "tel-format-test: PASS\n";
```

Note on 5b: the inner `post 200` and `link` resolutions are legitimate (different key) and resolve once each → `$depth` 2 and 3; the inner same-key call is rejected by the guard **without** calling the resolver. A guard keyed by name alone rejects all three → `$depth` 1 and `post`/`context` empty — the assertions fail. Without any guard the same-key call recurses until `RUNAWAY`.

Note on 6: `tel:+492082` — `0208 2` normalises to `+492082`.

- [ ] **Step 2: Run, expect failure**

Run: `"$PHP" tests/tel-format-test.php`
Expected: fatal `Failed opening required '.../inc/TelFormat/Controller.php'`.

- [ ] **Step 3: Create `inc/TelFormat/Controller.php`**

```php
<?php

declare(strict_types=1);

namespace SFX\TelFormat;

use SFX\TelNormalizer;

/**
 * `@format:tel` for simple Bricks dynamic tags: `tel:{acf_phone @format:tel}` → `tel:+49…`.
 *
 * Always on. Only `{name @format:tel}` (a tag name, the attribute, nothing else) is touched;
 * what that changes and what stays is the spec's compatibility table. ContactInfos handles its own
 * `{contact_info:… @format:tel}`. Spec: docs/superpowers/specs/2026-10-08-tel-format-design.md
 */
class Controller
{
    /** @var array<string, true> Resolutions in progress, keyed by name|post ID|context. */
    private static array $in_flight = [];

    public function __construct()
    {
        // Priority 9: before Bricks' own resolver (10) sees the tag.
        add_filter('bricks/dynamic_data/render_content', [self::class, 'render_content'], 9, 3);
        add_filter('bricks/frontend/render_data', [self::class, 'render_data'], 9, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_feature_config(): array
    {
        return [
            'class'                  => self::class,
            'show_in_theme_settings' => false,
            'hook'                   => null,
            'error'                  => 'Missing TelFormat Controller class in theme',
        ];
    }

    /**
     * bricks/frontend/render_data passes whole element output without a context.
     */
    public static function render_data($content, $post = null)
    {
        return self::render_content($content, $post, 'text');
    }

    public static function render_content($content, $post = null, $context = 'text')
    {
        if (!is_string($content) || strpos($content, '@format:tel') === false) {
            return $content;
        }

        $result = preg_replace_callback(
            '/\{([a-zA-Z0-9_-]+)\s*@format:tel\}/',
            static fn(array $m): string => self::resolve($m[1], $post, (string) $context),
            $content
        );

        return $result ?? $content; // PCRE failure: leave the content as it was
    }

    private static function resolve(string $name, $post, string $context): string
    {
        $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        $key = $name . '|' . $post_id . '|' . $context;
        if (isset(self::$in_flight[$key])) {
            return ''; // a provider asked for the same tag again while resolving it
        }

        self::$in_flight[$key] = true;
        try {
            $value = bricks_render_dynamic_data('{' . $name . '}', $post_id, $context);
        } finally {
            unset(self::$in_flight[$key]);
        }

        if (!is_string($value)) {
            return '';
        }

        // Decode first: the checks below must see &#123; and &lt;, and normalize_tel()
        // reads the first ";" as URI parameters (&nbsp; would cut the number).
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/^[\s\p{Z}]*tel:[\s\p{Z}]*/iu', '', $value); // keeps a leading "+"; NBSP too

        // Unresolved tag or markup: digits from a tag name or from href and text would be dialled.
        // Empty, not the tag — render_data would resolve a left-over tag against the page post.
        if (strpos($value, '{') !== false || strpos($value, '<') !== false) {
            return '';
        }

        $tel = TelNormalizer::normalize_tel($value);

        return $tel === '' ? '' : esc_html($tel);
    }
}
```

- [ ] **Step 4: Run the test, then the battery**

Run: `"$PHP" tests/tel-format-test.php` → Expected: `tel-format-test: PASS`.
Run: `git add inc/TelFormat/Controller.php tests/tel-format-test.php && ./quality.sh` → Expected: all PASS.

- [ ] **Step 5: Counterfactual check (not committed)**

Temporarily change the guard key to `$key = $name;` and run `"$PHP" tests/tel-format-test.php` → Expected: FAIL on `5b: outer + two legitimate inner resolutions`. Temporarily drop `esc_html(` → Expected: FAIL on `5a: escaped`. Temporarily return `'{' . $name . ' @format:tel}'` instead of `''` in the `{`/`<` rejection branch → Expected: FAIL on `4:` assertions (no PHP error). Before the first mutation `cp inc/TelFormat/Controller.php /private/tmp/claude-ctrl.bak`; after each, `cp` it back and confirm `cmp inc/TelFormat/Controller.php /private/tmp/claude-ctrl.bak` prints nothing; delete the backup at the end.

- [ ] **Step 6: WIP commit**

```bash
git add inc/TelFormat/Controller.php tests/tel-format-test.php
git commit -m "WIP: TelFormat module — @format:tel for simple Bricks tags"
```

---

### Task 3: Uniform spelling — picker, help tabs, comments, README, translations

**Files:**
- Modify: `inc/ContactInfos/Controller.php` (~121, ~125, ~167)
- Modify: `inc/ContactInfos/HelpTab.php` (bricks tab rows and attribute paragraph ~190–215; examples tab ~228–236)
- Modify: `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (comment ~135)
- Modify: `inc/SocialMediaAccounts/HelpTab.php` (~185–191)
- Modify: `README.md` (line 17)
- Modify: `languages/de_DE.po`, regenerate `languages/de_DE.mo`
- Test: `tests/contact-info-tel-test.php`, `tests/contact-social-help-tab-test.php`, `tests/social-bricks-dynamic-data-test.php`

**Interfaces:**
- Consumes: nothing new. Produces: picker names `'{contact_info:' . $field . ' @format:tel}'`.

- [ ] **Step 1: Update tests first (failing)**

`tests/contact-info-tel-test.php`, section 6 — replace the picker assertion with:

```php
foreach (['phone', 'mobile', 'fax'] as $field) {
    assert_true(in_array('{contact_info:' . $field . ' @format:tel}', $names, true), "6: picker has {$field} @format:tel (space form)");
}
```

and after section 5b add:

```php
// 5c. Both spellings render identically: the space form (shown in help and picker) and the
//     older no-space form already inserted in pages.
foreach ([
    ['{contact_info:phone:310 @format:tel}', '{contact_info:phone:310@format:tel}'],
    ['{contact_info:email:310 @link:false @wrap:true}', '{contact_info:email:310@link:false@wrap:true}'],
    ['{contact_info:phone:310 @link:false}', '{contact_info:phone:310|link=false}'],
] as [$spaced, $legacy]) {
    $a = ContactInfosController::render_bricks_dynamic_tag($spaced, null);
    assert_true($a !== '' && strpos($a, '{') === false, "5c: {$spaced} resolves");
    assert_same(ContactInfosController::render_bricks_dynamic_tag($legacy, null), $a, "5c: {$spaced} == {$legacy}");
}
assert_same('+492082076580', ContactInfosController::render_bricks_dynamic_tag('{contact_info:phone:310 @format:tel}', null), '5c: space form value');
$both = ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @link:false @wrap:true}', null);
assert_contains('<span', $both, '5c: @wrap:true wraps');
assert_true(strpos($both, '<a ') === false, '5c: @link:false drops the link');
assert_true(strpos(ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @link:false}', null), '<span') === false, '5c: without @wrap no span');
assert_contains('<a ', ContactInfosController::render_bricks_dynamic_tag('{contact_info:email:310 @wrap:true}', null), '5c: without @link:false the link stays');
```

`tests/contact-social-help-tab-test.php`, Case 4 — replace the needle list with:

```php
foreach ([
    'tel:{contact_info:phone @format:tel}',
    'mailto:{contact_info:email @link:false}',
    '{contact_info:phone:123 @link:false}',
    'tel:{acf_phone @format:tel}',
    '{contact_info:phone:123}',
    '[contact_info field=&quot;email&quot; text=&quot;Write to us&quot;]',
    'sfx_contact_info_default_country_code',
] as $needle) {
    assert_contains($needle, implode('', $contact), "Case 4: contact example {$needle}");
}
```

and at the end of the file, directly before its final `$failures` check (after Case 5 and later cases have set `$social`), add:

```php
// Case U — uniform spelling: every @ attribute example has a space before it; no pipe form shown.
$all_help = implode('', $contact) . implode('', $social);
assert_true(preg_match('/\{(contact_info|social_account):[^}]*[^\s]@/', $all_help) !== 1, 'Case U: space before every @ in tag examples');
assert_true(strpos($all_help, '|key=value') === false && strpos($all_help, '|link=false') === false, 'Case U: no pipe form shown');
```

`tests/social-bricks-dynamic-data-test.php` — after Case 5b add:

```php
run_social_bricks_case('Case 5c: several space-separated attributes take effect', 'render_bricks_dynamic_tag', function (): void {
    global $test_meta;
    $stored = $test_meta[123]['_link_target'];
    $test_meta[123]['_link_target'] = ['']; // let @target decide
    try {
        $all = SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:html:123 @class:x @size:small @target:_self}', null);
        assert_contains('social-account-small', $all, 'Case 5c: size');
        assert_contains(' x', $all, 'Case 5c: class');
        assert_contains('_self', $all, 'Case 5c: target');
        foreach (['@class:x', '@size:small', '@target:_self'] as $attr) {
            $without = SocialMediaAccountsController::render_bricks_dynamic_tag(
                str_replace(' ' . $attr, '', '{social_account:html:123 @class:x @size:small @target:_self}'),
                null
            );
            assert_true($without !== $all, "Case 5c: omitting {$attr} changes the output");
        }
        assert_same(
            SocialMediaAccountsController::render_bricks_dynamic_tag('{social_account:html:123@class:x@size:small@target:_self}', null),
            $all,
            'Case 5c: equals the no-space spelling'
        );
    } finally {
        $test_meta[123]['_link_target'] = $stored;
    }
});
```

Run: `./quality.sh` → Expected: FAIL on `6: picker has phone @format:tel`, `Case 4: … @format:tel`, `Case 4: every @ attribute example …`. Case 5c and 5c (contact) are expected to PASS already (parsers unchanged) — that is the compatibility pin.

- [ ] **Step 2: Picker and comments in `inc/ContactInfos/Controller.php`**

```php
    // Bare cleaned numbers for link fields such as "tel:{contact_info:phone @format:tel}".
```
```php
          'name'  => '{contact_info:' . $field . ' @format:tel}',
```
```php
    // Matches: {contact_info:field}, {contact_info:field:location}, {contact_info:field @attr:value}, etc.
```

In `inc/ContactInfos/Shortcode/SC_ContactInfos.php` (~135):

```php
        // Bare number for a tel: link set elsewhere, e.g. a Bricks button "tel:{contact_info:phone @format:tel}".
```

- [ ] **Step 3: ContactInfos help tab (`inc/ContactInfos/HelpTab.php`)**

Bricks tab: change the third row's code to `self::code('{contact_info:phone @format:tel}')`. Replace the attribute paragraph and add the `@format:tel` paragraph right after it:

```php
            . '<p>' . sprintf(
                /* translators: 1: "@key:value", 2: example tag */
                esc_html__('Attributes are appended as %1$s, separated by a space, and can be chained, e.g. %2$s. Values must not contain @, |, = or }.', 'sfxtheme'),
                self::code('@key:value'),
                self::code('{contact_info:email @link:false @wrap:true}')
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: "@format:tel", 2: example tag */
                esc_html__('%1$s also works on simple Bricks tags such as %2$s: the tag name and %1$s, nothing else; one phone number per field. Inside quotes or a :raw tag it is resolved as well. The builder canvas may show the raw tag; the page shows the number.', 'sfxtheme'),
                self::code('@format:tel'),
                self::code('tel:{acf_phone @format:tel}')
            ) . '</p>'
```

Examples tab rows (replace the three affected, add one after the `tel:` row):

```php
            ['tel:{contact_info:phone @format:tel}', __('Link field of a Bricks button: dials the main phone number.', 'sfxtheme')],
            ['tel:{acf_phone @format:tel}', __('Link field of a Bricks button: dials the number from the ACF field phone.', 'sfxtheme')],
            ['mailto:{contact_info:email @link:false}', __('Link field of a Bricks button: writes to the main email address.', 'sfxtheme')],
```
```php
            ['{contact_info:phone:123 @link:false}', __('Phone number of entry 123 as plain text.', 'sfxtheme')],
```

- [ ] **Step 4: SocialMediaAccounts help tab (`inc/SocialMediaAccounts/HelpTab.php`, ~185–191)**

```php
            . '<p>' . sprintf(
                /* translators: 1: "@key:value", 2: example tag */
                esc_html__('For the html field, class, size and target can be appended as %1$s, separated by a space, e.g. %2$s.', 'sfxtheme'),
                self::code('@key:value'),
                self::code('{social_account:html:123 @size:small}')
            ) . '</p>'
```

- [ ] **Step 5: README line 17**

Replace `{contact_info:phone@format:tel}` with `{contact_info:phone @format:tel}` and append, before the closing parenthesis: `; the same attribute works on simple Bricks tags, e.g. \`tel:{acf_phone @format:tel}\``.

- [ ] **Step 6: Translations**

In `languages/de_DE.po` replace the two changed entries (old msgid lines 8179 and 8269 — search by text) and add two new ones:

```po
#. translators: 1: "@key:value", 2: example tag
msgid "Attributes are appended as %1$s, separated by a space, and can be chained, e.g. %2$s. Values must not contain @, |, = or }."
msgstr "Attribute werden als %1$s mit einem Leerzeichen davor angehängt und lassen sich verketten, z. B. %2$s. Werte dürfen kein @, |, = oder } enthalten."

#. translators: 1: "@format:tel", 2: example tag
msgid "%1$s also works on simple Bricks tags such as %2$s: the tag name and %1$s, nothing else; one phone number per field. Inside quotes or a :raw tag it is resolved as well. The builder canvas may show the raw tag; the page shows the number."
msgstr "%1$s funktioniert auch an einfachen Bricks-Platzhaltern wie %2$s: Platzhaltername und %1$s, sonst nichts; eine Telefonnummer pro Feld. Auch in Anführungszeichen oder in einem :raw-Platzhalter wird er aufgelöst. Im Builder kann der rohe Platzhalter stehen; auf der Seite steht die Nummer."

msgid "Link field of a Bricks button: dials the number from the ACF field phone."
msgstr "Link-Feld eines Bricks-Buttons: wählt die Nummer aus dem ACF-Feld phone."

#. translators: 1: "@key:value", 2: example tag
msgid "For the html field, class, size and target can be appended as %1$s, separated by a space, e.g. %2$s."
msgstr "Für das Feld html lassen sich class, size und target als %1$s mit einem Leerzeichen davor anhängen, z. B. %2$s."
```

Then: `msgfmt --check -o languages/de_DE.mo languages/de_DE.po` → Expected: exit 0, no output.

- [ ] **Step 7: Run the battery**

Run: `./quality.sh` → Expected: all PASS.
Run: `grep -rnE '\{(contact_info|social_account):[^}]*[^ ]@' inc README.md` → Expected: only the parser regex lines (`Controller.php` ~168, SocialMediaAccounts `Controller.php` ~146), no examples or comments.

- [ ] **Step 8: WIP commit**

```bash
git add inc/ContactInfos inc/SocialMediaAccounts/HelpTab.php README.md languages/de_DE.po languages/de_DE.mo tests/contact-info-tel-test.php tests/contact-social-help-tab-test.php tests/social-bricks-dynamic-data-test.php
git commit -m "WIP: uniform tag attribute spelling (space before @), help for @format:tel"
```

---

### Task 4: Live verification harness and AGENTS.md

**Files:**
- Create: `tests/support/tel-format-live-check.php` (manual harness, not part of `quality.sh` — the glob is `tests/*-test.php`)
- Modify: `AGENTS.md` (line 14 module list; line 39 root-level services)

**Interfaces:**
- Consumes: the running local site (`wp-load.php`), Bricks 2.4.2, ACF, `SFX\TelFormat\Controller`.

- [ ] **Step 1: Write the harness**

Rules (AGENTS.md Don'ts): one `register_shutdown_function` teardown is declared before the first fixture; it deletes every recorded fixture, **verifies each is gone by ID**, and turns a failed cleanup into a non-zero exit; the site-root guard is fatal. Element shapes below were checked against the local site (Bricks 2.4.2): a container with `hasLoop` renders one `<div class="brxe-<id> brxe-container …">` per item, a button renders `<a class="brxe-<id> brxe-button bricks-button" href="…">label</a>`.

Known side effect, accepted: creating/deleting a social account bumps the option `sfx_social_accounts_cache_gen` (a cache generation counter; a higher value only invalidates cached social output). Nothing else persists.

```php
<?php
// Manual live check for TelFormat. Run from the theme root:
//   "$PHP" tests/support/tel-format-live-check.php
declare(strict_types=1);

$root = realpath(__DIR__ . '/../../../../../');
if ($root === false || !is_file($root . '/wp-load.php')) {
    fwrite(STDERR, "FATAL: site root not found from " . __DIR__ . "\n");
    exit(2);
}
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
wp_set_current_user(1);

$fixtures = [];
$failures = 0;
register_shutdown_function(static function () use (&$fixtures, &$failures): void {
    $left = [];
    foreach (array_reverse($fixtures) as $id) {
        wp_delete_post($id, true);
        clean_post_cache($id);
        if (get_post($id) !== null) {
            $left[] = $id;
        }
    }
    if ($left !== []) {
        fwrite(STDERR, 'TEARDOWN FAILED, still present: ' . implode(', ', $left) . "\n");
        exit(4);
    }
    echo 'teardown: removed and verified ' . count($fixtures) . " fixtures\n";
    exit($failures === 0 ? 0 : 1);
});

$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$make = static function (string $type, string $title, array $meta) use (&$fixtures): int {
    $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_title' => $title], true);
    if (is_wp_error($id) || $id <= 0) {
        fwrite(STDERR, "FATAL: could not create {$type} fixture\n");
        exit(3); // teardown still runs
    }
    $fixtures[] = (int) $id;
    foreach ($meta as $k => $v) {
        update_post_meta((int) $id, $k, $v);
    }
    return (int) $id;
};

// Coaches: two numbers, one empty, one with markup that survives sanitising; a page with its own number.
$a = $make('coach', 'TelFormat A', ['phone' => '0151 1111']);
$b = $make('coach', 'TelFormat B', ['phone' => '0208 / 222 0']);
$e = $make('coach', 'TelFormat Empty', ['phone' => '']);
$m = $make('coach', 'TelFormat Markup', ['phone' => '<a href="tel:9">9</a>']);
$page = $make('coach', 'TelFormat Page', ['phone' => '0999 9']);

// 1. Real query loop rendered by Bricks: each item dials its own number; label stays as entered.
$elements = [
    ['id' => 'tfloop', 'name' => 'container', 'parent' => 0, 'children' => ['tfbtn1'],
     'settings' => ['hasLoop' => true, 'query' => ['objectType' => 'post', 'post_type' => ['coach'],
        'post__in' => [$a, $b, $e, $m], 'orderby' => 'post__in', 'posts_per_page' => 4]]],
    ['id' => 'tfbtn1', 'name' => 'button', 'parent' => 'tfloop', 'children' => [],
     'settings' => ['text' => '{acf_phone}', 'link' => ['type' => 'external', 'url' => 'tel:{acf_phone @format:tel}']]],
];
$GLOBALS['post'] = get_post($page);
setup_postdata($GLOBALS['post']);
$html = \Bricks\Frontend::render_data($elements);
$items = preg_split('/(?=<div class="brxe-tfloop )/', $html, -1, PREG_SPLIT_NO_EMPTY);
$items = array_values(array_filter($items, static fn(string $c): bool => strpos($c, 'brxe-tfbtn1') !== false));
$check(count($items) === 4, '1: four loop items rendered (got ' . count($items) . ')');
$expect = [
    ['tel:+491511111', '>0151 1111<'],
    ['tel:+492082220', '>0208 / 222 0<'],
    ['tel:', null],
    ['tel:', null],
];
foreach ($expect as $i => [$href, $label]) {
    $chunk = $items[$i] ?? '';
    $got = preg_match('/class="brxe-tfbtn1[^"]*" href="([^"]*)"/', $chunk, $mm) === 1 ? $mm[1] : '(none)';
    $check($got === $href, "1: item {$i} href {$got}");
    if ($label !== null) {
        $check(strpos($chunk, $label) !== false, "1: item {$i} label as entered");
    }
}
$check(strpos($html, '+49999') === false, '1: page number never used inside the loop');
$check(strpos($html, '@format:tel') === false, '1: no raw tag left');

// 2. render_data path on its own, and render_content in link context.
$rd = apply_filters('bricks/frontend/render_data', '<p>tel:{acf_phone @format:tel}</p>', get_post($a));
$check($rd === '<p>tel:+491511111</p>', '2: render_data path -> ' . $rd);
$rc = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $b, 'link');
$check($rc === 'tel:+492082220', '2: render_content link context -> ' . $rc);

// 3. Counterfactuals: each hook contributes on its own; without both, the raw tag stays.
remove_filter('bricks/dynamic_data/render_content', [\SFX\TelFormat\Controller::class, 'render_content'], 9);
$rd2 = apply_filters('bricks/frontend/render_data', '<p>tel:{acf_phone @format:tel}</p>', get_post($a));
$check($rd2 === '<p>tel:+491511111</p>', '3: render_data alone resolves -> ' . $rd2);
remove_filter('bricks/frontend/render_data', [\SFX\TelFormat\Controller::class, 'render_data'], 9);
$raw = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $a, 'link');
$check(strpos($raw, '@format:tel') !== false, '3: without the module the raw tag stays -> ' . $raw);
add_filter('bricks/dynamic_data/render_content', [\SFX\TelFormat\Controller::class, 'render_content'], 9, 3);
$rc2 = bricks_render_dynamic_data('tel:{acf_phone @format:tel}', $a, 'link');
$check($rc2 === 'tel:+491511111', '3: render_content alone resolves -> ' . $rc2);
add_filter('bricks/frontend/render_data', [\SFX\TelFormat\Controller::class, 'render_data'], 9, 2);

// 4. Compatibility through the full pipeline: baselines pinned, then identical without the module.
//    Explicit IDs: the ID-less main-contact lookup is code this change does not touch (unit-tested).
$contact = $make('sfx_contact_info', 'TelFormat Contact', ['_phone' => '0208 207658 0', '_email' => 'tf@example.test']);
$social = $make('sfx_social_account', 'TelFormat Social', ['_link_url' => 'https://social.example/tf']);
$cases = [
    "{contact_info:phone:{$contact}@format:tel}"  => '+492082076580',
    "{contact_info:phone:{$contact} @format:tel}" => '+492082076580',
    "{contact_info:phone:{$contact}|link=false}"  => '0208 207658 0',
    "{social_account:url:{$social}}"              => 'https://social.example/tf',
    "{acf_phone @fallback:'x' @format:tel}"       => '0151 1111', // Bricks today: fallback swallows the rest
    '{acf_phone:plain @format:tel}'               => '0151 1111', // Bricks today, text context
];
$with = [];
foreach ($cases as $tag => $expected) {
    $with[$tag] = bricks_render_dynamic_data($tag, $a, 'text');
    $check($with[$tag] === $expected, "4: baseline {$tag} -> {$with[$tag]}");
}
remove_filter('bricks/dynamic_data/render_content', [\SFX\TelFormat\Controller::class, 'render_content'], 9);
remove_filter('bricks/frontend/render_data', [\SFX\TelFormat\Controller::class, 'render_data'], 9);
foreach ($cases as $tag => $expected) {
    $check(bricks_render_dynamic_data($tag, $a, 'text') === $with[$tag], "4: identical without module {$tag}");
}

echo $failures === 0 ? "tel-format-live-check: PASS\n" : "tel-format-live-check: {$failures} FAILED\n";
// exit status is set by the teardown
```

- [ ] **Step 2: Run it**

Run (MAMP MySQL running): `"$PHP" tests/support/tel-format-live-check.php; echo "exit=$?"`
Expected: every line `ok`, `tel-format-live-check: PASS`, `teardown: removed and verified 7 fixtures`, `exit=0`. If a check fails because Bricks renders differently than the shapes stated above, adjust **the harness only** (never the module to suit the harness) and record the change in the commit body.

- [ ] **Step 3: AGENTS.md**

Line 14: change `… editor prose mirroring, and a` to `… editor prose mirroring, \`@format:tel\` for simple Bricks dynamic tags, and a`.
Line 39: change `` Root-level shared services (`inc/AccessControl.php`, `inc/SFXBricksChildAdmin.php`, `` to `` Root-level shared services (`inc/AccessControl.php`, `inc/SFXBricksChildAdmin.php`, `inc/TelNormalizer.php`, ``.

Then grep for statements this change falsifies: `grep -n "normalize_tel\|SC_ContactInfos\|module\b" AGENTS.md README.md docs/*.md | head -40` and fix any line that names `SC_ContactInfos::normalize_tel` as where the logic lives.

- [ ] **Step 4: Battery and WIP commit**

Run: `./quality.sh` → Expected: all PASS (the harness is not in the glob; `prompt-artifact-paths-test.php` still passes with the AGENTS.md edit).

```bash
git add tests/support/tel-format-live-check.php AGENTS.md
git commit -m "WIP: TelFormat live check harness; AGENTS.md"
```

---

### Task 5: Gate B and closing commit

- [ ] **Step 1:** Run Gate B (CLAUDE.md §5) with `baseSha` = the parent of the first `WIP:` commit of this cycle (`git log --format='%h %s' main..HEAD`), including the spec path, the standing lens ("which existing statements does this diff falsify?" — sizes/values that changed: picker names, help strings, AGENTS.md lists, test counts in AGENTS.md "Commands"), and the evidence: `./quality.sh` output and the live harness output. Unprofiled (no story cited).
- [ ] **Step 2:** Fix Blocker/Major, re-review, until a pass is clean (min 3 passes).
- [ ] **Step 3:** `git reset --soft <parent-of-first-WIP>` then one commit:

```bash
git commit -m "feat(tel-format): @format:tel for simple Bricks tags; uniform attribute spelling

<summary of behaviour, compatibility table pointer, accepted limits, Gate B record>

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4:** Push the branch and open a PR (AGENTS.md invariant 7); stop there.
