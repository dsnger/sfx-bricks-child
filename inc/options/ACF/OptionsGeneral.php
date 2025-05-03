<?php

namespace SFX\Options\ACF;

class OptionsGeneral
{
	public function __construct()
	{
		add_action('acf/init', [$this, 'register_fields']);
	}

	public function register_fields()
	{
		// Your ACF field registration code here
		if (function_exists('acf_add_local_field_group')) {
			acf_add_local_field_group(array(
				'key' => 'group_66cdd1b538bcb',
				'title' => 'General Options',
				'fields' => array(
					array(
						'key' => 'field_66cdd1b58b67f',
						'label' => __('JQuery deaktivieren', 'sfxtheme'),
						'name' => 'disable_jquery',
						'aria-label' => '',
						'type' => 'true_false',
						'instructions' => __('Remove default JQuery for enhanced performance if not necessary for plugins.', 'sfxtheme'),
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'message' => '',
						'default_value' => 0,
						'ui_on_text' => '',
						'ui_off_text' => '',
						'ui' => 1,
					),
					array(
						'key' => 'field_66cdf27fd8e7e',
						'label' => __('Bricks JS deaktivieren', 'sfxtheme'),
						'name' => 'disable_bricks_js',
						'aria-label' => '',
						'type' => 'true_false',
						'instructions' => __('Remove the default Bricks JavaScript from the frontend for enhanced performance and custom JS solutions.', 'sfxtheme'),
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'message' => '',
						'default_value' => 0,
						'ui_on_text' => '',
						'ui_off_text' => '',
						'ui' => 1,
					),
					array(
						'key' => 'field_66cdf40363573',
						'label' => __('Bricks Styling deaktivieren', 'sfxtheme'),
						'name' => 'disable_bricks_css',
						'aria-label' => '',
						'type' => 'true_false',
						'instructions' => __('Remove all default Bricks styling.', 'sfxtheme'),
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'message' => '',
						'default_value' => 0,
						'ui_on_text' => '',
						'ui_off_text' => '',
						'ui' => 1,
					),
				),
				'location' => array(
					array(
						array(
							'param' => 'options_page',
							'operator' => '==',
							'value' => 'sfx-theme-settings',
						),
					),
				),
				'menu_order' => 0,
				'position' => 'normal',
				'style' => 'seamless',
				'label_placement' => 'left',
				'instruction_placement' => 'label',
				'hide_on_screen' => '',
				'active' => true,
				'description' => '',
				'show_in_rest' => 0,
				'acfe_display_title' => '',
				'acfe_autosync' => '',
				'acfe_form' => 0,
				'acfe_meta' => '',
				'acfe_note' => '',
			));
		}
	}
}
