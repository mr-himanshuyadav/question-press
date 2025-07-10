<?php

/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           1.1.2
 * Author:            Himanshu
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ABSPATH')) exit;

// Define constants and include files
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-topics-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-exams-page.php'; // <-- ADD THIS
require_once QP_PLUGIN_DIR . 'admin/class-qp-sources-page.php';
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

    // Table: Subjects
    $table_subjects = $wpdb->prefix . 'qp_subjects';
    $sql_subjects = "CREATE TABLE $table_subjects (
        subject_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subject_name VARCHAR(255) NOT NULL,
        description TEXT,
        PRIMARY KEY (subject_id)
    ) $charset_collate;";
    dbDelta($sql_subjects);
    if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_subjects WHERE subject_name = %s", 'Uncategorized')) == 0) {
        $wpdb->insert($table_subjects, ['subject_name' => 'Uncategorized', 'description' => 'Default subject for questions without an assigned one.']);
    }

    // *** NEW: Table for Topics ***
    $table_topics = $wpdb->prefix . 'qp_topics';
    $sql_topics = "CREATE TABLE $table_topics (
        topic_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        topic_name VARCHAR(255) NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (topic_id),
        KEY subject_id (subject_id)
    ) $charset_collate;";
    dbDelta($sql_topics);
    // --- NEW: Table for Exams ---
    $table_exams = $wpdb->prefix . 'qp_exams';
    $sql_exams = "CREATE TABLE $table_exams (
    exam_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_name VARCHAR(255) NOT NULL,
    PRIMARY KEY (exam_id)
) $charset_collate;";
    dbDelta($sql_exams);

    // --- NEW: Pivot Table for Exam-Subject relationship ---
    $table_exam_subjects = $wpdb->prefix . 'qp_exam_subjects';
    $sql_exam_subjects = "CREATE TABLE $table_exam_subjects (
        exam_id BIGINT(20) UNSIGNED NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (exam_id, subject_id),
        KEY subject_id (subject_id)
    ) $charset_collate;";
    dbDelta($sql_exam_subjects);

    // --- NEW: Table for Sources ---
    $table_sources = $wpdb->prefix . 'qp_sources';
    $sql_sources = "CREATE TABLE $table_sources (
    source_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_id BIGINT(20) UNSIGNED NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    description TEXT,
    PRIMARY KEY (source_id),
    KEY subject_id (subject_id)
) $charset_collate;";
    dbDelta($sql_sources);

    // --- NEW: Table for Source Sections ---
    $table_source_sections = $wpdb->prefix . 'qp_source_sections';
    $sql_source_sections = "CREATE TABLE $table_source_sections (
        section_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        source_id BIGINT(20) UNSIGNED NOT NULL,
        section_name VARCHAR(255) NOT NULL,
        PRIMARY KEY (section_id),
        KEY source_id (source_id)
    ) $charset_collate;";
    dbDelta($sql_source_sections);


    // Table: Labels
    $table_labels = $wpdb->prefix . 'qp_labels';
    $sql_labels = "CREATE TABLE $table_labels (
        label_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        label_name VARCHAR(255) NOT NULL,
        label_color VARCHAR(7) NOT NULL DEFAULT '#cccccc',
        is_default BOOLEAN NOT NULL DEFAULT 0,
        description TEXT,
        PRIMARY KEY (label_id)
    ) $charset_collate;";
    dbDelta($sql_labels);
    $default_labels = [
        ['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'],
        ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'],
        ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'],
        ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'],
        ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']
    ];
    foreach ($default_labels as $label) {
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) {
            $wpdb->insert($table_labels, $label);
        }
    }

    // --- UPDATED: Question Groups table with PYQ columns ---
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups (
        group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        direction_text LONGTEXT,
        direction_image_id BIGINT(20) UNSIGNED,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        is_pyq BOOLEAN NOT NULL DEFAULT 0,
        exam_id BIGINT(20) UNSIGNED DEFAULT NULL,
        pyq_year VARCHAR(4) DEFAULT NULL,
        PRIMARY KEY (group_id),
        KEY subject_id (subject_id),
        KEY is_pyq (is_pyq)
    ) $charset_collate;";
    dbDelta($sql_groups);

    // --- UPDATED: Questions table with PYQ columns removed ---
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        custom_question_id BIGINT(20) UNSIGNED,
        group_id BIGINT(20) UNSIGNED,
        topic_id BIGINT(20) UNSIGNED DEFAULT NULL,
        source_id BIGINT(20) UNSIGNED DEFAULT NULL,
        section_id BIGINT(20) UNSIGNED DEFAULT NULL,
        question_number_in_section VARCHAR(20) DEFAULT NULL,
        question_text LONGTEXT NOT NULL,
        question_text_hash VARCHAR(32) NOT NULL,
        duplicate_of BIGINT(20) UNSIGNED DEFAULT NULL,
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'publish',
        last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (question_id),
        UNIQUE KEY custom_question_id (custom_question_id),
        KEY group_id (group_id),
        KEY topic_id (topic_id),
        KEY source_id (source_id),
        KEY section_id (section_id),
        KEY status (status),
        KEY question_text_hash (question_text_hash)
    ) $charset_collate;";
    dbDelta($sql_questions);

    // Table: Options
    $table_options = $wpdb->prefix . 'qp_options';
    $sql_options = "CREATE TABLE $table_options (
        option_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        option_text TEXT NOT NULL,
        is_correct BOOLEAN NOT NULL DEFAULT 0,
        PRIMARY KEY (option_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_options);

    // Table: Question Labels
    $table_question_labels = $wpdb->prefix . 'qp_question_labels';
    $sql_question_labels = "CREATE TABLE $table_question_labels (
        question_id BIGINT(20) UNSIGNED NOT NULL,
        label_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (question_id, label_id),
        KEY label_id (label_id)
    ) $charset_collate;";
    dbDelta($sql_question_labels);

    // Table: User Sessions
    $table_sessions = $wpdb->prefix . 'qp_user_sessions';
    $sql_sessions = "CREATE TABLE $table_sessions (
        session_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        end_time DATETIME,
        total_attempted INT,
        correct_count INT,
        incorrect_count INT,
        skipped_count INT,
        marks_obtained DECIMAL(10, 2),
        settings_snapshot TEXT,
        PRIMARY KEY (session_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    dbDelta($sql_sessions);

    // Table: User Attempts
    $table_attempts = $wpdb->prefix . 'qp_user_attempts';
    $sql_attempts = "CREATE TABLE $table_attempts (
        attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        is_correct BOOLEAN,
        attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attempt_id),
        KEY session_id (session_id),
        KEY user_id (user_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_attempts);

    // Table: Logs
    $table_logs = $wpdb->prefix . 'qp_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        log_type VARCHAR(50) NOT NULL,
        log_message TEXT NOT NULL,
        log_data LONGTEXT,
        log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (log_id),
        KEY log_type (log_type),
        KEY resolved (resolved)
    ) $charset_collate;";
    dbDelta($sql_logs);

    // Set default options
    add_option('qp_next_custom_question_id', 1000, '', 'no');
    if (!get_option('qp_jwt_secret_key')) {
        add_option('qp_jwt_secret_key', wp_generate_password(64, true, true), '', 'no');
    }
}

