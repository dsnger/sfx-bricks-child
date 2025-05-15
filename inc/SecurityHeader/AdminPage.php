<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;

class AdminPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_submenu_page']);
    }

    public static function add_submenu_page(): void
    {
        add_submenu_page(
            'sfx-theme-settings',
            __('Security Header', 'sfxtheme'),
            __('Security Header', 'sfxtheme'),
            'manage_options',
            'sfx-security-header',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sfxtheme' ) );
        }
        ?>
        <div class="wrap" style="padding: 0; font-size: 14px;">
            <div class="sfx-flex">
                <div class="sfx-col" style="width: 50%;">
                    <div class="sfx-card">
                        <h1 class="sfx-title"><?php esc_html_e('Security Headers - HTTP Response Protection', 'sfxtheme'); ?></h1>
                        <form method="post" action="options.php">
                            <?php
                            settings_fields( \SFX\SecurityHeader\Settings::OPTION_GROUP );
                            do_settings_sections( \SFX\SecurityHeader\Settings::OPTION_GROUP );
                            ?>
                            <table class="sfx-form-table">
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'HSTS Max-Age', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="text" name="sfx_hsts_max_age" value="<?php echo esc_attr( get_option( 'sfx_hsts_max_age', '63072000' ) ); ?>" />
                                        <div class="sfx-description"><?php esc_html_e( 'The Max-Age parameter specifies the period of time (in seconds) for which the browser should store the HSTS information. During this time period, the browser will always use HTTPS to communicate with the website, even if the visitor has entered "http" or an HTTP link in the address bar. This helps protect the website and its visitors from man-in-the-middle (MITM) attacks and other security threats.', 'sfxtheme' ); ?></div>
                                        <div class="sfx-description"><em><?php esc_html_e( 'It is advisable to set "max-age" to a high value, such as a full year (31536000 seconds). This ensures that browsers continue to store security information for a long period of time, which helps protect users from man-in-the-middle attacks. However, it is important to keep in mind that setting the value too high could cause problems if you need to change your site\'s SSL configuration in the future. Therefore, it is important to carefully consider your usage and security needs before setting the value.', 'sfxtheme' ); ?></em></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Include Subdomains', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_hsts_include_subdomains" value="1" <?php checked( 1, (int) get_option( 'sfx_hsts_include_subdomains', 0 ) ); ?> />
                                        <div class="sfx-description"><?php esc_html_e( 'The "includeSubDomains" flag specifies that the effect of the header should also be applied to subdomains of the domain. When this directive is present, all requests to any subdomains of your domain are automatically redirected to the HTTPS protocol, providing enhanced security for the website and the users who visit it.', 'sfxtheme' ); ?></div>
                                        <div class="sfx-description"><em><?php esc_html_e( 'We recommend enabling the "includeSubDomains" option in the HSTS header to ensure that all subsections of your site (subdomains) are only loaded via HTTPS. However, before enabling this flag, it is important to ensure that all subdomains, resources and web services working under your domain are available via HTTPS and that there are no compatibility issues with any external services used by your site.', 'sfxtheme' ); ?></em></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Preload', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_hsts_preload" value="1" <?php checked( 1, (int) get_option( 'sfx_hsts_preload', 0 ) ); ?> />
                                        <div class="sfx-description"><?php printf( esc_html__( 'The "preload" flag allows the website to be included in the %1$sHSTS preload list%2$s, which instructs browsers to always use HTTPS connection for the site and its subdomains, without ever making insecure HTTP requests.', 'sfxtheme' ), '<a href="https://hstspreload.org/" target="_blank">', '</a>' ); ?></div>
                                        <div class="sfx-description"><em><?php printf( esc_html__( 'Enabling preload further helps prevent any potential man-in-the-middle attacks, thus improving connection security as far as it concerns HSTS. Please note that even if this flag is enabled, your website still needs to be manually submitted to the list. Please also note that inclusion in the preload list has permanent consequences and is not easy to undo, so you should only enable this flag and submit your website after making sure that all of the resources and services within your domain (and its subdomains, if includeSubDomains is also enabled) are indeed accessible and functional via HTTPS. %1$sLearn more%2$s.', 'sfxtheme' ), '<a href="https://hstspreload.org/#removal/" target="_blank">', '</a>' ); ?></em></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Content-Security-Policy (CSP)', 'sfxtheme' ); ?></th>
                                    <td>
                                        <textarea name="sfx_csp" rows="3" cols="60"><?php echo esc_textarea( get_option( 'sfx_csp', 'upgrade-insecure-requests;' ) ); ?></textarea>
                                        <div class="sfx-description"><?php esc_html_e( 'HTTP Content-Security-Policy header controls website resources, reducing XSS risk by specifying allowed server origins and script endpoints.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'CSP Report URI', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="text" name="sfx_csp_report_uri" value="<?php echo esc_attr( get_option( 'sfx_csp_report_uri', '' ) ); ?>" />
                                        <div class="sfx-description"><?php esc_html_e( 'Enter your custom URL (Sentry, URIports, Datadog, and Report URI) for CSP violation reports.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Permissions Policy', 'sfxtheme' ); ?></th>
                                    <td>
                                        <textarea name="sfx_permissions_policy" rows="3" cols="60"><?php echo esc_textarea( get_option( 'sfx_permissions_policy', '' ) ); ?></textarea>
                                        <div class="sfx-description"><?php esc_html_e( 'The HTTP Permissions-Policy header provides a mechanism to allow and deny the use of browser features in a document or within any <iframe> elements in the document.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'X-Frame-Options', 'sfxtheme' ); ?></th>
                                    <td>
                                        <select name="sfx_x_frame_options" id="sfx_x_frame_options">
                                            <option value="DENY" <?php selected( 'DENY', get_option( 'sfx_x_frame_options', 'SAMEORIGIN' ) ); ?>>DENY</option>
                                            <option value="SAMEORIGIN" <?php selected( 'SAMEORIGIN', get_option( 'sfx_x_frame_options', 'SAMEORIGIN' ) ); ?>>SAMEORIGIN</option>
                                            <option value="ALLOW-FROM" <?php selected( 'ALLOW-FROM', get_option( 'sfx_x_frame_options', 'SAMEORIGIN' ) ); ?>>ALLOW-FROM</option>
                                        </select>
                                        <div id="sfx_x_frame_options_url_field" style="display: <?php echo ( get_option( 'sfx_x_frame_options' ) === 'ALLOW-FROM' ) ? 'block' : 'none'; ?>;">
                                            <input type="text" name="sfx_x_frame_options_allow_from_url" value="<?php echo esc_attr( get_option( 'sfx_x_frame_options_allow_from_url', '' ) ); ?>" placeholder="https://example.com" />
                                        </div>
                                        <div class="sfx-description"><?php esc_html_e( 'The X-Frame-Options HTTP response header can be used to indicate whether or not a browser should be allowed to render a page in a <frame>, <iframe>, <embed> or <object>. Sites can use this to avoid click-jacking attacks, by ensuring that their content is not embedded into other sites.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row">&nbsp;</th>
                                    <td><hr class="sfx-hr" /></td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Disable HSTS Header', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_disable_hsts_header" value="1" <?php checked( 1, (int) get_option( 'sfx_disable_hsts_header', 0 ) ); ?> />
                                        <div class="sfx-description"><?php esc_html_e( 'Disable the Strict-Transport-Security header if you need to resolve conflicts or for debugging.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Disable CSP Header', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_disable_csp_header" value="1" <?php checked( 1, (int) get_option( 'sfx_disable_csp_header', 0 ) ); ?> />
                                        <div class="sfx-description"><?php esc_html_e( 'Disable the Content-Security-Policy header if you need to resolve conflicts or for debugging.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Disable X-Content-Type-Options Header', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_disable_x_content_type_options_header" value="1" <?php checked( 1, (int) get_option( 'sfx_disable_x_content_type_options_header', 0 ) ); ?> />
                                        <div class="sfx-description"><?php esc_html_e( 'Disable the X-Content-Type-Options header if you need to resolve conflicts or for debugging.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                                <tr valign="top">
                                    <th scope="row"><?php esc_html_e( 'Disable X-Frame-Options Header', 'sfxtheme' ); ?></th>
                                    <td>
                                        <input type="checkbox" name="sfx_disable_x_frame_options_header" value="1" <?php checked( 1, (int) get_option( 'sfx_disable_x_frame_options_header', 0 ) ); ?> />
                                        <div class="sfx-description"><?php esc_html_e( 'Disable the X-Frame-Options header if you need to resolve conflicts or for debugging.', 'sfxtheme' ); ?></div>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                    </div>
                </div>
                <div class="sfx-col" style="width: 50%; min-height: 100vh;">
                    <div class="sfx-card">
                        <h2 class="sfx-section-title"><?php esc_html_e('Security Header Tips', 'sfxtheme'); ?></h2>
                        <ul class="sfx-tips-list">
                            <li><?php esc_html_e('Always test new header settings on a staging site before deploying to production.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Use the browser console and security tools to verify headers are set as expected.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('If you experience issues with embedded content or third-party services, review your CSP and X-Frame-Options settings.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('For maximum security, enable HSTS with preload and includeSubDomains, but only if all subdomains use HTTPS.', 'sfxtheme'); ?></li>
                            <li><?php esc_html_e('Refer to Mozilla Observatory and securityheaders.com for header analysis.', 'sfxtheme'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                document.addEventListener('DOMContentLoaded', function() {
                    var selectElement = document.getElementById('sfx_x_frame_options');
                    var urlField = document.getElementById('sfx_x_frame_options_url_field');
                    function toggleUrlField() {
                        if (selectElement && urlField) {
                            urlField.style.display = selectElement.value === 'ALLOW-FROM' ? 'block' : 'none';
                        }
                    }
                    toggleUrlField();
                    if (selectElement) {
                        selectElement.addEventListener('change', toggleUrlField);
                    }
                });
            </script>
        </div>
        <?php
    }
}

