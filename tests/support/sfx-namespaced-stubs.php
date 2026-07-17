<?php

declare(strict_types=1);

/**
 * Stubs for classes in the SFX namespace.
 *
 * Separate file because PHP does not allow bracketed namespace blocks alongside
 * non-namespaced code, and social-bricks-stubs.php is global-namespace.
 */

namespace SFX;

/** PostType::register_post_type() registers its meta fields through this. */
class MetaFieldManager
{
    /**
     * @param list<string> $fields
     * @param list<string> $html_fields
     */
    public static function register_fields(string $post_type, array $fields, array $html_fields = []): void
    {
    }
}