register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');


// In question-press.php, replace the existing qp_admin_menu function
function qp_admin_menu()
{
    // Add top-level menu page for "All Questions" and store the hook
    $hook = add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);

    // Use this hook to add screen options for the questions list table
    add_action("load-{$hook}", 'qp_add_screen_options');

    // Primary Submenu Pages
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);

    // --- NEW: Unified Organization Page ---
    add_submenu_page('question-press', 'Organize', 'Organize', 'manage_options', 'qp-organization', 'qp_render_organization_page');

    add_submenu_page('question-press', 'Import', 'Import', 'manage_options', 'qp-import', ['QP_Import_Page', 'render']);
    add_submenu_page('question-press', 'Export', 'Export', 'manage_options', 'qp-export', ['QP_Export_Page', 'render']);
    add_submenu_page('question-press', 'Logs', 'Logs', 'manage_options', 'qp-logs', ['QP_Logs_Page', 'render']);
    add_submenu_page('question-press', 'Settings', 'Settings', 'manage_options', 'qp-settings', ['QP_Settings_Page', 'render']);

    // Hidden pages (for editing links and backwards compatibility)
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-edit-group', ['QP_Question_Editor_Page', 'render']);
}
add_action('admin_menu', 'qp_admin_menu');


function qp_render_organization_page()
{
    $tabs = [
        'subjects' => ['label' => 'Subjects', 'callback' => ['QP_Subjects_Page', 'render']],
        'topics'   => ['label' => 'Topics', 'callback' => ['QP_Topics_Page', 'render']],
        'labels'   => ['label' => 'Labels', 'callback' => ['QP_Labels_Page', 'render']],
        'exams'    => ['label' => 'Exams', 'callback' => ['QP_Exams_Page', 'render']],
        'sources'  => ['label' => 'Sources', 'callback' => ['QP_Sources_Page', 'render']],
    ];
    $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'subjects';
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Organize</h1>
        <p>Organize you questions using different taxanomies here.</p>
        <hr class="wp-header-end">

        <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
            <?php
            foreach ($tabs as $tab_id => $tab_data) {
                $class = ($tab_id === $active_tab) ? ' nav-tab-active' : '';
                echo '<a href="?page=qp-organization&tab=' . esc_attr($tab_id) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($tab_data['label']) . '</a>';
            }
            ?>
        </nav>

        <div class="tab-content" style="margin-top: 1.5rem;">
            <?php
            // Call the render method for the active tab
            call_user_func($tabs[$active_tab]['callback']);
            ?>
        </div>
    </div>
<?php
}

