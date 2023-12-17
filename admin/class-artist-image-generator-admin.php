<?php

use Orhanerday\OpenAi\OpenAi;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Artist_Image_Generator
 * @subpackage Artist_Image_Generator/admin
 * @author     Pierre Viéville <contact@pierrevieville.fr>
 */
class Artist_Image_Generator_Admin
{
    const QUERY_SETUP = 'setup';
    const QUERY_FIELD_ACTION = 'action';
    const ACTION_GENERATE = 'generate';
    const ACTION_VARIATE = 'variate';
    const ACTION_EDIT = 'edit';
    const ACTION_PUBLIC = 'public';
    const ACTION_SETTINGS = 'settings';
    const ACTION_ABOUT = 'about';
    const LAYOUT_MAIN = 'main';
    const DALL_E_MODEL_3 = "dall-e-3";
    const DALL_E_MODEL_2 = "dall-e-2";

    private string $plugin_name;
    private string $plugin_full_name = "Artist Image Generator";
    private string $version;

    private string $prefix;
    private string $admin_partials_path;
    private array $admin_display_templates;
    private array $admin_actions;
    private array $admin_actions_labels;

    // Set the license server URL, customer key, customer secret, and product IDs
    const AIG_LICENCE_SERVER = 'https://developpeur-web.site';
    const AIG_CUSTOMER_KEY = 'ck_cd59905ed7072a7f07ff8a028031743ec657661c';
    const AIG_CUSTOMER_SECRET = 'cs_52543ce45eb75c518aa939a480539e9a226026e1';
    const AIG_PRODUCT_IDS = [26733];

    private $options;

    public function __construct(string $plugin_name, string $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->prefix = "artist_image_generator";
        $this->admin_partials_path = "admin/partials/";
        $this->admin_display_templates = [
            'generate' => 'generate',
            'variate' => 'variate',
            'edit' => 'edit',
            'public' => 'public',
            'settings' => 'settings',
            'about' => 'about',
            'main' => 'main'
        ];
        $this->admin_actions = [
            self::ACTION_GENERATE,
            self::ACTION_PUBLIC,
            self::ACTION_SETTINGS,
            self::ACTION_ABOUT
        ];
        $this->admin_actions_labels = [
            self::ACTION_GENERATE => __('画像生成', 'artist-image-generator'),
            self::ACTION_PUBLIC => __('ショートコード', 'artist-image-generator'),
            self::ACTION_SETTINGS => __('設定', 'artist-image-generator'),
            self::ACTION_ABOUT => __('About', 'artist-image-generator')
        ];

        // Schedule license validity check event
        if (!wp_next_scheduled($this->prefix . '_license_validity')) {
            wp_schedule_event(time(), 'daily', $this->prefix . '_license_validity');
        }
        add_action($this->prefix . '_license_validity', [$this, 'validate_license']);
    }

    /**
     * Hook : Enqueue CSS scripts
     *
     * @return void
     */
    public function enqueue_styles(): void
    {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/artist-image-generator-admin.css', array(), $this->version, 'all');
    }

