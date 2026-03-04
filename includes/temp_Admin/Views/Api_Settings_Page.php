<?php
namespace QuestionPress\Admin\Views;

if (!defined('ABSPATH')) exit;

/**
 * Handles the rendering and registration of the REST API settings page.
 */
class Api_Settings_Page
{
    /**
     * Renders the settings page wrapper.
     */
    public static function render()
    {
        // This button handles the regeneration
        if (isset($_POST['regenerate_api_key']) && check_admin_referer('qp_regenerate_api_key_nonce')) {
            self::regenerate_secret_key();
            // Add a message
            add_settings_error('qp_api_settings', 'api_key_regenerated', 'New JWT Secret Key generated successfully.', 'success');
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php
            // Display settings errors (e.g., the "regenerated" message)
            settings_errors('qp_api_settings');
            ?>
            <form action="options.php" method="POST">
                <?php
                // This renders the "qp_api_settings_group"
                settings_fields('qp_api_settings_group');
                ?>
                <table class="form-table" role="presentation">
                <?php
                    // This renders the fields for the "qp_api_settings_section"
                    do_settings_sections('qp-api-settings-page');
                ?>
                </table>
                <?php
                // This renders the main "Save Settings" button
                submit_button('Save API Settings');
                ?>
            </form>

            <form action="" method="POST">
                <h2>Regenerate Secret Key</h2>
                <p>Regenerating the key will immediately log out all active mobile app users. They will need to log in again.</p>
                <?php wp_nonce_field('qp_regenerate_api_key_nonce'); ?>
                <input type="hidden" name="regenerate_api_key" value="1">
                <?php submit_button('Regenerate Secret Key', 'button-secondary', 'submit_regenerate', false); ?>
            </form>

        </div>
        <?php
    }

    /**
     * Registers all settings, sections, and fields for the API page.
     * HOOK THIS INTO 'admin_init'
     */
    public static function register_settings()
    {
        // 1. Register the main setting
        // This will store ONE option in wp_options called 'qp_jwt_secret_key'
        register_setting(
            'qp_api_settings_group',      // Group name
            'qp_jwt_secret_key',          // Option name
            ['sanitize_callback' => [self::class, 'sanitize_secret_key']] // Sanitization
        );

        // 2. Add the settings section
        add_settings_section(
            'qp_api_settings_section',    // ID
            'API Configuration',          // Title
            [self::class, 'render_api_section_text'], // Callback for text
            'qp-api-settings-page'        // Page slug
        );

        // 3. Add the settings field
        add_settings_field(
            'qp_jwt_secret_key',          // ID
            'JWT Secret Key',             // Title
            [self::class, 'render_api_key_field'], // Callback to render the field
            'qp-api-settings-page',       // Page slug
            'qp_api_settings_section'     // Section
        );

        // Add another field for your documentation, separate from the key
        add_settings_field(
            'qp_api_documentation',
            'API Endpoint Documentation',
            [self::class, 'render_api_documentation'],
            'qp-api-settings-page',
            'qp_api_settings_section'
        );
    }

    /**
     * Callback to render the description for the API section.
     */
    public static function render_api_section_text()
    {
        echo '<p>Manage the connection between your website and the mobile application.</p>';
    }

    /**
     * Callback to render the JWT Secret Key field.
     */
    public static function render_api_key_field()
    {
        $key = get_option('qp_jwt_secret_key');
        if (empty($key)) {
            echo '<p>No key generated yet. <strong>Save settings or regenerate to create one.</strong></p>';
        } else {
            echo '<input type="text" id="qp-api-secret-key-field" value="' . esc_attr($key) . '" readonly style="width: 100%; max-width: 500px; background: #f0f0f1;">';
        }
    }

    /**
     * Callback to render the API documentation (moved from Settings_Page.php)
     */
    public static function render_api_documentation()
    {
        // This is the documentation HTML you had in Settings_Page.php
        ?>
        <hr>
        <h3>API Endpoints</h3>
        <p>Base URL: <code><?php echo esc_url(get_site_url()); ?>/wp-json/questionpress/v1</code></p>
        
        <div class="api-doc-section">
            <h4>Authentication</h4>
            <p><strong>Endpoint:</strong> <code>POST /token</code></p>
            </div>

        <div class="api-doc-section">
            <h4>Get Subjects</h4>
            <p><strong>Endpoint:</strong> <code>GET /subjects</code></p>
            </div>
        
        <style>
            .api-doc-section { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #ddd; }
            .api-doc-section pre { background: #f6f7f7; padding: 1rem; border-radius: 4px; }
        </style>
        <?php
    }

    /**
     * Sanitizes the JWT secret key.
     */
    public static function sanitize_secret_key($input)
    {
        // If the key is empty (e.g., on first save), generate one.
        if (empty($input)) {
            return self::generate_new_key();
        }
        // Otherwise, just make sure it's a clean string
        return sanitize_text_field($input);
    }

    /**
     * Handles the 'regenerate_api_key' POST action.
     */
    private static function regenerate_secret_key()
    {
        $new_key = self::generate_new_key();
        update_option('qp_jwt_secret_key', $new_key);
    }

    /**
     * Generates a new random key.
     */
    private static function generate_new_key()
    {
        // A simple 64-character key
        return wp_generate_password(64, false, false);
    }
}