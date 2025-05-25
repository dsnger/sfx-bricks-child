<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

class AdminPage
{
  public static string $menu_slug = 'sfx-contact-infos';
  public static string $page_title = 'Contact Infos';
  public static string $description = 'Configure and manage the contact infos which can be used everywhere in pages or posts via shortcode.';

  
  public static function register(): void
  {
    add_action('admin_menu', [self::class, 'add_submenu_page']);
  }


  public static function init(): void
  {
    add_action('admin_menu', [self::class, 'add_admin_menu']);
  }

  public static function add_submenu_page(): void
  {
    add_submenu_page(
      \SFX\SFXBricksChildAdmin::$menu_slug,
      self::$page_title,
      self::$page_title,
      'manage_options',
      self::$menu_slug,
      [self::class, 'render_admin_page']
    );
  }

  /**
   * Render the admin page for contact settings.
   */
  public static function render_admin_page(): void
  {
    $fields = Settings::get_fields();
    $options = get_option(Settings::$OPTION_NAME, []);
    ?>
    <div class="wrap sfx-contact-admin-page">
      <h1><?php echo esc_html(self::$page_title); ?></h1>
      <p><?php echo esc_html(self::$description); ?></p>
      
      <div class="sfx-contact-admin-content">
        <form method="post" action="options.php">
          <?php
            settings_fields(Settings::$OPTION_GROUP);
            do_settings_sections(Settings::$OPTION_GROUP);
            submit_button();
          ?>
        </form>
      </div>

      <div class="sfx-contact-admin-sidebar">
        <div class="sfx-card">
          <h2><?php esc_html_e('Dynamic Tag Usage', 'sfxtheme'); ?></h2>
          <p><?php esc_html_e('Use these dynamic tags in Bricks Builder to display contact information:', 'sfxtheme'); ?></p>
          
          <div class="sfx-shortcode-tabs">
            <div class="sfx-shortcode-nav">
              <a href="#dt-basic" class="active"><?php esc_html_e('Basic Usage', 'sfxtheme'); ?></a>
              <a href="#dt-company"><?php esc_html_e('Company', 'sfxtheme'); ?></a>
              <a href="#dt-address"><?php esc_html_e('Address', 'sfxtheme'); ?></a>
              <a href="#dt-contact"><?php esc_html_e('Contact', 'sfxtheme'); ?></a>
              <a href="#dt-business"><?php esc_html_e('Business', 'sfxtheme'); ?></a>
              <a href="#dt-branch"><?php esc_html_e('Branches', 'sfxtheme'); ?></a>
            </div>
            
            <div class="sfx-shortcode-content">
              <div id="dt-basic" class="sfx-shortcode-panel active">
                <h3><?php esc_html_e('Basic Usage', 'sfxtheme'); ?></h3>
                <div class="sfx-shortcode-example">
                  <code class="shortcode-copy">{contact_info:company}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:company}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                <p class="description"><?php esc_html_e('The basic dynamic tag format uses {contact_info:field} to specify which information to display.', 'sfxtheme'); ?></p>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('With Icon', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:phone|icon=phone}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:phone|icon=phone}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom Text', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:email|text=Contact Us}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:email|text=Contact Us}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Without Link', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:email|link=false}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:email|link=false}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom CSS Class', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:phone|class=my-custom-class}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:phone|class=my-custom-class}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Multiple Attributes', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:phone|icon=phone|class=my-custom-class|link=false}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:phone|icon=phone|class=my-custom-class|link=false}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="dt-company" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Company Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Company Name', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:company}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:company}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Managing Director', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:director}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:director}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="dt-address" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Address Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Full Address', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:address}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:address}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Street', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:street}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:street}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('ZIP Code', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:zip}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:zip}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('City', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:city}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:city}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="dt-contact" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Contact Details', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Phone', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:phone}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:phone}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Email', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:email}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:email}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="dt-business" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Business Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('VAT ID', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:vat}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:vat}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Company Registration No.', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:hrb}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:hrb}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="dt-branch" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Branch Information', 'sfxtheme'); ?></h3>
                <p><?php esc_html_e('Use the location parameter to specify which branch to display (starting from 0):', 'sfxtheme'); ?></p>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Name', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:title:0}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:title:0}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Address', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:address:0}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:address:0}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Phone', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">{contact_info:phone:0}</code>
                  <button class="copy-button" data-clipboard-text="{contact_info:phone:0}"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <p><?php esc_html_e('You can change "0" to any branch index (0, 1, 2, etc.)', 'sfxtheme'); ?></p>
              </div>
            </div>
          </div>
        </div>
        <div class="sfx-card">
          <h2><?php esc_html_e('Shortcode Usage', 'sfxtheme'); ?></h2>
          <p><?php esc_html_e('Use these shortcodes to display contact information:', 'sfxtheme'); ?></p>
          
          <div class="sfx-shortcode-tabs">
            <div class="sfx-shortcode-nav">
              <a href="#sc-basic-usage" class="active"><?php esc_html_e('Basic Usage', 'sfxtheme'); ?></a>
              <a href="#sc-company-fields"><?php esc_html_e('Company', 'sfxtheme'); ?></a>
              <a href="#sc-address-fields"><?php esc_html_e('Address', 'sfxtheme'); ?></a>
              <a href="#sc-contact-fields"><?php esc_html_e('Contact', 'sfxtheme'); ?></a>
              <a href="#sc-business-fields"><?php esc_html_e('Business', 'sfxtheme'); ?></a>
              <a href="#sc-additional-fields"><?php esc_html_e('Additional', 'sfxtheme'); ?></a>
              <a href="#sc-branch-fields"><?php esc_html_e('Branches', 'sfxtheme'); ?></a>
              <a href="#sc-advanced-usage"><?php esc_html_e('Advanced', 'sfxtheme'); ?></a>
            </div>
            
            <div class="sfx-shortcode-content">
              <div id="sc-basic-usage" class="sfx-shortcode-panel active">
                <h3><?php esc_html_e('Basic Usage', 'sfxtheme'); ?></h3>
                <div class="sfx-shortcode-example">
                  <code class="shortcode-copy">[contact_info field="company"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;company&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                <p class="description"><?php esc_html_e('The basic shortcode format uses the field parameter to specify which information to display.', 'sfxtheme'); ?></p>

                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('With Icon', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" icon="phone"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; icon=&quot;phone&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom Text', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email" text="Contact Us"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot; text=&quot;Contact Us&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Without Link', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email" link="false"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot; link=&quot;false&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom CSS Class', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" class="my-custom-class"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; class=&quot;my-custom-class&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Multiple Attributes', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" icon="phone" class="my-custom-class" link="false"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; icon=&quot;phone&quot; class=&quot;my-custom-class&quot; link=&quot;false&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-company-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Company Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Company Name', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="company"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;company&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Managing Director', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="director"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;director&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-address-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Address Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Full Address', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="address"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;address&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Street', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="street"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;street&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('ZIP Code', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="zip"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;zip&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('City', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="city"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;city&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Country', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="country"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;country&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-contact-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Contact Details', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Phone', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Mobile', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="mobile"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;mobile&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Fax', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="fax"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;fax&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Email', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-business-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Business Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Tax ID', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="tax_id"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;tax_id&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('VAT ID', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="vat"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;vat&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Company Registration No.', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="hrb"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;hrb&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Registration Court', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="court"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;court&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Data Protection Officer', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="dsb"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;dsb&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-additional-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Additional Information', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Opening Hours', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="opening"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;opening&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Google Maps Link', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="maplink"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;maplink&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
              
              <div id="sc-branch-fields" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Branch Information', 'sfxtheme'); ?></h3>
                <p><?php esc_html_e('Use the location parameter to specify which branch to display (starting from 0):', 'sfxtheme'); ?></p>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Name', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="title" location="0"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;title&quot; location=&quot;0&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Address', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="address" location="0"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;address&quot; location=&quot;0&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Phone', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" location="0"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; location=&quot;0&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Branch Email', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email" location="0"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot; location=&quot;0&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <p><?php esc_html_e('You can change "0" to any branch index (0, 1, 2, etc.)', 'sfxtheme'); ?></p>
              </div>
              
              <div id="sc-advanced-usage" class="sfx-shortcode-panel">
                <h3><?php esc_html_e('Advanced Usage', 'sfxtheme'); ?></h3>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('With Icon', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" icon="phone"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; icon=&quot;phone&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom Text', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email" text="Contact Us"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot; text=&quot;Contact Us&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Without Link', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="email" link="false"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;email&quot; link=&quot;false&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
                
                <div class="sfx-shortcode-example">
                  <h4><?php esc_html_e('Custom CSS Class', 'sfxtheme'); ?></h4>
                  <code class="shortcode-copy">[contact_info field="phone" class="my-custom-class"]</code>
                  <button class="copy-button" data-clipboard-text="[contact_info field=&quot;phone&quot; class=&quot;my-custom-class&quot;]"><?php esc_html_e('Copy', 'sfxtheme'); ?></button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <style>
      .sfx-contact-admin-page {
        max-width: 100%;
      }
      .sfx-contact-admin-content {
        float: left;
        width: 70%;
        box-sizing: border-box;
        padding-right: 20px;
      }
      .sfx-contact-admin-sidebar {
        float: right;
        width: 28%;
        box-sizing: border-box;
      }
      .sfx-card {
        background: #fff;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        padding: 15px;
        margin-bottom: 20px;
      }
      .sfx-shortcode-example {
        margin-bottom: 15px;
        background: #f8f8f8;
        padding: 10px;
        border-radius: 4px;
        position: relative;
      }
      .sfx-shortcode-example h4 {
        margin: 0 0 5px 0;
        font-size: 14px;
        color: #23282d;
      }
      .sfx-shortcode-example code {
        display: block;
        background: #f1f1f1;
        padding: 8px;
        border-radius: 3px;
        margin: 0;
        overflow: auto;
        font-family: monospace;
      }
      .copy-button {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #f0f0f0;
        border: 1px solid #ddd;
        border-radius: 3px;
        padding: 2px 8px;
        font-size: 12px;
        cursor: pointer;
      }
      .copy-button:hover {
        background: #e0e0e0;
      }
      .sfx-shortcode-tabs {
        margin-top: 15px;
      }
      .sfx-shortcode-nav {
        display: flex;
        flex-wrap: wrap;
        border-bottom: 1px solid #ccc;
        margin-bottom: 15px;
      }
      .sfx-shortcode-nav a {
        padding: 8px 12px;
        margin-right: 5px;
        margin-bottom: -1px;
        text-decoration: none;
        color: #0073aa;
        border: 1px solid transparent;
      }
      .sfx-shortcode-nav a.active {
        border: 1px solid #ccc;
        border-bottom-color: #fff;
        background: #fff;
        color: #23282d;
      }
      .sfx-shortcode-panel {
        display: none;
      }
      .sfx-shortcode-panel.active {
        display: block;
      }
      .description {
        color: #666;
        font-style: italic;
        margin-top: 5px;
      }
    </style>

    <script>
      jQuery(document).ready(function($) {
        // Tab functionality for both panels
        $('.sfx-shortcode-tabs').each(function() {
          var $tabs = $(this);
          
          // Tab switching
          $tabs.find('.sfx-shortcode-nav a').on('click', function(e) {
            e.preventDefault();
            var $this = $(this);
            var target = $this.attr('href');
            
            // Update active state within this panel only
            $tabs.find('.sfx-shortcode-nav a').removeClass('active');
            $this.addClass('active');
            
            // Show target panel within this panel only
            $tabs.find('.sfx-shortcode-panel').removeClass('active');
            $tabs.find(target).addClass('active');
          });
        });
        
        // Copy functionality
        $('.copy-button').on('click', function() {
          var text = $(this).data('clipboard-text');
          var tempElement = $('<textarea>').val(text).appendTo('body').select();
          document.execCommand('copy');
          tempElement.remove();
          
          var originalText = $(this).text();
          $(this).text('Copied!');
          
          setTimeout(() => {
            $(this).text(originalText);
          }, 1500);
        });
      });
    </script>
    <?php
  }
}