// CORRECTED: Function to add screen options
function qp_add_screen_options()
{
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
function qp_save_screen_options($status, $option, $value)
{
    if ('qp_questions_per_page' === $option) {
        return $value;
    }
    return $status;
}
add_filter('set-screen-option', 'qp_save_screen_options', 10, 3);




function qp_admin_enqueue_scripts($hook_suffix)
{
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
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_PLUGIN_URL . 'admin/assets/js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
    if ($hook_suffix === 'question-press_page_qp-labels') {
        add_action('admin_footer', function () {
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
function qp_handle_form_submissions()
{
    if (isset($_GET['page']) && $_GET['page'] === 'qp-organization') {
        QP_Sources_Page::handle_forms(); // <-- ADD THIS LINE
        QP_Topics_Page::handle_forms();
        QP_Subjects_Page::handle_forms(); // <-- ADD THIS LINE
        QP_Labels_Page::handle_forms();
        QP_Exams_Page::handle_forms();
    }
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
    qp_handle_topic_forms();
    QP_Settings_Page::register_settings();
    qp_handle_clear_logs();
    qp_handle_resolve_log();
}
add_action('admin_init', 'qp_handle_form_submissions');
add_action('admin_init', 'qp_run_source_data_migration');
add_action('admin_notices', 'qp_show_migration_admin_notice');
add_action('admin_init', 'qp_run_details_data_migration');
add_action('admin_notices', 'qp_show_details_migration_notice');
add_action('admin_init', 'qp_run_orphan_source_migration');
add_action('admin_notices', 'qp_show_orphan_source_notice');

// *** ADD THIS ENTIRE NEW FUNCTION ***
function qp_handle_topic_forms()
{
    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';

    // Handle Add Topic
    if (isset($_POST['add_topic']) && check_admin_referer('qp_add_topic_nonce')) {
        $topic_name = sanitize_text_field($_POST['topic_name']);
        $subject_id = absint($_POST['subject_id']);
        if (!empty($topic_name) && $subject_id > 0) {
            $wpdb->insert($topics_table, ['topic_name' => $topic_name, 'subject_id' => $subject_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }

    // Handle Update Topic
    if (isset($_POST['update_topic']) && isset($_POST['topic_id']) && check_admin_referer('qp_update_topic_nonce')) {
        $topic_id = absint($_POST['topic_id']);
        $topic_name = sanitize_text_field($_POST['topic_name']);
        $subject_id = absint($_POST['subject_id']);
        if (!empty($topic_name) && $subject_id > 0) {
            $wpdb->update($topics_table, ['topic_name' => $topic_name, 'subject_id' => $subject_id], ['topic_id' => $topic_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }

    // Handle Delete Topic
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['topic_id'])) {
        $topic_id = absint($_GET['topic_id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_delete_topic_' . $topic_id)) {
            $wpdb->delete($topics_table, ['topic_id' => $topic_id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-topics'));
        exit;
    }
}

function qp_all_questions_page_cb()
{
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
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
        <?php $list_table->views(); ?>
        <form method="post">
            <?php wp_nonce_field('bulk-questions'); ?>
            <?php $list_table->search_box('Search Questions', 'question');
            $list_table->display(); ?>
        </form>
        <style type="text/css">
            .wp-list-table .column-custom_question_id {
                width: 10%;
            }

            .wp-list-table .column-question_text {
                width: 50%;
            }

            .wp-list-table .column-subject_name {
                width: 15%;
            }

            .wp-list-table .column-source {
                width: 15%;
            }

            .wp-list-table .column-import_date {
                width: 10%;
            }

            .wp-list-table.questions #the-list tr td {
                border-bottom: 1px solid rgb(174, 174, 174);
            }
        </style>
    </div>
<?php
}

// ADD THIS NEW HELPER FUNCTION anywhere in question-press.php
function get_question_custom_id($question_id)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
}


function qp_handle_save_question_group() {
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    // --- Get all data from the metaboxes ---
    $direction_text = isset($_POST['direction_text']) ? stripslashes($_POST['direction_text']) : '';
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $section_id = isset($_POST['section_id']) ? absint($_POST['section_id']) : 0;
    $question_num = isset($_POST['question_number_in_section']) ? sanitize_text_field($_POST['question_number_in_section']) : '';
    
    // --- UPDATED: Get group-level PYQ data from the form ---
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $exam_id = isset($_POST['exam_id']) ? absint($_POST['exam_id']) : 0;
    $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';


    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($subject_id) || empty($questions_from_form)) {
        // You might want to add an admin notice here for failed validation
        return;
    }

    // --- UPDATED: Group Data now includes the PYQ fields ---
    $group_data = [
        'direction_text' => sanitize_textarea_field($direction_text),
        'direction_image_id' => $direction_image_id,
        'subject_id' => $subject_id,
        'is_pyq' => $is_pyq,
        'exam_id' => $is_pyq && $exam_id > 0 ? $exam_id : null,
        'pyq_year' => $is_pyq ? $pyq_year : null,
    ];

    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }

    // --- Process Questions ---
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $ql_table = "{$wpdb->prefix}qp_question_labels";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
        if (empty(trim($question_text))) continue;

        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        
        // --- UPDATED: This data applies to EVERY question in the group, PYQ fields removed ---
        $question_db_data = [
            'group_id' => $group_id,
            'question_text' => sanitize_textarea_field($question_text),
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
            'topic_id' => $topic_id > 0 ? $topic_id : null,
            'source_id' => $source_id > 0 ? $source_id : null,
            'section_id' => $section_id > 0 ? $section_id : null,
            'question_number_in_section' => $question_num,
        ];

        if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
            // Update existing question
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
            $submitted_q_ids[] = $question_id;
        } else {
            // Insert new question
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            $question_db_data['custom_question_id'] = $next_custom_id;
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id;
            update_option('qp_next_custom_question_id', $next_custom_id + 1);
            $submitted_q_ids[] = $question_id;
        }

        // --- Process Options ---
        $wpdb->delete($o_table, ['question_id' => $question_id]);
        $options = isset($q_data['options']) ? (array)$q_data['options'] : [];
        $correct_option_index = isset($q_data['is_correct_option']) ? absint($q_data['is_correct_option']) : -1;
        foreach ($options as $index => $option_text) {
            if (!empty(trim($option_text))) {
                $wpdb->insert($o_table, [
                    'question_id' => $question_id,
                    'option_text' => sanitize_text_field(stripslashes($option_text)),
                    'is_correct' => ($index === $correct_option_index) ? 1 : 0
                ]);
            }
        }

        // --- Process Labels ---
        $wpdb->delete($ql_table, ['question_id' => $question_id]);
        $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
        foreach ($labels as $label_id) {
            $wpdb->insert($ql_table, ['question_id' => $question_id, 'label_id' => $label_id]);
        }
    }

    // --- Clean up removed questions ---
    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $ql_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
    }
    
    // --- Delete the group if it becomes empty ---
    if ($is_editing && empty($submitted_q_ids)) {
        $wpdb->delete("{$wpdb->prefix}qp_question_groups", ['group_id' => $group_id]);
        wp_safe_redirect(admin_url('admin.php?page=question-press&message=1'));
        exit;
    }

    // --- Redirect on success ---
    $redirect_url = $is_editing ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1') : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');
    wp_safe_redirect($redirect_url);
    exit;
}

// Public-facing hooks and AJAX handlers
function qp_public_init()
{
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);
}
add_action('init', 'qp_public_init');

function qp_public_enqueue_scripts()
{
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard'))) {

        // --- Cache Busting Logic ---
        $css_file_path = QP_PLUGIN_DIR . 'public/assets/css/practice.css';
        $css_version = file_exists($css_file_path) ? filemtime($css_file_path) : '1.0.0';

        $practice_js_file_path = QP_PLUGIN_DIR . 'public/assets/js/practice.js';
        $practice_js_version = file_exists($practice_js_file_path) ? filemtime($practice_js_file_path) : '1.0.0';

        $dashboard_js_file_path = QP_PLUGIN_DIR . 'public/assets/js/dashboard.js';
        $dashboard_js_version = file_exists($dashboard_js_file_path) ? filemtime($dashboard_js_file_path) : '1.0.0';
        // --- End of Cache Busting Logic ---

        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], $css_version);

        // Get dynamic URLs from settings
        $options = get_option('qp_settings');
        $practice_page_id = isset($options['practice_page']) ? absint($options['practice_page']) : 0;
        $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;

        $practice_page_url = $practice_page_id ? get_permalink($practice_page_id) : home_url('/');
        $dashboard_page_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/');

        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'practice_page_url'  => $practice_page_url,
            'dashboard_page_url' => $dashboard_page_url
        ];

        if (has_shortcode($post->post_content, 'question_press_practice')) {
            // KaTeX styles and scripts
            wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
            wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
            wp_enqueue_script('katex-mhchem', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/mhchem.min.js', ['katex-js'], '0.16.9', true);
            wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-mhchem'], '0.16.9', true);

            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery', 'katex-auto-render'], $practice_js_version, true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
        }

        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery'], $dashboard_js_version, true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');


// *** ADD THIS ENTIRE NEW FUNCTION ***
function qp_get_topics_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_id) {
        wp_send_json_error(['message' => 'Invalid subject ID.']);
    }

    global $wpdb;
    $topics_table = $wpdb->prefix . 'qp_topics';
    $topics = $wpdb->get_results($wpdb->prepare("SELECT topic_id, topic_name FROM $topics_table WHERE subject_id = %d ORDER BY topic_name ASC", $subject_id));

    wp_send_json_success(['topics' => $topics]);
}
add_action('wp_ajax_get_topics_for_subject', 'qp_get_topics_for_subject_ajax');

