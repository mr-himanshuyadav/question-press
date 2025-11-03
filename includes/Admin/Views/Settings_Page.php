<?php

namespace QuestionPress\Admin\Views;

if (!defined('ABSPATH')) exit;

use QuestionPress\Utils\Template_Loader;

class Settings_Page
{

    public static function render()
    {
        // Display settings errors and messages first, outside the template
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        settings_errors();

        // --- Capture WordPress settings functions output ---
        
        // Capture settings_fields()
        ob_start();
        settings_fields('qp_settings_group');
        $settings_fields_html = ob_get_clean();

        // Capture top submit_button()
        ob_start();
        submit_button('Save Settings', 'primary', 'submit_top', false);
        $submit_button_top = ob_get_clean();
        
        // Capture do_settings_sections()
        ob_start();
        do_settings_sections('qp-settings-page');
        $sections_html = ob_get_clean();

        // Capture bottom submit_button()
        ob_start();
        submit_button('Save Settings');
        $submit_button_bottom = ob_get_clean();
        
        // --- End capturing ---

        // Prepare arguments for the template
        $args = [
            'settings_fields_html' => $settings_fields_html,
            'submit_button_top'    => $submit_button_top,
            'sections_html'        => $sections_html,
            'submit_button_bottom' => $submit_button_bottom,
        ];
        
        // Load and echo the template
        echo Template_Loader::get_html( 'settings-page-wrapper', 'admin', $args );
    }

