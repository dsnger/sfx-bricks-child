<?php

declare(strict_types=1);

namespace SFX\CustomScriptsManager;

class Settings
{

  public static string $OPTION_GROUP;
  public static string $OPTION_NAME;

  public static function register(string $option_key): void
  {
    self::$OPTION_GROUP = $option_key . '_group';
    self::$OPTION_NAME = $option_key;
    add_action('admin_init', [self::class, 'register_settings']);
  }

  /**
   * Get all custom scripts fields for the settings page.
   */
  public static function get_fields(): array 
  {
    return [
      [
        'id'          => 'custom_scripts',
        'label'       => __('Custom Scripts', 'sfxtheme'),
        'description' => '',
        'type'        => 'repeater',
        'default'     => [],
        'fields'      => [
          [
            'id'      => 'script_name',
            'label'   => __('Script Name', 'sfxtheme'),
            'type'    => 'text',
            'default' => '',
            'required' => true,
            'css_class' => 'sfx-col-3',
          ],
          [
            'id'      => 'script_type',
            'label'   => __('Script Type', 'sfxtheme'),
            'type'    => 'select',
            'default' => 'javascript',
            'css_class' => 'sfx-col-3',
            'choices' => [
              'javascript' => 'JavaScript',
              'css' => 'CSS',
            ],
          ],
          [
            'id'      => 'location',
            'label'   => __('Location', 'sfxtheme'),
            'type'    => 'select',
            'default' => 'footer',
            'css_class' => 'sfx-col-3',
            'choices' => [
              'header' => 'Header',
              'footer' => 'Footer',
            ],
          ],
          [
            'id'      => 'include_type',
            'label'   => __('Include Type', 'sfxtheme'),
            'type'    => 'select',
            'default' => 'enqueue',
            'css_class' => 'sfx-col-3',
            'choices' => [
              'register' => 'Register',
              'enqueue' => 'Enqueue',
            ],
          ],
          [
            'id'      => 'frontend_only',
            'label'   => __('Frontend Only', 'sfxtheme'),
            'type'    => 'checkbox',
            'default' => true,
            'css_class' => 'sfx-col-3',
          ],
          [
            'id'      => 'script_source_type',
            'label'   => __('Script Source Type', 'sfxtheme'),
            'type'    => 'radio',
            'default' => 'file',
            'css_class' => 'sfx-col-3',
            'choices' => [
              'file' => 'Upload File',
              'cdn' => 'CDN Link',
              'cdn_file' => 'Upload from CDN',
            ],
          ],
          [
            'id'      => 'script_file',
            'label'   => __('Upload Script File', 'sfxtheme'),
            'type'    => 'file',
            'default' => '',
            'css_class' => 'sfx-col-4',
            'conditional_logic' => [
              'field' => 'script_source_type',
              'operator' => '==',
              'value' => 'file',
            ],
          ],
          [
            'id'      => 'script_cdn',
            'label'   => __('CDN Link', 'sfxtheme'),
            'type'    => 'url',
            'default' => '',
            'css_class' => 'sfx-col-4',
            'conditional_logic' => [
              'field' => 'script_source_type',
              'operator' => 'in',
              'value' => ['cdn', 'cdn_file'],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Register all settings for custom scripts.
   */
  public static function register_settings(): void 
  {
    register_setting(self::$OPTION_GROUP, self::$OPTION_NAME, [
      'type' => 'array',
      'sanitize_callback' => [self::class, 'sanitize_options'],
      'default' => [],
    ]);

    add_settings_section(
      self::$OPTION_NAME . '_section',
      '',
      [self::class, 'render_section'],
      self::$OPTION_GROUP
    );

    // Add custom scripts field
    $fields = self::get_fields();
    foreach ($fields as $field) {
      if ($field['id'] === 'custom_scripts') {
        add_settings_field(
          $field['id'],
          $field['label'],
          [self::class, 'render_scripts_field'],
          self::$OPTION_GROUP,
          self::$OPTION_NAME . '_section',
          $field
        );
      }
    }
  }

  /**
   * Render section description.
   */
  public static function render_section(): void 
  {
    echo '';
  }

  /**
   * Render the custom scripts repeater field.
   */
  public static function render_scripts_field(array $args): void 
  {
    $options = get_option(self::$OPTION_NAME, []);
    $id = esc_attr($args['id']);
    $scripts = isset($options[$id]) ? $options[$id] : $args['default'];
    $script_fields = $args['fields'];
    ?>
    <?php if (!empty($args['description'])) : ?>
      <p><?php echo esc_html($args['description']); ?></p>
    <?php endif; ?>
    <div class="sfx-scripts-container" data-field-id="<?php echo esc_attr($id); ?>" data-next-index="<?php echo count($scripts); ?>">
      <div class="sfx-scripts-list">
        <?php if (!empty($scripts) && is_array($scripts)) : ?>
          <?php foreach ($scripts as $index => $script) : ?>
            <div class="sfx-settings-card" data-index="<?php echo esc_attr($index); ?>">
              <div class="sfx-settings-card-header">
                <h3><?php echo !empty($script['script_name']) ? esc_html($script['script_name']) : __('New Script', 'sfxtheme'); ?></h3>
                <button type="button" class="button-link sfx-remove-script" title="<?php esc_attr_e('Remove Script', 'sfxtheme'); ?>">
                  <span class="dashicons dashicons-trash"></span>
                </button>
              </div>
              <div class="sfx-settings-card-body">
                <div class="sfx-settings-fields-grid">
                  <?php foreach ($script_fields as $field) : ?>
                    <?php
                    $field_id = $field['id'];
                    $field_name = self::$OPTION_NAME . '[' . $id . '][' . $index . '][' . $field_id . ']';
                    $field_value = isset($script[$field_id]) ? $script[$field_id] : $field['default'];
                    $is_conditional = !empty($field['conditional_logic']);
                    $conditional_class = $is_conditional ? 'sfx-conditional-field' : '';
                    $custom_css_class = !empty($field['css_class']) ? $field['css_class'] : '';
                    $conditional_attr = '';
                    if ($is_conditional) {
                      $conditional_value = $field['conditional_logic']['value'];
                      $conditional_attr = 'data-conditional="' . esc_attr(is_array($conditional_value) ? implode(',', $conditional_value) : $conditional_value) . '"';
                    }
                    ?>
                    <div class="sfx-settings-field <?php echo esc_attr($conditional_class); ?> <?php echo esc_attr($custom_css_class); ?>" <?php echo $conditional_attr; ?>>
                      <fieldset class="sfx-fieldset">
                        <legend class="sfx-fieldset-legend"><?php echo esc_html($field['label']); ?>
                        <?php if (!empty($field['required'])) : ?><span class="sfx-required">*</span><?php endif; ?></legend>
                        <?php
                        switch ($field['type']) {
                          case 'text':
                            ?>
                            <input type="text" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($field_value); ?>" class="regular-text" <?php echo !empty($field['required']) ? 'required' : ''; ?> />
                            <?php
                            break;
                          case 'url':
                            ?>
                            <input type="url" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_url($field_value); ?>" class="regular-text" />
                            <?php
                            break;
                          case 'select':
                            ?>
                            <select name="<?php echo esc_attr($field_name); ?>">
                              <?php foreach ($field['choices'] as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($field_value, $value); ?>><?php echo esc_html($label); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <?php
                            break;
                          case 'radio':
                            foreach ($field['choices'] as $value => $label) {
                              ?>
                              <label style="display: block; margin: 5px 0;">
                                <input type="radio" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($field_value, $value); ?> />
                                <span><?php echo esc_html($label); ?></span>
                              </label>
                              <?php
                            }
                            break;
                          case 'checkbox':
                            ?>
                            <input type="hidden" name="<?php echo esc_attr($field_name); ?>" value="0" />
                            <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="1" <?php checked($field_value, 1); ?> />
                            <?php
                            break;
                          case 'file':
                            ?>
                            <div class="sfx-file-input-group">
                              <input type="url" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_url($field_value); ?>" class="regular-text sfx-file-input" placeholder="<?php esc_attr_e('File URL', 'sfxtheme'); ?>" />
                              <button type="button" class="button sfx-upload-file"><?php esc_html_e('Upload File', 'sfxtheme'); ?></button>
                            </div>
                            <?php
                            break;
                        }
                        ?>
                        <?php if (!empty($field['description'])) : ?><p class="description"><?php echo esc_html($field['description']); ?></p><?php endif; ?>
                      </fieldset>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <template class="sfx-script-template">
        <div class="sfx-settings-card" data-index="{{index}}">
          <div class="sfx-settings-card-header">
            <h3><?php echo __('New Script', 'sfxtheme'); ?></h3>
            <button type="button" class="button-link sfx-remove-script" title="<?php esc_attr_e('Remove Script', 'sfxtheme'); ?>">
              <span class="dashicons dashicons-trash"></span>
            </button>
          </div>
          <div class="sfx-settings-card-body">
            <div class="sfx-settings-fields-grid">
              <?php foreach ($script_fields as $field) : ?>
                <?php
                $field_id = $field['id'];
                $is_conditional = !empty($field['conditional_logic']);
                $conditional_class = $is_conditional ? 'sfx-conditional-field' : '';
                $custom_css_class = !empty($field['css_class']) ? $field['css_class'] : '';
                $conditional_attr = '';
                if ($is_conditional) {
                  $conditional_value = $field['conditional_logic']['value'];
                  $conditional_attr = 'data-conditional="' . esc_attr(is_array($conditional_value) ? implode(',', $conditional_value) : $conditional_value) . '"';
                }
                ?>
                <div class="sfx-settings-field <?php echo esc_attr($conditional_class); ?> <?php echo esc_attr($custom_css_class); ?>" <?php echo $conditional_attr; ?>>
                  <label>
                    <?php echo esc_html($field['label']); ?>
                    <?php if (!empty($field['required'])) : ?><span class="sfx-required">*</span><?php endif; ?>
                    <?php
                    switch ($field['type']) {
                      case 'text':
                        ?>
                        <input type="text" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="" class="regular-text" <?php echo !empty($field['required']) ? 'required' : ''; ?> />
                        <?php
                        break;
                      case 'url':
                        ?>
                        <input type="url" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="" class="regular-text" />
                        <?php
                        break;
                      case 'select':
                        ?>
                        <select name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]">
                          <?php foreach ($field['choices'] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <?php
                        break;
                      case 'radio':
                        foreach ($field['choices'] as $value => $label) {
                          ?>
                          <label style="display: block; margin: 5px 0;">
                            <input type="radio" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="<?php echo esc_attr($value); ?>" />
                            <span><?php echo esc_html($label); ?></span>
                          </label>
                          <?php
                        }
                        break;
                      case 'checkbox':
                        ?>
                        <input type="hidden" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="0"/>
                        <input type="checkbox" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="1" class="sfx-toggle" />
                        <?php
                        break;
                      case 'file':
                        ?>
                        <div class="sfx-file-input-group">
                          <input type="url" name="<?php echo esc_attr(self::$OPTION_NAME); ?>[<?php echo esc_attr($id); ?>][{{index}}][<?php echo esc_attr($field_id); ?>]" value="" class="regular-text sfx-file-input" placeholder="<?php esc_attr_e('File URL', 'sfxtheme'); ?>" />
                          <button type="button" class="button sfx-upload-file"><?php esc_html_e('Upload File', 'sfxtheme'); ?></button>
                        </div>
                        <?php
                        break;
                    }
                    ?>
                    <?php if (!empty($field['description'])) : ?><p class="description"><?php echo esc_html($field['description']); ?></p><?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </template>
      
      <div class="sfx-add-item-container">
        <button type="button" class="button sfx-add-script"><?php esc_html_e('Add Script', 'sfxtheme'); ?></button>
      </div>
    </div>
    <?php
  }

  /**
   * Sanitize the options.
   */
  public static function sanitize_options($input): array 
  {
    $output = [];
    $fields = self::get_fields();
    
    foreach ($fields as $field) {
      $id = $field['id'];
      
      if ($field['type'] === 'repeater' && isset($input[$id]) && is_array($input[$id])) {
        $output[$id] = self::sanitize_scripts($input[$id], $field['fields']);
      }
    }
    
    return $output;
  }

  /**
   * Sanitize scripts array.
   */
  private static function sanitize_scripts(array $scripts, array $script_fields): array 
  {
    $sanitized_scripts = [];

    foreach ($scripts as $index => $script) {
      if (!is_array($script)) {
        continue;
      }

      $sanitized_script = [];
      foreach ($script_fields as $field) {
        $field_id = $field['id'];
        if (isset($script[$field_id])) {
          switch ($field['type']) {
            case 'url':
            case 'file':
              $sanitized_script[$field_id] = esc_url_raw($script[$field_id]);
              break;
            case 'checkbox':
              $sanitized_script[$field_id] = !empty($script[$field_id]) ? 1 : 0;
              break;
            case 'select':
            case 'radio':
              // Validate against allowed choices
              if (isset($field['choices']) && array_key_exists($script[$field_id], $field['choices'])) {
                $sanitized_script[$field_id] = $script[$field_id];
              } else {
                $sanitized_script[$field_id] = $field['default'];
              }
              break;
            default:
              $sanitized_script[$field_id] = sanitize_text_field($script[$field_id]);
          }
        } else {
          $sanitized_script[$field_id] = $field['default'];
        }
      }

      // Only add script if it has a name
      if (!empty($sanitized_script['script_name'])) {
        $sanitized_scripts[$index] = $sanitized_script;
      }
    }

    return $sanitized_scripts;
  }

  /**
   * Delete all options.
   */
  public static function delete_all_options(): void 
  {
    delete_option(self::$OPTION_NAME);
  }

}