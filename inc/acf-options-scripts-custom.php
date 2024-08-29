<?php

if (function_exists('acf_add_local_field_group')):

    acf_add_local_field_group(array(
        'key' => 'group_script_configuration',
        'title' => __('Custom Scripts', 'sfx'),
        'fields' => array(
            array(
                'key' => 'field_custom_scripts',
                'label' => __('Add Custom Scripts', 'sfx'),
                'name' => 'custom_scripts',
                'type' => 'repeater',
                'layout' => 'row',
                'pagination' => 1,
                'rows_per_page' => 8,
                'min' => 0,
                'max' => 0,
                'collapsed' => 'field_script_name',
                'button_label' => 'Script hinzufügen',
                'sub_fields' => array(
                    array(
                        'key' => 'field_script_name',
                        'label' => 'Script Name',
                        'name' => 'script_name',
                        'type' => 'text',
                        'required' => 1,
                    ),
                    array(
                        'key' => 'field_script_type',
                        'label' => __('Script Type', 'sfx'),
                        'name' => 'script_type',
                        'type' => 'select',
                        'choices' => array(
                            'javascript' => 'JavaScript',
                            'CSS' => 'CSS',
                            // Add other script types as needed
                        ),
                        'default_value' => 'javascript',
                    ),
                    array(
                        'key' => 'field_location',
                        'label' => __('Location', 'sfx'),
                        'name' => 'location',
                        'type' => 'select',
                        'choices' => array(
                            'footer' => 'Footer',
                            'header' => 'Header',
                            // Add other locations as needed
                        ),
                    ),
                    array(
                        'key' => 'field_include_type',
                        'label' => __('Include Type', 'sfx'),
                        'name' => 'include_type',
                        'type' => 'select',
                        'choices' => array(
                            'register' => 'Register',
                            'enqueue' => 'Enqueue',
                            // Add other include types as needed
                        ),
                    ),
                    array(
                        'key' => 'field_frontend_only',
                        'label' => __('Frontend Only', 'sfx'),
                        'name' => 'frontend_only',
                        'type' => 'true_false',
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_script_source_type',
                        'label' => 'Script Source Type',
                        'name' => 'script_source_type',
                        'type' => 'radio',
                        'choices' => array(
                            'file' => 'Upload File',
                            'cdn' => 'CDN Link',
                        ),
                        'layout' => 'horizontal',
                    ),
                    array(
                        'key' => 'field_script_file',
                        'label' => 'Upload Script File',
                        'name' => 'script_file',
                        'type' => 'file',
                        'return_format' => 'url',
                        'library' => 'all',
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_script_source_type',
                                    'operator' => '==',
                                    'value' => 'file',
                                ),
                            ),
                        ),
                    ),
                    array(
                        'key' => 'field_script_cdn',
                        'label' => 'CDN Link',
                        'name' => 'script_cdn',
                        'type' => 'url',
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_script_source_type',
                                    'operator' => '==',
                                    'value' => 'cdn',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'options_page',
                    'operator' => '==',
                    'value' => 'acf-options-custom-scripts',
                ),
            ),
        ),
        'style' => 'seamless',
        'label_placement' => 'top',
        'instruction_placement' => 'label',

    ));

endif;
