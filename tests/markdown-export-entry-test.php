<?php

declare(strict_types=1);

// The ImportExport catalogue holds the Markdown option as a single, exportable group.
function __($text, $domain = 'default') { return $text; }
function get_post_types($args = [], $output = 'names') { return []; }
function apply_filters($hook, $value, ...$args) { return $value; }

require __DIR__ . '/../inc/ImportExport/Controller.php';

$groups = \SFX\ImportExport\Controller::get_settings_groups();
if (($groups['markdown']['option_key'] ?? null) !== 'sfx_markdown_options' || ($groups['markdown']['type'] ?? null) !== 'single') {
    fwrite(STDERR, "FAIL: catalogue lacks markdown => sfx_markdown_options / single\n");
    exit(1);
}
echo "OK: ImportExport catalogue holds the markdown entry\n";