    /**
     * Registers all settings, sections, and fields.
     */
    public static function register_settings()
    {
        register_setting('qp_settings_group', 'qp_settings', ['sanitize_callback' => [self::class, 'sanitize_settings']]);

        // Page Settings Section
        add_settings_section('qp_page_settings_section', 'Page Settings', [self::class, 'render_page_section_text'], 'qp-settings-page');
        add_settings_field('qp_practice_page', 'Practice Page', [self::class, 'render_practice_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_dashboard_page', 'Dashboard Page', [self::class, 'render_dashboard_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_review_page', 'Session Review Page', [self::class, 'render_review_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_session_page', 'Session Page', [self::class, 'render_session_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');
        add_settings_field('qp_signup_page', 'Signup Page', [self::class, 'render_signup_page_dropdown'], 'qp-settings-page', 'qp_page_settings_section');

        // Data Management Section
        add_settings_section('qp_data_settings_section', 'Data Management', [self::class, 'render_data_section_text'], 'qp-settings-page');
        add_settings_field('qp_question_order', 'Question Order in Practice', [self::class, 'render_question_order_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_source_meta_roles', 'Display Source Meta To', [self::class, 'render_source_meta_role_multiselect'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_delete_on_uninstall', 'Delete Data on Uninstall', [self::class, 'render_delete_data_checkbox'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_session_timeout', 'Session Timeout (Minutes)', [self::class, 'render_session_timeout_input'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_show_question_counts', 'Show Unattempted Counts', [self::class, 'render_show_question_counts_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        add_settings_field('qp_show_topic_meta', 'Display Topic Meta', [self::class, 'render_show_topic_meta_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        add_settings_field('qp_normal_practice_limit', 'Normal Practice Question Limit', [self::class, 'render_normal_practice_limit_input'], 'qp-settings-page', 'qp_data_settings_section');


        add_settings_field('qp_can_delete_history_roles', 'Roles That Can Delete History', [self::class, 'render_can_delete_history_roles_multiselect'], 'qp-settings-page', 'qp_data_settings_section');
        add_settings_field('qp_allow_session_termination', 'Allow Session Termination', [self::class, 'render_allow_session_termination_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        add_settings_field('qp_allow_course_opt_out', 'Allow Course Opt-Out', [self::class, 'render_allow_course_opt_out_checkbox'], 'qp-settings-page', 'qp_data_settings_section');

        // API Section
        add_settings_section('qp_api_settings_section', 'REST API Documentation', [self::class, 'render_api_section_text'], 'qp-settings-page');
        add_settings_field('qp_api_secret_key', 'JWT Secret Key', [self::class, 'render_api_key_field'], 'qp-settings-page', 'qp_api_settings_section');
    }

    /**
     * Callback to render the description for the page settings section.
     */
    public static function render_page_section_text()
    {
        echo '<p>Select the pages where you have placed the Question Press shortcodes. This ensures all links and redirects work correctly.</p>';
    }

    /**
     * Callback to render the practice page dropdown.
     */
    public static function render_practice_page_dropdown()
    {
        $options = get_option('qp_settings');
        $selected = isset($options['practice_page']) ? $options['practice_page'] : 0;
        wp_dropdown_pages([
            'name' => 'qp_settings[practice_page]',
            'selected' => $selected,
            'show_option_none' => '— Select a Page —',
            'option_none_value' => '0'
        ]);
        echo '<p class="description">Select the page that contains the <code>[question_press_practice]</code> shortcode.</p>';
    }

    /**
     * Callback to render the dashboard page dropdown.
     */
    public static function render_dashboard_page_dropdown()
    {
        $options = get_option('qp_settings');
        $selected = isset($options['dashboard_page']) ? $options['dashboard_page'] : 0;
        wp_dropdown_pages([
            'name' => 'qp_settings[dashboard_page]',
            'selected' => $selected,
            'show_option_none' => '— Select a Page —',
            'option_none_value' => '0'
        ]);
        echo '<p class="description">Select the page that contains the <code>[question_press_dashboard]</code> shortcode.</p>';
    }

    public static function render_session_page_dropdown()
    {
        $options = get_option('qp_settings');
        $selected = isset($options['session_page']) ? $options['session_page'] : 0;
        wp_dropdown_pages([
            'name' => 'qp_settings[session_page]',
            'selected' => $selected,
            'show_option_none' => '— Select a Page —',
            'option_none_value' => '0'
        ]);
        echo '<p class="description">Select the page that will contain the <code>[question_press_session]</code> shortcode. This is where users will be redirected to take their test.</p>';
    }

    /**
     * Callback to render the description for the data section.
     */
    public static function render_data_section_text()
    {
        echo '<p>General plugin and data management settings.</p>';
    }

    public static function render_session_timeout_input()
    {
        $options = get_option('qp_settings');
        $timeout = isset($options['session_timeout']) ? $options['session_timeout'] : 20;
        echo '<input type="number" name="qp_settings[session_timeout]" value="' . esc_attr($timeout) . '" min="5" /> ';
        echo '<p class="description">Automatically mark a session as "abandoned" if there is no activity for this many minutes. Minimum: 5 minutes.</p>';
    }

    public static function render_show_question_counts_checkbox()
    {
        $options = get_option('qp_settings');
        $checked = isset($options['show_question_counts']) ? $options['show_question_counts'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[show_question_counts]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Display the number of unattempted questions next to subjects, topics, and sections on the practice form.</span></label>';
        echo '<p class="description">Note: Enabling this may slightly increase the form loading time for users with a large history.</p>';
    }

    public static function render_show_topic_meta_checkbox()
    {
        $options = get_option('qp_settings');
        $checked = isset($options['show_topic_meta']) ? $options['show_topic_meta'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[show_topic_meta]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Display the topic/subject lineage on the session page.</span></label>';
    }

    public static function render_can_delete_history_roles_multiselect()
    {
        $options = get_option('qp_settings');
        $selected_roles = isset($options['can_delete_history_roles']) && is_array($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : [];

        echo '<fieldset><div style="display: flex; flex-wrap: wrap; gap: 10px 20px;">';
        // Ensure administrator is always selected and disabled
        echo '<label><input type="checkbox" name="qp_settings[can_delete_history_roles][]" value="administrator" checked disabled /> ';
        echo '<span>Administrator (Always allowed)</span></label>';

        foreach (get_editable_roles() as $role_slug => $role_info) {
            if ($role_slug === 'administrator') {
                continue; // Skip administrator as it's handled above
            }
            $checked = in_array($role_slug, $selected_roles);
            echo '<label><input type="checkbox" name="qp_settings[can_delete_history_roles][]" value="' . esc_attr($role_slug) . '" ' . checked(true, $checked, false) . ' /> ';
            echo '<span>' . esc_html($role_info['name']) . '</span></label>';
        }
        echo '</div></fieldset>';
        echo '<p class="description">Select the user roles that are allowed to delete their own session history from the dashboard.</p>';
    }

    /**
     * Callback to render the description for the API section.
     */
    public static function render_api_section_text()
    {
        echo '<p>Use these endpoints and the secret key to connect your mobile application to this website.</p>';
    }

    public static function render_question_order_input()
    {
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

    public static function render_source_meta_role_multiselect()
    {
        $options = get_option('qp_settings');
        $selected_roles = isset($options['show_source_meta_roles']) && is_array($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];

        echo '<fieldset><div style="display: flex; flex-wrap: wrap; gap: 10px 20px;">';
        foreach (get_editable_roles() as $role_slug => $role_info) {
            $checked = in_array($role_slug, $selected_roles);
            echo '<label><input type="checkbox" name="qp_settings[show_source_meta_roles][]" value="' . esc_attr($role_slug) . '" ' . checked(true, $checked, false) . ' /> ';
            echo '<span>' . esc_html($role_info['name']) . '</span></label>';
        }
        echo '</div></fieldset>';
        echo '<p class="description">Select the user roles that can see the source file, page, and number on the practice screen.</p>';
    }

    public static function render_delete_data_checkbox()
    {
        $options = get_option('qp_settings');
        $checked = isset($options['delete_on_uninstall']) ? $options['delete_on_uninstall'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[delete_on_uninstall]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Check this box to permanently delete all questions, subjects, labels, and user history when the plugin is uninstalled. This action cannot be undone.</span></label>';
    }


    public static function render_api_key_field()
    {
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
            <p><strong>Endpoint:</strong> <code>GET /question/id/&lt;question_id&gt;</code></p>
            <p>Returns the full data for a single question using its question ID. Requires authentication.</p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer [YOUR_TOKEN]</code></p>
            <p><strong>Success Response (200):</strong></p>
            <pre>{ "question_id": "1001", "question_text": "...", "direction_text": "...", ... }</pre>
        </div>
        <style>
            .api-doc-section {
                margin-top: 1.5rem;
                padding-top: 1.5rem;
                border-top: 1px solid #ddd;
            }

            .api-doc-section pre {
                background: #f6f7f7;
                padding: 1rem;
                border-radius: 4px;
            }
        </style>
    <?php
    }

    public static function sanitize_settings($input)
    {
        $new_input = [];

        if (isset($input['practice_page'])) {
            $new_input['practice_page'] = absint($input['practice_page']);
        }
        if (isset($input['dashboard_page'])) {
            $new_input['dashboard_page'] = absint($input['dashboard_page']);
        }
        if (isset($input['session_page'])) {
            $new_input['session_page'] = absint($input['session_page']);
        }

        if (isset($input['signup_page'])) {
            $new_input['signup_page'] = absint($input['signup_page']);
        }

        if (isset($input['delete_on_uninstall'])) {
            $new_input['delete_on_uninstall'] = absint($input['delete_on_uninstall']);
        }

        if (isset($input['question_order']) && in_array($input['question_order'], ['random', 'in_order', 'user_input'])) {
            $new_input['question_order'] = sanitize_text_field($input['question_order']);
        } else {
            $new_input['question_order'] = 'random';
        }
        if (isset($input['show_source_meta_roles']) && is_array($input['show_source_meta_roles'])) {
            $new_input['show_source_meta_roles'] = array_map('sanitize_key', $input['show_source_meta_roles']);
        } else {
            $new_input['show_source_meta_roles'] = [];
        }

        if (isset($input['session_timeout'])) {
            $new_input['session_timeout'] = absint($input['session_timeout']) >= 5 ? absint($input['session_timeout']) : 20;
        }
        if (isset($input['show_question_counts'])) {
            $new_input['show_question_counts'] = absint($input['show_question_counts']);
        } else {
            $new_input['show_question_counts'] = 0;
        }
        if (isset($input['show_topic_meta'])) {
            $new_input['show_topic_meta'] = absint($input['show_topic_meta']);
        } else {
            $new_input['show_topic_meta'] = 0;
        }
        if (isset($input['review_page'])) {
            $new_input['review_page'] = absint($input['review_page']);
        }

        if (isset($input['can_delete_history_roles']) && is_array($input['can_delete_history_roles'])) {
            // Add administrator back in since disabled fields aren't submitted
            $new_input['can_delete_history_roles'] = array_map('sanitize_key', $input['can_delete_history_roles']);
            if (!in_array('administrator', $new_input['can_delete_history_roles'])) {
                $new_input['can_delete_history_roles'][] = 'administrator';
            }
        } else {
            // If nothing is selected, default to only administrator
            $new_input['can_delete_history_roles'] = ['administrator'];
        }

        if (isset($input['allow_session_termination'])) {
            $new_input['allow_session_termination'] = absint($input['allow_session_termination']);
        } else {
            $new_input['allow_session_termination'] = 0;
        }

        if (isset($input['allow_course_opt_out'])) {
            $new_input['allow_course_opt_out'] = absint($input['allow_course_opt_out']);
        } else {
            $new_input['allow_course_opt_out'] = 0;
        }

        if (isset($input['normal_practice_limit'])) {
            $limit = absint($input['normal_practice_limit']);
            // Ensure the limit is at least 10, default to 100 if not
            $new_input['normal_practice_limit'] = ($limit >= 10) ? $limit : 100;
        } else {
            $new_input['normal_practice_limit'] = 100; // Default if not set
        }

        return $new_input;
    }

    public static function render_review_page_dropdown()
    {
        $options = get_option('qp_settings');
        $selected = isset($options['review_page']) ? $options['review_page'] : 0;
        wp_dropdown_pages([
            'name' => 'qp_settings[review_page]',
            'selected' => $selected,
            'show_option_none' => '— Select a Page —',
            'option_none_value' => '0'
        ]);
        echo '<p class="description">Select the page that contains the <code>[question_press_review]</code> shortcode.</p>';
    }

    public static function render_allow_session_termination_checkbox()
    {
        $options = get_option('qp_settings');
        $checked = isset($options['allow_session_termination']) ? $options['allow_session_termination'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[allow_session_termination]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Allow users to "Terminate" their own active/paused sessions from the dashboard.</span></label>';
        echo '<p class="description">Note: Mock tests can never be terminated by a user, only submitted.</p>';
    }

    public static function render_normal_practice_limit_input()
    {
        $options = get_option('qp_settings');
        $limit = isset($options['normal_practice_limit']) ? $options['normal_practice_limit'] : 100;
        echo '<input type="number" name="qp_settings[normal_practice_limit]" value="' . esc_attr($limit) . '" min="10" max="500" /> ';
        echo '<p class="description">Set the maximum number of questions a "Normal Practice" session can have (e.g., 100). This does not affect Mock Tests or Revision Mode.</p>';
    }

    public static function render_allow_course_opt_out_checkbox()
    {
        $options = get_option('qp_settings');
        $checked = isset($options['allow_course_opt_out']) ? $options['allow_course_opt_out'] : 0;
        echo '<label><input type="checkbox" name="qp_settings[allow_course_opt_out]" value="1" ' . checked(1, $checked, false) . ' /> ';
        echo '<span>Allow users to "Deregister" (opt-out) from courses?</span></label>';
        echo '<p class="description">If enabled, you must also enable this on a per-course basis.</p>';
    }

    /**
     * Callback to render the signup page dropdown.
     */
    public static function render_signup_page_dropdown()
    {
        $options = get_option('qp_settings');
        $selected = isset($options['signup_page']) ? $options['signup_page'] : 0;
        wp_dropdown_pages([
            'name' => 'qp_settings[signup_page]',
            'selected' => $selected,
            'show_option_none' => '— Select a Page —',
            'option_none_value' => '0'
        ]);
        echo '<p class="description">Select the page that contains the <code>[question_press_signup]</code> shortcode.</p>';
    }
}
