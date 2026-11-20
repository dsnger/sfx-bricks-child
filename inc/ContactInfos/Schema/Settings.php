<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * The schema switch, the organization type and whether an organization was ever chosen (C4, C5, C10).
 */
final class Settings
{
    public const OPTION = 'sfx_contact_infos_schema';

    private const TYPES = ['Organization', 'LocalBusiness'];

    /** @return array{enabled:bool,type:string,chosen_once:bool} a missing option is off (C10) */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $type = $stored['type'] ?? '';
        return [
            'enabled'     => (bool) ($stored['enabled'] ?? false),
            'type'        => in_array($type, self::TYPES, true) ? $type : 'Organization',
            'chosen_once' => (bool) ($stored['chosen_once'] ?? false),
        ];
    }

    /** @param array{enabled?:bool,type?:string,chosen_once?:bool} $values merged over the stored ones */
    public static function update(array $values): void
    {
        $merged = array_merge(self::get(), array_intersect_key($values, self::get()));
        update_option(self::OPTION, [
            'enabled'     => (bool) $merged['enabled'],
            'type'        => in_array($merged['type'], self::TYPES, true) ? $merged['type'] : 'Organization',
            'chosen_once' => (bool) $merged['chosen_once'],
        ]);
    }
}
