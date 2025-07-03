<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       question-press
 */

if (!defined('ABSPATH')) exit;

// Define constants
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

// Include class files
require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-import-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-importer.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-export-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-questions-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-question-editor-page.php';


// Activation/Deactivation/Uninstall Hooks
// Activation/Deactivation/Uninstall Hooks
function qp_activate_plugin() { 
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // ... (All other table schemas are unchanged) ...
    // Table for Subjects
    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects ( subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, subject_name VARCHAR(255) NOT NULL, description TEXT, PRIMARY KEY (subject_id) ) $charset_collate;";
    dbDelta($sql_subjects);
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized')) == 0) { $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized', 'description' => 'Default subject for questions without an assigned one.']); }
    // Table for Labels
    $table_labels = $wpdb->prefix . 'qp_labels';
    $sql_labels = "CREATE TABLE $table_labels ( label_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, label_name VARCHAR(255) NOT NULL, label_color VARCHAR(7) NOT NULL DEFAULT '#cccccc', is_default BOOLEAN NOT NULL DEFAULT 0, description TEXT, PRIMARY KEY (label_id) ) $charset_collate;";
    dbDelta($sql_labels);
    $default_labels = [['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'], ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'], ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'], ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'], ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']];
    foreach ($default_labels as $label) { if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) { $wpdb->insert($table_labels, $label); } }
    // Table for Question Groups (Directions)
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups ( group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, direction_text LONGTEXT, direction_image_id BIGINT(20) UNSIGNED, subject_id BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (group_id), KEY subject_id (subject_id) ) $charset_collate;";
    dbDelta($sql_groups);

    // UPDATED Table for Questions
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
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'publish',
        PRIMARY KEY (question_id),
        UNIQUE KEY custom_question_id (custom_question_id),
        KEY group_id (group_id),
        KEY status (status),
        KEY is_pyq (is_pyq),
        KEY question_text_hash (question_text_hash)
    ) $charset_collate;";
    dbDelta($sql_questions);
    
    // ... (All other table schemas are unchanged) ...
    // Table for Options
    $table_options = $wpdb->prefix . 'qp_options'; $sql_options = "CREATE TABLE $table_options ( option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, question_id BIGINT(20) UNSIGNED NOT NULL, option_text TEXT NOT NULL, is_correct BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (option_id), KEY question_id (question_id) ) $charset_collate;"; dbDelta($sql_options);
    // Table for Question Labels
    $table_question_labels = $wpdb->prefix . 'qp_question_labels'; $sql_question_labels = "CREATE TABLE $table_question_labels ( question_id BIGINT(20) UNSIGNED NOT NULL, label_id BIGINT(20) UNSIGNED NOT NULL, PRIMARY KEY (question_id, label_id), KEY label_id (label_id) ) $charset_collate;"; dbDelta($sql_question_labels);
    // Table for User Practice Sessions
    $table_sessions = $wpdb->prefix . 'qp_user_sessions'; $sql_sessions = "CREATE TABLE $table_sessions ( session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT(20) UNSIGNED NOT NULL, start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, end_time DATETIME, total_attempted INT, correct_count INT, incorrect_count INT, skipped_count INT, marks_obtained DECIMAL(10, 2), settings_snapshot TEXT, PRIMARY KEY (session_id), KEY user_id (user_id) ) $charset_collate;"; dbDelta($sql_sessions);
    // Table for User Question Attempts
    $table_attempts = $wpdb->prefix . 'qp_user_attempts'; $sql_attempts = "CREATE TABLE $table_attempts ( attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, session_id BIGINT(20) UNSIGNED NOT NULL, user_id BIGINT(20) UNSIGNED NOT NULL, question_id BIGINT(20) UNSIGNED NOT NULL, is_correct BOOLEAN, attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (attempt_id), KEY session_id (session_id), KEY user_id (user_id), KEY question_id (question_id) ) $charset_collate;"; dbDelta($sql_attempts);
    // Table for Logs
    $table_logs = $wpdb->prefix . 'qp_logs'; $sql_logs = "CREATE TABLE $table_logs ( log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, log_type VARCHAR(50) NOT NULL, log_message TEXT NOT NULL, log_data LONGTEXT, log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (log_id), KEY log_type (log_type) ) $charset_collate;"; dbDelta($sql_logs);

    // NEW: Initialize our custom ID counter
    add_option('qp_next_custom_question_id', 1000, '', 'no');
}
register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');

