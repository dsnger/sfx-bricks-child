<?php
// The page template for tests/support/markdown-fpm-app.php; what it does depends on $scenario.
// No strict_types: WordPress includes templates as plain files.

$head = '<!DOCTYPE html><html><head><title>T</title></head><body><header id="brx-header">SITE-HEADER</header>';
$content = '<main id="brx-content"><h1>Hello</h1><p>World</p></main>';
$foot = '<footer id="brx-footer">SITE-FOOTER</footer></body></html>';

switch ($scenario) {
    case 'unclosed-root':
        echo $head, '<main id="brx-content"><h1>Hello</h1><p>World</p>', $foot;
        break;
    case 'enhance-passthrough':
        echo $head, '<div>No content area</div>', $foot;
        break;
    case 'throw':
        echo $head, $content;
        throw new RuntimeException('template failed');
    case 'leave-buffer-open':
        ob_start();
        echo $head, $content, $foot;
        break;
    case 'close-own-buffer':
        echo $head;
        ob_end_flush();
        echo $content, $foot;
        break;
    case 'ob-flush':
        echo $head;
        ob_flush();
        echo $content, $foot;
        break;
    case 'close-and-reopen':
        echo $head;
        ob_end_flush(); // flush, not clean: a stray clean discards different bytes with and without the module
        ob_start();
        echo $content, $foot;
        break;
    case 'exit-in-template':
        echo $head, $content, $foot;
        exit;
    default:
        echo $head, $content, $foot;
}
