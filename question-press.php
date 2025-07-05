<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           1.0.0
 * Author:            Himanshu
 */

if (!defined('ABSPATH')) exit;

// Define constants and include files
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-import-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-importer.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-export-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-questions-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-question-editor-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-settings-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-list-table.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-shortcodes.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-dashboard.php';
require_once QP_PLUGIN_DIR . 'api/class-qp-rest-api.php';


// Activation, Deactivation, Uninstall Hooks
function qp_activate_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects ( subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, subject_name VARCHAR(255) NOT NULL, description TEXT, PRIMARY KEY (subject_id) ) $charset_collate;";
    dbDelta($sql_subjects);
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized')) == 0) { $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized', 'description' => 'Default subject for questions without an assigned one.']); }

    $table_labels = $wpdb->prefix . 'qp_labels';
    $sql_labels = "CREATE TABLE $table_labels ( label_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, label_name VARCHAR(255) NOT NULL, label_color VARCHAR(7) NOT NULL DEFAULT '#cccccc', is_default BOOLEAN NOT NULL DEFAULT 0, description TEXT, PRIMARY KEY (label_id) ) $charset_collate;";
    dbDelta($sql_labels);
    $default_labels = [['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'], ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'], ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'], ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'], ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']];
    foreach ($default_labels as $label) { if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) { $wpdb->insert($table_labels, $label); } }

    // UPDATED: Table for Questions
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        custom_question_id BIGINT(20) UNSIGNED,
        group_id BIGINT(20) UNSIGNED,
        question_text LONGTEXT NOT NULL,
        question_text_hash VARCHAR(32) NOT NULL,
        is_pyq BOOLEAN NOT NULL DEFAULT 0,
        source_file VARCHAR(255),
        source_page INT,
        source_number INT,
        duplicate_of BIGINT(20) UNSIGNED DEFAULT NULL,
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'publish',
        PRIMARY KEY (question_id),
        UNIQUE KEY custom_question_id (custom_question_id),
        KEY group_id (group_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_questions);

    add_option('qp_next_custom_question_id', 1000, '', 'no');
    if (!get_option('qp_jwt_secret_key')) {
        add_option('qp_jwt_secret_key', wp_generate_password(64, true, true), '', 'no');
    }


    $table_options = $wpdb->prefix . 'qp_options';
    $sql_options = "CREATE TABLE $table_options ( option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, question_id BIGINT(20) UNSIGNED NOT NULL, option_text TEXT NOT NULL, is_correct BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (option_id), KEY question_id (question_id) ) $charset_collate;";
    dbDelta($sql_options);

    $table_question_labels = $wpdb->prefix . 'qp_question_labels';
    $sql_question_labels = "CREATE TABLE $table_question_labels ( question_id BIGINT(20) UNSIGNED NOT NULL, label_id BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (question_id, label_id), KEY label_id (label_id) ) $charset_collate;";
    dbDelta($sql_question_labels);

    $table_sessions = $wpdb->prefix . 'qp_user_sessions';
    $sql_sessions = "CREATE TABLE $table_sessions ( session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT(20) UNSIGNED NOT NULL, start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, end_time DATETIME, total_attempted INT, correct_count INT, incorrect_count INT, skipped_count INT, marks_obtained DECIMAL(10, 2), settings_snapshot TEXT, PRIMARY KEY (session_id), KEY user_id (user_id) ) $charset_collate;";
    dbDelta($sql_sessions);

    $table_attempts = $wpdb->prefix . 'qp_user_attempts';
    $sql_attempts = "CREATE TABLE $table_attempts ( attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, session_id BIGINT(20) UNSIGNED NOT NULL, user_id BIGINT(20) UNSIGNED NOT NULL, question_id BIGINT(20) UNSIGNED NOT NULL, is_correct BOOLEAN, attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (attempt_id), KEY session_id (session_id), KEY user_id (user_id), KEY question_id (question_id) ) $charset_collate;";
    dbDelta($sql_attempts);

    $table_logs = $wpdb->prefix . 'qp_logs';
    $sql_logs = "CREATE TABLE $table_logs ( log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, log_type VARCHAR(50) NOT NULL, log_message TEXT NOT NULL, log_data LONGTEXT, log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, resolved TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (log_id), KEY log_type (log_type), KEY resolved (resolved) ) $charset_collate;";
    dbDelta($sql_logs);

    
    
}
register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');

