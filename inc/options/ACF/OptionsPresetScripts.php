<?php

namespace SFX\Options\ACF;

class OptionsPresetScripts
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
                'page_title'    => __('Preset Scripts', 'sfxtheme'),
                'menu_title'    => __('Preset Scripts', 'sfxtheme'),
                'parent_slug'   => \SFX\Options\AdminOptionPages::$menu_slug,
                'post_content'  => __('
                <p>It´s is recommanded to disable default Bricks JS, when not using the default elements.</p>
              ', 'sfxtheme'),
            ));
        }
    }

    public function register_fields()
    {
        // Your ACF field registration code here
        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group(array(
                'key' => 'group_64a1b2c3d4e5f',
                'title' => __('Scripts Presets', 'sfxtheme'),
                'fields' => array(
                    array(
                        'key' => 'field_iconify',
                        'label' => __('Enable Iconify', 'sfxtheme'),
                        'name' => 'enable_iconify',
                        'type' => 'true_false',
                        'instructions' => __('Enabling this setting will enqueue the Iconify script on your website.', 'sfxtheme'),
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '',
                        'default_value' => 0,
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_iconify_information',
                        'label' => __('Iconify Information', 'sfxtheme'),
                        'name' => 'iconify_information',
                        'type' => 'message',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_iconify',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '<p>Iconify is a unified SVG framework that allows you to use icons from multiple icon sets with a simple syntax.</p>
                <p><strong>Basic Usage:</strong></p>
                <pre>&lt;span class="iconify" data-icon="mdi-light:home"&gt;&lt;/span&gt;</pre>
                <p><strong>Styling Icons:</strong></p>
                <pre>&lt;span class="iconify" data-icon="mdi-light:home" style="color: red; font-size: 24px;"&gt;&lt;/span&gt;</pre>
                <p><strong>Available Attributes:</strong></p>
                <ul>
                    <li>data-icon: Icon name (required)</li>
                    <li>data-inline: Boolean, makes icon behave like a glyph (optional)</li>
                    <li>data-width and data-height: Dimensions of the icon (optional)</li>
                    <li>data-flip: "horizontal", "vertical" or "horizontal,vertical" (optional)</li>
                    <li>data-rotate: Rotation in degrees (optional)</li>
                </ul>
                <p>For more information and to browse available icons, visit the <a href="https://iconify.design/" target="_blank">Iconify website</a>.</p>',
                        'new_lines' => 'wpautop',
                        'esc_html' => 0,
                    ),

                    array(
                        'key' => 'field_64a1b2c3d4e60',
                        'label' => 'Enable Alpine JS',
                        'name' => 'enable_alpine',
                        'type' => 'true_false',
                        'instructions' => 'Enabling this setting will enqueue Alpine JS on your website.',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '',
                        'default_value' => 0,
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e61',
                        'label' => 'Alpine JS Options',
                        'name' => 'alpine_options',
                        'type' => 'group',
                        'instructions' => 'Configure Alpine JS options and features.',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e60',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'layout' => 'row',
                        'sub_fields' => array(
                            array(
                                'key' => 'field_64a1b2c3d4e62',
                                'label' => 'Core',
                                'name' => 'core',
                                'type' => 'true_false',
                                'instructions' => 'Core Alpine JS functionality (always enabled).',
                                'required' => 0,
                                'default_value' => 1,
                                'ui' => 1,
                                'ui_on_text' => 'Enabled',
                                'ui_off_text' => 'Disabled',
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e63',
                                'label' => 'Ajax',
                                'name' => 'ajax',
                                'type' => 'true_false',
                                'instructions' => 'Simplifies AJAX requests.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e64',
                                'label' => 'Anchor',
                                'name' => 'anchor',
                                'type' => 'true_false',
                                'instructions' => 'Manipulates URL hash without page reload.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e65',
                                'label' => 'Collapse',
                                'name' => 'collapse',
                                'type' => 'true_false',
                                'instructions' => 'Handles collapsible elements.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e66',
                                'label' => 'Focus',
                                'name' => 'focus',
                                'type' => 'true_false',
                                'instructions' => 'Manages focus in components.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e67',
                                'label' => 'Intersect',
                                'name' => 'intersect',
                                'type' => 'true_false',
                                'instructions' => 'Detects element visibility in viewport.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e68',
                                'label' => 'Mask',
                                'name' => 'mask',
                                'type' => 'true_false',
                                'instructions' => 'Adds input masking functionality.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e69',
                                'label' => 'Morph',
                                'name' => 'morph',
                                'type' => 'true_false',
                                'instructions' => 'Morphs elements smoothly.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e70',
                                'label' => 'Persist',
                                'name' => 'persist',
                                'type' => 'true_false',
                                'instructions' => 'Persists component state.',
                                'ui' => 1,
                                'wrapper' => array('width' => '25'),
                            ),
                        ),
                    ),
                    array(
                        'key' => 'field_66ce0426c60af',
                        'label' => 'Alpine JS Information',
                        'name' => 'alpine_information',
                        'type' => 'message',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e60',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '<p>Alpine JS is a lightweight JavaScript framework for adding interactive behavior to your markup. Here\'s an overview of the available features:</p>
    
                <h4>Core Features:</h4>
                <ul>
                    <li><strong>x-data</strong>: Declares a new Alpine component and its data.</li>
                    <li><strong>x-bind</strong> or <strong>:</strong>: Dynamically sets HTML attributes.</li>
                    <li><strong>x-on</strong> or <strong>@</strong>: Attaches event listeners to elements.</li>
                    <li><strong>x-show</strong>: Toggles the visibility of an element.</li>
                    <li><strong>x-for</strong>: Iterates over arrays or objects.</li>
                    <li><strong>x-text</strong>: Sets the text content of an element.</li>
                    <li><strong>x-effect</strong>: Runs a side effect whenever its dependencies change.</li>
                </ul>
    
                <h4>Additional Plugins:</h4>
                <ul>
                    <li><strong>Ajax</strong>: Simplifies making AJAX requests directly from Alpine components.</li>
                    <li><strong>Anchor</strong>: Allows manipulation of the URL hash without triggering a page reload.</li>
                    <li><strong>Collapse</strong>: Provides utilities for creating collapsible elements.</li>
                    <li><strong>Focus</strong>: Offers utilities for managing focus within your components.</li>
                    <li><strong>Intersect</strong>: Uses Intersection Observer to react when an element enters or leaves the viewport.</li>
                    <li><strong>Mask</strong>: Allows you to automatically format input fields as the user types.</li>
                    <li><strong>Morph</strong>: Enables smooth morphing between elements, useful for dynamic content updates.</li>
                    <li><strong>Persist</strong>: Allows persisting the state of Alpine components, even after page reloads.</li>
                </ul>
    
                <h4>Example using multiple features:</h4>
                <pre>&lt;div x-data="{ open: false, name: \'\' }" x-persist="form-state"&gt;
        &lt;button @click="open = !open"&gt;Toggle Form&lt;/button&gt;
        &lt;form x-show="open" x-transition:enter="transition ease-out duration-300"
              x-transition:enter-start="opacity-0 scale-90"
              x-transition:enter-end="opacity-100 scale-100"&gt;
            &lt;input type="text" x-model="name" x-mask="AA-99" placeholder="Enter code (e.g. AB-12)"&gt;
            &lt;div x-text="\'Hello, \' + name" x-show="name.length"&gt;&lt;/div&gt;
        &lt;/form&gt;
    &lt;/div&gt;</pre>
    
                <p>This example demonstrates the use of x-persist, x-show with transitions, x-mask for input formatting, and x-model for two-way data binding.</p>
    
                <p>For more detailed information and advanced usage, visit the <a href="https://alpinejs.dev/" target="_blank">Alpine JS official documentation</a>.</p>',
                        'new_lines' => 'wpautop',
                        'esc_html' => 0,
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e71',
                        'label' => 'GSAP',
                        'name' => 'enable_gsap',
                        'type' => 'true_false',
                        'instructions' => 'GSAP is a powerful JavaScript animation library that allows you to create complex and interactive animations.',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '',
                        'default_value' => 0,
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e72',
                        'label' => 'GSAP Information',
                        'name' => 'gsap_information',
                        'type' => 'message',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e71',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '
            <p><strong>Scripts Included:</strong></p>
            <ul>
                <li><code>gsap.min.js</code>: The core GSAP library.</li>
                <li><code>ScrollTrigger.min.js</code>: A GSAP plugin that enables scroll-based animations.</li>
                <li><code>gsap-data-animate.js</code>: A custom script that utilizes GSAP and ScrollTrigger for animating elements based on data attributes.</li>
            </ul>
            <p><strong>Supported Features:</strong></p>
            <pre>
x: Horizontal position (e.g., x: 100).
y: Vertical position (e.g., y: -50).
o: Opacity (e.g., o: 0.5).
r: Rotation angle (e.g., r: 45).
s: Scale (e.g., s: 0.8).
start: Scroll trigger start position (e.g., start: top 20%).
end: Scroll trigger end position (e.g., end: bottom 80%).
scrub: Scrubbing behavior (e.g., scrub: true).
pin: Pin element during scroll (e.g., pin: true).
markers: Display scroll trigger markers (e.g., markers: true).
toggleClass: Toggle CSS class (e.g., toggleClass: active).
pinSpacing: Spacing behavior when pinning (e.g., pinSpacing: margin).
splittext: Split text into characters (e.g., splittext: true).
stagger: Stagger delay between characters (e.g., stagger: 0.05).
            </pre>
            <p><strong>Example 1:</strong> This example will animate the element by fading it in from the left. The element will start with an x-offset of -50 pixels and an opacity of 0.</p>
            <pre>&lt;h1 data-animate="x:-50, o:0, start:top 80%, end:bottom 20%"&gt;Welcome to my website!&lt;/h1&gt;</pre>
            <p><strong>Example 2:</strong> In this example, the div element will scale up from 0.5 to 1 and rotate by 180 degrees. The animation will start when the element is 60% from the top of the viewport and end when it reaches 40% from the bottom.</p>
            <pre>&lt;div data-animate="s:0.5, r:180, start:top 60%, end:bottom 40%, scrub:true"&gt;Lorem ipsum dolor sit amet.&lt;/div&gt;</pre>',
                        'new_lines' => 'wpautop',
                        'esc_html' => 0,
                    ),

                    array(
                        'key' => 'field_64a1b2c3d4e75',
                        'label' => 'Enable Locomotive Scroll',
                        'name' => 'enable_locomotive_scroll',
                        'type' => 'true_false',
                        'instructions' => 'Enabling this setting will enqueue the Locomotive Scroll library on your website.',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '',
                        'default_value' => 0,
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e76',
                        'label' => 'Locomotive Scroll Information',
                        'name' => 'locomotive_scroll_information',
                        'type' => 'message',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e75',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '<p>Locomotive Scroll is a modern, powerful library for creating smooth scrolling experiences and parallax effects.</p>
        <p><strong>Basic Setup:</strong></p>
        <pre>
&lt;div data-scroll-container&gt;
&lt;section data-scroll-section&gt;
    &lt;h1 data-scroll&gt;Hello World!&lt;/h1&gt;
&lt;/section&gt;
&lt;/div&gt;

&lt;script&gt;
const scroll = new LocomotiveScroll({
el: document.querySelector(\'[data-scroll-container]\'),
smooth: true
});
&lt;/script&gt;</pre>
        <p><strong>Common Data Attributes:</strong></p>
        <ul>
            <li><code>data-scroll</code>: Basic scroll detection</li>
            <li><code>data-scroll-speed</code>: Parallax speed (e.g., "2" for 2x speed)</li>
            <li><code>data-scroll-direction</code>: Scroll direction ("horizontal" or "vertical")</li>
            <li><code>data-scroll-delay</code>: Delay the animation (in seconds)</li>
            <li><code>data-scroll-repeat</code>: Repeat the animation every time</li>
            <li><code>data-scroll-call</code>: Trigger a function on scroll</li>
            <li><code>data-scroll-position</code>: Element position ("top", "bottom", "left", or "right")</li>
            <li><code>data-scroll-sticky</code>: Make an element sticky</li>
        </ul>
        <p><strong>Parallax Example:</strong></p>
        <pre>&lt;div data-scroll data-scroll-speed="2"&gt;I will move at 2x speed!&lt;/div&gt;</pre>
        <p><strong>Sticky Element Example:</strong></p>
        <pre>&lt;div data-scroll data-scroll-sticky data-scroll-target="#target"&gt;I am sticky&lt;/div&gt;</pre>
        <p>For more information and advanced usage, visit the <a href="https://github.com/locomotivemtl/locomotive-scroll" target="_blank">Locomotive Scroll GitHub page</a>.</p>',
                        'new_lines' => 'wpautop',
                        'esc_html' => 0,
                    ),

                    array(
                        'key' => 'field_64a1b2c3d4e73',
                        'label' => 'Enable AOS (Animate On Scroll)',
                        'name' => 'enable_aos',
                        'type' => 'true_false',
                        'instructions' => 'Enabling this setting will enqueue the AOS library on your website.',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '',
                        'default_value' => 0,
                        'ui' => 1,
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e74',
                        'label' => 'AOS Global Settings',
                        'name' => 'aos_settings',
                        'type' => 'group',
                        'instructions' => 'Configure global settings for AOS animations.',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e73',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'layout' => 'row',
                        'sub_fields' => array(
                            array(
                                'key' => 'field_64a1b2c3d4e75',
                                'label' => 'Default Animation',
                                'name' => 'default_animation',
                                'type' => 'select',
                                'instructions' => 'Choose the default animation for all AOS elements.',
                                'choices' => array(
                                    'fade' => 'Fade',
                                    'fade-up' => 'Fade Up',
                                    'fade-down' => 'Fade Down',
                                    'fade-left' => 'Fade Left',
                                    'fade-right' => 'Fade Right',
                                    'flip-up' => 'Flip Up',
                                    'flip-down' => 'Flip Down',
                                    'flip-left' => 'Flip Left',
                                    'flip-right' => 'Flip Right',
                                    'slide-up' => 'Slide Up',
                                    'slide-down' => 'Slide Down',
                                    'slide-left' => 'Slide Left',
                                    'slide-right' => 'Slide Right',
                                    'zoom-in' => 'Zoom In',
                                    'zoom-out' => 'Zoom Out',
                                ),
                                'default_value' => 'fade-up',
                                'allow_null' => 0,
                                'multiple' => 0,
                                'ui' => 1,
                                'ajax' => 0,
                                'return_format' => 'value',
                                'placeholder' => '',
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e76',
                                'label' => 'Default Duration',
                                'name' => 'default_duration',
                                'type' => 'number',
                                'instructions' => 'Set the default duration for animations (in milliseconds).',
                                'required' => 0,
                                'conditional_logic' => 0,
                                'wrapper' => array(
                                    'width' => '',
                                    'class' => '',
                                    'id' => '',
                                ),
                                'default_value' => 400,
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => 'ms',
                                'min' => 0,
                                'max' => 3000,
                                'step' => 50,
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e77',
                                'label' => 'Default Easing',
                                'name' => 'default_easing',
                                'type' => 'select',
                                'instructions' => 'Choose the default easing function for animations.',
                                'choices' => array(
                                    'linear' => 'Linear',
                                    'ease' => 'Ease',
                                    'ease-in' => 'Ease In',
                                    'ease-out' => 'Ease Out',
                                    'ease-in-out' => 'Ease In Out',
                                    'ease-in-back' => 'Ease In Back',
                                    'ease-out-back' => 'Ease Out Back',
                                    'ease-in-out-back' => 'Ease In Out Back',
                                ),
                                'default_value' => 'ease',
                                'allow_null' => 0,
                                'multiple' => 0,
                                'ui' => 1,
                                'ajax' => 0,
                                'return_format' => 'value',
                                'placeholder' => '',
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e78',
                                'label' => 'Default Offset',
                                'name' => 'default_offset',
                                'type' => 'number',
                                'instructions' => 'Set the default offset (in pixels) to trigger animations.',
                                'required' => 0,
                                'conditional_logic' => 0,
                                'wrapper' => array(
                                    'width' => '',
                                    'class' => '',
                                    'id' => '',
                                ),
                                'default_value' => 120,
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => 'px',
                                'min' => 0,
                                'max' => 1000,
                                'step' => 10,
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e79',
                                'label' => 'Default Delay',
                                'name' => 'default_delay',
                                'type' => 'number',
                                'instructions' => 'Set the default delay for animations (in milliseconds).',
                                'required' => 0,
                                'conditional_logic' => 0,
                                'wrapper' => array(
                                    'width' => '',
                                    'class' => '',
                                    'id' => '',
                                ),
                                'default_value' => 0,
                                'placeholder' => '',
                                'prepend' => '',
                                'append' => 'ms',
                                'min' => 0,
                                'max' => 3000,
                                'step' => 50,
                            ),
                            array(
                                'key' => 'field_64a1b2c3d4e80',
                                'label' => 'Once',
                                'name' => 'once',
                                'type' => 'true_false',
                                'instructions' => 'Whether animation should happen only once - while scrolling down.',
                                'required' => 0,
                                'conditional_logic' => 0,
                                'wrapper' => array(
                                    'width' => '',
                                    'class' => '',
                                    'id' => '',
                                ),
                                'message' => '',
                                'default_value' => 1,
                                'ui' => 1,
                                'ui_on_text' => 'Yes',
                                'ui_off_text' => 'No',
                            ),
                        ),
                    ),
                    array(
                        'key' => 'field_64a1b2c3d4e81',
                        'label' => 'AOS Information',
                        'name' => 'aos_information',
                        'type' => 'message',
                        'instructions' => '',
                        'required' => 0,
                        'conditional_logic' => array(
                            array(
                                array(
                                    'field' => 'field_64a1b2c3d4e73',
                                    'operator' => '==',
                                    'value' => '1',
                                ),
                            ),
                        ),
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'message' => '<p>AOS (Animate On Scroll) is a library that allows you to animate elements as you scroll down, and up.</p>
            <p><strong>Basic Usage:</strong></p>
            <pre>&lt;div data-aos="fade-up"&gt;Content&lt;/div&gt;</pre>
            <p><strong>Additional Attributes:</strong></p>
            <ul>
                <li>data-aos-duration="3000" (in ms)</li>
                <li>data-aos-delay="300" (in ms)</li>
                <li>data-aos-easing="ease-in-sine"</li>
                <li>data-aos-anchor=".selector"</li>
                <li>data-aos-once="false"</li>
                <li>data-aos-offset="200" (in px)</li>
            </ul>
            <p>You can override global settings on a per-element basis using these data attributes.</p>
            <p>For more information and advanced usage, visit the <a href="https://github.com/michalsnik/aos" target="_blank">AOS GitHub page</a>.</p>',
                        'new_lines' => 'wpautop',
                        'esc_html' => 0,
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param' => 'options_page',
                            'operator' => '==',
                            'value' => 'acf-options-preset-scripts',
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
            ));
        }
    }
}
