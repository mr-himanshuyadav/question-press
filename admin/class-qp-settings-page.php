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
        // Register the main setting. This will create an entry in the wp_options table.
        register_setting(
            'qp_settings_group',      // Option group
            'qp_settings',            // Option name
            ['sanitize_callback' => [self::class, 'sanitize_settings']] // Sanitization callback
        );

        // Add the main section for data settings
        add_settings_section(
            'qp_data_settings_section', // Section ID
            'Data Management',          // Section Title
            [self::class, 'render_data_section_text'], // Callback to render section description
            'qp-settings-page'          // Page slug
        );

        // Add the checkbox field to our section
        add_settings_field(
            'qp_delete_on_uninstall',   // Field ID
            'Delete Data on Uninstall', // Field Title
            [self::class, 'render_delete_data_checkbox'], // Callback to render the field HTML
            'qp-settings-page',         // Page slug
            'qp_data_settings_section'  // Section ID
        );
        
        // Add section for API info
        add_settings_section(
            'qp_api_settings_section',
            'API Information',
            [self::class, 'render_api_section_text'],
            'qp-settings-page'
        );
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
        echo '<p>Use these details to connect your Android application to this website. Full documentation will be available here upon completion of the REST API.</p>';
        // We will add API key display logic here in a future milestone.
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