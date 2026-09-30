<?php

declare(strict_types=1);

namespace Bricks {
    class Database
    {
        public static $global_data = [];
    }

    class Assets
    {
        public static $global_classes_elements = [];
        public static $inline_css = [];
        public static $inline_css_breakpoints = [];
        public static $unique_inline_css = [];
        public static $inline_css_dynamic_data = '';
        public static $current_generating_element = null;

        public static bool $throw = false;
        public static array $seen = [];

        public static function generate_global_classes($key = 'global_classes')
        {
            self::$seen[] = [$key, self::$global_classes_elements];
            $map = self::$global_classes_elements;
            // Dirty every static the way a real compile can, before returning or throwing.
            self::$global_classes_elements = ['dirty' => ['x']];
            self::$inline_css = ['dirty' => 'x'];
            self::$inline_css_breakpoints = ['dirty' => 'x'];
            self::$unique_inline_css = ['dirty'];
            self::$inline_css_dynamic_data = 'dirty';
            self::$current_generating_element = 'dirty';
            if (self::$throw) {
                throw new \RuntimeException('boom');
            }
            if ($map === []) {
                return null;
            }
            $css = '';
            foreach ($map as $id => $els) {
                $css .= ".{$id}.brxe-{$els[0]} { font-family: \"Inter\"; }\n";
            }
            return $css;
        }

        public static function load_webfonts($css, $return_html_links = false)
        {
            // Only answers the HTML-links mode with a font actually used in $css.
            if ($return_html_links !== true || strpos((string) $css, 'Inter') === false) {
                return null;
            }
            return '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
                . '<link rel="stylesheet" href="https://fonts.example/css?family=Inter&amp;display=swap">';
        }
    }
}

namespace Bricks\Integrations {
    class Block_Editor
    {
        public static function scope_css_for_gutenberg($css, $map = false)
        {
            return preg_replace('/^\./m', '.block-editor-iframe__body .', $css);
        }
    }
}
