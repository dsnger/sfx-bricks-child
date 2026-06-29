<?php

declare(strict_types=1);

namespace SFX\Admin;

use SFX\ContactInfos\FieldRegistry as ContactFieldRegistry;
use SFX\SocialMediaAccounts\FieldRegistry as SocialFieldRegistry;

final class PlaceholderItems
{
    /**
     * @return array<int, array{label: string, shortcode: string, bricks: string}>
     */
    public static function build_contact_items(int $post_id): array
    {
        $items = [];

        foreach (ContactFieldRegistry::get_fields() as $field => $label) {
            $items[] = [
                'label'     => $label,
                'shortcode' => sprintf('[contact_info field="%s" contact_id="%d"]', $field, $post_id),
                'bricks'    => sprintf('{contact_info:%s:%d}', $field, $post_id),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{label: string, shortcode: string, bricks: string}>
     */
    public static function build_social_items(int $post_id): array
    {
        $items = [];
        $fields = SocialFieldRegistry::get_fields();

        foreach ($fields as $field => $label) {
            if ($field === 'html') {
                continue;
            }

            $items[] = [
                'label'     => $label,
                'shortcode' => sprintf('[social_account id="%d" field="%s"]', $post_id, $field),
                'bricks'    => sprintf('{social_account:%s:%d}', $field, $post_id),
            ];
        }

        $items[] = [
            'label'     => $fields['html'],
            'shortcode' => sprintf('[social_account id="%d"]', $post_id),
            'bricks'    => sprintf('{social_account:html:%d}', $post_id),
        ];

        return $items;
    }
}