function qp_uninstall_plugin() {}
register_uninstall_hook(QP_PLUGIN_FILE, 'qp_uninstall_plugin');

// ADMIN MENU & SCRIPTS SETUP
function qp_admin_menu() {
    // Add top-level menu page and store the returned hook suffix
    $hook = add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);
    
    // Use this hook to add screen options
    add_action("load-{$hook}", 'qp_add_screen_options');
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-edit-group', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page('question-press', 'Import', 'Import', 'manage_options', 'qp-import', ['QP_Import_Page', 'render']);
    add_submenu_page('question-press', 'Export', 'Export', 'manage_options', 'qp-export', ['QP_Export_Page', 'render']);
    add_submenu_page('question-press', 'Subjects', 'Subjects', 'manage_options', 'qp-subjects', ['QP_Subjects_Page', 'render']);
    add_submenu_page('question-press', 'Labels', 'Labels', 'manage_options', 'qp-labels', ['QP_Labels_Page', 'render']);
    add_submenu_page('question-press', 'Logs', 'Logs', 'manage_options', 'qp-logs', ['QP_Logs_Page', 'render']);
    add_submenu_page('question-press', 'Settings', 'Settings', 'manage_options', 'qp-settings', ['QP_Settings_Page', 'render']);
}
add_action('admin_menu', 'qp_admin_menu');

// CORRECTED: Function to add screen options
function qp_add_screen_options() {
    $option = 'per_page';
    $args = [
        'label'   => 'Questions per page',
        'default' => 20,
        'option'  => 'qp_questions_per_page'
    ];
    add_screen_option($option, $args);
    new QP_Questions_List_Table(); // Instantiate table to register columns
}

// CORRECTED: Function to save the screen options
function qp_save_screen_options($status, $option, $value) {
    if ('qp_questions_per_page' === $option) {
        return $value;
    }
    return $status;
}
add_filter('set-screen-option', 'qp_save_screen_options', 10, 3);




function qp_admin_enqueue_scripts($hook_suffix) {
    if (strpos($hook_suffix, 'qp-') !== false || strpos($hook_suffix, 'question-press') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    if ($hook_suffix === 'question-press_page_qp-question-editor' || $hook_suffix === 'admin_page_qp-edit-group') {
        wp_enqueue_media();
        wp_enqueue_script('qp-media-uploader-script', QP_PLUGIN_URL . 'admin/assets/js/media-uploader.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('qp-editor-script', QP_PLUGIN_URL . 'admin/assets/js/question-editor.js', ['jquery'], '1.0.1', true);
    }
    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_PLUGIN_URL . 'admin/assets/js/quick-edit.js', ['jquery'], '1.0.1', true);
        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce')
        ]);
    }
    if ($hook_suffix === 'question-press_page_qp-labels') {
        add_action('admin_footer', function() {
            echo '<script>jQuery(document).ready(function($){$(".qp-color-picker").wpColorPicker();});</script>';
        });
    }

    // Load script for the Settings page
    if ($hook_suffix === 'question-press_page_qp-settings') {
        wp_enqueue_script('qp-settings-script', QP_PLUGIN_URL . 'admin/assets/js/settings-page.js', ['jquery'], '1.0.0', true);
    }
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions() {
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
    QP_Settings_Page::register_settings();
    qp_handle_clear_logs();
    qp_handle_resolve_log();
}
add_action('admin_init', 'qp_handle_form_submissions');

