<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

use SFX\ContactInfos\Shortcode\SC_ContactInfos;

/**
 * Usage reference for [contact_info] and the {contact_info:…} Bricks tags, shown in the
 * native "Help" tab of the contact info list and edit screens.
 */
final class HelpTab
{
    public static function register(): void
    {
        add_action('current_screen', [self::class, 'add_to_screen']);
    }

    /**
     * @param mixed $screen
     */
    public static function add_to_screen($screen): void
    {
        if (!$screen instanceof \WP_Screen
            || $screen->post_type !== PostType::$post_type
            || !in_array($screen->base, ['edit', 'post'], true)) {
            return;
        }

        foreach (self::get_tabs() as $tab) {
            $tab['content'] = wp_kses_post($tab['content']);
            $screen->add_help_tab($tab);
        }
        $screen->set_help_sidebar(wp_kses_post(self::get_sidebar()));
    }

    /**
     * @return list<array{id: string, title: string, content: string}>
     */
    public static function get_tabs(): array
    {
        return [
            [
                'id'      => 'sfx-contact-info-shortcode',
                'title'   => __('Shortcode', 'sfxtheme'),
                'content' => self::shortcode_tab(),
            ],
            [
                'id'      => 'sfx-contact-info-attributes',
                'title'   => __('Attributes', 'sfxtheme'),
                'content' => self::attributes_tab(),
            ],
            [
                'id'      => 'sfx-contact-info-bricks',
                'title'   => __('Bricks tags', 'sfxtheme'),
                'content' => self::bricks_tab(),
            ],
            [
                'id'      => 'sfx-contact-info-examples',
                'title'   => __('Examples', 'sfxtheme'),
                'content' => self::examples_tab(),
            ],
        ];
    }

    public static function get_sidebar(): string
    {
        return '<p><strong>' . esc_html__('Contact Information', 'sfxtheme') . '</strong></p>'
            . '<p>' . esc_html__('Selection by type and in query loops uses published entries only; an entry chosen by ID is shown whatever its status. Field values can be cached for up to 30 minutes. Saving an entry refreshes its own values; the "Clear Cache" button on the list screen refreshes all published entries and the main/branch values at once.', 'sfxtheme') . '</p>';
    }

    private static function shortcode_tab(): string
    {
        $main_only = ['company', 'tax_id', 'vat', 'hrb', 'court', 'dsb'];
        $notes = [
            'email'   => __('Email link (mailto:)', 'sfxtheme'),
            'phone'   => __('Phone link (tel:) with a cleaned number', 'sfxtheme'),
            'mobile'  => __('Phone link (tel:) with a cleaned number', 'sfxtheme'),
            'address' => __('Full Address as entered (HTML allowed); if empty, built from street, ZIP code, city and country', 'sfxtheme'),
            'opening' => __('HTML from the editor, never wrapped', 'sfxtheme'),
            'maplink' => __('Link "View on Map", opens in a new tab', 'sfxtheme'),
        ];

        $rows = [];
        foreach (FieldRegistry::get_fields() as $key => $label) {
            $note = $notes[$key] ?? __('Plain text', 'sfxtheme');
            if (in_array($key, $main_only, true)) {
                /* translators: %s: how the field is output, e.g. "Plain text" */
                $note = sprintf(__('%s; main contact only', 'sfxtheme'), $note);
            }
            $rows[] = [self::code($key), esc_html($label), esc_html($note)];
        }

        return '<p>' . sprintf(
            /* translators: 1: shortcode example, 2: attribute name, 3: attribute name */
            esc_html__('%1$s shows one field of a contact entry. Without %2$s or %3$s it uses the main contact.', 'sfxtheme'),
            self::code('[contact_info field="phone"]'),
            self::code('contact_id'),
            self::code('type')
        ) . '</p>'
            . self::table([__('Field', 'sfxtheme'), __('Label', 'sfxtheme'), __('Output', 'sfxtheme')], $rows)
            . '<p>' . sprintf(
                /* translators: 1: "00", 2: "+", 3: "0", 4: "+49", 5: "(0)", 6: ";ext=", 7: filter name */
                esc_html__('Numbers in tel: links are cleaned: separators and spaces are removed, a leading %1$s becomes %2$s, a leading %3$s becomes %4$s, %5$s after the country code is dropped, and an extension becomes %6$s. Filter for the country code: %7$s.', 'sfxtheme'),
                self::code('00'),
                self::code('+'),
                self::code('0'),
                self::code('+49'),
                self::code('(0)'),
                self::code(';ext='),
                self::code('sfx_contact_info_default_country_code')
            ) . '</p>';
    }

