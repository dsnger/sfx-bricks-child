<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * The one access predicate of the module (spec "Who can use it"): theme
 * access AND manage_options. Every page and endpoint asks this; endpoints
 * additionally check the nonce NONCE.
 */
final class Access
{
    public const NONCE = 'sfx_site_check';

    public static function allowed(): bool
    {
        return \SFX\AccessControl::can_access_theme_settings() && current_user_can('manage_options');
    }
}