function qp_start_practice_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $settings_str = isset($_POST['settings']) ? $_POST['settings'] : '';
    $form_settings = [];
    parse_str($settings_str, $form_settings);

    $session_settings = [
        'subject_id'      => isset($form_settings['qp_subject']) ? $form_settings['qp_subject'] : '',
        'topic_id'        => isset($form_settings['qp_topic']) ? $form_settings['qp_topic'] : 'all',
        'sheet_label_id'  => isset($form_settings['qp_sheet_label']) ? $form_settings['qp_sheet_label'] : 'all', // NEW: Get sheet label ID
        'pyq_only'        => isset($form_settings['qp_pyq_only']),
        'revise_mode'     => isset($form_settings['qp_revise_mode']),
        'marks_correct'   => isset($form_settings['qp_marks_correct']) ? floatval($form_settings['qp_marks_correct']) : 4.0,
        'marks_incorrect' => isset($form_settings['qp_marks_incorrect']) ? -abs(floatval($form_settings['qp_marks_incorrect'])) : -1.0,
        'timer_enabled'   => isset($form_settings['qp_timer_enabled']),
        'timer_seconds'   => isset($form_settings['qp_timer_seconds']) ? absint($form_settings['qp_timer_seconds']) : 60
    ];

    if (empty($session_settings['subject_id'])) {
        wp_send_json_error(['message' => 'Please select a subject.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $l_table = $wpdb->prefix . 'qp_labels';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    $base_where_clauses = ["q.status = 'publish'"];
    $query_args = [];
    $joins = "LEFT JOIN {$g_table} g ON q.group_id = g.group_id"; // Start with base join

    $review_label_ids = $wpdb->get_col("SELECT label_id FROM $l_table WHERE label_name IN ('Wrong Answer', 'No Answer')");
    if (!empty($review_label_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($review_label_ids), '%d'));
        $base_where_clauses[] = "q.question_id NOT IN (SELECT question_id FROM $ql_table WHERE label_id IN ($ids_placeholder))";
        $query_args = array_merge($query_args, $review_label_ids);
    }

    if ($session_settings['subject_id'] !== 'all') {
        $base_where_clauses[] = "g.subject_id = %d";
        $query_args[] = absint($session_settings['subject_id']);
    }

    if ($session_settings['topic_id'] !== 'all' && is_numeric($session_settings['topic_id'])) {
        $base_where_clauses[] = "q.topic_id = %d";
        $query_args[] = absint($session_settings['topic_id']);
    }

    // NEW: Add join and filter for sheet label if selected
    if ($session_settings['sheet_label_id'] !== 'all' && is_numeric($session_settings['sheet_label_id'])) {
        $joins .= " JOIN {$ql_table} ql ON q.question_id = ql.question_id";
        $base_where_clauses[] = "ql.label_id = %d";
        $query_args[] = absint($session_settings['sheet_label_id']);
    }

    if ($session_settings['pyq_only']) {
        $base_where_clauses[] = "q.is_pyq = 1";
    }

    $base_where_sql = implode(' AND ', $base_where_clauses);

    $final_where_clauses = $base_where_clauses;
    if ($session_settings['revise_mode']) {
        $final_where_clauses[] = $wpdb->prepare("q.question_id IN (SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d)", $user_id);
    } else {
        $final_where_clauses[] = $wpdb->prepare("q.question_id NOT IN (SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d)", $user_id);
    }

    $final_where_sql = implode(' AND ', $final_where_clauses);

    $options = get_option('qp_settings');
    $question_order = isset($options['question_order']) ? $options['question_order'] : 'random';
    $order_by_sql = ($question_order === 'in_order') ? 'ORDER BY q.custom_question_id ASC' : 'ORDER BY RAND()';

    $query = "SELECT DISTINCT q.question_id FROM {$q_table} q {$joins} WHERE {$final_where_sql} {$order_by_sql}";

    $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

    if (empty($question_ids)) {
        $total_questions_matching_criteria = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(DISTINCT q.question_id) FROM {$q_table} q {$joins} WHERE " . $base_where_sql, $query_args)
        );

        $error_code = 'NO_QUESTIONS_EXIST';
        if ($session_settings['revise_mode']) {
            $error_code = 'NO_REVISION_QUESTIONS';
        } else if ($total_questions_matching_criteria > 0) {
            $error_code = 'ALL_ATTEMPTED';
        }

        wp_send_json_error(['error_code' => $error_code]);
    }

    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $wpdb->insert($sessions_table, ['user_id' => $user_id, 'settings_snapshot' => wp_json_encode($session_settings)]);
    $session_id = $wpdb->insert_id;
    $response_data = ['ui_html' => QP_Shortcodes::render_practice_ui(), 'question_ids' => $question_ids, 'session_id' => $session_id, 'settings' => $session_settings];
    wp_send_json_success($response_data);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // We can re-use the function from our shortcode class to get the form HTML
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}
add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax');

