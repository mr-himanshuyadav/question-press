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
            <?php
                settings_errors();
            ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('qp_settings_group');
                // Add the top save button
                submit_button('Save Settings', 'primary', 'submit_top', false);
                do_settings_sections('qp-settings-page');
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
        add_settings_field('qp_question_order', 'Question Order in Practice', [self::class, 'render_question_order_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_source_meta', 'Display Source Meta', [self::class, 'render_show_source_meta_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_delete_on_uninstall', 'Delete Data on Uninstall', [self::class, 'render_delete_data_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        
        add_settings_section('qp_api_settings_section', 'REST API Documentation', [self::class, 'render_api_section_text'], 'qp-settings-page');
        add_settings_field('qp_api_secret_key', 'JWT Secret Key', [self::class, 'render_api_key_field'], 'qp-settings-page', 'qp_api_settings_section');
    }

    

    /**
     * Callback to render the description for the data section.
     */
    public static function render_data_section_text() {
        echo '<p>General plugin and data management settings.</p>';
    }
    
    /**
     * Callback to render the description for the API section.
     */
    public static function render_api_section_text() {
        echo '<p>Use these endpoints and the secret key to connect your mobile application to this website.</p>';
    }

    public static function render_question_order_input() {
        $options = get_option('qp_settings');
        $value = isset($options['question_order']) ? $options['question_order'] : 'random';
        ?>
        <fieldset>
            <label>
                <input type="radio" name="qp_settings[question_order]" value="random" <?php checked('random', $value); ?>>
                <span>Random</span>
            </label><br>
            <label>
                <input type="radio" name="qp_settings[question_order]" value="in_order" <?php checked('in_order', $value); ?>>
                <span>In Order (by Question ID)</span>
            </label>
            <p class="description">Choose how questions are ordered when a user starts a practice session.</p>
        </fieldset>
        <?php
    }

    public static function render_show_source_meta_checkbox() {
        $options = get_option('qp_settings');
        $checked = isset($options['show_source_meta']) ? $options['show_source_meta'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[show_source_meta]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Check this box to display the source file, page, and number to administrators on the practice screen.</span></label>';
    }

    public static function render_delete_data_checkbox() {
        $options = get_option('qp_settings');
        $checked = isset($options['delete_on_uninstall']) ? $options['delete_on_uninstall'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[delete_on_uninstall]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Check this box to permanently delete all questions, subjects, labels, and user history when the plugin is uninstalled. This action cannot be undone.</span></label>';
    }

    public static function render_api_key_field() {
        $key = get_option('qp_jwt_secret_key');
        $nonce = wp_create_nonce('qp_regenerate_api_key_nonce');
        ?>
        <input type="text" id="qp-api-secret-key-field" value="<?php echo esc_attr($key); ?>" readonly style="width: 100%; max-width: 500px; background: #f0f0f1;">
        <button id="qp-regenerate-api-key" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">Regenerate Secret Key</button>
        <hr>
        <h3>API Endpoints</h3>
        <p>Base URL: <code><?php echo esc_url(get_site_url()); ?>/wp-json/questionpress/v1</code></p>
        
        <div class="api-doc-section">
            <h4>Authentication</h4>
            <p><strong>Endpoint:</strong> <code>POST /token</code></p>
            <p>Public endpoint to authenticate a user. Returns a JWT token upon success.</p>
            <p><strong>Body (form-data):</strong></p>
            <ul>
                <li><code>username</code>: The user's WordPress username.</li>
                <li><code>password</code>: The user's WordPress password.</li>
            </ul>
            <p><strong>Success Response (200):</strong></p>
            <pre>{ "token": "...", "user_email": "...", "user_display_name": "..." }</pre>
        </div>
        
        <div class="api-doc-section">
            <h4>Get Subjects</h4>
            <p><strong>Endpoint:</strong> <code>GET /subjects</code></p>
            <p>Returns a list of all available subjects. Requires authentication.</p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer [YOUR_TOKEN]</code></p>
            <p><strong>Success Response (200):</strong></p>
            <pre>[ { "subject_id": "1", "subject_name": "Physics" }, ... ]</pre>
        </div>

        <div class="api-doc-section">
            <h4>Start Session</h4>
            <p><strong>Endpoint:</strong> <code>POST /start-session</code></p>
            <p>Returns a randomized list of question IDs based on user settings. Requires authentication.</p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer [YOUR_TOKEN]</code></p>
            <p><strong>Body (form-data):</strong></p>
            <ul>
                <li><code>subject_id</code>: (Required) The ID of the subject, or 'all'.</li>
                <li><code>pyq_only</code>: (Optional) Set to `true` to only get PYQs.</li>
            </ul>
            <p><strong>Success Response (200):</strong></p>
            <pre>{ "question_ids": [105, 42, 113, ...] }</pre>
        </div>

        <div class="api-doc-section">
            <h4>Get Single Question</h4>
            <p><strong>Endpoint:</strong> <code>GET /question/id/&lt;custom_id&gt;</code></p>
            <p>Returns the full data for a single question using its custom ID. Requires authentication.</p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer [YOUR_TOKEN]</code></p>
            <p><strong>Success Response (200):</strong></p>
            <pre>{ "custom_question_id": "1001", "question_text": "...", "direction_text": "...", ... }</pre>
        </div>
        <style> .api-doc-section { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #ddd; } .api-doc-section pre { background: #f6f7f7; padding: 1rem; border-radius: 4px; } </style>
        <?php
    }

    public static function sanitize_settings($input) {
        $new_input = [];
        if (isset($input['delete_on_uninstall'])) { $new_input['delete_on_uninstall'] = absint($input['delete_on_uninstall']); }
        
        if (isset($input['question_order']) && in_array($input['question_order'], ['random', 'in_order'])) {
            $new_input['question_order'] = sanitize_text_field($input['question_order']);
        } else {
            $new_input['question_order'] = 'random';
        }

        $new_input['show_source_meta'] = isset($input['show_source_meta']) ? 1 : 0;
        
        return $new_input;
    }
}