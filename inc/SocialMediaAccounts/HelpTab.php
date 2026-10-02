<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

/**
 * Usage reference for [social_accounts], [social_account] and the {social_account…} Bricks
 * tags, shown in the native "Help" tab of the social account list and edit screens.
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
                'id'      => 'sfx-social-account-shortcodes',
                'title'   => __('Shortcodes', 'sfxtheme'),
                'content' => self::shortcodes_tab(),
            ],
            [
                'id'      => 'sfx-social-account-bricks',
                'title'   => __('Bricks tags', 'sfxtheme'),
                'content' => self::bricks_tab(),
            ],
            [
                'id'      => 'sfx-social-account-examples',
                'title'   => __('Examples', 'sfxtheme'),
                'content' => self::examples_tab(),
            ],
        ];
    }

    public static function get_sidebar(): string
    {
        return '<p><strong>' . esc_html__('Social Media Accounts', 'sfxtheme') . '</strong></p>'
            . '<p>' . esc_html__('Only published entries are used; the account list follows their Order. Saving an entry refreshes the cached output at once.', 'sfxtheme') . '</p>';
    }

    private static function shortcodes_tab(): string
    {
        $list_rows = [
            [
                self::code('class'),
                self::code('social-accounts'),
                esc_html__('CSS class on the wrapper and on every account.', 'sfxtheme'),
            ],
            [
                self::code('style'),
                self::code('list'),
                sprintf(
                    /* translators: %s: CSS class pattern */
                    esc_html__('Added as the class %s. Any value; the theme ships no styles for it.', 'sfxtheme'),
                    self::code('social-accounts-{style}')
                ),
            ],
            [
                self::code('size'),
                self::code('medium'),
                sprintf(
                    /* translators: 1: CSS class pattern on the wrapper, 2: CSS class pattern on each account */
                    esc_html__('Added as the class %1$s on the wrapper and %2$s on every account. Any value.', 'sfxtheme'),
                    self::code('social-accounts-{size}'),
                    self::code('social-account-{size}')
                ),
            ],
            [
                self::code('target'),
                self::code('_blank'),
                sprintf(
                    /* translators: 1: "_blank", 2: "_self", 3: "_blank" */
                    esc_html__('Link target for accounts without their own: %1$s or %2$s; anything else becomes %3$s.', 'sfxtheme'),
                    self::code('_blank'),
                    self::code('_self'),
                    self::code('_blank')
                ),
            ],
        ];

        $notes = [
            'url'    => __('Link URL', 'sfxtheme'),
            'icon'   => __('Icon image URL', 'sfxtheme'),
            'title'  => __('Link title, or the account title if empty', 'sfxtheme'),
            'target' => __('_blank or _self (default _blank)', 'sfxtheme'),
            'html'   => __('The account as a full link block, as in the list; class, size and target apply here', 'sfxtheme'),
        ];
        $field_rows = [];
        foreach (FieldRegistry::get_fields() as $key => $label) {
            $field_rows[] = [self::code($key), esc_html($label), esc_html($notes[$key] ?? '')];
        }

        return '<p>' . sprintf(
            /* translators: %s: shortcode */
            esc_html__('%s lists all published accounts in their Order. Each links to its URL with its icon image, or its title if there is no icon. Accounts without a URL are skipped.', 'sfxtheme'),
            self::code('[social_accounts]')
        ) . '</p>'
            . self::table([__('Attribute', 'sfxtheme'), __('Default', 'sfxtheme'), __('Effect', 'sfxtheme')], $list_rows)
            . '<p>' . sprintf(
                /* translators: 1: shortcode example, 2: attribute name "id", 3: attribute name "field", 4: default field "html" */
                esc_html__('%1$s shows one field of one published account. %2$s is required; %3$s defaults to %4$s.', 'sfxtheme'),
                self::code('[social_account id="123" field="url"]'),
                self::code('id'),
                self::code('field'),
                self::code('html')
            ) . '</p>'
            . self::table([__('Field', 'sfxtheme'), __('Label', 'sfxtheme'), __('Output', 'sfxtheme')], $field_rows);
    }

    private static function bricks_tab(): string
    {
        $rows = [
            [
                self::code('{social_accounts}'),
                sprintf(
                    /* translators: %s: shortcode */
                    esc_html__('Same as %s with its defaults; takes no attributes.', 'sfxtheme'),
                    self::code('[social_accounts]')
                ),
            ],
            [
                self::code('{social_account:url:123}'),
                esc_html__('Field of the account with ID 123.', 'sfxtheme'),
            ],
            [
                self::code('{social_account:url}'),
                esc_html__('Inside a query loop over social accounts: field of the current account. Outside such a loop it outputs nothing.', 'sfxtheme'),
            ],
        ];

        return self::table([__('Tag', 'sfxtheme'), __('Output', 'sfxtheme')], $rows)
            . '<p>' . sprintf(
                /* translators: 1: "@key:value", 2: "|key=value", 3: example tag */
                esc_html__('For the html field, class, size and target can be appended as %1$s or %2$s, e.g. %3$s.', 'sfxtheme'),
                self::code('@key:value'),
                self::code('|key=value'),
                self::code('{social_account:html:123@size:small}')
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: query type "Posts", 2: post type label "Social Media Accounts" */
                esc_html__('Query loop: type %1$s, post type %2$s. Only published accounts resolve; an ID in the tag always wins.', 'sfxtheme'),
                '<strong>' . esc_html__('Posts', 'sfxtheme') . '</strong>',
                '<strong>' . esc_html__('Social Media Accounts', 'sfxtheme') . '</strong>'
            ) . '</p>'
            . '<p>' . sprintf(
                /* translators: %s: Bricks picker group name */
                esc_html__('The dynamic data picker lists every field of every published account in the group %s.', 'sfxtheme'),
                '<strong>' . esc_html__('Social Accounts', 'sfxtheme') . '</strong>'
            ) . '</p>';
    }

    private static function examples_tab(): string
    {
        $rows = [
            ['[social_accounts]', __('All accounts as a list of links.', 'sfxtheme')],
            ['[social_accounts class="footer-social" size="small"]', __('All accounts with your own class and size class.', 'sfxtheme')],
            ['[social_account id="123" field="url"]', __('Only the URL of account 123.', 'sfxtheme')],
            ['{social_account:html:123}', __('Account 123 as a link block in a Bricks text element.', 'sfxtheme')],
            ['{social_account:icon}', __('Inside a query loop: image URL for a Bricks image element.', 'sfxtheme')],
            ['{social_account:url}', __('Inside a query loop: link URL for a Bricks button or link.', 'sfxtheme')],
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
