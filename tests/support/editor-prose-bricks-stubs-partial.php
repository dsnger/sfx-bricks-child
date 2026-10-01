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
        public static bool $called = false;

        public static function generate_global_classes($key = 'global_classes')
        {
            self::$called = true;
            return ".abc.brxe-text { color: red; }\n";
        }
    }
}

namespace Bricks\Integrations {
    class Block_Editor
    {
        public static function scope_css_for_gutenberg($css, $map = false)
        {
            return $css;
        }
    }
}
