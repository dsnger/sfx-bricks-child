<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

final class FieldRegistry
{
    /**
     * @return array<string, string>
     */
    public static function get_fields(): array
    {
        return [
            'url'    => __('URL', 'sfxtheme'),
            'icon'   => __('Icon', 'sfxtheme'),
            'title'  => __('Title', 'sfxtheme'),
            'target' => __('Link Target', 'sfxtheme'),
            'html'   => __('Full HTML Block', 'sfxtheme'),
        ];
    }

    public static function get_meta_key(string $field): string
    {
        return match ($field) {
            'url'    => '_link_url',
            'icon'   => '_icon_image',
            'title'  => '_link_title',
            'target' => '_link_target',
            default  => '',
        };
    }
}