function qp_all_questions_page_cb() {
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
    global $wpdb;
    if (!empty($list_table->items)) {
        foreach ($list_table->items as &$item) {
            $item['group_id'] = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $item['question_id']));
        }
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">All Questions</h1>
        <a href="<?php echo admin_url('admin.php?page=qp-question-editor'); ?>" class="page-title-action">Add New</a>
        <?php if (isset($_GET['message'])) {
            $messages = ['1' => 'Question(s) updated successfully.', '2' => 'Question(s) saved successfully.'];
            $message_id = absint($_GET['message']);
            if (isset($messages[$message_id])) { echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message_id]) . '</p></div>'; }
        } ?>
        <hr class="wp-header-end">
        <?php $list_table->views(); ?>
        <form method="post">
            <?php wp_nonce_field('bulk-questions'); ?>
            <?php $list_table->search_box('Search Questions', 'question'); $list_table->display(); ?>
        </form>
    </div>
    <?php
}

// ADD THIS NEW HELPER FUNCTION anywhere in question-press.php
function get_question_custom_id($question_id) {
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
}


function qp_handle_save_question_group() {
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) { return; }
    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;
    $direction_text = sanitize_textarea_field($_POST['direction_text']);
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];
    if (empty($subject_id) || empty($questions_from_form)) { return; }

    $group_data = ['direction_text' => $direction_text, 'direction_image_id' => $direction_image_id, 'subject_id' => $subject_id];
    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }
    $q_table = "{$wpdb->prefix}qp_questions"; $o_table = "{$wpdb->prefix}qp_options"; $ql_table = "{$wpdb->prefix}qp_question_labels";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
$submitted_q_ids = [];
    if (!empty($questions_from_form)) {
        foreach ($questions_from_form as $q_index => $q_data) {
            $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
            $question_text = isset($q_data['question_text']) ? sanitize_textarea_field($q_data['question_text']) : '';

            if (empty($question_text)) {
                continue; // Skip empty questions
            }

            $question_db_data = [
                'group_id' => $group_id,
                'question_text' => $question_text,
                'is_pyq' => $is_pyq,
                'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))))
            ];

            if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
                // This is an existing question, so we UPDATE it.
                $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
                $submitted_q_ids[] = $question_id;
            } else {
                // This is a new question, so we INSERT it.
                $next_custom_id = get_option('qp_next_custom_question_id', 1000);
                $question_db_data['custom_question_id'] = $next_custom_id;
                $wpdb->insert($q_table, $question_db_data);
                $question_id = $wpdb->insert_id;
                update_option('qp_next_custom_question_id', $next_custom_id + 1);
                $submitted_q_ids[] = $question_id;
            }

            // --- Handle Options ---
            $wpdb->delete($o_table, ['question_id' => $question_id]);
            $options = isset($q_data['options']) ? (array) $q_data['options'] : [];
            $correct_option_index = isset($q_data['is_correct_option']) ? absint($q_data['is_correct_option']) : -1;
            foreach ($options as $index => $option_text) {
                if (!empty(trim($option_text))) {
                    $wpdb->insert($o_table, [
                        'question_id' => $question_id,
                        'option_text' => sanitize_text_field($option_text),
                        'is_correct' => ($index === $correct_option_index) ? 1 : 0
                    ]);
                }
            }

            // --- Handle Labels ---
            $wpdb->delete($ql_table, ['question_id' => $question_id]);
            $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
            foreach ($labels as $label_id) {
                $wpdb->insert($ql_table, ['question_id' => $question_id, 'label_id' => $label_id]);
            }
        }
    }


    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) { $ids_placeholder = implode(',', array_map('absint', $questions_to_delete)); $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)"); $wpdb->query("DELETE FROM $ql_table WHERE question_id IN ($ids_placeholder)"); $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)"); }
    // Determine the correct redirect URL and message
    if ($is_editing) {
        // Redirect to the "All Questions" list with an "updated" message
        $redirect_url = admin_url('admin.php?page=question-press&message=1');
    } else {
        // After creating a new group, redirect to the new group's editor page
        // with a "saved" message.
        $redirect_url = admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');
    }

    wp_safe_redirect($redirect_url);
    exit;
}

