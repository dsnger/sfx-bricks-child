<?php

namespace SFX\Options\ACF;

class OptionsLogo
{
	public function __construct()
	{
		add_action('acf/init', [$this, 'add_acf_options_pages']);
		add_action('acf/init', [$this, 'register_fields']);
	}

	public function add_acf_options_pages()
	{

		// Make sure ACF is active
		if (function_exists('acf_add_options_page')) {

			acf_add_options_sub_page(array(
				'page_title'    => __('Company Logo', 'sfxtheme'),
				'menu_title'    => __('Company Logo', 'sfxtheme'),
				'menu_slug'     => 'sfx-logo-settings',
				'parent_slug'   => \SFX\SFXBricksChildAdmin::$menu_slug,
			));
		}
	}


	public function register_fields()
	{
		// Your ACF field registration code here
		if (function_exists('acf_add_local_field_group')) {
			acf_add_local_field_group(array(
				'key' => 'group_5c61400ed5cfe',
				'title' => 'Options Logo-Einstellungen',
				'fields' => array(
					array(
						'key' => 'field_5c6533e23f6fc',
						'label' => 'Logo groß',
						'name' => 'logo',
						'aria-label' => '',
						'type' => 'image',
						'instructions' => 'Logo-Bild hochladen (Empfehlung: SVG).',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'acfe_save_meta' => 1,
						'uploader' => '',
						'return_format' => 'url',
						'upload_folder' => '',
						'acfe_thumbnail' => 0,
						'min_width' => '',
						'min_height' => '',
						'min_size' => '',
						'max_width' => '',
						'max_height' => '',
						'max_size' => '',
						'mime_types' => '',
						'preview_size' => 'thumbnail',
						'library' => 'all',
					),
					array(
						'key' => 'field_5d0b4b76012d4',
						'label' => 'Logo invertiert',
						'name' => 'logo_inverted',
						'aria-label' => '',
						'type' => 'image',
						'instructions' => 'Invertierte Logovariante',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'acfe_save_meta' => 1,
						'uploader' => '',
						'return_format' => 'url',
						'upload_folder' => '',
						'acfe_thumbnail' => 0,
						'min_width' => '',
						'min_height' => '',
						'min_size' => '',
						'max_width' => '',
						'max_height' => '',
						'max_size' => '',
						'mime_types' => '',
						'preview_size' => 'thumbnail',
						'library' => 'all',
					),
					array(
						'key' => 'field_5c65342a3f6fd',
						'label' => 'Logo klein',
						'name' => 'logo_tiny',
						'aria-label' => '',
						'type' => 'image',
						'instructions' => 'Kleine Logovariante (z. B. für "Sticky" Header)',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'acfe_save_meta' => 1,
						'uploader' => '',
						'return_format' => 'url',
						'upload_folder' => '',
						'acfe_thumbnail' => 0,
						'min_width' => '',
						'min_height' => '',
						'min_size' => '',
						'max_width' => '',
						'max_height' => '',
						'max_size' => '',
						'mime_types' => '',
						'preview_size' => 'thumbnail',
						'library' => 'all',
					),
					array(
						'key' => 'field_5d0b4c1174f16',
						'label' => 'Logo klein invertiert',
						'name' => 'logo_inverted_tiny',
						'aria-label' => '',
						'type' => 'image',
						'instructions' => 'Kleine invertierte Logovariante.',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'acfe_save_meta' => 1,
						'uploader' => '',
						'return_format' => 'url',
						'upload_folder' => '',
						'acfe_thumbnail' => 0,
						'min_width' => '',
						'min_height' => '',
						'min_size' => '',
						'max_width' => '',
						'max_height' => '',
						'max_size' => '',
						'mime_types' => '',
						'preview_size' => 'thumbnail',
						'library' => 'all',
					),
				),
				'location' => array(
					array(
						array(
							'param' => 'options_page',
							'operator' => '==',
							'value' => 'sfx-logo-settings',
						),
					),
				),
				'menu_order' => 13,
				'position' => 'normal',
				'style' => 'seamless',
				'label_placement' => 'top',
				'instruction_placement' => 'field',
				'hide_on_screen' => '',
				'active' => true,
				'description' => '',
				'show_in_rest' => 0,
				'acfe_autosync' => array(
					0 => 'php',
					1 => 'json',
				),
				'acfe_form' => 0,
				'acfe_display_title' => 'Logo einrichten',
				'acfe_permissions' => '',
				'acfe_meta' => '',
				'acfe_note' => '',
				'modified' => 1700509521,
				'acfe_categories' => array(
					'options' => 'Options',
				),
			));
		}
	}
}
