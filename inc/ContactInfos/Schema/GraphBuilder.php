<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

use SFX\ContactInfos\PostType;
use SFX\SocialMediaAccounts\FieldRegistry as SocialFieldRegistry;
use SFX\SocialMediaAccounts\PostType as SocialPostType;

/**
 * The organization entity built from the selected contacts (spec § "Eligibility and minimum data",
 * § "What is published"), in the page language, plain strings only, empty values omitted.
 * Own output adds @context, @id, url and logo; the plugin extension merges the entity.
 */
final class GraphBuilder
{
    /**
     * @param array  $selection Selection::resolve() result
     * @param string $type      'Organization' or 'LocalBusiness' (C4)
     * @return array{entity:array, incomplete:list<array{id:int, missing:list<string>}>}
     *         entity is [] when nothing may be published; missing holds
     *         name | address | area | telephone_or_email.
     */
    public static function organization(array $selection, string $type): array
    {
        $id = (int) ($selection['organization_id'] ?? 0);
        if (!in_array($selection['status'] ?? '', ['ok', 'none_marked_unconfigured'], true) || $id <= 0) {
            return ['entity' => [], 'incomplete' => []];
        }
        $name = self::text($id, 'company');
        if ($name === '') {
            return ['entity' => [], 'incomplete' => [['id' => $id, 'missing' => ['name']]]];
        }

        $incomplete = [];
        $address = self::postal_address($id);
        $local_business = $type === 'LocalBusiness' && $address !== null; // else downgraded
        $entity = self::filled([
            '@type'     => $local_business ? 'LocalBusiness' : 'Organization',
            'name'      => $name,
            'address'   => $address,
            'telephone' => self::text($id, 'phone'),
            'email'     => self::text($id, 'email'),
            'sameAs'    => self::same_as(),
        ]);

        foreach ($selection['contact_point_ids'] ?? [] as $point_id) {
            $missing = [];
            $area = self::text($point_id, 'schema_area', (string) get_post_meta($point_id, '_sfx_contact_schema_area', true));
            $telephone = self::text($point_id, 'phone');
            $email = self::text($point_id, 'email');
            if ($area === '') {
                $missing[] = 'area';
            }
            if ($telephone === '' && $email === '') {
                $missing[] = 'telephone_or_email';
            }
            if ($missing !== []) {
                $incomplete[] = ['id' => $point_id, 'missing' => $missing];
                continue;
            }
            $entity['contactPoint'][] = self::filled([
                '@type' => 'ContactPoint', 'contactType' => $area, 'telephone' => $telephone, 'email' => $email,
            ]);
        }

        foreach ($selection['location_ids'] ?? [] as $location_id) {
            $post = get_post($location_id);
            $location_name = self::text($location_id, 'title', $post instanceof \WP_Post ? $post->post_title : '');
            $location_address = self::postal_address($location_id);
            $missing = array_keys(array_filter(['name' => $location_name === '', 'address' => $location_address === null]));
            if ($missing !== []) {
                $incomplete[] = ['id' => $location_id, 'missing' => $missing];
                continue;
            }
            $entity['department'][] = self::filled([
                '@type'                     => 'LocalBusiness',
                'name'                      => $location_name,
                'address'                   => $location_address,
                'telephone'                 => self::text($location_id, 'phone'),
                'email'                     => self::text($location_id, 'email'),
                'openingHoursSpecification' => self::hours($location_id),
            ]);
        }

        if ($local_business) {
            $entity += self::filled(['openingHoursSpecification' => self::hours($id)]);
        }

        return ['entity' => $entity, 'incomplete' => $incomplete];
    }

    /** A PostalAddress from the address parts; null unless street and city are filled. */
    public static function postal_address(int $contact_id): ?array
    {
        $street = self::text($contact_id, 'street');
        $city = self::text($contact_id, 'city');
        if ($street === '' || $city === '') {
            return null;
        }
        return self::filled([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $street,
            'postalCode'      => self::text($contact_id, 'zip'),
            'addressLocality' => $city,
            'addressCountry'  => self::text($contact_id, 'country'),
        ]);
    }

    private static function text(int $id, string $field, string $default = ''): string
    {
        return wp_strip_all_tags(PostType::get_translated_field($id, $field, $default));
    }

    /** @return list<array> */
    private static function hours(int $id): array
    {
        $hours = get_post_meta($id, '_sfx_contact_schema_hours', true);
        return is_array($hours) ? OpeningHours::to_schema($hours) : [];
    }

    /** @return list<string> URLs of the published social media accounts, in their list order */
    private static function same_as(): array
    {
        $accounts = get_posts([
            'post_type'   => SocialPostType::$post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
        ]);
        $urls = [];
        foreach ($accounts as $account) {
            $url = esc_url_raw((string) get_post_meta($account->ID, SocialFieldRegistry::get_meta_key('url'), true), ['http', 'https']);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** Drops empty strings, nulls and empty lists, so nothing is published empty. */
    private static function filled(array $values): array
    {
        return array_filter($values, static fn($v): bool => $v !== '' && $v !== null && $v !== []);
    }
}