// Public-facing hooks and AJAX handlers
function qp_public_init() {
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);
}
add_action('init', 'qp_public_init');

function qp_public_enqueue_scripts() {
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard'))) {
        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], '1.0.3');
        $ajax_data = ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('qp_practice_nonce')];
        if (has_shortcode($post->post_content, 'question_press_practice')) {
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery'], '1.0.3', true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
        }
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery'], '1.0.0', true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');

// In question-press.php

function qp_start_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $settings_str = isset($_POST['settings']) ? $_POST['settings'] : '';
    $form_settings = [];
    parse_str($settings_str, $form_settings);

    $session_settings = [
        'subject_id'      => isset($form_settings['qp_subject']) ? $form_settings['qp_subject'] : '',
        'pyq_only'        => isset($form_settings['qp_pyq_only']),
        'revise_mode'     => isset($form_settings['qp_revise_mode']),
        'marks_correct'   => isset($form_settings['qp_marks_correct']) ? floatval($form_settings['qp_marks_correct']) : 4.0,
        'marks_incorrect' => isset($form_settings['qp_marks_incorrect']) ? -abs(floatval($form_settings['qp_marks_incorrect'])) : -1.0,
        'timer_enabled'   => isset($form_settings['qp_timer_enabled']),
        'timer_seconds'   => isset($form_settings['qp_timer_seconds']) ? absint($form_settings['qp_timer_seconds']) : 60
    ];

    if (empty($session_settings['subject_id'])) { wp_send_json_error(['message' => 'Please select a subject.']); }

    global $wpdb;
    $user_id = get_current_user_id();
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $l_table = $wpdb->prefix . 'qp_labels';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    $where_clauses = ["q.status = 'publish'"];
    $query_args = [];

    // Exclude questions needing review
    $review_label_ids = $wpdb->get_col("SELECT label_id FROM $l_table WHERE label_name IN ('Wrong Answer', 'No Answer')");
    if (!empty($review_label_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));
        $where_clauses[] = "q.question_id NOT IN (SELECT question_id FROM $ql_table WHERE label_id IN ($ids_placeholder))";
        $query_args = array_merge($query_args, $review_label_ids);
    }
    
    // --- NEW: Revision Mode Logic ---
    if ($session_settings['revise_mode']) {
        // If revision mode is on, select from questions the user has previously attempted.
        $where_clauses[] = $wpdb->prepare("q.question_id IN (SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d)", $user_id);
    }

    if ($session_settings['subject_id'] !== 'all') {
        $where_clauses[] = "g.subject_id = %d";
        $query_args[] = absint($session_settings['subject_id']);
    }
    if ($session_settings['pyq_only']) {
        $where_clauses[] = "q.is_pyq = 1";
    }

    $where_sql = implode(' AND ', $where_clauses);
    $query = "SELECT q.question_id FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id WHERE {$where_sql} ORDER BY RAND()";
    
    $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

    if (empty($question_ids)) {
        $message = $session_settings['revise_mode'] ? 'No previously attempted questions found for this criteria.' : 'No questions found matching your criteria.';
        wp_send_json_error(['message' => $message]);
    }
    
    // Create the session and send the response (this part is unchanged)
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $wpdb->insert($sessions_table, ['user_id' => $user_id, 'settings_snapshot' => wp_json_encode($session_settings)]);
    $session_id = $wpdb->insert_id;
    $response_data = ['ui_html' => QP_Shortcodes::render_practice_ui(), 'question_ids' => $question_ids, 'session_id' => $session_id, 'settings' => $session_settings];
    wp_send_json_success($response_data);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

// In question-press.php, REPLACE this function

