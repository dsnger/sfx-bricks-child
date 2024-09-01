<?php

namespace SFX\Options;

class ACFOptionsSocialMedia
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
        'page_title'   => __('Social Media', 'sfx'),
        'menu_title'   => __('Social Media Profile', 'sfx'),
        'parent_slug'  => ACFOptionsContact::$menu_slug,
        'autoload'     => false
      ));

		}
	}

	public function register_fields()
	{
		// Your ACF field registration code here
		if (function_exists('acf_add_local_field_group')) {
			acf_add_local_field_group(array(
				'key' => 'group_5bf33f3261789',
				'title' => 'Social Media Profile',
				'fields' => array(
					array(
						'key' => 'field_5bf33f408b76e',
						'label' => 'Profile',
						'name' => 'profiles',
						'aria-label' => '',
						'type' => 'repeater',
						'instructions' => '',
						'required' => 0,
						'conditional_logic' => 0,
						'wrapper' => array(
							'width' => '',
							'class' => '',
							'id' => '',
						),
						'acfe_permissions' => '',
						'acfe_repeater_stylised_button' => 0,
						'collapsed' => '',
						'min' => 0,
						'max' => 0,
						'layout' => 'table',
						'button_label' => 'Profil hinzufügen',
						'rows_per_page' => 20,
						'sub_fields' => array(
							array(
								'key' => 'field_646cb1646dfaa',
								'label' => 'Icon-Bild',
								'name' => 'img',
								'aria-label' => '',
								'type' => 'image',
								'instructions' => 'Am besten eine SVG-Datei in der gewünschten Größe.',
								'required' => 0,
								'conditional_logic' => 0,
								'wrapper' => array(
									'width' => '',
									'class' => '',
									'id' => '',
								),
								'uploader' => '',
								'return_format' => 'id',
								'upload_folder' => '',
								'acfe_thumbnail' => 0,
								'min_width' => '',
								'min_height' => '',
								'min_size' => '',
								'max_width' => '',
								'max_height' => '',
								'max_size' => '',
								'mime_types' => 'svg,png,webp',
								'preview_size' => 'thumbnail',
								'library' => 'all',
								'parent_repeater' => 'field_5bf33f408b76e',
							),
							array(
								'key' => 'field_5bf33f988b771',
								'label' => 'Link',
								'name' => 'link',
								'aria-label' => '',
								'type' => 'link',
								'instructions' => '',
								'required' => 0,
								'conditional_logic' => 0,
								'wrapper' => array(
									'width' => '',
									'class' => '',
									'id' => '',
								),
								'return_format' => 'array',
								'acfe_permissions' => '',
								'parent_repeater' => 'field_5bf33f408b76e',
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param' => 'options_page',
							'operator' => '==',
							'value' => 'acf-options-social-media-profile',
						),
					),
				),
				'menu_order' => 18,
				'position' => 'normal',
				'style' => 'default',
				'label_placement' => 'top',
				'instruction_placement' => 'label',
				'hide_on_screen' => array(
					0 => 'author',
					1 => 'featured_image',
					2 => 'discussion',
					3 => 'format',
					4 => 'the_content',
					5 => 'categories',
					6 => 'comments',
					7 => 'permalink',
					8 => 'revisions',
					9 => 'tags',
					10 => 'page_attributes',
					11 => 'excerpt',
					12 => 'slug',
					13 => 'send-trackbacks',
				),
				'active' => true,
				'description' => '',
				'show_in_rest' => 0,
				'acfe_autosync' => array(
					0 => 'php',
					1 => 'json',
				),
				'acfe_form' => 0,
				'acfe_display_title' => '',
				'acfe_permissions' => '',
				'acfe_meta' => '',
				'acfe_note' => '',
				'modified' => 1700509586,
				'acfe_categories' => array(
					'options' => 'Options',
				),
			));
		}
	}
}