// In question-press.php, REPLACE this function

function qp_get_question_data_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID.']);
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $s_table = $wpdb->prefix . 'qp_subjects';
    $t_table = $wpdb->prefix . 'qp_topics'; // Topic table
    $o_table = $wpdb->prefix . 'qp_options';
    $a_table = $wpdb->prefix . 'qp_user_attempts';

    // *** UPDATED QUERY to include topic_name ***
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.custom_question_id, q.question_text, q.source_file, q.source_page, q.source_number, 
                g.direction_text, g.direction_image_id, s.subject_name, t.topic_name 
         FROM {$q_table} q 
         LEFT JOIN {$g_table} g ON q.group_id = g.group_id 
         LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
         LEFT JOIN {$t_table} t ON q.topic_id = t.topic_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    $options = get_option('qp_settings');
    $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
    $user = wp_get_current_user();
    $user_can_view = !empty(array_intersect($allowed_roles, $user->roles));


    if (!$user_can_view) {
        unset($question_data['source_file']);
        unset($question_data['source_page']);
        unset($question_data['source_number']);
    }

    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;

    $question_data['options'] = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY RAND()", $question_id), ARRAY_A);
    $user_id = get_current_user_id();
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d", $user_id, $question_id));

    wp_send_json_success(['question' => $question_data, 'is_revision' => ($attempt_count > 0), 'is_admin' => $user_can_view]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

function qp_check_answer_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;
    if (!$session_id || !$question_id || !$option_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }
    global $wpdb;
    $o_table = $wpdb->prefix . 'qp_options';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));
    $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => $is_correct ? 1 : 0]);
    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

// function qp_skip_question_ajax() {
//     check_ajax_referer('qp_practice_nonce', 'nonce');
//     $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
//     $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
//     if (!$session_id || !$question_id) { wp_send_json_error(['message' => 'Invalid data submitted.']); }
//     global $wpdb;
//     $attempts_table = $wpdb->prefix . 'qp_user_attempts';
//     $wpdb->insert($attempts_table, ['session_id' => $session_id, 'user_id' => get_current_user_id(), 'question_id' => $question_id, 'is_correct' => null]);
//     wp_send_json_success();
// }
// add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');


/**
 * AJAX handler for ending a practice session.
 */
function qp_end_practice_session_ajax()
{
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
    $wpdb->update(
        $sessions_table,
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
function qp_delete_user_session_ajax()
{
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
function qp_report_question_issue_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $report_label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : 'Incorrect Formatting';
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID.']);
    }

    global $wpdb;
    $label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_labels WHERE label_name = %s", $report_label_name));
    if (!$label_id) {
        wp_send_json_error(['message' => 'Reporting system not configured.']);
    }

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
function qp_report_and_skip_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $label_name = isset($_POST['label_name']) ? sanitize_text_field($_POST['label_name']) : '';
    if (!$session_id || !$question_id || !$label_name) {
        wp_send_json_error(['message' => 'Invalid data.']);
    }

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
 * AJAX handler for deleting a user's entire revision and session history.
 */
function qp_delete_revision_history_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error(['message' => 'You must be logged in to do this.']);
    }

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // Delete all rows from the attempts table for the current user.
    $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);

    // ALSO, delete all rows from the sessions table for the current user.
    $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

    wp_send_json_success(['message' => 'Your practice and revision history has been successfully deleted.']);
}
add_action('wp_ajax_delete_revision_history', 'qp_delete_revision_history_ajax');

// NEW: Function to handle clearing the logs
function qp_handle_clear_logs()
{
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
function qp_handle_resolve_log()
{
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


function qp_get_quick_edit_form_ajax()
{
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error();
    }

    global $wpdb;
    // ... (database queries and data preparation remain the same) ...
    $question = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, q.is_pyq, q.topic_id, g.subject_id 
         FROM {$wpdb->prefix}qp_questions q 
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id 
         WHERE q.question_id = %d",
        $question_id
    ));
    $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
    $all_topics = $wpdb->get_results("SELECT topic_id, topic_name, subject_id FROM {$wpdb->prefix}qp_topics ORDER BY topic_name ASC");
    $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
    $current_labels = $wpdb->get_col($wpdb->prepare("SELECT label_id FROM {$wpdb->prefix}qp_question_labels WHERE question_id = %d", $question_id));
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    $topics_by_subject = [];
    foreach ($all_topics as $topic) {
        if (!isset($topics_by_subject[$topic->subject_id])) {
            $topics_by_subject[$topic->subject_id] = [];
        }
        $topics_by_subject[$topic->subject_id][] = ['id' => $topic->topic_id, 'name' => $topic->topic_name];
    }

    ob_start();