// In question-press.php
function qp_get_question_data_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) { wp_send_json_error(['message' => 'Invalid Question ID.']); }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions'; $g_table = $wpdb->prefix . 'qp_question_groups'; $s_table = $wpdb->prefix . 'qp_subjects'; $o_table = $wpdb->prefix . 'qp_options'; $a_table = $wpdb->prefix . 'qp_user_attempts';

    $question_data = $wpdb->get_row($wpdb->prepare("SELECT q.custom_question_id, q.question_text, g.direction_text, g.direction_image_id, s.subject_name FROM {$q_table} q LEFT JOIN {$g_table} g ON q.group_id = g.group_id LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id WHERE q.question_id = %d", $question_id), ARRAY_A);
    if (!$question_data) { wp_send_json_error(['message' => 'Question not found.']); }
    
    // NEW: Get image URL if an ID exists
    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;

    $question_data['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY RAND()", $question_id), ARRAY_A);
    $user_id = get_current_user_id();
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d", $user_id, $question_id));
    
    wp_send_json_success(['question' => $question_data, 'is_revision' => ($attempt_count > 0)]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

function qp_check_answer_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;
    if (!$session_id || !$question_id || !$option_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
    global $wpdb;
    $o_table = $wpdb->prefix . 'qp_options'; $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));
    $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => $is_correct ? 1 : 0]);
    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

function qp_skip_question_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$session_id || !$question_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
    wp_send_json_success();
}
add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');


/**
 * AJAX handler for ending a practice session.
 */
function qp_end_practice_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session.']);
    }

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Get session settings to calculate score
    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
    $settings = json_decode($session->settings_snapshot, true);
    $marks_correct = isset($settings['marks_correct']) ? floatval($settings['marks_correct']) : 0;
    $marks_incorrect = isset($settings['marks_incorrect']) ? floatval($settings['marks_incorrect']) : 0;

    // Calculate stats from attempts
    $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
    $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
    $skipped_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct IS NULL", $session_id));
    $total_attempted = $correct_count + $incorrect_count;

    // Calculate final score
    $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

    // Update the session in the database with the final stats
    $wpdb->update($sessions_table, 
        [
            'end_time' => current_time('mysql', 1),
            'total_attempted' => $total_attempted,
            'correct_count' => $correct_count,
            'incorrect_count' => $incorrect_count,
            'skipped_count' => $skipped_count,
            'marks_obtained' => $final_score
        ],
        ['session_id' => $session_id]
    );

    // Send the final stats back to the frontend
    wp_send_json_success([
        'final_score' => $final_score,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
    ]);
}
add_action('wp_ajax_end_practice_session', 'qp_end_practice_session_ajax');


// ADD THIS NEW FUNCTION to the end of the file
function qp_delete_user_session_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));

    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'You do not have permission to delete this session.']);
    }

    // Delete the session and its related attempts
    $wpdb->delete($attempts_table, ['session_id' => $session_id], ['%d']);
    $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

    wp_send_json_success(['message' => 'Session deleted.']);
}
add_action('wp_ajax_delete_user_session', 'qp_delete_user_session_ajax');

/**
 * AJAX handler for reporting an issue with a question.
 */
// UPDATED: Report issue function
function qp_report_question_issue_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $report_label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : 'Incorrect Formatting';
    if (!$question_id) { wp_send_json_error(['message' => 'Invalid Question ID.']); }

    global $wpdb;
    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name = %s", $report_label_name));
    if (!$label_id) { wp_send_json_error(['message' => 'Reporting system not configured.']); }

    $already_assigned = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d AND label_id = %d", $question_id, $label_id));
    if ($already_assigned == 0) {
        $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => $label_id]);
        
        // CORRECTED: Use custom_question_id for the log message
        $custom_id = $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type' => 'Report',
            'log_message' => sprintf('User reported question #%s with label: %s', $custom_id, $report_label_name),
            'log_data' => wp_json_encode(['user_id' => get_current_user_id(), 'question_id' => $question_id])
        ]);
    }
    wp_send_json_success(['message' => 'Issue reported.']);
}
add_action('wp_ajax_report_question_issue', 'qp_report_question_issue_ajax');


// In question-press.php, ADD this new function

