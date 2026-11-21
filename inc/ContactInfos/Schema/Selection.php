<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

use SFX\ContactInfos\PostType;

/**
 * Which contact is the organization, and which contacts are its locations and contact points
 * (spec § "Selection of contacts"). Read fresh on every call; never from the shortcode cache.
 */
final class Selection
{
    public const ORG_META = '_sfx_contact_schema_org';
    public const EXCLUDED_META = '_sfx_contact_schema_excluded';

    /**
     * @return array{status:string, organization_id:int, location_ids:list<int>, contact_point_ids:list<int>, unused_main_ids:list<int>, marked_ids:list<int>}
     *         status: ok|none_marked_unconfigured|marked_invalid|multiple_marked|deleted_after_choice|no_main;
     *         organization_id is 0 unless the status is ok or none_marked_unconfigured;
     *         marked_ids: every contact carrying the mark, any status, ascending.
     */
    public static function resolve(): array
    {
        $contacts = self::contacts();
        $marked = array_values(array_filter($contacts, static fn(\WP_Post $p): bool => self::is_marked($p->ID)));
        $mains = array_values(array_filter($contacts, static fn(\WP_Post $p): bool => self::is_published_main($p)));

        $marked_ids = array_map(static fn(\WP_Post $p): int => $p->ID, $marked);
        sort($marked_ids);

        $organization_id = 0;
        if (count($marked) > 1) {
            $status = 'multiple_marked';
        } elseif (count($marked) === 1) {
            $status = self::is_published_main($marked[0]) ? 'ok' : 'marked_invalid';
            $organization_id = $status === 'ok' ? $marked[0]->ID : 0;
        } elseif (Settings::get()['chosen_once']) {
            $status = 'deleted_after_choice'; // the chosen contact was deleted: no silent substitution
        } elseif ($mains === []) {
            $status = 'no_main';
        } else {
            $status = 'none_marked_unconfigured';
            $organization_id = $mains[0]->ID;
        }

        $ids_of = static function (string $type) use ($contacts): array {
            $ids = [];
            foreach ($contacts as $post) {
                if ($post->post_status === 'publish' && self::type($post->ID) === $type
                    && get_post_meta($post->ID, self::EXCLUDED_META, true) !== '1') {
                    $ids[] = $post->ID;
                }
            }
            return $ids;
        };

        return [
            'status'            => $status,
            'organization_id'   => $organization_id,
            'location_ids'      => $ids_of('branch'),
            'contact_point_ids' => $ids_of('contact_point'),
            'unused_main_ids'   => array_values(array_diff(array_map(static fn(\WP_Post $p): int => $p->ID, $mains), [$organization_id])),
            'marked_ids'        => $marked_ids,
        ];
    }

    /**
     * Mark one published main contact as the organization and store the exclusions.
     * Exclusions for the organization itself or for unpublished or unknown IDs are ignored.
     *
     * @param list<int> $excluded_ids
     * @return string saved | invalid_choice (nothing written) | write_failed (a metadata write did not take)
     */
    public static function save_choice(int $organization_id, array $excluded_ids): string
    {
        $contacts = self::contacts();
        $chosen = null;
        foreach ($contacts as $post) {
            if ($post->ID === $organization_id && self::is_published_main($post)) {
                $chosen = $post;
            }
        }
        if ($chosen === null) {
            return 'invalid_choice';
        }

        $ok = true;
        foreach ($contacts as $post) {
            if ($post->ID !== $organization_id && self::is_marked($post->ID)) {
                $ok = self::remove($post->ID, self::ORG_META) && $ok;
            }
        }
        $ok = self::write($organization_id, self::ORG_META) && $ok;

        $excluded_ids = array_map('intval', $excluded_ids);
        foreach ($contacts as $post) {
            if ($post->post_status !== 'publish') {
                continue;
            }
            if ($post->ID !== $organization_id && in_array($post->ID, $excluded_ids, true)) {
                $ok = self::write($post->ID, self::EXCLUDED_META) && $ok;
            } else {
                $ok = self::remove($post->ID, self::EXCLUDED_META) && $ok;
            }
        }

        Settings::update(['chosen_once' => true]);
        return $ok ? 'saved' : 'write_failed';
    }

    /** Stores '1'; update_post_meta() is false also for an unchanged value, so the stored value decides. */
    private static function write(int $id, string $key): bool
    {
        return update_post_meta($id, $key, '1') !== false || get_post_meta($id, $key, true) === '1';
    }

    /** delete_post_meta() is false also when there was nothing to delete, so what is left decides. */
    private static function remove(int $id, string $key): bool
    {
        return delete_post_meta($id, $key) || get_post_meta($id, $key) === [];
    }

    /** @return list<\WP_Post> every contact in any status, so a mark on an unpublished one is seen */
    private static function contacts(): array
    {
        return get_posts([
            'post_type'   => PostType::$post_type,
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'numberposts' => -1,
            'orderby'     => ['menu_order' => 'ASC', 'date' => 'DESC'],
        ]);
    }

    /** main, branch or contact_point; anything else counts as main, as in the contact edit form */
    public static function type(int $id): string
    {
        $type = (string) get_post_meta($id, '_contact_type', true);
        return in_array($type, ['branch', 'contact_point'], true) ? $type : 'main';
    }

    private static function is_marked(int $id): bool
    {
        return get_post_meta($id, self::ORG_META, true) === '1';
    }

    private static function is_published_main(\WP_Post $post): bool
    {
        return $post->post_status === 'publish' && self::type($post->ID) === 'main';
    }
}