?>
    <script>
        var qp_quick_edit_topics_data = <?php echo json_encode($topics_by_subject); ?>;
        var qp_current_topic_id = <?php echo json_encode($question->topic_id); ?>;
    </script>
    <form class="quick-edit-form-wrapper">
        <h4><span style="font-weight: 700;">Question:</span> <span class="title"><?php echo esc_html(wp_trim_words($question->question_text, 10, '...')); ?></span></h4>
        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

        <div class="quick-edit-main-container">
            <div class="quick-edit-col-left">
                <label><strong>Correct Answer</strong></label>
                <div class="options-group">
                    <?php foreach ($options as $index => $option): ?>
                        <label class="option-label">
                            <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                            <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="quick-edit-col-right">
                <div class="form-row-flex">
                    <div class="form-group-half">
                        <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                        <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>" class="qe-subject-select">
                            <?php foreach ($all_subjects as $subject) : ?>
                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $question->subject_id); ?>><?php echo esc_html($subject->subject_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-half">
                        <label for="qe-topic-<?php echo esc_attr($question_id); ?>"><strong>Topic</strong></label>
                        <select name="topic_id" id="qe-topic-<?php echo esc_attr($question_id); ?>" class="qe-topic-select" disabled>
                            <option value="">— Select subject first —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <label class="inline-checkbox"><input type="checkbox" name="is_pyq" value="1" <?php checked($question->is_pyq, 1); ?>> Is PYQ?</label>
                </div>
                <div class="form-row">
                    <label><strong>Labels</strong></label>
                    <div class="labels-group">
                        <?php foreach ($all_labels as $label) : ?>
                            <label class="inline-checkbox"><input type="checkbox" name="labels[]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_labels)); ?>> <?php echo esc_html($label->label_name); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <p class="submit inline-edit-save">
            <button type="button" class="button-secondary cancel">Cancel</button>
            <button type="button" class="button-primary save">Update</button>
        </p>
    </form>

    <style>
        .quick-edit-form-wrapper h4 {
            font-size: 16px;
            /* Increased font size for visibility */
            margin-top: 20px;
            margin-bottom: 10px;
            Padding: 10px 20px;
        }

        .inline-edit-row .submit {
            padding: 20px;
        }

        .quick-edit-form-wrapper .title {
            font-size: 15px;
            /* Also increased */
            font-weight: 500;
            color: #555;
        }

        .quick-edit-form-wrapper .form-row,
        .quick-edit-form-wrapper .form-row-flex {
            margin-bottom: 1rem;
        }

        .quick-edit-form-wrapper .form-row:last-child {
            margin-bottom: 0;
        }

        .quick-edit-form-wrapper strong,
        .quick-edit-form-wrapper label {
            font-weight: 600;
            display: block;
            margin-bottom: .5rem;
        }

        .quick-edit-form-wrapper select {
            width: 100%;
        }

        .quick-edit-main-container {
            display: flex;
            gap: 20px;
            margin-bottom: 1rem;
            padding: 0px 20px;
        }

        .quick-edit-col-left {
            flex: 0 0 40%;
        }

        .quick-edit-col-right {
            flex: 1;
        }

        .options-group {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: space-between;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff;
            height: 100%;
            box-sizing: border-box;
        }

        .options-group label:last-child {
            margin-bottom: 0;
        }

        .option-label {
            display: flex;
            align-items: center;
            /* Vertically aligns the radio button and text */
            gap: .5rem;
            margin-bottom: .5rem;
        }

        /* Crucial fix for radio button alignment */
        .option-label input[type="radio"] {
            margin-top: 0;
            /* Resets default WordPress top margin on radio buttons */
            align-self: center;
        }

        .option-label input[type="text"] {
            width: 90%;
            background-color: #f0f0f1;
        }

        .form-row-flex {
            display: flex;
            gap: 1rem;
        }

        .form-group-half {
            flex: 1;
        }

        .quick-edit-form-wrapper p.submit button.button-secondary {
            margin-right: 10px;
        }

        .labels-group {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem 1rem;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff;
        }

        .inline-checkbox {
            white-space: nowrap;
        }
    </style>
    <?php
    wp_send_json_success(['form' => ob_get_clean()]);
}
add_action('wp_ajax_get_quick_edit_form', 'qp_get_quick_edit_form_ajax');