// UPDATED: Report and Skip function
function qp_report_and_skip_question_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : '';
    if (!$session_id || !$question_id || !$label_name) { wp_send_json_error(['message' => 'Invalid data.']); }

    global $wpdb;
    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name = %s", $label_name));
    if ($label_id) {
        $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => $label_id]);
        
        // CORRECTED: Use custom_question_id for the log message
        $custom_id = $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        $wpdb->insert("{$wpdb->prefix}qp_logs", [
            'log_type' => 'Report',
            'log_message' => sprintf('User reported question #%s with label: %s', $custom_id, $label_name),
            'log_data' => wp_json_encode(['user_id' => get_current_user_id(), 'session_id' => $session_id, 'question_id' => $question_id])
        ]);
    }
    
    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
    wp_send_json_success(['message' => 'Question reported and skipped.']);
}
add_action('wp_ajax_report_and_skip_question', 'qp_report_and_skip_question_ajax');


/**
 * AJAX handler for deleting a user's entire revision history.
 */
function qp_delete_revision_history_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to do this.']);
    }

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Delete all rows from the attempts table for the current user.
    $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);

    wp_send_json_success(['message' => 'Revision history deleted.']);
}
add_action('wp_ajax_delete_revision_history', 'qp_delete_revision_history_ajax');

// NEW: Function to handle clearing the logs
function qp_handle_clear_logs() {
    if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_clear_logs_nonce')) {
            wp_die('Security check failed.');
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
        wp_safe_redirect(admin_url('admin.php?page=qp-logs&message=1'));
        exit;
    }
}

// ADD this new function to the file
function qp_handle_resolve_log() {
    if (isset($_GET['action']) && $_GET['action'] === 'resolve_log' && isset($_GET['log_id'])) {
        $log_id = absint($_GET['log_id']);
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qp_resolve_log_' . $log_id)) {
            wp_die('Security check failed.');
        }
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}qp_logs", ['resolved' => 1], ['log_id' => $log_id]);
        
        // Redirect back to the page the user was on
        wp_safe_redirect(remove_query_arg(['action', 'log_id', '_wpnonce']));
        exit;
    }
}