    private static function attributes_tab(): string
    {
        $effects = [
            'field'      => sprintf(
                /* translators: %s: name of the Shortcode help tab */
                esc_html__('Required. A field key from the %s tab; without it nothing is output.', 'sfxtheme'),
                esc_html__('Shortcode', 'sfxtheme')
            ),
            'contact_id' => esc_html__('ID of one contact entry. Takes precedence over type.', 'sfxtheme'),
            'type'       => sprintf(
                /* translators: 1: "main", 2: "branch" */
                esc_html__('%1$s or %2$s: uses the newest published entry of this type.', 'sfxtheme'),
                self::code('main'),
                self::code('branch')
            ),
            'icon'       => sprintf(
                /* translators: %s: shortcode name "[icon]" */
                esc_html__('Icon name, passed to an %s shortcode placed before the value. The theme does not provide that shortcode; without a plugin that does, its text is printed as is.', 'sfxtheme'),
                self::code('[icon]')
            ),
            'icon_class' => esc_html__('Currently without effect.', 'sfxtheme'),
            'text'       => esc_html__('Email only: link text instead of the address.', 'sfxtheme'),
            'class'      => esc_html__('CSS classes for the wrapper; turns the wrapper on.', 'sfxtheme'),
            'link'       => sprintf(
                /* translators: 1: "false", 2: "0", 3: "no", 4: "off" */
                esc_html__('%1$s, %2$s, %3$s or %4$s: email, phone and mobile without a link.', 'sfxtheme'),
                self::code('false'),
                self::code('0'),
                self::code('no'),
                self::code('off')
            ),
            'wrap'       => sprintf(
                /* translators: 1: "true", 2: "1", 3: "yes", 4: "on", 5: example class "contact-info-phone" */
                esc_html__('%1$s, %2$s, %3$s or %4$s: wraps the output in a tag with a field class such as %5$s. Opening hours and a filled-in Full Address are never wrapped.', 'sfxtheme'),
                self::code('true'),
                self::code('1'),
                self::code('yes'),
                self::code('on'),
                self::code('contact-info-phone')
            ),
            'tag'        => sprintf(
                /* translators: 1: example tag "div", 2: default tag "span" */
                esc_html__('Wrapper tag, e.g. %1$s (default and fallback for invalid names: %2$s); turns the wrapper on.', 'sfxtheme'),
                self::code('div'),
                self::code('span')
            ),
            'debug'      => esc_html__('Any non-empty value except 0: shows the field value before formatting in a preformatted block instead (ignored when format=tel applies).', 'sfxtheme'),
            'format'     => sprintf(
                /* translators: 1: "tel", 2: example number "+4930123456" */
                esc_html__('%1$s with phone, mobile or fax: only the cleaned number (e.g. %2$s), without link, icon or wrapper. Meant for tel: links.', 'sfxtheme'),
                self::code('tel'),
                self::code('+4930123456')
            ),
        ];

        $rows = [];
        foreach (SC_ContactInfos::DEFAULT_ATTS as $key => $default) {
            $rows[] = [
                self::code($key),
                $default === null ? '&mdash;' : self::code((string) $default),
                $effects[$key] ?? '',
            ];
        }

        return '<p>' . sprintf(
            /* translators: %s: shortcode name */
            esc_html__('Attributes of %s. The Bricks tags accept the same attributes.', 'sfxtheme'),
            self::code('[contact_info]')
        ) . '</p>'
            . self::table([__('Attribute', 'sfxtheme'), __('Default', 'sfxtheme'), __('Effect', 'sfxtheme')], $rows);
    }

    private static function bricks_tab(): string
    {
        $rows = [
            [
                self::code('{contact_info:phone}'),
                esc_html__('Field of the main contact; inside a query loop over contact entries, of the current entry.', 'sfxtheme'),
            ],
            [
                self::code('{contact_info:phone:123}'),
                esc_html__('Field of the entry with ID 123.', 'sfxtheme'),
            ],
            [
                self::code('{contact_info:phone@format:tel}'),
                esc_html__('Cleaned number only, for link fields.', 'sfxtheme'),
            ],
        ];

        return self::table([__('Tag', 'sfxtheme'), __('Output', 'sfxtheme')], $rows)
            . '<p>' . sprintf(
                /* translators: 1: "@key:value", 2: "|key=value", 3: example tag */
                esc_html__('Attributes are appended as %1$s or %2$s and can be chained, e.g. %3$s. Values must not contain @, |, = or }.', 'sfxtheme'),
                self::code('@key:value'),
                self::code('|key=value'),
                self::code('{contact_info:email@link:false@wrap:true}')
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: query type "Posts", 2: post type label "Contact Information" */
                esc_html__('Query loop: type %1$s, post type %2$s. Inside the loop, tags without an ID show the current entry (published entries only); an ID in the tag always wins, and type has no effect there.', 'sfxtheme'),
                '<strong>' . esc_html__('Posts', 'sfxtheme') . '</strong>',
                '<strong>' . esc_html__('Contact Information', 'sfxtheme') . '</strong>'
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: %s: Bricks picker group name */
                esc_html__('The dynamic data picker lists every field in the group %s.', 'sfxtheme'),
                '<strong>' . esc_html('Contact Info') . '</strong>'
            ) . '</p>';
    }

    private static function examples_tab(): string
    {
        $rows = [
            ['[contact_info field="email" text="Write to us"]', __('Email link with your own link text.', 'sfxtheme')],
            ['[contact_info field="phone" type="branch" class="footer-phone"]', __('Branch phone as a link, wrapped in a span with the class footer-phone.', 'sfxtheme')],
            ['[contact_info field="opening" contact_id="123"]', __('Opening hours of entry 123.', 'sfxtheme')],
            ['tel:{contact_info:phone@format:tel}', __('Link field of a Bricks button: dials the main phone number.', 'sfxtheme')],
            ['mailto:{contact_info:email@link:false}', __('Link field of a Bricks button: writes to the main email address.', 'sfxtheme')],
            ['{contact_info:city}', __('Inside a query loop over contact entries: the city of each entry.', 'sfxtheme')],
            ['{contact_info:phone:123|link=false}', __('Phone number of entry 123 as plain text.', 'sfxtheme')],
        ];

        return self::table(
            [__('Example', 'sfxtheme'), __('Result', 'sfxtheme')],
            array_map(static fn(array $row): array => [self::code($row[0]), esc_html($row[1])], $rows)
        );
    }

    private static function code(string $text): string
    {
        return '<code>' . esc_html($text) . '</code>';
    }

    /**
     * @param list<string>       $headers Plain text, escaped here.
     * @param list<list<string>> $rows    Cells already escaped by the caller.
     */
    private static function table(array $headers, array $rows): string
    {
        $html = '<table class="widefat striped"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . esc_html($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . implode('</td><td>', $row) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }
}