function qp_save_quick_edit_data_ajax()
{
    check_ajax_referer('qp_save_quick_edit_nonce', 'nonce');

    parse_str($_POST['form_data'], $data);

    $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID in form data.']);
    }

    global $wpdb;

    $wpdb->update("{$wpdb->prefix}qp_questions", [
        'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
        'topic_id' => isset($data['topic_id']) && $data['topic_id'] > 0 ? absint($data['topic_id']) : null,
        'last_modified' => current_time('mysql')
    ], ['question_id' => $question_id]);

    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
    if ($group_id) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", ['subject_id' => absint($data['subject_id'])], ['group_id' => $group_id]);
    }

    $correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
    if ($correct_option_id) {
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 0], ['question_id' => $question_id]);
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 1], ['option_id' => $correct_option_id, 'question_id' => $question_id]);
    }

    $wpdb->delete("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id]);
    if (!empty($data['labels'])) {
        foreach ($data['labels'] as $label_id) {
            $wpdb->insert("{$wpdb->prefix}qp_question_labels", ['question_id' => $question_id, 'label_id' => absint($label_id)]);
        }
    }

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
function qp_init_plugin()
{
    QP_Rest_Api::init();
}
add_action('init', 'qp_init_plugin');

// NEW: Add this function to the end of the file
function qp_regenerate_api_key_ajax()
{
    check_ajax_referer('qp_regenerate_api_key_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $new_key = wp_generate_password(64, true, true);
    update_option('qp_jwt_secret_key', $new_key);

    wp_send_json_success(['new_key' => $new_key]);
}
add_action('wp_ajax_regenerate_api_key', 'qp_regenerate_api_key_ajax');


function qp_admin_head_styles_for_list_table()
{
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_question-press') {
    ?>
        <style type="text/css">
            .qp-multi-select-dropdown {
                position: relative;
                display: inline-block;
                vertical-align: middle;
            }

            .qp-multi-select-list {
                display: none;
                position: absolute;
                background-color: white;
                border: 1px solid #ccc;
                z-index: 1000;
                padding: 10px;
                max-height: 250px;
                overflow-y: auto;
            }

            .qp-multi-select-list label {
                display: block;
                white-space: nowrap;
                padding: 5px;
            }

            .qp-multi-select-list label:hover {
                background-color: #f1f1f1;
            }
        </style>
    <?php
    }
}
add_action('admin_head', 'qp_admin_head_styles_for_list_table');

/**
 * AJAX handler to get "Sheet" labels relevant to a specific topic.
 */
function qp_get_sheets_for_topic_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$topic_id) {
        wp_send_json_success(['labels' => []]); // Send empty array if no topic is selected
        return;
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $l_table = $wpdb->prefix . 'qp_labels';
    $ql_table = $wpdb->prefix . 'qp_question_labels';

    // This query finds labels that start with "Sheet" AND are linked to at least one question with the given topic_id.
    $sheet_labels = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT l.label_id, l.label_name
         FROM {$l_table} l
         JOIN {$ql_table} ql ON l.label_id = ql.label_id
         JOIN {$q_table} q ON ql.question_id = q.question_id
         WHERE l.label_name LIKE %s
         AND q.topic_id = %d
         ORDER BY l.label_name ASC",
        'Sheet%',
        $topic_id
    ));

    wp_send_json_success(['labels' => $sheet_labels]);
}
add_action('wp_ajax_get_sheets_for_topic', 'qp_get_sheets_for_topic_ajax');


// In question-press.php

/**
 * Handles the one-time data migration from the old source_file column
 * to the new sources table. Triggered by a link in an admin notice.
 */
function qp_run_source_data_migration()
{
    // Check if the trigger is present in the URL and if the user is an admin
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_migrate_sources' || !current_user_can('manage_options')) {
        return;
    }

    // Security check
    check_admin_referer('qp_source_migration_nonce');

    global $wpdb;
    $questions_table = $wpdb->prefix . 'qp_questions';
    $sources_table = $wpdb->prefix . 'qp_sources';

    // Get all distinct, non-empty source_file entries from the questions table
    $old_source_files = $wpdb->get_col("SELECT DISTINCT source_file FROM {$questions_table} WHERE source_file IS NOT NULL AND source_file != ''");

    if (empty($old_source_files)) {
        // Nothing to migrate, so we just mark it as done
        update_option('qp_source_migration_status', 'done');
        wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
        exit;
    }

    foreach ($old_source_files as $source_file_name) {
        // Check if this source already exists in our new table
        $existing_source_id = $wpdb->get_var($wpdb->prepare("SELECT source_id FROM {$sources_table} WHERE source_name = %s", $source_file_name));

        if (null === $existing_source_id) {
            // It doesn't exist, so insert it
            $wpdb->insert(
                $sources_table,
                ['source_name' => $source_file_name, 'description' => 'Migrated from old source file.'],
                ['%s', '%s']
            );
            $new_source_id = $wpdb->insert_id;
        } else {
            // It already exists, just get its ID
            $new_source_id = $existing_source_id;
        }

        // Now, update all questions that used the old source_file name
        // to point to the new source_id.
        $wpdb->update(
            $questions_table,
            ['source_id' => $new_source_id],
            ['source_file' => $source_file_name],
            ['%d'],
            ['%s']
        );
    }

    // VERY IMPORTANT: Mark the migration as complete so the notice disappears.
    update_option('qp_source_migration_status', 'done');

    // Store a success message to show the user.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['qp_admin_message'] = 'Successfully migrated ' . count($old_source_files) . ' legacy sources to the new database structure.';
        $_SESSION['qp_admin_message_type'] = 'success';
    }


    // Redirect back to the admin page without the action parameters
    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}

// In question-press.php

/**
 * Displays a persistent admin notice if the source data migration needs to be run.
 */
function qp_show_migration_admin_notice()
{
    // Only show this notice to admins and only if the migration is not marked as 'done'
    if (!current_user_can('manage_options') || get_option('qp_source_migration_status') === 'done') {
        return;
    }

    // Check if we are on one of the plugin's pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'qp-') === false && strpos($screen->id, 'question-press') === false) {
        return;
    }

    // Display the success message after redirection
    if (isset($_SESSION['qp_admin_message'])) {
        echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
        unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
    }

    // Create the URL for the migration button
    $migration_url = add_query_arg(
        [
            'action' => 'qp_migrate_sources',
            '_wpnonce' => wp_create_nonce('qp_source_migration_nonce'),
        ]
    );
    ?>
    <div class="notice notice-warning is-dismissible">
        <h3>Question Press Data Update</h3>
        <p>
            The Question Press plugin has been updated with a new database structure for question sources. Please run the one-time data update script to migrate your existing data. This is a required step.
        </p>
        <p>
            <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">Run Data Migration</a>
        </p>
    </div>
<?php
}

/**
 * Handles the one-time data migration for question numbers and PYQ status.
 */
