<?php

declare(strict_types=1);

// The ImportExport catalogue holds the Markdown option as a single, exportable group,
// and an export/import round trip through the real catalogue keeps the effective selection.
$test_options = [];
function __($text, $domain = 'default') { return $text; }
function apply_filters($hook, $value, ...$args) { return $value; }
function get_option($name, $default = false)
{
    global $test_options;
    return array_key_exists($name, $test_options) ? $test_options[$name] : $default;
}
function update_option($name, $value) { global $test_options; $test_options[$name] = $value; return true; }
function sanitize_key($key) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function sanitize_text_field($s) { return trim((string) $s); }
function get_post_types($args = [], $output = 'names')
{
    $all = isset($args['name']) ? [$args['name'] => 'Pages'] : ['post' => 'Posts'];
    $out = [];
    foreach ($all as $name => $label) {
        $out[$name] = (object) ['name' => $name, 'label' => $label, 'labels' => (object) ['name' => $label]];
    }
    return $output === 'objects' ? $out : array_keys($out);
}

require __DIR__ . '/../inc/ImportExport/Controller.php';
require __DIR__ . '/../inc/Markdown/Settings.php';

function fail(string $m): void { fwrite(STDERR, "FAIL: {$m}\n"); exit(1); }

$groups = \SFX\ImportExport\Controller::get_settings_groups();
if (($groups['markdown']['option_key'] ?? null) !== 'sfx_markdown_options' || ($groups['markdown']['type'] ?? null) !== 'single') {
    fail('catalogue lacks markdown => sfx_markdown_options / single');
}

$io = (new ReflectionClass(\SFX\ImportExport\Controller::class))->newInstanceWithoutConstructor();
$export = new ReflectionMethod($io, 'collect_settings_data');
$import = new ReflectionMethod($io, 'import_settings_data');
if (PHP_VERSION_ID < 80100) {
    $export->setAccessible(true);
    $import->setAccessible(true);
}

/** Exports from $source, imports into an empty site in $mode, returns the target's effective settings. */
$round_trip = static function (?array $source, string $mode) use ($export, $import): array {
    global $test_options;
    $test_options = $source === null ? [] : ['sfx_markdown_options' => $source];
    $io = $export->getDeclaringClass()->newInstanceWithoutConstructor();
    $data = json_decode((string) json_encode($export->invoke($io, ['markdown'])), true);
    $test_options = [];
    $import->invoke($io, $data, ['markdown'], $mode);
    return \SFX\Markdown\Settings::get();
};

foreach (['replace', 'merge'] as $mode) {
    if ($round_trip(null, $mode)['post_types'] !== ['page', 'post']) {
        fail("{$mode}: a never-saved option must import as the defaults page/post");
    }
    // Merge mode skips empty imported values by design (Controller::deep_merge_arrays), so the
    // never-saved target keeps its own defaults; replace mode copies the empty selection.
    $want = $mode === 'replace' ? [] : ['page', 'post'];
    if ($round_trip(['post_types' => []], $mode)['post_types'] !== $want) {
        fail("{$mode}: a saved empty selection imports wrongly");
    }
    if ($round_trip(['post_types' => ['post']], $mode)['post_types'] !== ['post']) {
        fail("{$mode}: a saved selection must import unchanged");
    }
}
echo "OK: ImportExport catalogue holds the markdown entry; export/import keeps defaults, empty and saved selections\n";
