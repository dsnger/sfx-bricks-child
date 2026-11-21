<?php
// template_include's replacement (Response::maybe_take_over). template-loader.php includes it at
// global scope, so the template included here keeps the scope it has without the module.
try {
    include \SFX\Markdown\Response::capture();
} catch (\Throwable $sfx_markdown_error) {
    \SFX\Markdown\Response::release();
    throw $sfx_markdown_error;
}
\SFX\Markdown\Response::finish();
