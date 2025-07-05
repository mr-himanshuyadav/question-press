<?php
if (!defined('ABSPATH')) exit;

class QP_Settings_Page {

    /**
     * Renders the main settings page wrapper and form.
     */
    public static function render() {
        ?>
        <div class="wrap">
            <h1>Question Press Settings</h1>
            <form action="options.php" method="post">
                <?php
                // Output security fields for the registered setting section
                settings_fields('qp_settings_group');
                // Output the settings sections and their fields
                do_settings_sections('qp-settings-page');
                // Output the save button
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Registers all settings, sections, and fields.
     */
    public static function register_settings() {
        register_setting('qp_settings_group', 'qp_settings', ['sanitize_callback' => [self::class, 'sanitize_settings']]);
        add_settings_section('qp_data_settings_section', 'Data Management', [self::class, 'render_data_section_text'], 'qp-settings-page');
        add_settings_field('qp_delete_on_uninstall', 'Delete Data on Uninstall', [self::class, 'render_delete_data_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        // UPDATED: Add a new section and field for the API key
        add_settings_section('qp_api_settings_section', 'API Information', [self::class, 'render_api_section_text'], 'qp-settings-page');
        add_settings_field('qp_api_secret_key', 'JWT Secret Key', [self::class, 'render_api_key_field'], 'qp-settings-page', 'qp_api_settings_section');
    }

    

    /**
     * Callback to render the description for the data section.
     */
    public static function render_data_section_text() {
        echo '<p>Control how the plugin handles its data upon deletion.</p>';
    }
    
    /**
     * Callback to render the description for the API section.
     */
    public static function render_api_section_text() {
        echo '<p>Use this secret key to sign authentication tokens in your mobile application. Keep it safe and do not share it publicly.</p>';
    }

    /**
     * Callback to render our checkbox setting.
     */
    public static function render_delete_data_checkbox() {
        $options = get_option('qp_settings');
        $checked = isset($options['delete_on_uninstall']) ? $options['delete_on_uninstall'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[delete_on_uninstall]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Check this box to permanently delete all questions, subjects, labels, and user history when the plugin is uninstalled. This action cannot be undone.</span></label>';
    }

    // NEW: Renders the API key field and regenerate button
    public static function render_api_key_field() {
        $key = get_option('qp_jwt_secret_key');
        $nonce = wp_create_nonce('qp_regenerate_api_key_nonce');
        ?>
        <input type="text" id="qp-api-secret-key-field" value="<?php echo esc_attr($key); ?>" readonly style="width: 100%; max-width: 500px; background: #f0f0f1;">
        <button id="qp-regenerate-api-key" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">Regenerate Secret Key</button>
        <?php
    }

    /**
     * Sanitizes the settings array before saving.
     */
    public static function sanitize_settings($input) {
        $new_input = [];
        if (isset($input['delete_on_uninstall'])) {
            $new_input['delete_on_uninstall'] = absint($input['delete_on_uninstall']);
        }
        return $new_input;
    }
}