function qp_run_details_data_migration()
{
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_migrate_details' || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('qp_details_migration_nonce');

    global $wpdb;
    $questions_table = $wpdb->prefix . 'qp_questions';

    // Get all questions that have a legacy source_number but no new question_number_in_section
    $questions_to_migrate = $wpdb->get_results(
        "SELECT question_id, source_number FROM {$questions_table} WHERE source_number IS NOT NULL AND (question_number_in_section IS NULL OR question_number_in_section = '')"
    );

    if (empty($questions_to_migrate)) {
        update_option('qp_details_migration_status', 'done');
        wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
        exit;
    }

    $migrated_count = 0;
    foreach ($questions_to_migrate as $question) {
        $wpdb->update(
            $questions_table,
            ['question_number_in_section' => $question->source_number], // Copy old number to new column
            ['question_id' => $question->question_id],
            ['%s'],
            ['%d']
        );
        $migrated_count++;
    }

    // Mark this specific migration as complete.
    update_option('qp_details_migration_status', 'done');

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['qp_admin_message'] = 'Successfully migrated question numbers for ' . $migrated_count . ' questions.';
        $_SESSION['qp_admin_message_type'] = 'success';
    }

    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}

/**
 * Displays an admin notice for the details migration if it needs to be run.
 */
function qp_show_details_migration_notice()
{
    if (!current_user_can('manage_options') || get_option('qp_details_migration_status') === 'done') {
        return;
    }

    // Only show on our plugin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'qp-') === false && strpos($screen->id, 'question-press') === false) {
        return;
    }

    $migration_url = add_query_arg(
        ['action' => 'qp_migrate_details', '_wpnonce' => wp_create_nonce('qp_details_migration_nonce')]
    );
?>
    <div class="notice notice-info is-dismissible">
        <h3>Question Press Data Update (Step 2)</h3>
        <p>
            An update is needed to migrate your legacy question numbers to the new database structure.
        </p>
        <p>
            <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">Migrate Question Numbers</a>
        </p>
    </div>
<?php
}

// In question-press.php

/**
 * Handles the one-time migration for orphaned sources that have no subject assigned.
 */
function qp_run_orphan_source_migration()
{
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_migrate_orphan_sources' || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('qp_orphan_source_migration_nonce');

    global $wpdb;
    $sources_table = $wpdb->prefix . 'qp_sources';
    $subjects_table = $wpdb->prefix . 'qp_subjects';

    // Find the ID for the "Uncategorized" subject
    $uncategorized_subject_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM {$subjects_table} WHERE subject_name = %s", 'Uncategorized'));

    if (!$uncategorized_subject_id) {
        // Failsafe in case "Uncategorized" was deleted, though it shouldn't be possible.
        update_option('qp_orphan_source_migration_status', 'done');
        wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
        exit;
    }

    // Find all sources where subject_id is 0 or NULL
    $updated_rows = $wpdb->update(
        $sources_table,
        ['subject_id' => $uncategorized_subject_id],
        ['subject_id' => 0],
        ['%d'],
        ['%d']
    );

    // Mark migration as complete
    update_option('qp_orphan_source_migration_status', 'done');

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['qp_admin_message'] = $updated_rows > 0 ? 'Successfully assigned ' . $updated_rows . ' orphaned sources to the "Uncategorized" subject.' : 'No orphaned sources found to migrate.';
        $_SESSION['qp_admin_message_type'] = 'success';
    }

    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}


/**
 * Displays an admin notice for the orphaned sources migration if it needs to be run.
 */
function qp_show_orphan_source_notice()
{
    if (!current_user_can('manage_options') || get_option('qp_orphan_source_migration_status') === 'done') {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'qp-') === false && strpos($screen->id, 'question-press') === false) {
        return;
    }

    $migration_url = add_query_arg(
        ['action' => 'qp_migrate_orphan_sources', '_wpnonce' => wp_create_nonce('qp_orphan_source_migration_nonce')]
    );
?>
    <div class="notice notice-info is-dismissible">
        <h3>Question Press Data Update (Step 3)</h3>
        <p>
            An update is needed to find any old question sources that are not assigned to a subject. This script will assign them to "Uncategorized" so you can manage them.
        </p>
        <p>
            <a href="<?php echo esc_url($migration_url); ?>" class="button button-primary">Find and Assign Orphaned Sources</a>
        </p>
    </div>
<?php
}

// In question-press.php

/**
 * Handles the one-time action of dropping old DB columns.
 */
function qp_run_db_cleanup()
{
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_cleanup_db' || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('qp_db_cleanup_nonce');

    global $wpdb;
    $table_name = $wpdb->prefix . 'qp_questions';
    $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN source_file, DROP COLUMN source_page, DROP COLUMN source_number;");

    update_option('qp_db_cleanup_status', 'done');

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['qp_admin_message'] = 'Database cleanup successful. Old source columns have been removed.';
        $_SESSION['qp_admin_message_type'] = 'success';
    }

    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}

/**
 * Displays an admin notice for the final DB cleanup.
 */
function qp_show_db_cleanup_notice()
{
    // Only show if all migrations are done AND cleanup hasn't been done
    if (
        get_option('qp_source_migration_status') !== 'done' ||
        get_option('qp_details_migration_status') !== 'done' ||
        get_option('qp_orphan_source_migration_status') !== 'done' ||
        get_option('qp_db_cleanup_status') === 'done'
    ) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'qp-') === false) return;

    $cleanup_url = add_query_arg(['action' => 'qp_cleanup_db', '_wpnonce' => wp_create_nonce('qp_db_cleanup_nonce')]);
?>
    <div class="notice notice-error is-dismissible">
        <h3>Question Press Final Step: Database Cleanup</h3>
        <p>
            <strong>Warning:</strong> All data has been migrated to the new structure. You can now permanently remove the old, redundant database columns (`source_file`, `source_page`, `source_number`).
            <strong>This action cannot be undone.</strong> Please ensure you have backed up your database before proceeding.
        </p>
        <p>
            <a href="<?php echo esc_url($cleanup_url); ?>" class="button button-danger">Run Final Cleanup</a>
        </p>
    </div>
<?php
}

// Add the hooks
add_action('admin_init', 'qp_run_db_cleanup');
add_action('admin_notices', 'qp_show_db_cleanup_notice');