// NEW: AJAX handler to fetch the Quick Edit form HTML
function qp_get_quick_edit_form_ajax() {
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) { wp_send_json_error(); }

    global $wpdb;
    // Fetch all necessary data
    $question = $wpdb->get_row($wpdb->prepare("SELECT q.question_text, q.is_pyq, g.subject_id FROM {$wpdb->prefix}qp_questions q LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id WHERE q.question_id = %d", $question_id));
    $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
    $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
    $current_labels = $wpdb->get_col($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d", $question_id));
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    ob_start();
    ?>
    <form class="quick-edit-form-wrapper">
        <h4>Quick Edit: <span class="title"><?php echo esc_html(wp_trim_words($question->question_text, 10, '...')); ?></span></h4>
        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">
        <?php wp_nonce_field('qp_quick_edit_save_nonce'); ?>
        
        <div class="form-row">
            <label class="form-label"><strong>Correct Answer</strong></label>
            <div class="options-group">
                <?php foreach ($options as $index => $option): ?>
                <label class="option-label">
                    <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                    <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-row form-row-flex">
            <div class="form-group-half">
                <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>">
                    <?php foreach ($all_subjects as $subject) : ?>
                        <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $question->subject_id); ?>><?php echo esc_html($subject->subject_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group-half form-group-center-align">
                <label class="inline-checkbox"><input type="checkbox" name="is_pyq" value="1" <?php checked($question->is_pyq, 1); ?>> Is PYQ?</label>
            </div>
        </div>
        <div class="form-row">
            <label class="form-label"><strong>Labels</strong></label>
            <div class="labels-group">
                <?php foreach ($all_labels as $label) : ?>
                    <label class="inline-checkbox"><input type="checkbox" name="labels[]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_labels)); ?>> <?php echo esc_html($label->label_name); ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <p class="submit inline-edit-save">
            <button type="button" class="button-secondary cancel">Cancel</button>
            <button type="button" class="button-primary save">Update</button>
        </p>
    </form>
    <style>
        .quick-edit-form-wrapper { padding: 1rem; }
        .quick-edit-form-wrapper .form-row { margin-bottom: 1rem; }
        .quick-edit-form-wrapper .form-row-flex { display: flex; gap: 1rem; align-items: flex-end; }
        .quick-edit-form-wrapper .form-group-half { flex: 1; }
        .quick-edit-form-wrapper .form-group-center-align { padding-bottom: 5px; }
        .quick_edit_form-wrapper .form-label, .quick-edit-form-wrapper .title, .quick-edit-form-wrapper strong { font-weight: 600; display: block; margin-bottom: .5rem;}
        .quick-edit-form-wrapper textarea, .quick-edit-form-wrapper select, .quick-edit-form-wrapper .options-group input[type="text"] { width: 100%; }
        .quick-edit-form-wrapper .options-group .option-label { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
        .quick-edit-form-wrapper .labels-group { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding: .5rem; border: 1px solid #ddd; background: #fff; }
        .quick-edit-form-wrapper .inline-checkbox { white-space: nowrap; }
    </style>
     <?php
    wp_send_json_success(['form' => ob_get_clean()]);
}
add_action('wp_ajax_get_quick_edit_form', 'qp_get_quick_edit_form_ajax');

// NEW: AJAX handler to save the Quick Edit data
function qp_save_quick_edit_data_ajax() {
    check_ajax_referer('qp_save_quick_edit_nonce', 'nonce');
    
    // The form data is sent as a serialized string, so we need to parse it first.
    parse_str($_POST['form_data'], $data);

    // Now, get the question_id from the parsed data.
    $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID in form data.']);
    }
    
    global $wpdb;
    
// Update Question PYQ status and last modified date
    $wpdb->update("{$wpdb->prefix}qp_questions", [
        'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
        'last_modified' => current_time('mysql') // NEW: Update the modified time
    ], ['question_id' => $question_id]);

    // Update Subject
    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
    if ($group_id) { $wpdb->update("{$wpdb->prefix}qp_question_groups", ['subject_id' => absint($data['subject_id'])], ['group_id' => $group_id]); }
    
    // Update which option is correct
    $correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
    if ($correct_option_id) {
        // Set all options for this question to incorrect first
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 0], ['question_id' => $question_id]);
        // Set the selected option to correct
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 1], ['option_id' => $correct_option_id, 'question_id' => $question_id]);
    }

    // Update Labels
    $wpdb->delete("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id]);
    if (!empty($data['labels'])) {
        foreach ($data['labels'] as $label_id) {
            $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => absint($label_id)]);
        }
    }

    // Fetch the updated row HTML to send back
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
    $found_item = null;
    foreach ($list_table->items as $item) {
        if ($item['question_id'] == $question_id) {
            $found_item = $item;
            break;
        }
    }
    
    if ($found_item) {
        $found_item['group_id'] = $group_id;
        ob_start();
        $list_table->single_row($found_item);
        $row_html = ob_get_clean();
        wp_send_json_success(['row_html' => $row_html]);
    }

    wp_send_json_error(['message' => 'Could not retrieve updated row.']);
}
add_action('wp_ajax_save_quick_edit_data', 'qp_save_quick_edit_data_ajax');


/**
 * Initialize all plugin features that hook into WordPress.
 */
function qp_init_plugin() {
    QP_Rest_Api::init();
}
add_action('init', 'qp_init_plugin');

// NEW: Add this function to the end of the file
function qp_regenerate_api_key_ajax() {
    check_ajax_referer('qp_regenerate_api_key_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $new_key = wp_generate_password(64, true, true);
    update_option('qp_jwt_secret_key', $new_key);

    wp_send_json_success(['new_key' => $new_key]);
}
add_action('wp_ajax_regenerate_api_key', 'qp_regenerate_api_key_ajax');