function qp_uninstall_plugin() {}
register_uninstall_hook(QP_PLUGIN_FILE, 'qp_uninstall_plugin');

// ADMIN MENU & SCRIPTS SETUP
function qp_admin_menu() {
    add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    // Unified editor page for both Add New and Edit
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page('question-press', 'Import', 'Import', 'manage_options', 'qp-import', ['QP_Import_Page', 'render']);
    add_submenu_page('question-press', 'Export', 'Export', 'manage_options', 'qp-export', ['QP_Export_Page', 'render']);
    add_submenu_page('question-press', 'Subjects', 'Subjects', 'manage_options', 'qp-subjects', ['QP_Subjects_Page', 'render']);
    add_submenu_page('question-press', 'Labels', 'Labels', 'manage_options', 'qp-labels', ['QP_Labels_Page', 'render']);
}
add_action('admin_menu', 'qp_admin_menu');

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
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions() {
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
}
add_action('admin_init', 'qp_handle_form_submissions');

function qp_all_questions_page_cb() {
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
    global $wpdb;
    foreach ($list_table->items as &$item) {
        $item['group_id'] = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $item['question_id']));
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">All Questions</h1>
        <a href="<?php echo admin_url('admin.php?page=qp-question-editor'); ?>" class="page-title-action">Add New</a>
        <?php if (isset($_GET['message'])) {
            $messages = ['1' => 'Question(s) updated successfully.', '2' => 'Question(s) saved successfully.'];
            $message_id = absint($_GET['message']);
            if (isset($messages[$message_id])) {
                echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message_id]) . '</p></div>';
            }
        } ?>
        <hr class="wp-header-end">
        <form method="post">
            <?php wp_nonce_field('bulk-questions'); $list_table->display(); ?>
        </form>
    </div>
    <?php
}

// REWRITTEN & ROBUST SAVE LOGIC
// In question-press.php

// UPDATED SAVE LOGIC
function qp_handle_save_question_group() {
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    $direction_text = sanitize_textarea_field($_POST['direction_text']);
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $labels = isset($_POST['labels']) ? array_map('absint', $_POST['labels']) : [];
    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($subject_id) || empty($questions_from_form)) { return; }

    // Group Handling
    $group_data = ['direction_text' => $direction_text, 'direction_image_id' => $direction_image_id, 'subject_id' => $subject_id];
    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }

    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $ql_table = "{$wpdb->prefix}qp_question_labels";
    
    if ($is_editing) {
        $existing_q_ids = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id));
        if (!empty($existing_q_ids)) {
            $ids_placeholder = implode(',', array_map('absint', $existing_q_ids));
            $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
            $wpdb->query("DELETE FROM $ql_table WHERE question_id IN ($ids_placeholder)");
            $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
        }
    }

    foreach ($questions_from_form as $q_data) {
        $question_text = sanitize_textarea_field($q_data['question_text']);
        if (empty($question_text)) continue;
        
        // --- NEW: Get and increment the custom question ID ---
        $next_custom_id = get_option('qp_next_custom_question_id', 1000);

        $wpdb->insert($q_table, [
            'custom_question_id' => $next_custom_id, // Add the new ID
            'group_id' => $group_id,
            'question_text' => $question_text,
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
            'is_pyq' => $is_pyq
        ]);
        $question_id = $wpdb->insert_id;
        
        update_option('qp_next_custom_question_id', $next_custom_id + 1);

        $options = isset($q_data['options']) ? (array) $q_data['options'] : [];
        $correct_option_index = isset($q_data['is_correct_option']) ? absint($q_data['is_correct_option']) : -1;
        foreach ($options as $index => $option_text) {
            if (!empty(trim($option_text))) {
                $wpdb->insert($o_table, ['question_id' => $question_id, 'option_text' => sanitize_text_field($option_text), 'is_correct' => ($index === $correct_option_index) ? 1 : 0 ]);
            }
        }

        foreach ($labels as $label_id) {
            $wpdb->insert($ql_table, ['question_id' => $question_id, 'label_id' => $label_id]);
        }
    }
    
    // Redirection Logic
    if ($is_editing) {
        wp_safe_redirect(admin_url('admin.php?page=question-press&message=1'));
    } else {
        wp_safe_redirect(admin_url('admin.php?page=qp-question-editor&message=2'));
    }
    exit;
}