    /**
     * Hook : Enqueue JS scripts
     *
     * @return void
     */
    public function enqueue_scripts(): void
    {
        $is_media_editor_page = $this->is_media_editor_page();
        $is_plugin_page = $this->is_artist_image_generator_page();

        // Enqueue scripts only on specific admin pages
        if ($is_plugin_page || $is_media_editor_page) {
            $dependencies = array('wp-util', 'jquery', 'underscore');
            // Enqueue necessary scripts
            wp_enqueue_script('wp-util');

            if ($is_media_editor_page) {
                $dependencies[] = 'media-editor';
                wp_enqueue_media();
                wp_enqueue_script('media-editor');
            }

            //wp_enqueue_script( $this->plugin_name . '-cropper', plugin_dir_url( __FILE__ ) . 'js/artist-image-generator-admin-cropper.js', [], $this->version, true );
            wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/artist-image-generator-admin.js', $dependencies, $this->version, true);
            wp_localize_script($this->plugin_name, 'aig_ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'cropper_script_path' => plugin_dir_url(__FILE__) . 'js/artist-image-generator-admin-cropper.js',
                'drawing_tool_script_path' => plugin_dir_url(__FILE__) . 'js/artist-image-generator-admin-drawing.js',
                'is_media_editor' => $is_media_editor_page,
                'variateLabel' => esc_attr__('Variate', 'artist-image-generator'),
                'editLabel' => esc_attr__('Edit (Pro)', 'artist-image-generator'),
                'publicLabel' => esc_attr__('Shortcodes', 'artist-image-generator'),
                'generateLabel' => esc_attr__('Generate', 'artist-image-generator'),
                'cropperCropLabel' => esc_attr__('Crop this zone', 'artist-image-generator'),
                'cropperCancelLabel' => esc_attr__('Cancel the zoom', 'artist-image-generator'),
                'cancelLabel' => esc_attr__('Cancel', 'artist-image-generator'),
                'maskLabel' => esc_attr__('Create mask', 'artist-image-generator'),
                'valid_licence' => $this->check_license_validity(),
            ));

            if ($is_media_editor_page) {
                $data = [
                    'error' => [],
                    'images' => [],
                    'prompt_input' => "",
                    'size_input' => $this->get_default_image_dimensions(),
                    'n_input' => 1
                ];

                // Pass the variable to the template
                wp_localize_script($this->plugin_name, 'aig_data', $data);
            }
        }
    }

    /**
     * Check if current page is the Artist Image Generator page
     *
     * @return bool
     */
    private function is_artist_image_generator_page(): bool
    {
        global $pagenow;

        return $pagenow === 'upload.php' && isset($_GET['page']) && $_GET['page'] === $this->prefix;
    }

    /**
     * Check if current page is the media editor page and the edit action is set
     *
     * @return bool
     */
    private function is_media_editor_page(): bool
    {
        global $pagenow;

        return ($pagenow === 'post.php' || $pagenow === 'post-new.php');
    }

    /**
     * Hook : Add links to plugin meta
     *
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_row_meta(array $links, string $file): array
    {
        if (strpos($file, "{$this->plugin_name}/{$this->plugin_name}.php") === false) {
            return $links;
        }

        $meta = array(
            'support' => sprintf('<a href="%1$s" target="_blank"><span class="dashicons dashicons-sos"></span> %2$s</a>', esc_url("https://wordpress.org/support/plugin/{$this->plugin_name}"), esc_html__('Support', 'artist-image-generator')),
            'review' => sprintf('<a href="%1$s" target="_blank"><span class="dashicons dashicons-thumbs-up"></span> %2$s</a>', esc_url("https://wordpress.org/support/plugin/{$this->plugin_name}/reviews/#new-post"), esc_html__('Review', 'artist-image-generator')),
            'github' => sprintf('<a href="%1$s" target="_blank"><span class="dashicons dashicons-randomize"></span> %2$s</a>', esc_url("https://github.com/Immolare/{$this->plugin_name}"), esc_html__('GitHub', 'artist-image-generator')),
        );

        return array_merge($links, $meta);
    }

    /**
     * Hook : Add settings link to plugin action
     *
     * @param array $links
     * @param string $file
     * @return array
     */
    public function plugin_action_links(array $links, string $file): array
    {
        if ($file !== "{$this->plugin_name}/{$this->plugin_name}.php" || !current_user_can('manage_options')) {
            return $links;
        }

        array_unshift(
            $links,
            sprintf('<a href="%1$s">%2$s</a>', $this->get_admin_tab_url(self::ACTION_SETTINGS), esc_html__(ucfirst(self::ACTION_SETTINGS), 'artist-image-generator'))
        );

        return $links;
    }

    /**
     * Hook : Add plugin menu item to the admin menu
     *
     * @return void
     */
    public function admin_menu(): void
    {
        add_media_page(
            $this->plugin_full_name,
            __('Image Generator', 'artist-image-generator'),
            'manage_options',
            $this->prefix,
            [$this, 'admin_page']
        );
    }

    public function check_license_validity()
    {
        // Récupérer l'objet de licence depuis les options
        $license_object = get_option($this->prefix . '_aig_licence_object_0', '');

        // Vérifier si l'objet de licence existe et s'il est valide
        if ($license_object && $license_object['status'] === 2) {
            return true;
        }

        return false;
    }

    public function validate_license($license_key = null, $activate = false)
    {
        $license_key = is_null($license_key) ?
            get_option($this->prefix . '_aig_licence_key_0', '') :
            $license_key;

        // Create an instance of the license SDK
        $license_sdk = new LMFW\SDK\License(
            'Artist Image Generator',
            self::AIG_LICENCE_SERVER,
            self::AIG_CUSTOMER_KEY,
            self::AIG_CUSTOMER_SECRET,
            self::AIG_PRODUCT_IDS,
            $license_key,
            'aig-is-valid',
            2
        );

        // Validate the license first
        $valid_status = $license_sdk->validate_status($license_key);

        if (!$valid_status['is_valid']) {
            // The license is not valid, return WP_Error
            return new WP_Error('invalid_license', __('Invalid license key. Please enter a valid license key.', 'artist-image-generator'));
        }

        if ($activate && !$this->check_license_validity()) {
            try {
                // Activate the license
                $activated_license = $license_sdk->activate($license_key);

                // Store the license object on the database
                update_option($this->prefix . '_aig_licence_object_0', $activated_license);

                // Return true on success
                return true;
            } catch (Exception $e) {
                // Exception occurred, return WP_Error
                return new WP_Error('license_activation_failed', __('License activation failed.', 'artist-image-generator'));
            }
        }

        return true;
    }

    /**
     * Hook : Init plugin's parameters
     *
     * @return void
     */
    public function admin_init(): void
    {
        register_setting(
            $this->prefix . '_option_group', // option_group
            $this->prefix . '_option_name', // option_name
            array($this, 'sanitize') // sanitize_callback
        );

        add_settings_section(
            $this->prefix . '_setting_section', // id
            __('Settings', 'artist-image-generator'), // title
            array($this, 'section_info'), // callback
            $this->prefix . '-admin' // page
        );

        add_settings_field(
            $this->prefix . '_openai_api_key_0', // id
            'OPENAI_API_KEY', // title
            array($this, 'openai_api_key_0_callback'), // callback
            $this->prefix . '-admin', // page
            $this->prefix . '_setting_section' // section
        );

        add_settings_field(
            $this->prefix . '_aig_licence_key_0', // id
            'AIG_PREMIUM_LICENCE_KEY', // title
            array($this, 'aig_licence_key_0_callback'), // callback
            $this->prefix . '-admin', // page
            $this->prefix . '_setting_section' // section
        );
    }

    /**
     * Utility function to sanitize input field parameter
     *
     * @param array $input
     * @return array
     */
    public function sanitize(array $input): array
    {
        $sanitizedValues = [];

        if (isset($input[$this->prefix . '_openai_api_key_0'])) {
            $sanitizedValues[$this->prefix . '_openai_api_key_0'] = sanitize_text_field($input[$this->prefix . '_openai_api_key_0']);
        }

        if (isset($input[$this->prefix . '_aig_licence_key_0'])) {
            $licence_key = sanitize_text_field($input[$this->prefix . '_aig_licence_key_0']);

            if (!empty($licence_key)) {
                // Validate the license
                $is_valid_license = $this->validate_license($licence_key, true);

                // If the license is valid, save it to the options
                if ($is_valid_license === true) {
                    $sanitizedValues[$this->prefix . '_aig_licence_key_0'] = $licence_key;
                } else {
                    // The license is not valid, reset the license key value
                    add_settings_error(
                        $this->prefix . '_option_name',
                        'invalid_license',
                        $is_valid_license->get_error_message(),
                        'error'
                    );
                    return $sanitizedValues;
                }
            }
        }

        return $sanitizedValues;
    }

    /**
     * Section info : Not used
     *
     * @return void
     */
    public function section_info(): void
    {
    }

    /**
     * Utility function to print the input field parameter
     *
     * @return void
     */
    public function openai_api_key_0_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="' . $this->prefix . '_option_name[' . $this->prefix . '_openai_api_key_0]" id="' . $this->prefix . '_openai_api_key_0" value="%s">',
            isset($this->options[$this->prefix . '_openai_api_key_0']) ? esc_attr($this->options[$this->prefix . '_openai_api_key_0']) : ''
        );
    }

    /**
     * Utility function to print the input field parameter
     *
     * @return void
     */
    public function aig_licence_key_0_callback(): void
    {
        printf(
            '<input class="regular-text" type="text" name="' . $this->prefix . '_option_name[' . $this->prefix . '_aig_licence_key_0]" id="' . $this->prefix . '_aig_licence_key_0" value="%s">',
            isset($this->options[$this->prefix . '_aig_licence_key_0']) ? esc_attr($this->options[$this->prefix . '_aig_licence_key_0']) : ''
        );
    }

    /**
     * Hook : The plugin's administration page
     *
     * @return void
     */
    public function admin_page()
    {
        $data = $this->do_post_request();

        if (wp_doing_ajax()) {
            wp_send_json($data);
            wp_die();
        }

        // Pass the variable to the template
        wp_localize_script($this->plugin_name, 'aig_data', $data);

        require_once $this->get_admin_template(self::LAYOUT_MAIN);
    }

    /**
     * Utility function to do some post request processing used on admin_page and admin_media_manager_page
     *
     * @return void
     */
    public function do_post_request()
    {
        $images = [];
        $error = [];

        $this->options = get_option($this->prefix . '_option_name');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $this->is_setting_up()) {
            $is_generation = isset($_POST['generate']) && sanitize_text_field($_POST['generate']);
            $is_variation = isset($_POST['variate']) && sanitize_text_field($_POST['variate']);
            $is_edit = isset($_POST['edit']) && sanitize_text_field($_POST['edit']);
            $prompt_input = isset($_POST['prompt']) ? sanitize_text_field($_POST['prompt']) : null;
            $size_input = isset($_POST['size']) ? sanitize_text_field($_POST['size']) : $this->get_default_image_dimensions();
            $n_input = isset($_POST['n']) ? sanitize_text_field($_POST['n']) : 1;
            // DALL-E 3
            $model = isset($_POST['model']) && sanitize_text_field($_POST['model']) === self::DALL_E_MODEL_3 ? self::DALL_E_MODEL_3 : null; 

            if ($is_generation) {
                if (empty($prompt_input)) {
                    $error = [
                        'msg' => __('画像を生成するためには、プロンプト入力が必要です。', 'artist-image-generator')
                    ];
                } else {
                    $response = $this->generate($prompt_input, $n_input, $size_input, $model);
                }
            } elseif ($is_variation) {
                $errorMsg = __('A .png square (1:1) image of maximum 4MB needs to be uploaded in order to generate a variation of this image.', 'artist-image-generator');
                $image_file = isset($_FILES['image']) && $_FILES['image']['size'] > 0 ? $_FILES['image'] : null;

                if (empty($image_file)) {
                    $error = ['msg' => $errorMsg];
                } else {
                    $image_mime_type = mime_content_type($image_file['tmp_name']);
                    list($image_width, $image_height) = getimagesize($image_file['tmp_name']);
                    $image_wrong_size = $image_file['size'] >= ((1024 * 1024) * 4) || $image_file['size'] == 0;
                    $allowed_file_types = ['image/png']; // If you want to allow certain files

                    if (!in_array($image_mime_type, $allowed_file_types) || $image_wrong_size || $image_height !== $image_width) {
                        $error = ['msg' => $errorMsg];
                    } else {
                        $response = $this->variate($image_file, $n_input, $size_input);
                    }
                }
            } elseif ($is_edit && $this->check_license_validity()) {
                $errorMsg = __('A .png square (1:1) image of maximum 4MB needs to be uploaded in order to generate a variation of this image.', 'artist-image-generator');
                $image_file = isset($_FILES['image']) && $_FILES['image']['size'] > 0 ? $_FILES['image'] : null;
                $mask_file = isset($_FILES['mask']) && $_FILES['mask']['size'] > 0 ? $_FILES['mask'] : null;

                if (empty($image_file)) {
                    $error = ['msg' => $errorMsg];
                } else {
                    // EDIT MASK FILE                  
                    $image_mime_type = mime_content_type($image_file['tmp_name']);
                    list($image_width, $image_height) = getimagesize($image_file['tmp_name']);
                    $image_wrong_size = $image_file['size'] >= ((1024 * 1024) * 4) || $image_file['size'] == 0;
                    $allowed_file_types = ['image/png']; // If you want to allow certain files

                    if (!in_array($image_mime_type, $allowed_file_types) || $image_wrong_size || $image_height !== $image_width) {
                        $error = ['msg' => $errorMsg];
                    } else {
                        $response = $this->edit($image_file, $mask_file, $prompt_input, $n_input, $size_input);
                    }
                }
            }

            if (isset($response)) {
                if (array_key_exists('error', $response)) {
                    $error = ['msg' => $response['error']['message']];
                } else {
                    $images = $response;
                }
            }
        }

        $data = [
            'error' => $error,
            'images' => count($images) ? $images['data'] : [],
            'prompt_input' => $prompt_input ?? '',
            'size_input' => $size_input ?? $this->get_default_image_dimensions(),
            'n_input' => $n_input ?? 1
        ];

        return $data;
    }

    /**
     * Utility function to check if the plugin parameters are set
     *
     * @return boolean
     */
    private function is_setting_up(): bool
    {
        return is_array($this->options) && array_key_exists($this->prefix . '_openai_api_key_0', $this->options);
    }

    /**
     * Utility function to communicate with OpenAI API when generating image
     *
     * @param string $prompt_input
     * @param integer $n_input
     * @param string $size_input
     * @return array
     */
    private function generate(string $prompt_input, int $n_input, string $size_input, string $model = null): array
    {
        $num_images = max(1, min(10, (int) $n_input));
        $open_ai = new OpenAi($this->options[$this->prefix . '_openai_api_key_0']);
        $params = [
            "prompt" => $prompt_input,
            "n" => $num_images,
            "size" => $size_input,
        ];

        if (!is_null($model)) {
            $params['model'] = self::DALL_E_MODEL_3;
            $params['n'] = 1;
            $params['quality'] = 'hd';
        }

        $result = $open_ai->image($params);

        return json_decode($result, true);
    }

    /**
     * Utility function to communicate with OpenAI API when making a variation of an image
     *
     * @param array $image_file
     * @param integer $n_input
     * @param string $size_input
     * @return array
     */
    private function variate(array $image_file, int $n_input, string $size_input): array
    {
        $num_variations = max(1, min(10, (int) $n_input));
        $open_ai = new OpenAi($this->options[$this->prefix . '_openai_api_key_0']);
        $tmp_file = $image_file['tmp_name'];
        $file_name = basename($image_file['name']);
        $image = curl_file_create($tmp_file, $image_file['type'], $file_name);
        $result = $open_ai->createImageVariation([
            //"model" => self::DALL_E_MODEL_3,
            "image" => $image,
            "n" => $num_variations,
            "size" => $size_input,
        ]);
        return json_decode($result, true);
    }

    /**
     * Utility function to communicate with OpenAI API when making a variation of an image
     *
     * @param array $image_file
     * @param array $mask_file
     * @param string $prompt_input
     * @param integer $n_input
     * @param string $size_input
     * @return array
     */
    private function edit(array $image_file, array $mask_file, string $prompt_input, int $n_input, string $size_input): array
    {
        if (!$this->check_license_validity()) {
            return [];
        }

        $num_variations = max(1, min(10, (int) $n_input));
        $open_ai = new OpenAi($this->options[$this->prefix . '_openai_api_key_0']);
        $tmp_file = $image_file['tmp_name'];
        $file_name = basename($image_file['name']);
        $image = curl_file_create($tmp_file, $image_file['type'], $file_name);

        $tmp_file_mask = $mask_file['tmp_name'];
        $file_name_mask = basename($mask_file['name']);
        $mask = curl_file_create($tmp_file_mask, $mask_file['type'], $file_name_mask);

        $result = $open_ai->imageEdit([
            //"model" => self::DALL_E_MODEL_3,
            "image" => $image,
            "mask" => $mask,
            "prompt" => $prompt_input,
            "n" => $num_variations,
            "size" => $size_input,
        ]);

        return json_decode($result, true);
    }

    /**
     * The ajax part to save generated image into media library
     *
     * @return mixed
     */
    public function add_to_media(): mixed
    {
        require_once ABSPATH . "/wp-admin/includes/image.php";
        require_once ABSPATH . "/wp-admin/includes/file.php";
        require_once ABSPATH . "/wp-admin/includes/media.php";

        $url = sanitize_url($_POST['url']);
        $alt = sanitize_text_field($_POST['description']);

        // Download url to a temp file
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return false;
        }

        // Get the filename and extension ("photo.png" => "photo", "png")
        $filename = pathinfo($url, PATHINFO_FILENAME);
        $extension = pathinfo($url, PATHINFO_EXTENSION);

        // An extension is required or else WordPress will reject the upload
        if (!$extension) {
            // Look up mime type, example: "/photo.png" -> "image/png"
            $mime = mime_content_type($tmp);
            $mime = is_string($mime) ? sanitize_mime_type($mime) : false;

            // Only allow certain mime types because mime types do not always end in a valid extension (see the .doc example below)
            $mime_extensions = array(
                'image/jpg'  => 'jpg',
                'image/jpeg' => 'jpeg',
                'image/gif'  => 'gif',
                'image/png'  => 'png'
            );

            $extension = $mime_extensions[$mime] ?? false;

            if (!$extension) {
                // Could not identify extension
                @unlink($tmp);
                return false;
            }
        }

        // Upload by "sideloading": "the same way as an uploaded file is handled by media_handle_upload"
        $args = array(
            'name' => sanitize_title($alt, $filename) . ".$extension",
            'tmp_name' => $tmp,
        );

        // Do the upload
        $attachment_id = media_handle_sideload($args, 0, $alt);

        // Cleanup temp file
        @unlink($tmp);

        // Error uploading
        if (is_wp_error($attachment_id)) {
            return false;
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

        // Success, return attachment ID (int)
        wp_send_json_success(['attachment_id' => (int) $attachment_id]);

        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_die();
        }
    }

    /**
     * Utility function to define default image dimensions
     *
     * @return string
     */
    public function get_default_image_dimensions(): string
    {
        return "1024x1024";
    }

    /**
     * Utility function to generate the current admin view
     *
     * @param string $template
     * @param [type] ...$params
     * @return string
     */
    public function get_admin_template(string $template, ...$params): string
    {
        $pluginPath = plugin_dir_path(dirname(__FILE__));

        $valid_templates = $this->admin_display_templates;

        if (!in_array($template, $valid_templates)) {
            $template = self::ACTION_GENERATE;
        }

        $template_path = $this->admin_display_templates[$template];

        if ($template !== self::LAYOUT_MAIN) {
            $template_path .= '-template';
        }

        return $pluginPath . $this->admin_partials_path . $template_path . '.php';
    }

    /**
     * Utility function to get child templates based on
     *
     * @param string $template (error/error) to get templates/error/error-template.php
     * @return string
     */
    public function get_admin_child_template(string $template): string
    {
        $pluginPath = plugin_dir_path(dirname(__FILE__));
        $templatePath = $pluginPath . $this->admin_partials_path . $template . '-template.php';

        if (file_exists($templatePath)) {
            return $templatePath;
        }
    }

    /**
     * Utility function to generate the get the URL of an action 
     *
     * @param string $action
     * @return string
     */
    public function get_admin_tab_url(string $action): string
    {
        if (!in_array($action, $this->admin_actions)) {
            $action = self::ACTION_GENERATE;
        }

        return esc_url(
            add_query_arg(
                [
                    self::QUERY_FIELD_ACTION => $action
                ],
                admin_url('upload.php?page=' . $this->prefix)
            )
        );
    }

    /**
     * Utility function to check if the tab is active and show css classes
     *
     * @param string $needle
     * @param boolean $withCssClasses
     * @return boolean
     */
    public function is_tab_active(string $needle, bool $withCssClasses = false)
    {
        $classes = ' nav-tab-active';
        $action = $_GET[self::QUERY_FIELD_ACTION] ?? null;
        $cond1 = is_null($action) && $needle === self::ACTION_GENERATE;
        $action_sanitized = sanitize_text_field($action);
        $cond2 = ($action && $needle === $action_sanitized && in_array($action_sanitized, $this->admin_actions));
        $bool = $cond1 || $cond2;

        if ($withCssClasses) {
            return $bool ? $classes : '';
        }

        return $bool;
    }

    public function print_tabs_templates()
    {
?>
        <?php // Template for generate tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-generate">
            <form action="" method="post" enctype="multipart/form-data">
                <div class="notice-container"></div>
                <table class="form-table" role="presentation">
                    <tbody class="tbody-container"></tbody>
                </table>
                <p class="submit">
                    <input type="hidden" name="generate" value="1" />
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Generate Image(s)', 'artist-image-generator'); ?>" />
                </p>
                <hr />
                <div class="result-container"></div>
            </form>
        </script>

        <?php // Template for variate tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-variate">
            <form action="" method="post" enctype="multipart/form-data">
                <div class="notice-container"></div>
                <div class="notice notice-info inline" style="margin-top:15px;">
                    <p><?php esc_attr_e('Heads up ! To make an image variation you need to submit a .png file less than 4MB in a 1:1 format (square). However, you can upload a non square .jpg or a .png file at full size, and use the "crop" functionnality to resize the area you want. You can also add a prompt input to describe the image. This value will be used to fill the image name and alternative text.', 'artist-image-generator'); ?></p>
                </div>
                <table class="form-table" role="presentation">
                    <tbody class="tbody-container"></tbody>
                </table>
                <p class="submit">
                    <input type="hidden" name="variate" value="1" />
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Generate Image(s)', 'artist-image-generator'); ?>" />
                </p>
                <hr />
                <div class="result-container"></div>
            </form>
        </script>

        <?php // Template for edit tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-edit">
            <form action="" method="post" enctype="multipart/form-data">
                <div class="notice-container"></div>
                <div class="notice notice-info inline" style="margin-top:15px;">
                    <p><?php esc_attr_e('Heads up ! To make an image edition you need to submit a .png file less than 4MB in a 1:1 format (square). However, you can upload a non square .jpg or a .png file at full size, and use the "crop" functionnality to resize the area you want. You can also add a prompt input to describe the image. This value will be used to fill the image name and alternative text.', 'artist-image-generator'); ?></p>
                </div>
                <table class="form-table" role="presentation">
                    <tbody class="tbody-container"></tbody>
                </table>
                <p class="submit">
                    <input type="hidden" name="edit" value="1" />
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Generate Image(s)', 'artist-image-generator'); ?>" />
                </p>
                <hr />
                <div class="result-container"></div>
            </form>
        </script>

        <?php // Template for edit demo tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-edit-demo">
            <div class="card">
                <h2 class="title">Provide full access to OpenAi Edit Image feature</h2>
                <p>With Open AI Edit Image, you can upload an image, create a mask around the subject, enter your desired modifications within the mask, and generate various image variations.</p>
                <p>By purchasing a unique license for just <strong>€29.99 (including 20% VAT)</strong>, you unlock this powerful functionality along with future updates.</p>
                <p>1. you can transform your images like never before</p>
                <p>2. in "Edit" tab, import any image and add a mask and an input to fill the mask with what you want</p>
                <p>3. bring your imagination in a next level with image manipulation</p>
                <p>Demo : <a href="https://youtu.be/zfK1yJk9gRc" target="_blank" title="Artist Image Generator - Image Edition feature">https://youtu.be/zfK1yJk9gRc</a></p>
                <p>
                    Don't miss out on this opportunity to elevate your image editing capabilities. Unlock your artistic potential today.
                    <br /><br />

                    <a href="https://developpeur-web.site/produit/artist-image-generator-pro/" title="Purchase Artist Image Generator Pro Licence key" target="_blank" class="button">
                        Buy Artist Image Generator (Pro) - Licence Key
                    </a>
                </p>
            </div>
        </script>

        <?php // Template for public tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-public">
            <div class="aig-container aig-container-3">
                <style>
                    /* Ajout de CSS pour améliorer l'apparence */
                    .aig-code {
                        background-color: #f0f0f0;
                        padding: 10px;
                        border: 1px solid #ccc;
                        border-radius: 5px;
                    }
                </style>
                <div class="card">
                    <h2 class="title"><?php esc_attr_e('ショートコード (ベータ)', 'artist-image-generator'); ?></h2>
                    <p><?php esc_attr_e('WordPressで公開AI画像生成フォームを作成するには、次のショートコードを使用できます：', 'artist-image-generator'); ?></p>
                    <div class="aig-code">
                        [aig prompt="あなたのカスタム説明をここに入力してください {topics} と {public_prompt}" topics="カンマで区切ったトピックのリスト" n="3" size="1024x1024" model="dall-e-3" download="manual"]
                    </div>
                    <p><?php esc_attr_e('あなたのカスタム説明をここに入力してください"をあなたの説明に置き換え、カンマで区切ったトピックのリストを指定します。あなたの説明には次のプレースホルダーを使用できます:', 'artist-image-generator'); ?></p>
                    <ul>
                        <li>- {topics} : <?php esc_attr_e('ユーザーが選択できるトピックのリストを含めるために。', 'artist-image-generator'); ?></li>
                        <li>- {public_prompt} : <?php esc_attr_e('ユーザーに対するプロンプトを含めるために。', 'artist-image-generator'); ?></li>
                    </ul>
                    <p><?php esc_attr_e('ショートコードには次のオプションの属性も使用できます:', 'artist-image-generator'); ?></p>
                    <ul>
                        <li>- n : <?php esc_attr_e('生成する画像の数（デフォルトは3、最大10）。', 'artist-image-generator'); ?></li>
                        <li>- size : <?php esc_attr_e('生成する画像のサイズ（例："256x256"、"512x512"、"1024x1024"。デフォルトは1024x1024）。', 'artist-image-generator'); ?></li>
                        <li>- model : <?php esc_attr_e('使用するOpenAiモデル（例："dall-e-2"、"dall-e-3"。デフォルトは"dall-e-2"）。', 'artist-image-generator'); ?></li>
                        <li>- download : <?php esc_attr_e('画像をダウンロードするか、WPのプロフィール画像として使用する（例："manual"、"wp_avatar"。デフォルトは"manual"）。', 'artist-image-generator'); ?></li>
                    </ul>
                    <p><?php esc_attr_e('ショートコードが準備できたら、WordPressの任意のページや投稿に追加して、公開AI画像生成フォームを表示できます。', 'artist-image-generator'); ?></p>
                </div>
            </div>
        </script>

        <?php // Template for settings tab. 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-settings">
            <h2><?php esc_attr_e('OpenAI APIキーを取得する方法', 'artist-image-generator'); ?></h2>
            <ol>
                <li>
                    <?php esc_attr_e('OpenAI開発者ポータルにサインアップ/ログインする', 'artist-image-generator'); ?> :
                    <a target="_blank" title="OpenAI Developer Portail" href="https://openai.com/api/">https://openai.com/api/</a>
                </li>
                <li>
                    <?php esc_attr_e('ユーザー > APIキーを表示で新しいシークレットキーを作成する', 'artist-image-generator'); ?> :
                    <a target="_blank" title="OpenAI - API keys" href="https://platform.openai.com/account/api-keys">https://platform.openai.com/account/api-keys</a>
                </li>
                <li>
                    <?php esc_attr_e('新しいシークレットキーをコピーして、ここにあるOPENAI_API_KEYフィールドに貼り付けます。', 'artist-image-generator'); ?>
                </li>
                <li>
                    <?php esc_attr_e('「変更を保存」を押すと完了です。', 'artist-image-generator'); ?>
                </li>
            </ol>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->prefix . '_option_group');
                do_settings_sections($this->prefix . '-admin');
                submit_button();
                ?>
            </form>
        </script>

        <?php // 「About」タブのテンプレート
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-about">
            <div class="aig-container aig-container-3">
                <div class="card">
                    <h2 class="title">
                        どのように動作しますか？
                    </h2>
                    <p>
                        <strong>このプラグインは、<a target="_blank" title="DALL·E 2を訪問" href="https://openai.com/dall-e-2/">DALL·E 2</a>とOpenAI APIの統合です</strong>
                    </p>
                    <p>
                        DALL·E 2は、テキストの説明からオリジナルでリアルな画像やアートを作成できます。概念、属性、スタイルを組み合わせることができます。
                        このAIは、画像とそれを説明するテキストとの関係を学習しました。
                    </p>
                    <p>
                        基本的には、ユーザーが欲しい画像を説明するテキストを入力します。1-10の画像が生成されます。
                        その後、ユーザーはいくつかの画像を選択し、Wordpressのメディアライブラリに追加して、ページやブログ投稿で使用する準備をします。
                    </p>
                    <p>
                        生成された画像は、どのような使用にも無料ライセンスです。
                    </p>
                </div>
                </div>
            </script>

        <?php // Child template for notice block (notice-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-notice">
            <# if ( data.error && data.error.msg ) { #>
                <div class="notice notice-error inline" style="margin-top:15px;">
                    <p><?php echo esc_html('{{ data.error.msg }}'); ?></p>
                </div>
                <# } #>
        </script>

        <?php // Child template for result block (result-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-result">
            <div class="aig-container">
                <# if ( data.images ) { #>
                    <# _.each(data.images, function(image, k) { #>
                        <div class="card">
                            <h2 class="title">
                                <?php esc_attr_e('Image N°', 'artist-image-generator'); ?><?php echo esc_attr('{{ k + 1 }}'); ?>
                                <div class="spinner" style="margin-top: 0;"></div>
                                <span class="dashicons dashicons-yes alignright" style="color:#46B450"></span>
                            </h2>
                            <img src="{{ image.url }}" width="100%" height="auto">
                            <a class="button add_as_media" href="javascript:void(0);">
                                <?php esc_attr_e('Add to media library', 'artist-image-generator'); ?>
                            </a>
                        </div>
                    <# }) #>
                <# } #>
            </div>
        </script>

        <?php // Child template for form/image block (tbody-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-form-image">
            <tr>
                <th scope="row">
                    <label for="image"><?php esc_attr_e('File (.png, .jpg)', 'artist-image-generator'); ?></label>
                </th>
                <td>
                    <input type="file" name="image" id="image" class="regular-text aig_handle_cropper" accept=".png,.jpg" />
                    <input type="file" name="mask" id="mask" class="regular-text" accept=".png,.jpg" hidden readonly />
                    <input type="file" name="original" id="original" class="regular-text" accept=".png,.jpg" hidden readonly />
                </td>
            </tr>
            <tr>
                <th id="aig_cropper_preview" scope="row"></th>
                <td id="aig_cropper_canvas_area"></td>
            </tr>
        </script>

        <?php // Child template for form/n block (tbody-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-form-n">
            <tr>
                <th scope="row">
                    <label for="n"><?php esc_attr_e('出力する画像の数', 'artist-image-generator'); ?></label>
                </th>
                <td>
                    <select name="n" id="n">
                        <# for (var i=1; i <=10; i++) { #>
                            <# var is_selected=(data.n_input && data.n_input==i) ? 'selected' : '' ; #>
                                <option value="<?php echo esc_attr('{{ i }}'); ?>" <?php echo esc_attr('{{ is_selected }}'); ?>>
                                    <?php echo esc_attr('{{ i }}'); ?>
                                </option>
                        <# } #>
                    </select>
                </td>
            </tr>
        </script>

        <?php // Child template for form/prompt block (tbody-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-form-prompt">
            <tr>
                <th scope="row">
                    <label for="prompt"><?php esc_attr_e('プロンプト', 'artist-image-generator'); ?></label>
                </th>
                <td>
                    <input type="text" id="prompt" name="prompt" class="regular-text" placeholder="<?php esc_attr_e('例) 千葉県の海沿いにある和風な家', 'artist-image-generator'); ?>" value="<?php echo esc_attr('{{ data.prompt_input }}'); ?>" />
                </td>
            </tr>
        </script>

        <?php // Child template for form/model block (tbody-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-form-model">
            <# var is_selected_dalle2=(data.model && data.model=='' ) ? 'selected' : '' ; #>
            <# var is_selected_dalle3=(data.model && data.model=='dall-e-3' ) ? 'selected' : '' ; #>
            <tr>
                <th scope="row">
                    <label for="model"><?php esc_attr_e('モデル', 'artist-image-generator'); ?></label>
                </th>
                <td>
                    <select name="model" id="model">
                        <option value="" <?php echo esc_attr('{{ is_selected_dalle2 }}'); ?>><?php echo self::DALL_E_MODEL_2; ?></option>
                        <option value="<?php echo self::DALL_E_MODEL_3; ?>" <?php echo esc_attr('{{ is_selected_dalle3 }}'); ?>><?php echo self::DALL_E_MODEL_3; ?></option>
                    </select>
                </td>
            </tr>
        </script>

        <?php // Child template for form/size block (tbody-container). 
        ?>
        <script type="text/html" id="tmpl-artist-image-generator-form-size">
            <# var is_selected_dalle2=(data.model && data.model=='' ) ? 'selected' : '' ; #>
            <# var is_selected_dalle3=(data.model && data.model=='dall-e-3' ) ? 'selected' : '' ; #>
            <# var is_selected_256=(is_selected_dalle2 && data.size_input && data.size_input=='256x256' ) ? 'selected' : '' ; #>
            <# var is_selected_512=(is_selected_dalle2 && data.size_input && data.size_input=='512x512' ) ? 'selected' : '' ; #>
            <# var is_selected_1024=(
                is_selected_dalle2 && (!data.size_input || (data.size_input && data.size_input=='1024x1024' )) ||
                is_selected_dalle3 && (!data.size_input || (data.size_input && data.size_input=='1024x1024' ))
            ) ? 'selected' : '' ; #>
            <# var is_selected_1792h=(data.size_input && data.size_input=='1024x1792' ) ? 'selected' : '' ; #>
            <# var is_selected_1792v=(data.size_input && data.size_input=='1792x1024' ) ? 'selected' : '' ; #>
                <tr>
                    <th scope="row">
                        <label for="size"><?php esc_attr_e('Size in pixels', 'artist-image-generator'); ?></label>
                    </th>
                    <td>
                        <select name="size" id="size" >
                            <option data-dalle="2" value="256x256" <?php echo esc_attr('{{ is_selected_256 }}'); ?>>256x256</option>
                            <option data-dalle="2" value="512x512" <?php echo esc_attr('{{ is_selected_512 }}'); ?>>512x512</option>
                            <option data-dalle="23" value="1024x1024" <?php echo esc_attr('{{ is_selected_1024 }}'); ?>>1024x1024</option>
                            <option data-dalle="3" value="1024x1792" <?php echo esc_attr('{{ is_selected_1792h }}'); ?>>1024x1792</option>
                            <option data-dalle="3" value="1792x1024" <?php echo esc_attr('{{ is_selected_1792v }}'); ?>>1792x1024</option>
                        </select>
                    </td>
                </tr>
        </script>

<?php
    }
}
