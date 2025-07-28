<?php

/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           3.2.4
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
require_once QP_PLUGIN_DIR . 'admin/class-qp-exams-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-sources-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-import-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-importer.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-export-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-questions-list-table.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-question-editor-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-settings-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-logs-reports-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-backup-restore-page.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-shortcodes.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-dashboard.php';
require_once QP_PLUGIN_DIR . 'api/class-qp-rest-api.php';

// Activation, Deactivation, Uninstall Hooks
function qp_activate_plugin()
{
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
    subject_id BIGINT(20) UNSIGNED DEFAULT NULL,
    source_type VARCHAR(50) NOT NULL DEFAULT 'book',
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
        parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        section_name VARCHAR(255) NOT NULL,
        PRIMARY KEY (section_id),
        KEY source_id (source_id),
        KEY parent (parent)
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

    // Create default labels in BOTH old and new systems for compatibility
    $default_labels = [
        ['label_name' => 'Wrong Answer', 'label_color' => '#ff5733', 'is_default' => 1, 'description' => 'Reported by users for having an incorrect answer key.'],
        ['label_name' => 'No Answer', 'label_color' => '#ffc300', 'is_default' => 1, 'description' => 'Reported by users because the question has no correct option provided.'],
        ['label_name' => 'Incorrect Formatting', 'label_color' => '#900c3f', 'is_default' => 1, 'description' => 'Reported by users for formatting or display issues.'],
        ['label_name' => 'Wrong Subject', 'label_color' => '#581845', 'is_default' => 1, 'description' => 'Reported by users for being in the wrong subject category.'],
        ['label_name' => 'Duplicate', 'label_color' => '#c70039', 'is_default' => 1, 'description' => 'Automatically marked as a duplicate of another question during import.']
    ];

    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'label'");

    foreach ($default_labels as $label) {
        // Old table
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_labels WHERE label_name = %s", $label['label_name'])) == 0) {
            $wpdb->insert($table_labels, $label);
        }
        // New taxonomy system
        if ($label_tax_id) {
            $term_exists = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE name = %s AND taxonomy_id = %d", $label['label_name'], $label_tax_id));
            if (!$term_exists) {
                $wpdb->insert($wpdb->prefix . 'qp_terms', ['taxonomy_id' => $label_tax_id, 'name' => $label['label_name'], 'slug' => sanitize_title($label['label_name'])]);
                $new_term_id = $wpdb->insert_id;
                // Add meta for the new term
                $wpdb->insert($wpdb->prefix . 'qp_term_meta', ['term_id' => $new_term_id, 'meta_key' => 'color', 'meta_value' => $label['label_color']]);
                $wpdb->insert($wpdb->prefix . 'qp_term_meta', ['term_id' => $new_term_id, 'meta_key' => 'description', 'meta_value' => $label['description']]);
                $wpdb->insert($wpdb->prefix . 'qp_term_meta', ['term_id' => $new_term_id, 'meta_key' => 'is_default', 'meta_value' => '1']);
            }
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
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
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
        start_time DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        last_activity DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        end_time DATETIME,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        total_active_seconds INT UNSIGNED DEFAULT NULL,
        total_attempted INT,
        correct_count INT,
        incorrect_count INT,
        skipped_count INT,
        not_viewed_count INT,
        marks_obtained DECIMAL(10, 2),
        end_reason VARCHAR(50) DEFAULT NULL,
        settings_snapshot TEXT,
        question_ids_snapshot LONGTEXT,
        PRIMARY KEY (session_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_sessions);

    // --- NEW: Table for Session Pauses ---
    $table_session_pauses = $wpdb->prefix . 'qp_session_pauses';
    $sql_session_pauses = "CREATE TABLE $table_session_pauses (
        pause_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT(20) UNSIGNED NOT NULL,
        pause_time DATETIME NOT NULL,
        resume_time DATETIME DEFAULT NULL,
        PRIMARY KEY (pause_id),
        KEY session_id (session_id)
    ) $charset_collate;";
    dbDelta($sql_session_pauses);


    // --- UPDATED: User Attempts table with selected_option_id and mock_status ---
    $table_attempts = $wpdb->prefix . 'qp_user_attempts';
    $sql_attempts = "CREATE TABLE $table_attempts (
    attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    question_id BIGINT(20) UNSIGNED NOT NULL,
    selected_option_id BIGINT(20) UNSIGNED,
    is_correct BOOLEAN,
    status VARCHAR(20) NOT NULL DEFAULT 'answered',
    mock_status VARCHAR(50) DEFAULT NULL,
    remaining_time INT,
    attempt_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (attempt_id),
    UNIQUE KEY session_question (session_id, question_id),
    KEY user_id (user_id),
    KEY question_id (question_id),
    KEY status (status),
    KEY mock_status (mock_status)
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

    // --- NEW: Table for "Review Later" questions ---
    $table_review_later = $wpdb->prefix . 'qp_review_later';
    $sql_review_later = "CREATE TABLE $table_review_later (
        review_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        PRIMARY KEY (review_id),
        UNIQUE KEY user_question (user_id, question_id),
        KEY user_id (user_id),
        KEY question_id (question_id)
    ) $charset_collate;";
    dbDelta($sql_review_later);

    // --- NEW: Table for Report Reasons ---
    $table_report_reasons = $wpdb->prefix . 'qp_report_reasons';
    $sql_report_reasons = "CREATE TABLE $table_report_reasons (
        reason_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        reason_text VARCHAR(255) NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        PRIMARY KEY (reason_id)
    ) $charset_collate;";
    dbDelta($sql_report_reasons);

    // --- NEW: Table for storing individual reports ---
    $table_question_reports = $wpdb->prefix . 'qp_question_reports';
    $sql_question_reports = "CREATE TABLE $table_question_reports (
        report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        reason_id BIGINT(20) UNSIGNED NOT NULL,
        report_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        PRIMARY KEY (report_id),
        KEY question_id (question_id),
        KEY user_id (user_id),
        KEY reason_id (reason_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_question_reports);

    // --- NEW: Table for Revision Mode Attempts ---
    $table_revision_attempts = $wpdb->prefix . 'qp_revision_attempts';
    $sql_revision_attempts = "CREATE TABLE $table_revision_attempts (
    revision_attempt_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    question_id BIGINT(20) UNSIGNED NOT NULL,
    topic_id BIGINT(20) UNSIGNED NOT NULL,
    attempt_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (revision_attempt_id),
    UNIQUE KEY user_question_topic (user_id, question_id, topic_id),
    KEY user_id (user_id),
    KEY topic_id (topic_id)
) $charset_collate;";
    dbDelta($sql_revision_attempts);

    // === NEW TAXONOMY TABLES (v3.3+) ===

    // 1. Taxonomies Table
    $table_taxonomies = $wpdb->prefix . 'qp_taxonomies';
    $sql_taxonomies = "CREATE TABLE $table_taxonomies (
        taxonomy_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        taxonomy_name VARCHAR(191) NOT NULL,
        taxonomy_label VARCHAR(255) NOT NULL,
        hierarchical TINYINT(1) NOT NULL DEFAULT 0,
        description TEXT,
        PRIMARY KEY (taxonomy_id),
        UNIQUE KEY taxonomy_name (taxonomy_name)
    ) $charset_collate;";
    dbDelta($sql_taxonomies);

    // 2. Terms Table
    $table_terms = $wpdb->prefix . 'qp_terms';
    $sql_terms = "CREATE TABLE $table_terms (
        term_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        taxonomy_id BIGINT(20) UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (term_id),
        KEY taxonomy_id (taxonomy_id),
        KEY name (name(191))
    ) $charset_collate;";
    dbDelta($sql_terms);

    // 3. Term Relationships Table
    $table_term_relationships = $wpdb->prefix . 'qp_term_relationships';
    $sql_term_relationships = "CREATE TABLE $table_term_relationships (
        object_id BIGINT(20) UNSIGNED NOT NULL,
        term_id BIGINT(20) UNSIGNED NOT NULL,
        object_type VARCHAR(20) NOT NULL DEFAULT 'question',
        PRIMARY KEY (object_id, term_id, object_type),
        KEY term_id (term_id)
    ) $charset_collate;";
    dbDelta($sql_term_relationships);

    // 4. Term Meta Table
    $table_term_meta = $wpdb->prefix . 'qp_term_meta';
    $sql_term_meta = "CREATE TABLE $table_term_meta (
        meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        term_id BIGINT(20) UNSIGNED NOT NULL,
        meta_key VARCHAR(255) DEFAULT NULL,
        meta_value LONGTEXT,
        PRIMARY KEY (meta_id),
        KEY term_id (term_id),
        KEY meta_key (meta_key(191))
    ) $charset_collate;";
    dbDelta($sql_term_meta);

    // Add some default report reasons if the table is empty
    if ($wpdb->get_var("SELECT COUNT(*) FROM $table_report_reasons") == 0) {
        $default_reasons = ['Wrong Answer', 'Typo in question', 'Options are incorrect', 'Image is not loading', 'Question is confusing'];
        foreach ($default_reasons as $reason) {
            $wpdb->insert($table_report_reasons, ['reason_text' => $reason]);
        }
    }

    // Set default options
    add_option('qp_next_custom_question_id', 1000, '', 'no');
    if (!get_option('qp_jwt_secret_key')) {
        add_option('qp_jwt_secret_key', wp_generate_password(64, true, true), '', 'no');
    }
}

register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');


function qp_admin_menu()
{
    // Add top-level menu page for "All Questions" and store the hook
    $hook = add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);

    // Use this hook to add screen options for the questions list table
    add_action("load-{$hook}", 'qp_add_screen_options');

    // Primary Submenu Pages
    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page('question-press', 'Organize', 'Organize', 'manage_options', 'qp-organization', 'qp_render_organization_page');

    // --- NEW: Unified Tools Page ---
    add_submenu_page('question-press', 'Tools', 'Tools', 'manage_options', 'qp-tools', 'qp_render_tools_page');

    add_submenu_page('question-press', 'Reports', 'Reports', 'manage_options', 'qp-logs-reports', ['QP_Logs_Reports_Page', 'render']);
    add_submenu_page('question-press', 'Settings', 'Settings', 'manage_options', 'qp-settings', ['QP_Settings_Page', 'render']);

    // Hidden pages (for editing links and backwards compatibility)
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-edit-group', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page(null, 'Merge Terms', 'Merge Terms', 'manage_options', 'qp-merge-terms', 'qp_render_merge_terms_page');
}
add_action('admin_menu', 'qp_admin_menu');


function qp_render_organization_page()
{
    $tabs = [
        'subjects' => ['label' => 'Subjects', 'callback' => ['QP_Subjects_Page', 'render']],
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

function qp_render_merge_terms_page()
{
    global $wpdb;

    // Security and data validation
    if (!isset($_REQUEST['term_ids']) || !is_array($_REQUEST['term_ids']) || count($_REQUEST['term_ids']) < 2) {
        wp_die('Please select at least two items to merge.');
    }
    // Further security checks can be added here later

    $term_ids_to_merge = array_map('absint', $_REQUEST['term_ids']);
    $taxonomy_name = sanitize_key($_REQUEST['taxonomy']); // e.g., 'subject' or 'source'
    $taxonomy_label = sanitize_text_field($_REQUEST['taxonomy_label']);
    $ids_placeholder = implode(',', $term_ids_to_merge);

    $term_table = $wpdb->prefix . 'qp_terms';
    $terms_to_merge = $wpdb->get_results("SELECT * FROM {$term_table} WHERE term_id IN ({$ids_placeholder})");

    // The first selected term will be the master, its data pre-fills the form
    $master_term = $terms_to_merge[0];
    $master_description = qp_get_term_meta($master_term->term_id, 'description', true);

    // Get all possible parent terms for the dropdown
    $parent_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND term_id NOT IN ({$ids_placeholder}) ORDER BY name ASC",
        $master_term->taxonomy_id
    ));

?>
    <div class="wrap">
        <h1>Merge <?php echo esc_html($taxonomy_label); ?>s</h1>
        <p>You are about to merge the following items into a single item. The other items will be deleted, and all associated questions will be reassigned to the final, merged item.</p>

        <ul style="list-style:disc; padding-left:20px;">
            <?php foreach ($terms_to_merge as $term) : ?>
                <li><strong><?php echo esc_html($term->name); ?></strong> (ID: <?php echo esc_html($term->term_id); ?>)</li>
            <?php endforeach; ?>
        </ul>

        <form method="post" action="admin.php?page=qp-organization&tab=<?php echo esc_attr($taxonomy_name); ?>s">
            <?php wp_nonce_field('qp_perform_merge_nonce'); ?>
            <input type="hidden" name="action" value="perform_merge">
            <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy_name); ?>">
            <input type="hidden" name="destination_term_id" value="<?php echo esc_attr($master_term->term_id); ?>">
            <?php foreach ($term_ids_to_merge as $term_id) : ?>
                <input type="hidden" name="source_term_ids[]" value="<?php echo esc_attr($term_id); ?>">
            <?php endforeach; ?>

            <h2>Final Merged Item Details</h2>
            <p>Review and edit the details for the final merged item below.</p>

            <table class="form-table">
                <tr class="form-field form-required">
                    <th scope="row"><label for="term-name">Name</label></th>
                    <td><input name="term_name" id="term-name" type="text" value="<?php echo esc_attr($master_term->name); ?>" size="40" required></td>
                </tr>
                <tr class="form-field">
                    <th scope="row"><label for="parent-term">Parent</label></th>
                    <td>
                        <select name="parent" id="parent-term">
                            <option value="0">— None —</option>
                            <?php foreach ($parent_terms as $parent) : ?>
                                <option value="<?php echo esc_attr($parent->term_id); ?>" <?php selected($master_term->parent, $parent->term_id); ?>>
                                    <?php echo esc_html($parent->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr class="form-field">
                    <th scope="row"><label for="term-description">Description</label></th>
                    <td><textarea name="term_description" id="term-description" rows="5" cols="50"><?php echo esc_textarea($master_description); ?></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" class="button button-primary button-large" value="Confirm Merge">
                <a href="javascript:history.back()" class="button button-secondary">Cancel</a>
            </p>
        </form>
    </div>
<?php
}

function qp_render_tools_page()
{
    $tabs = [
        'import' => ['label' => 'Import', 'callback' => ['QP_Import_Page', 'render']],
        'export'   => ['label' => 'Export', 'callback' => ['QP_Export_Page', 'render']],
        'backup_restore'   => ['label' => 'Backup & Restore', 'callback' => ['QP_Backup_Restore_Page', 'render']],
    ];
    $active_tab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $tabs) ? $_GET['tab'] : 'import';
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Tools</h1>
        <p>Import, export, and manage your Question Press data.</p>
        <hr class="wp-header-end">

        <nav class="nav-tab-wrapper wp-clearfix" aria-label="Secondary menu">
            <?php
            foreach ($tabs as $tab_id => $tab_data) {
                $class = ($tab_id === $active_tab) ? ' nav-tab-active' : '';
                echo '<a href="?page=qp-tools&tab=' . esc_attr($tab_id) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($tab_data['label']) . '</a>';
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
    // --- NEW: Enqueue script for Tools page ---
    if ($hook_suffix === 'question-press_page_qp-tools') {
        // First, ensure SweetAlert2 is loaded
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        // Now, enqueue our script which depends on it
        wp_enqueue_script('qp-backup-restore-script', QP_PLUGIN_URL . 'admin/assets/js/backup-restore.js', ['jquery', 'sweetalert2'], '1.0.0', true);
        wp_localize_script('qp-backup-restore-script', 'qp_backup_restore_data', [
            'nonce' => wp_create_nonce('qp_backup_restore_nonce')
        ]);
    }

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
    if ($hook_suffix === 'question-press_page_qp-organization' && isset($_GET['tab']) && $_GET['tab'] === 'labels') {
        add_action('admin_footer', function () {
            echo '<script>jQuery(document).ready(function($){$(".qp-color-picker").wpColorPicker();});</script>';
        });
    }

    // Load script for the Settings page
    if ($hook_suffix === 'question-press_page_qp-settings') {
        wp_enqueue_script('qp-settings-script', QP_PLUGIN_URL . 'admin/assets/js/settings-page.js', ['jquery'], '1.0.0', true);
    }
    // Enqueue SweetAlert2 for admin pages that use it
    if (
        $hook_suffix === 'question-press_page_qp-settings' ||
        $hook_suffix === 'toplevel_page_question-press' ||
        $hook_suffix === 'question-press_page_qp-question-editor' ||
        $hook_suffix === 'admin_page_qp-edit-group'
    ) {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
    }

    // Load KaTeX for the admin view modal
    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
        wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
        wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);
    }

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_PLUGIN_URL . 'admin/assets/js/quick-edit.js', ['jquery'], '1.0.2', true); // Version bump
        // NEW: Add a nonce specifically for our new admin filters
        wp_localize_script('qp-quick-edit-script', 'qp_admin_filter_data', [
            'nonce' => wp_create_nonce('qp_admin_filter_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Get taxonomy IDs
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");

        // Get all topics (terms with a parent under the subject taxonomy)
        $all_topics = $wpdb->get_results($wpdb->prepare("SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $subject_tax_id));

        // Get all sources (top-level terms under the source taxonomy)
        $all_sources = $wpdb->get_results($wpdb->prepare("SELECT term_id AS source_id, name AS source_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $source_tax_id));

        // Get all sections (child terms under the source taxonomy)
        $all_sections = $wpdb->get_results($wpdb->prepare("SELECT term_id AS section_id, name AS section_name, parent AS source_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $source_tax_id));

        // --- NEW: Build the source-to-subject relationship map ---
        $source_subject_links = $wpdb->get_results(
            "SELECT DISTINCT
        parent_source.term_id as source_id,
        subject_term.term_id as subject_id
    FROM {$wpdb->prefix}qp_questions q
    JOIN {$rel_table} subject_rel ON q.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'
    JOIN {$term_table} subject_term ON subject_rel.term_id = subject_term.term_id AND subject_term.parent = 0
    JOIN {$rel_table} source_rel ON q.question_id = source_rel.object_id AND source_rel.object_type = 'question'
    JOIN {$term_table} linked_source_term ON source_rel.term_id = linked_source_term.term_id
    JOIN {$term_table} parent_source ON (CASE WHEN linked_source_term.parent = 0 THEN linked_source_term.term_id ELSE linked_source_term.parent END) = parent_source.term_id"
        );

        // Get all exams
        $all_exams = $wpdb->get_results($wpdb->prepare("SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d", $exam_tax_id));

        // Get all exam-to-subject links
        $exam_subject_links = $wpdb->get_results("SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'");

        wp_localize_script('qp-quick-edit-script', 'qp_bulk_edit_data', [
            'sources' => $all_sources,
            'sections' => $all_sections,
            'exams' => $all_exams,
            'exam_subject_links' => $exam_subject_links,
            'source_subject_links' => $source_subject_links, // Add this new line
            'topics' => $all_topics
        ]);

        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce'),
            'nonce' => wp_create_nonce('qp_practice_nonce')
        ]);
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_PLUGIN_URL . 'admin/assets/js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
}
add_action('admin_enqueue_scripts', 'qp_admin_enqueue_scripts');

// FORM & ACTION HANDLERS
function qp_handle_form_submissions()
{
    // NEW: Handle list table actions here, before any other output.
    // We check if the user is on the main "All Questions" page.
    if (isset($_GET['page']) && $_GET['page'] === 'question-press') {
        $list_table = new QP_Questions_List_Table();
        $list_table->process_bulk_action();
    }

    if (isset($_GET['page']) && $_GET['page'] === 'qp-organization') {
        QP_Sources_Page::handle_forms();
        QP_Topics_Page::handle_forms();
        QP_Subjects_Page::handle_forms();
        QP_Labels_Page::handle_forms();
        QP_Exams_Page::handle_forms();
    }
    QP_Export_Page::handle_export_submission();
    qp_handle_save_question_group();
    qp_handle_topic_forms();
    QP_Settings_Page::register_settings();
    QP_Backup_Restore_Page::handle_forms();
    qp_run_unified_data_migration();
    qp_run_v3_taxonomy_migration();
}
add_action('admin_init', 'qp_handle_form_submissions');


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
        }
        // NEW: Check for our bulk edit confirmation message
        if (isset($_GET['bulk_edit_message']) && $_GET['bulk_edit_message'] === '1') {
            echo '<div id="message" class="notice notice-success is-dismissible"><p>Questions have been bulk updated successfully.</p></div>';
        }

        ?>
        <hr class="wp-header-end">
        <?php $list_table->views(); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            <?php $list_table->search_box('Search Questions', 'question'); ?>
            <?php $list_table->display(); ?>
        </form>
        <?php $list_table->display_view_modal(); ?>
        <style type="text/css">
            #post-query-submit {
                margin-left: 8px;
            }

            .wp-list-table .column-custom_question_id {
                width: 5%;
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

            #qp-view-modal-backdrop {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                z-index: 1001;
                display: none;
                /* Initially hidden */
                justify-content: center;
                align-items: center;
            }

            #qp-view-modal-content {
                background: #fff;
                padding: 2rem;
                border-radius: 8px;
                max-width: 90%;
                width: 700px;
                max-height: 90vh;
                overflow-y: auto;
                position: relative;
                font: normal 1.5em KaTeX_Main, Times New Roman, serif;
            }

            .qp-modal-close-btn {
                position: absolute;
                top: 1rem;
                right: 1rem;
                font-size: 24px;
                background: none;
                border: none;
                cursor: pointer;
                color: #50575e;
            }
        </style>
    </div>
    <?php
}

function get_question_custom_id($question_id)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT custom_question_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
}


function qp_handle_save_question_group()
{
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    // --- Get group-level data from the form ---
    $direction_text = isset($_POST['direction_text']) ? stripslashes($_POST['direction_text']) : '';
    $direction_image_id = absint($_POST['direction_image_id']);
    $subject_id = absint($_POST['subject_id']);
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $section_id = isset($_POST['section_id']) ? absint($_POST['section_id']) : 0;
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $exam_id = isset($_POST['exam_id']) ? absint($_POST['exam_id']) : 0;
    $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';

    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($subject_id) || empty($questions_from_form)) {
        return;
    }

    // --- Save Group Data ---
    // Prepare data only for the existing columns in the groups table.
    $group_data = [
        'direction_text'     => wp_kses_post($direction_text),
        'direction_image_id' => $direction_image_id,
        'is_pyq'             => $is_pyq,
        'pyq_year'           => $is_pyq ? $pyq_year : null,
    ];

    if ($is_editing) {
        $wpdb->update("{$wpdb->prefix}qp_question_groups", $group_data, ['group_id' => $group_id]);
    } else {
        $wpdb->insert("{$wpdb->prefix}qp_question_groups", $group_data);
        $group_id = $wpdb->insert_id;
    }

    // --- Handle Group-level Term Relationships ---
    if ($group_id) {
        $rel_table = "{$wpdb->prefix}qp_term_relationships";
        $term_table = "{$wpdb->prefix}qp_terms";
        $tax_table = "{$wpdb->prefix}qp_taxonomies";

        // Get taxonomy IDs for subject and exam
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");

        // Delete all old subject and exam relationships for this group to prevent duplicates
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN (%d, %d))",
            $group_id,
            $subject_tax_id,
            $exam_tax_id
        ));

        // Insert the new subject relationship
        if ($subject_id > 0) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $subject_id, 'object_type' => 'group']);
        }

        // Insert the new exam relationship if it's a PYQ
        if ($is_pyq && $exam_id > 0) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $exam_id, 'object_type' => 'group']);
        }
    }


    // --- Process Individual Questions ---
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $rel_table = "{$wpdb->prefix}qp_term_relationships";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
        if (empty(trim($question_text))) continue;

        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        $question_num = isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '';
        $is_question_complete = !empty($q_data['correct_option_id']);

        // 1. Prepare data for the questions table (no legacy columns)
        $question_db_data = [
            'group_id'                   => $group_id,
            'question_number_in_section' => $question_num,
            'question_text'              => wp_kses_post($question_text),
            'question_text_hash'         => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
            'status'                     => $is_question_complete ? 'publish' : 'draft',
        ];

        // 2. Insert or Update the question
        if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
            // It's an existing question, so we update it
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
        } else {
            // It's a new question, so we insert it
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            $question_db_data['custom_question_id'] = $next_custom_id;
            $question_db_data['status'] = 'draft'; // New questions are always drafts initially
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id; // Get the new ID
            update_option('qp_next_custom_question_id', $next_custom_id + 1);
        }
        $submitted_q_ids[] = $question_id;

        // 3. Handle ALL Term Relationships (Topic, Source/Section, Labels)
        if ($question_id > 0) {
            $wpdb->delete($rel_table, ['object_id' => $question_id, 'object_type' => 'question']);

            $term_ids_to_link = [];
            if ($topic_id > 0) $term_ids_to_link[] = $topic_id;
            if ($section_id > 0) $term_ids_to_link[] = $section_id;
            elseif ($source_id > 0) $term_ids_to_link[] = $source_id;

            $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
            $term_ids_to_link = array_merge($term_ids_to_link, $labels);

            foreach (array_unique($term_ids_to_link) as $term_id) {
                if ($term_id > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $term_id, 'object_type' => 'question']);
                }
            }
        }

        // --- Process Options & Labels ONLY IF EDITING ---
        if ($is_editing) {
            $submitted_option_ids = [];
            $options_text = isset($q_data['options']) ? (array)$q_data['options'] : [];
            $option_ids = isset($q_data['option_ids']) ? (array)$q_data['option_ids'] : [];
            $correct_option_id_from_form = isset($q_data['correct_option_id']) ? $q_data['correct_option_id'] : null;

            foreach ($options_text as $index => $option_text) {
                $option_id = isset($option_ids[$index]) ? absint($option_ids[$index]) : 0;
                $trimmed_option_text = trim(stripslashes($option_text));

                if (empty($trimmed_option_text)) {
                    continue;
                }

                $option_data = ['option_text' => sanitize_text_field($trimmed_option_text)];

                if ($option_id > 0) {
                    $wpdb->update($o_table, $option_data, ['option_id' => $option_id]);
                    $submitted_option_ids[] = $option_id;
                } else {
                    $option_data['question_id'] = $question_id;
                    $wpdb->insert($o_table, $option_data);
                    $new_option_id = $wpdb->insert_id;
                    $submitted_option_ids[] = $new_option_id;

                    if ($correct_option_id_from_form === 'new_' . $index) {
                        $correct_option_id_from_form = $new_option_id;
                    }
                }
            }

            $existing_db_option_ids = $wpdb->get_col($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d", $question_id));
            $options_to_delete = array_diff($existing_db_option_ids, $submitted_option_ids);
            if (!empty($options_to_delete)) {
                $ids_placeholder = implode(',', array_map('absint', $options_to_delete));
                $wpdb->query("DELETE FROM $o_table WHERE option_id IN ($ids_placeholder)");
            }

            // Get the original correct option ID before the update
            $original_correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$o_table} WHERE question_id = %d AND is_correct = 1", $question_id));

            $wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]);
            if ($correct_option_id_from_form) {
                $wpdb->update($o_table, ['is_correct' => 1], ['option_id' => absint($correct_option_id_from_form), 'question_id' => $question_id]);
            }

            // If the correct answer has changed, trigger the re-evaluation.
            if ($original_correct_option_id != $correct_option_id_from_form) {
                qp_re_evaluate_question_attempts($question_id, absint($correct_option_id_from_form));
            }
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
    $redirect_url = $is_editing
        ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1')
        : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');
    wp_safe_redirect($redirect_url);
    exit;
}



// Helper function for processing options to keep the main function clean
function process_question_options($question_id, $q_data)
{
    global $wpdb;
    $o_table = "{$wpdb->prefix}qp_options";
    $submitted_option_ids = [];
    $options_text = isset($q_data['options']) ? (array)$q_data['options'] : [];
    $option_ids = isset($q_data['option_ids']) ? (array)$q_data['option_ids'] : [];
    $correct_option_id_from_form = isset($q_data['correct_option_id']) ? $q_data['correct_option_id'] : null;

    foreach ($options_text as $index => $option_text) {
        $option_id = isset($option_ids[$index]) ? absint($option_ids[$index]) : 0;
        $trimmed_option_text = trim(stripslashes($option_text));
        if (empty($trimmed_option_text)) continue;
        $option_data = ['option_text' => sanitize_text_field($trimmed_option_text)];
        if ($option_id > 0) {
            $wpdb->update($o_table, $option_data, ['option_id' => $option_id]);
            $submitted_option_ids[] = $option_id;
        } else {
            $option_data['question_id'] = $question_id;
            $wpdb->insert($o_table, $option_data);
            $new_option_id = $wpdb->insert_id;
            $submitted_option_ids[] = $new_option_id;
            if ($correct_option_id_from_form === 'new_' . $index) {
                $correct_option_id_from_form = $new_option_id;
            }
        }
    }
    $existing_db_option_ids = $wpdb->get_col($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d", $question_id));
    $options_to_delete = array_diff($existing_db_option_ids, $submitted_option_ids);
    if (!empty($options_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $options_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE option_id IN ($ids_placeholder)");
    }
    $wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]);
    if ($correct_option_id_from_form) {
        $wpdb->update($o_table, ['is_correct' => 1], ['option_id' => absint($correct_option_id_from_form), 'question_id' => $question_id]);
    }
}

// Helper function for processing taxonomy to keep the main function clean
function process_question_taxonomy($question_id, $q_data)
{
    global $wpdb;
    $rel_table = "{$wpdb->prefix}qp_term_relationships";
    $wpdb->delete($rel_table, ['object_id' => $question_id, 'object_type' => 'question']);

    $topic_term_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $source_term_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $section_term_id = isset($_POST['section_id']) ? absint($_POST['section_id']) : 0;

    $term_ids_to_link = [];
    if ($topic_term_id > 0) $term_ids_to_link[] = $topic_term_id;
    if ($section_term_id > 0) {
        $term_ids_to_link[] = $section_term_id;
    } elseif ($source_term_id > 0) {
        $term_ids_to_link[] = $source_term_id;
    }
    $label_term_ids = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
    $term_ids_to_link = array_merge($term_ids_to_link, $label_term_ids);
    foreach (array_unique($term_ids_to_link) as $term_id) {
        if ($term_id > 0) {
            $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $term_id, 'object_type' => 'question']);
        }
    }
}


/**
 * AJAX handler for the admin list table.
 * Gets child topics for a given parent subject term.
 */
function qp_get_topics_for_list_table_filter_ajax()
{
    check_ajax_referer('qp_admin_filter_nonce', 'nonce');
    $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_term_id) {
        wp_send_json_success(['topics' => []]);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    // This query finds terms that are children of the selected subject term.
    $topics = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id as topic_id, name as topic_name
         FROM {$term_table}
         WHERE parent = %d
         ORDER BY name ASC",
        $subject_term_id
    ));

    wp_send_json_success(['topics' => $topics]);
}
add_action('wp_ajax_get_topics_for_list_table_filter', 'qp_get_topics_for_list_table_filter_ajax');


/**
 * AJAX handler for the admin list table.
 * Gets sources/sections that have questions for a given subject and topic.
 */
function qp_get_sources_for_list_table_filter_ajax()
{
    check_ajax_referer('qp_admin_filter_nonce', 'nonce');
    $topic_term_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$topic_term_id) {
        wp_send_json_success(['sources' => []]);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';

    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // Find all question IDs linked to the selected topic
    $question_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'question'",
        $topic_term_id
    ));

    if (empty($question_ids)) {
        wp_send_json_success(['sources' => []]);
    }

    $ids_placeholder = implode(',', $question_ids);

    // Find all source/section terms linked to those questions
    $source_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.term_id, t.name, t.parent 
         FROM {$term_table} t
         JOIN {$rel_table} r ON t.term_id = r.term_id
         WHERE r.object_id IN ($ids_placeholder) AND r.object_type = 'question' AND t.taxonomy_id = %d
         ORDER BY t.parent, t.name ASC",
        $source_tax_id
    ));

    // Group sections under their parent source
    $sources = [];
    foreach ($source_terms as $term) {
        if ($term->parent == 0) { // This is a top-level source
            if (!isset($sources[$term->term_id])) {
                $sources[$term->term_id] = [
                    'source_id'   => $term->term_id,
                    'source_name' => $term->name,
                    'sections'    => []
                ];
            }
        } else { // This is a section
            // Find the parent source's name
            $parent_source_id = $term->parent;
            if (!isset($sources[$parent_source_id])) {
                $parent_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $term_table WHERE term_id = %d", $parent_source_id));
                $sources[$parent_source_id] = [
                    'source_id' => $parent_source_id,
                    'source_name' => $parent_name,
                    'sections' => []
                ];
            }
            $sources[$parent_source_id]['sections'][$term->term_id] = [
                'section_id'   => $term->term_id,
                'section_name' => $term->name
            ];
        }
    }

    wp_send_json_success(['sources' => array_values($sources)]);
}
add_action('wp_ajax_get_sources_for_list_table_filter', 'qp_get_sources_for_list_table_filter_ajax');

/**
 * AJAX handler to create a new backup.
 */
/**
 * AJAX handler to create a new backup.
 */
function qp_create_backup_ajax()
{
    check_ajax_referer('qp_backup_restore_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $result = qp_perform_backup('manual');

    if ($result['success']) {
        $backups_html = qp_get_local_backups_html();
        wp_send_json_success(['backups_html' => $backups_html]);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}
add_action('wp_ajax_qp_create_backup', 'qp_create_backup_ajax');

/**
 * Performs the core backup creation process and saves the file locally.
 *
 * @param string $type The type of backup ('manual' or 'auto').
 * @return array An array containing 'success' status and a 'message' or 'filename'.
 */
function qp_perform_backup($type = 'manual')
{


    global $wpdb;
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }

    $tables_to_backup = [
        'qp_question_groups',
        'qp_questions',
        'qp_options',
        'qp_report_reasons',
        'qp_question_reports',
        'qp_logs',
        'qp_user_sessions',
        'qp_session_pauses',
        'qp_user_attempts',
        'qp_review_later',
        'qp_revision_attempts',
        'qp_taxonomies',
        'qp_terms',
        'qp_term_meta',
        'qp_term_relationships',
    ];
    $full_table_names = array_map(function ($table) use ($wpdb) {
        return $wpdb->prefix . $table;
    }, $tables_to_backup);

    $backup_data = [];
    foreach ($full_table_names as $table) {
        $table_name_without_prefix = str_replace($wpdb->prefix, '', $table);
        $backup_data[$table_name_without_prefix] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
    }

    $backup_data['plugin_settings'] = [
        'qp_settings' => get_option('qp_settings'),
        'qp_next_custom_question_id' => get_option('qp_next_custom_question_id'),
    ];

    $json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json_filename = 'database.json';
    $temp_json_path = trailingslashit($backup_dir) . $json_filename;
    file_put_contents($temp_json_path, $json_data);

    $image_ids = $wpdb->get_col("SELECT DISTINCT direction_image_id FROM {$wpdb->prefix}qp_question_groups WHERE direction_image_id IS NOT NULL AND direction_image_id > 0");

    // --- NEW: Filename logic ---
    $prefix = ($type === 'auto') ? 'qp-auto-backup-' : 'qp-backup-';
    $timestamp = current_time('mysql'); // Get time in WordPress's configured timezone
    $datetime = new DateTime($timestamp);
    $timezone_abbr = 'IST'; // Manually setting to IST as requested
    $backup_filename = $prefix . $datetime->format('Y-m-d_H-i-s') . '_' . $timezone_abbr . '.zip';

    $zip_path = trailingslashit($backup_dir) . $backup_filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return ['success' => false, 'message' => 'Cannot create ZIP archive.'];
    }

    $zip->addFile($temp_json_path, $json_filename);

    if (!empty($image_ids)) {
        $zip->addEmptyDir('images');
        foreach ($image_ids as $image_id) {
            $image_path = get_attached_file($image_id);
            if ($image_path && file_exists($image_path)) {
                $zip->addFile($image_path, 'images/' . basename($image_path));
            }
        }
    }

    $zip->close();
    unlink($temp_json_path);
    qp_prune_old_backups();

    return ['success' => true, 'filename' => $backup_filename];
}

/**
 * Intelligently prunes old backup files based on saved schedule settings.
 * Correctly sorts by file modification time and respects pruning rules.
 */
function qp_prune_old_backups()
{
    $schedule = get_option('qp_auto_backup_schedule', false);
    if (!$schedule || !isset($schedule['keep'])) {
        return; // No schedule or keep limit set, so do nothing.
    }

    $backups_to_keep = absint($schedule['keep']);
    $prune_manual = !empty($schedule['prune_manual']);

    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    if (!is_dir($backup_dir)) {
        return;
    }

    $all_files_in_dir = array_diff(scandir($backup_dir), ['..', '.']);

    // Create a detailed list of backup files with their timestamps
    $backup_files_with_time = [];
    foreach ($all_files_in_dir as $file) {
        $is_auto = strpos($file, 'qp-auto-backup-') === 0;
        $is_manual = strpos($file, 'qp-backup-') === 0;

        if ($is_auto || $is_manual) {
            $backup_files_with_time[] = [
                'name' => $file,
                'type' => $is_auto ? 'auto' : 'manual',
                'time' => filemtime(trailingslashit($backup_dir) . $file)
            ];
        }
    }

    // Determine which files are candidates for deletion
    $candidate_files = [];
    if ($prune_manual) {
        // If pruning manual, all backups are candidates
        $candidate_files = $backup_files_with_time;
    } else {
        // Otherwise, only auto-backups are candidates
        foreach ($backup_files_with_time as $file_data) {
            if ($file_data['type'] === 'auto') {
                $candidate_files[] = $file_data;
            }
        }
    }

    if (count($candidate_files) <= $backups_to_keep) {
        return; // Nothing to do
    }

    // **CRITICAL FIX:** Sort candidates by their actual file time, oldest first
    usort($candidate_files, function ($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    $backups_to_delete = array_slice($candidate_files, 0, count($candidate_files) - $backups_to_keep);

    foreach ($backups_to_delete as $file_data_to_delete) {
        unlink(trailingslashit($backup_dir) . $file_data_to_delete['name']);
    }
}

/**
 * The function that runs on the scheduled cron event to create a backup.
 */
function qp_run_scheduled_backup_event()
{
    // We can simply call our reusable backup function.
    // We could add logging here in the future if needed.
    qp_prune_old_backups();
    qp_perform_backup('auto');
}
add_action('qp_scheduled_backup_hook', 'qp_run_scheduled_backup_event');

/**
 * Scans the backup directory and returns the HTML for the local backups table body.
 *
 * @return string The HTML for the table rows.
 */
function qp_get_local_backups_html()
{
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    $backup_url_base = trailingslashit($upload_dir['baseurl']) . 'qp-backups';
    $backups = file_exists($backup_dir) ? array_reverse(array_diff(scandir($backup_dir), ['..', '.'])) : [];

    ob_start();

    if (empty($backups)) {
        echo '<tr class="no-items"><td class="colspanchange" colspan="4">No local backups found.</td></tr>';
    } else {
        foreach ($backups as $backup_file) {
            $file_path = trailingslashit($backup_dir) . $backup_file;
            $file_url = trailingslashit($backup_url_base) . $backup_file;
            if (is_dir($file_path)) continue;

            $file_size = size_format(filesize($file_path));
            $file_timestamp_gmt = filemtime($file_path);
            $file_date = get_date_from_gmt(date('Y-m-d H:i:s', $file_timestamp_gmt), 'M j, Y, g:i a');
    ?>
            <tr data-filename="<?php echo esc_attr($backup_file); ?>">
                <td><?php echo esc_html($file_date); ?></td>
                <td>
                    <?php if (strpos($backup_file, 'qp-auto-backup-') === 0) : ?>
                        <span style="background-color: #dadae0ff; color: #383d42ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">AUTO</span>
                    <?php else : ?>
                        <span style="background-color: #d8e7f2ff; color: #0f82e7ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">MANUAL</span>
                    <?php endif; ?>
                    <?php echo esc_html($backup_file); ?>
                </td>
                <td><?php echo esc_html($file_size); ?></td>
                <td>
                    <a href="<?php echo esc_url($file_url); ?>" class="button button-secondary" download>Download</a>
                    <button type="button" class="button button-primary qp-restore-btn">Restore</button>
                    <button type="button" class="button button-link-delete qp-delete-backup-btn">Delete</button>
                </td>
            </tr>
    <?php
        }
    }
    return ob_get_clean();
}

/**
 * AJAX handler to delete a local backup file.
 */
function qp_delete_backup_ajax()
{
    check_ajax_referer('qp_backup_restore_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';

    if (empty($filename) || (strpos($filename, 'qp-backup-') !== 0 && strpos($filename, 'qp-auto-backup-') !== 0 && strpos($filename, 'uploaded-') !== 0) || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
        wp_send_json_error(['message' => 'Invalid or malicious filename provided.']);
    }

    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    $file_path = trailingslashit($backup_dir) . $filename;

    if (file_exists($file_path)) {
        if (unlink($file_path)) {
            // Deletion successful, send back the updated list
            $backups_html = qp_get_local_backups_html();
            wp_send_json_success(['backups_html' => $backups_html, 'message' => 'Backup deleted successfully.']);
        } else {
            wp_send_json_error(['message' => 'Could not delete the file. Please check file permissions.']);
        }
    } else {
        wp_send_json_error(['message' => 'File not found. It may have already been deleted.']);
    }
}
add_action('wp_ajax_qp_delete_backup', 'qp_delete_backup_ajax');

/**
 * AJAX handler to restore a backup from a local file.
 * This version is optimized for performance and reliability.
 */
function qp_restore_backup_ajax()
{
    check_ajax_referer('qp_backup_restore_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
    if (empty($filename)) {
        wp_send_json_error(['message' => 'Invalid filename.']);
    }

    $result = qp_perform_restore($filename);

    if ($result['success']) {
        wp_send_json_success(['message' => 'Data has been successfully restored.', 'stats' => $result['stats']]);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}
add_action('wp_ajax_qp_restore_backup', 'qp_restore_backup_ajax');

/**
 * Performs the core backup restore process from a given filename.
 *
 * @param string $filename The name of the backup .zip file in the qp-backups directory.
 * @return array An array containing 'success' status and a 'message' or 'stats'.
 */
function qp_perform_restore($filename)
{
    // This function contains the exact logic from the previous qp_restore_backup_ajax(),
    // but instead of sending JSON, it returns an array.
    @ini_set('max_execution_time', 300);
    @ini_set('memory_limit', '256M');

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    $file_path = trailingslashit($backup_dir) . $filename;
    $temp_extract_dir = trailingslashit($backup_dir) . 'temp_restore_' . time();

    if (!file_exists($file_path)) {
        return ['success' => false, 'message' => 'Backup file not found on server.'];
    }

    wp_mkdir_p($temp_extract_dir);
    $zip = new ZipArchive;
    if ($zip->open($file_path) !== TRUE) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'Failed to open the backup file.'];
    }
    $zip->extractTo($temp_extract_dir);
    $zip->close();

    $json_file_path = trailingslashit($temp_extract_dir) . 'database.json';
    if (!file_exists($json_file_path)) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'database.json not found in the backup file.'];
    }

    $backup_data = json_decode(file_get_contents($json_file_path), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'Invalid JSON in backup file.'];
    }

    $tables_to_clear = [
        'qp_question_groups',
        'qp_questions',
        'qp_options',
        'qp_report_reasons',
        'qp_question_reports',
        'qp_logs',
        'qp_user_sessions',
        'qp_session_pauses',
        'qp_user_attempts',
        'qp_review_later',
        'qp_revision_attempts',
        'qp_taxonomies',
        'qp_terms',
        'qp_term_meta',
        'qp_term_relationships',
    ];
    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables_to_clear as $table) {
        $wpdb->query("DELETE FROM {$wpdb->prefix}{$table}");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

    $stats = [
        'questions' => isset($backup_data['qp_questions']) ? count($backup_data['qp_questions']) : 0,
        'options' => isset($backup_data['qp_options']) ? count($backup_data['qp_options']) : 0,
        'sessions' => isset($backup_data['qp_user_sessions']) ? count($backup_data['qp_user_sessions']) : 0,
        'attempts' => isset($backup_data['qp_user_attempts']) ? count($backup_data['qp_user_attempts']) : 0,
        'reports' => isset($backup_data['qp_question_reports']) ? count($backup_data['qp_question_reports']) : 0,
        'duplicates_handled' => 0
    ];
    if (!empty($backup_data['qp_user_attempts'])) {
        $original_attempt_count = count($backup_data['qp_user_attempts']);
        $unique_attempts = [];
        foreach ($backup_data['qp_user_attempts'] as $attempt) {
            $key = $attempt['session_id'] . '-' . $attempt['question_id'];
            if (!isset($unique_attempts[$key])) {
                $unique_attempts[$key] = $attempt;
            } else {
                $existing_attempt = $unique_attempts[$key];
                $current_attempt = $attempt;
                if (!empty($current_attempt['selected_option_id']) && empty($existing_attempt['selected_option_id'])) {
                    $unique_attempts[$key] = $current_attempt;
                }
            }
        }
        $final_attempts = array_values($unique_attempts);
        $stats['duplicates_handled'] = $original_attempt_count - count($final_attempts);
        $backup_data['qp_user_attempts'] = $final_attempts;
    }

    $restore_order = [
        'qp_taxonomies',
        'qp_terms',
        'qp_term_meta',
        'qp_term_relationships',
        'qp_question_groups',
        'qp_questions',
        'qp_options',
        'qp_report_reasons',
        'qp_question_reports',
        'qp_logs',
        'qp_user_sessions',
        'qp_session_pauses',
        'qp_user_attempts',
        'qp_review_later',
        'qp_revision_attempts'
    ];
    foreach ($restore_order as $table_name) {
        if (!empty($backup_data[$table_name])) {
            $rows = $backup_data[$table_name];
            $chunks = array_chunk($rows, 100);
            foreach ($chunks as $chunk) {
                if (empty($chunk)) continue;
                $columns = array_keys($chunk[0]);
                $placeholders = [];
                $values = [];
                foreach ($chunk as $row) {
                    $row_placeholders = [];
                    foreach ($columns as $column) {
                        $row_placeholders[] = '%s';
                        $values[] = $row[$column];
                    }
                    $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
                }
                $query = "INSERT INTO {$wpdb->prefix}{$table_name} (`" . implode('`, `', $columns) . "`) VALUES " . implode(', ', $placeholders);
                if ($wpdb->query($wpdb->prepare($query, $values)) === false) {
                    qp_delete_dir($temp_extract_dir);
                    return ['success' => false, 'message' => "An error occurred while restoring '{$table_name}'. DB Error: " . $wpdb->last_error];
                }
            }
        }
    }

    if (isset($backup_data['plugin_settings'])) {
        update_option('qp_settings', $backup_data['plugin_settings']['qp_settings']);
        update_option('qp_next_custom_question_id', $backup_data['plugin_settings']['qp_next_custom_question_id']);
    }

    $images_dir = trailingslashit($temp_extract_dir) . 'images';
    if (file_exists($images_dir)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $image_files = array_diff(scandir($images_dir), ['..', '.']);
        foreach ($image_files as $image_filename) {
            $existing_attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like($image_filename)));
            if (!$existing_attachment_id) {
                media_handle_sideload(['name' => $image_filename, 'tmp_name' => trailingslashit($images_dir) . $image_filename], 0);
            }
        }
    }

    qp_delete_dir($temp_extract_dir);
    return ['success' => true, 'stats' => $stats];
}

/**
 * Helper function to recursively delete a directory.
 *
 * @param string $dirPath The path to the directory to delete.
 */
function qp_delete_dir($dirPath)
{
    if (!is_dir($dirPath)) {
        return;
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        is_dir($file) ? qp_delete_dir($file) : unlink($file);
    }
    rmdir($dirPath);
}

/**
 * Helper function to get the source and section names for a given question.
 *
 * @param int $question_id The ID of the question.
 * @return array An array containing 'source' and 'section' names.
 */
function qp_get_source_hierarchy_for_question($question_id)
{
    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $term_table = $wpdb->prefix . 'qp_terms';

    // First, get the source and section IDs from the question itself.
    $source_ids = $wpdb->get_row($wpdb->prepare(
        "SELECT source_id, section_id FROM {$q_table} WHERE question_id = %d",
        $question_id
    ));

    if (!$source_ids) {
        return ['source' => null, 'section' => null];
    }

    $source_name = null;
    $section_name = null;

    if ($source_ids->source_id) {
        $source_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$term_table} WHERE term_id = %d",
            $source_ids->source_id
        ));
    }

    if ($source_ids->section_id) {
        $section_name = $wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$term_table} WHERE term_id = %d",
            $source_ids->section_id
        ));
    }

    return ['source' => $source_name, 'section' => $section_name];
}

/**
 * Helper function to get or create a term in the new taxonomy system.
 *
 * @param string $name         The name of the term.
 * @param int    $taxonomy_id  The ID of the taxonomy.
 * @param int    $parent_id    Optional. The term_id of the parent.
 * @return int                 The term_id of the existing or newly created term.
 */
function qp_get_or_create_term($name, $taxonomy_id, $parent_id = 0)
{
    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    // Sanitize the input
    $name = sanitize_text_field($name);
    if (empty($name)) {
        return 0;
    }

    // Check if the term already exists
    $existing_term_id = $wpdb->get_var($wpdb->prepare(
        "SELECT term_id FROM {$term_table} WHERE name = %s AND taxonomy_id = %d AND parent = %d",
        $name,
        $taxonomy_id,
        $parent_id
    ));

    if ($existing_term_id) {
        return (int) $existing_term_id;
    }

    // If it doesn't exist, create it
    $wpdb->insert(
        $term_table,
        [
            'name'        => $name,
            'slug'        => sanitize_title($name),
            'taxonomy_id' => $taxonomy_id,
            'parent'      => $parent_id,
        ]
    );

    return (int) $wpdb->insert_id;
}




// Public-facing hooks and AJAX handlers
function qp_public_init()
{
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);

    add_shortcode('question_press_session', ['QP_Shortcodes', 'render_session_page']);
    add_shortcode('question_press_review', ['QP_Shortcodes', 'render_review_page']);
}
add_action('init', 'qp_public_init');

function qp_public_enqueue_scripts()
{
    global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard') || has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review'))) {

        wp_enqueue_style('dashicons');

        // File versions for cache busting
        $css_version = filemtime(QP_PLUGIN_DIR . 'public/assets/css/practice.css');
        $practice_js_version = filemtime(QP_PLUGIN_DIR . 'public/assets/js/practice.js');
        $dashboard_js_version = filemtime(QP_PLUGIN_DIR . 'public/assets/js/dashboard.js');
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];

        // Check if the user's roles intersect with the allowed roles
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        wp_enqueue_style('qp-practice-styles', QP_PLUGIN_URL . 'public/assets/css/practice.css', [], $css_version);
        // SweetAlert2 for modern popups
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

        $options = get_option('qp_settings');
        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'dashboard_page_url' => isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/'),
            'practice_page_url'  => isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/'),
            'review_page_url'    => isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/'),
            'question_order_setting'   => isset($options['question_order']) ? $options['question_order'] : 'random',
            'can_delete_history' => $can_delete
        ];

        // --- CORRECTED SCRIPT LOADING LOGIC ---

        // Load dashboard script if the dashboard shortcode is present
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
            wp_enqueue_script('qp-dashboard-script', QP_PLUGIN_URL . 'public/assets/js/dashboard.js', ['jquery', 'sweetalert2'], $dashboard_js_version, true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }

        // Load practice script if practice or session shortcodes are present
        if (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review')) {
            // NEW: Enqueue the Hammer.js library from a CDN
            wp_enqueue_script('hammer-js', 'https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js', [], '2.0.8', true);

            // THE FIX: Add 'hammer-js' as a dependency for your practice script
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery', 'hammer-js'], $practice_js_version, true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
            // Localize settings, including the new 'show_counts' option
            $qp_settings = get_option('qp_settings');
            wp_localize_script('qp-practice-script', 'qp_practice_settings', [
                'show_counts' => !empty($qp_settings['show_question_counts'])
            ]);
        }

        // Load KaTeX if any page that can display questions is present
        if (has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review') || has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
            wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
            wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);

            // Add the inline script to actually render the math
            wp_add_inline_script('katex-auto-render', "
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof renderMathInElement === 'function') {
                        renderMathInElement(document.body, {
                            delimiters: [
                                {left: '$$', right: '$$', display: true},
                                {left: '$', right: '$', display: false},
                                {left: '\\\\[', right: '\\\\]', display: true},
                                {left: '\\\\(', right: '\\\\)', display: false}
                            ],
                            throwOnError: false
                        });
                    }
                });
            ");
        }

        // Localize session data specifically for the session page
        if (has_shortcode($post->post_content, 'question_press_session')) {
            $session_data = QP_Shortcodes::get_session_data_for_script();
            if ($session_data) {
                wp_localize_script('qp-practice-script', 'qp_session_data', $session_data);
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'qp_public_enqueue_scripts');


/**
 * AJAX handler to get topics for a subject THAT HAVE QUESTIONS.
 */
function qp_get_topics_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $subject_ids_raw = isset($_POST['subject_id']) ? $_POST['subject_id'] : [];
    if (empty($subject_ids_raw)) {
        wp_send_json_error(['message' => 'No subjects provided.']);
    }

    $subject_term_ids = array_filter(array_map('absint', $subject_ids_raw), function ($id) {
        return $id > 0;
    });

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    // Base query to get topics (child terms) of the selected subjects (parent terms)
    $sql = "
        SELECT parent_term.term_id as subject_id, parent_term.name as subject_name,
               child_term.term_id as topic_id, child_term.name as topic_name
        FROM {$term_table} child_term
        JOIN {$term_table} parent_term ON child_term.parent = parent_term.term_id
    ";

    $where_clauses = [];
    $params = [];

    // If specific subjects are selected, filter by their term IDs
    if (!empty($subject_term_ids) && !in_array('all', $subject_ids_raw)) {
        $ids_placeholder = implode(',', array_fill(0, count($subject_term_ids), '%d'));
        $where_clauses[] = "child_term.parent IN ($ids_placeholder)";
        $params = array_merge($params, $subject_term_ids);
    }

    // Ensure we are only getting topics (terms with parents) from the subject taxonomy
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'");
    if ($subject_tax_id) {
        $where_clauses[] = "child_term.taxonomy_id = %d";
        $params[] = $subject_tax_id;
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }

    $sql .= " ORDER BY parent_term.name, child_term.name ASC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $params));

    // *** THIS IS THE FIX: Group by subject_id first, then create the final structure ***
    $topics_by_subject_id = [];
    foreach ($results as $row) {
        if (!isset($topics_by_subject_id[$row->subject_id])) {
            $topics_by_subject_id[$row->subject_id] = [
                'name' => $row->subject_name,
                'topics' => []
            ];
        }
        $topics_by_subject_id[$row->subject_id]['topics'][] = [
            'topic_id'   => $row->topic_id,
            'topic_name' => $row->topic_name
        ];
    }

    // Create the final structure that the JavaScript expects
    $grouped_topics = [];
    foreach ($topics_by_subject_id as $data) {
        $grouped_topics[$data['name']] = $data['topics'];
    }

    wp_send_json_success(['topics' => $grouped_topics]);
}
add_action('wp_ajax_get_topics_for_subject', 'qp_get_topics_for_subject_ajax');

/**
 * AJAX handler to get sections containing questions for a given subject and topic.
 */
function qp_get_sections_for_subject_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $user_id = get_current_user_id();

    if (!$topic_id) {
        wp_send_json_error(['message' => 'Invalid topic ID.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // Find all question IDs linked to the selected topic
    $question_ids_in_topic = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'question'",
        $topic_id
    ));

    if (empty($question_ids_in_topic)) {
        wp_send_json_success(['sections' => []]);
    }

    $qids_placeholder = implode(',', $question_ids_in_topic);

    // Find all source/section terms linked to those questions
    $source_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.term_id, t.name, t.parent 
         FROM {$term_table} t
         JOIN {$rel_table} r ON t.term_id = r.term_id
         WHERE r.object_id IN ($qids_placeholder) AND r.object_type = 'question' AND t.taxonomy_id = %d
         ORDER BY t.parent, t.name ASC",
        $source_tax_id
    ));

    // Get all question IDs the user has already attempted.
    $attempted_q_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'",
        $user_id
    ));
    $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

    $results = [];
    foreach ($source_terms as $term) {
        // We are only interested in sections (terms with parents) for this dropdown
        if ($term->parent > 0) {
            $parent_source_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $term_table WHERE term_id = %d", $term->parent));

            // Subquery to count unattempted questions in this specific section and topic
            $unattempted_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(q.question_id) 
                 FROM {$questions_table} q
                 JOIN {$rel_table} r_topic ON q.question_id = r_topic.object_id AND r_topic.object_type = 'question'
                 JOIN {$rel_table} r_section ON q.question_id = r_section.object_id AND r_section.object_type = 'question'
                 WHERE r_topic.term_id = %d 
                 AND r_section.term_id = %d
                 AND q.question_id NOT IN ({$attempted_q_ids_placeholder})",
                $topic_id,
                $term->term_id
            ));

            $results[] = [
                'section_id' => $term->term_id,
                'source_name' => $parent_source_name,
                'section_name' => $term->name,
                'unattempted_count' => $unattempted_count
            ];
        }
    }

    wp_send_json_success(['sections' => $results]);
}
add_action('wp_ajax_get_sections_for_subject', 'qp_get_sections_for_subject_ajax');


/**
 * AJAX handler to get the number of unattempted questions for the current user.
 */
function qp_get_unattempted_counts_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $q_table = $wpdb->prefix . 'qp_questions';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';

    // 1. Get all question IDs the user has already attempted.
    $attempted_q_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT question_id FROM {$a_table} WHERE user_id = %d AND status = 'answered'",
        $user_id
    ));
    $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

    // 2. Get all unattempted questions and their term relationships
    $results = $wpdb->get_results("
        SELECT 
            r.term_id,
            t.parent
        FROM {$q_table} q
        JOIN {$rel_table} r ON q.question_id = r.object_id AND r.object_type = 'question'
        JOIN {$term_table} t ON r.term_id = t.term_id
        WHERE q.status = 'publish' AND q.question_id NOT IN ({$attempted_q_ids_placeholder})
    ");

    // 3. Process the results into a structured array for the frontend.
    $counts = [
        'by_subject' => [],
        'by_topic'   => [],
        'by_section' => [],
    ];

    $term_parents = [];
    foreach ($results as $row) {
        $term_id = $row->term_id;
        $parent_id = $row->parent;

        // This is a topic or section, so it has a parent
        if ($parent_id != 0) {
            // Get the parent's parent (for sections under topics under sources)
            if (!isset($term_parents[$parent_id])) {
                $term_parents[$parent_id] = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $parent_id));
            }
            $grandparent_id = $term_parents[$parent_id];

            // It's a topic (parent is a subject)
            if ($grandparent_id == 0) {
                if (!isset($counts['by_topic'][$term_id])) $counts['by_topic'][$term_id] = 0;
                $counts['by_topic'][$term_id]++;

                if (!isset($counts['by_subject'][$parent_id])) $counts['by_subject'][$parent_id] = 0;
                $counts['by_subject'][$parent_id]++;
            } else { // It's a section
                if (!isset($counts['by_section'][$term_id])) $counts['by_section'][$term_id] = 0;
                $counts['by_section'][$term_id]++;
            }
        }
    }

    wp_send_json_success(['counts' => $counts]);
}
add_action('wp_ajax_get_unattempted_counts', 'qp_get_unattempted_counts_ajax');

function qp_start_practice_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    $practice_mode = isset($_POST['practice_mode']) ? sanitize_key($_POST['practice_mode']) : 'normal';
    global $wpdb;

    // --- THIS IS THE KEY ADDITION ---
    // Get a list of all question IDs that have an open report.
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    $exclude_sql = !empty($reported_question_ids) ? 'AND q.question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';
    // --- END OF ADDITION ---

    if ($practice_mode === 'revision') {
        // ... (The revision mode logic remains the same, but we will add the exclude filter)
        $session_settings = [
            'practice_mode'   => 'revision',
            'selection_type'  => isset($_POST['revision_selection_type']) ? sanitize_key($_POST['revision_selection_type']) : 'auto',
            'subjects'        => isset($_POST['revision_subjects']) ? array_map('absint', $_POST['revision_subjects']) : [],
            'topics'          => isset($_POST['revision_topics']) ? array_map('absint', $_POST['revision_topics']) : [],
            'questions_per'   => isset($_POST['qp_revision_questions_per_topic']) ? absint($_POST['qp_revision_questions_per_topic']) : 10,
            'marks_correct'    => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
            'marks_incorrect'  => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
            'timer_enabled'   => isset($_POST['qp_timer_enabled']),
            'timer_seconds'   => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];

        $user_id = get_current_user_id();
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';

        // ... (rest of revision logic is the same)
        $topic_ids_to_query = [];
        if ($session_settings['selection_type'] === 'manual' && (!empty($session_settings['subjects']) || !empty($session_settings['topics']))) {
            $topic_ids_to_query = $session_settings['topics'];
            if (!empty($session_settings['subjects'])) {
                $subject_ids_placeholder = implode(',', $session_settings['subjects']);
                $topics_in_subjects = $wpdb->get_col("SELECT topic_id FROM {$wpdb->prefix}qp_topics WHERE subject_id IN ($subject_ids_placeholder)");
                $topic_ids_to_query = array_unique(array_merge($topic_ids_to_query, $topics_in_subjects));
            }
        } else {
            $topic_ids_to_query = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT q.topic_id FROM {$attempts_table} a JOIN {$questions_table} q ON a.question_id = q.question_id WHERE a.user_id = %d AND q.topic_id IS NOT NULL", $user_id));
        }

        if (empty($topic_ids_to_query)) {
            wp_send_json_error(['html' => '<div class="qp-container"><p>No previously attempted questions found for the selected criteria. Try different options or a Normal Practice session.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
        }

        $final_question_ids = [];
        $questions_per_topic = $session_settings['questions_per'];

        foreach ($topic_ids_to_query as $topic_id) {
            // Apply the exclude filter to the revision query
            $q_ids = $wpdb->get_col($wpdb->prepare("SELECT q.question_id FROM {$questions_table} q JOIN {$attempts_table} a ON q.question_id = a.question_id WHERE a.user_id = %d AND q.topic_id = %d {$exclude_sql} ORDER BY RAND() LIMIT %d", $user_id, $topic_id, $questions_per_topic));
            $final_question_ids = array_merge($final_question_ids, $q_ids);
        }
        $question_ids = array_unique($final_question_ids);
        shuffle($question_ids);
    } else {
        // **THE FIX**: This logic now handles arrays of subjects and topics
        $subjects_raw = isset($_POST['qp_subject']) && is_array($_POST['qp_subject']) ? $_POST['qp_subject'] : [];
        $topics_raw = isset($_POST['qp_topic']) && is_array($_POST['qp_topic']) ? $_POST['qp_topic'] : [];

        if ($practice_mode === 'normal' && empty($subjects_raw)) {
            wp_send_json_error(['message' => 'Please select at least one subject.']);
        }

        $section_id = isset($_POST['qp_section']) ? $_POST['qp_section'] : 'all';
        $practice_mode = 'normal';
        if ($section_id !== 'all' && is_numeric($section_id)) {
            $practice_mode = 'Section Wise Practice';
        }

        $session_settings = [
            'practice_mode'    => $practice_mode,
            'subjects'         => $subjects_raw,
            'topics'           => $topics_raw,
            'section_id'       => $section_id,
            'pyq_only'         => isset($_POST['qp_pyq_only']),
            'include_attempted' => isset($_POST['qp_include_attempted']),
            'marks_correct'    => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
            'marks_incorrect'  => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
            'timer_enabled'    => isset($_POST['qp_timer_enabled']),
            'timer_seconds'    => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
        ];


        $user_id = get_current_user_id();
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $a_table = $wpdb->prefix . 'qp_user_attempts';

        $user_id = get_current_user_id();
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $a_table = $wpdb->prefix . 'qp_user_attempts';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $where_clauses = ["q.status = 'publish'"];
        $query_params = [];
        $joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

        // Handle Subject and Topic selection using the new taxonomy system
        $term_ids_to_filter = [];
        $subjects_selected = !empty($subjects_raw) && !in_array('all', $subjects_raw);
        $topics_selected = !empty($topics_raw) && !in_array('all', $topics_raw);

        if ($topics_selected) {
            // If specific topics are chosen, they are the most specific filter.
            $term_ids_to_filter = array_map('absint', $topics_raw);
            $joins .= " JOIN {$rel_table} topic_rel ON q.question_id = topic_rel.object_id AND topic_rel.object_type = 'question'";
            $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
            $where_clauses[] = $wpdb->prepare("topic_rel.term_id IN ($ids_placeholder)", $term_ids_to_filter);
        } elseif ($subjects_selected) {
            // If only subjects are chosen, filter by them.
            $term_ids_to_filter = array_map('absint', $subjects_raw);
            $joins .= " JOIN {$rel_table} subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'";
            $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
            $where_clauses[] = $wpdb->prepare("subject_rel.term_id IN ($ids_placeholder)", $term_ids_to_filter);
        }

        // Handle Section selection
        if ($session_settings['section_id'] !== 'all' && is_numeric($session_settings['section_id'])) {
            $joins .= " JOIN {$rel_table} section_rel ON q.question_id = section_rel.object_id AND section_rel.object_type = 'question'";
            $where_clauses[] = $wpdb->prepare("section_rel.term_id = %d", absint($session_settings['section_id']));
        }

        if ($session_settings['pyq_only']) {
            $where_clauses[] = "g.is_pyq = 1";
        }

        $base_where_sql = implode(' AND ', $where_clauses);
        if (!$session_settings['include_attempted']) {
            // **THE FIX**: Only exclude questions that were explicitly 'answered'.
            $attempted_q_ids_sql = $wpdb->prepare("SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d AND status = 'answered'", $user_id);
            $base_where_sql .= " AND q.question_id NOT IN ($attempted_q_ids_sql)";
        }

        // **THE FIX**: This is the new, corrected ordering logic.
        $options = get_option('qp_settings');
        $admin_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
        $order_by_sql = '';

        if ($session_settings['section_id'] !== 'all' && is_numeric($session_settings['section_id'])) {
            // Force numerical incrementing order if a specific section is chosen
            $order_by_sql = 'ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.custom_question_id ASC';
        } else {
            // Otherwise, use the admin setting
            $order_by_sql = ($admin_order_setting === 'in_order') ? 'ORDER BY q.custom_question_id ASC' : 'ORDER BY RAND()';
        }

        $question_ids = [];
        if ($practice_mode === 'Section Wise Practice') {
            $query = "SELECT q.question_id, q.question_number_in_section FROM {$q_table} q {$joins} WHERE {$base_where_sql} {$exclude_sql} {$order_by_sql}";
            $question_results = $wpdb->get_results($wpdb->prepare($query, $query_args));
            if (!empty($question_results)) {
                $question_ids = wp_list_pluck($question_results, 'question_id');
                // Create a map of question IDs to their numbers and add it to the settings
                $session_settings['question_numbers'] = wp_list_pluck($question_results, 'question_number_in_section', 'question_id');
            }
        } else {
            // For all other modes, the original logic is fine
            $query = "SELECT q.question_id FROM {$q_table} q {$joins} WHERE {$base_where_sql} {$exclude_sql} {$order_by_sql}";
            $question_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));
        }
    }

    // --- COMMON SESSION CREATION LOGIC --- (No changes here)
    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
    }

    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => get_current_user_id(),
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_start_practice_session', 'qp_start_practice_session_ajax');

/**
 * AJAX handler to start a special session with incorrectly answered questions.
 */
function qp_start_incorrect_practice_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $questions_table = $wpdb->prefix . 'qp_questions';

    // Decide which set of questions to fetch based on the checkbox
    $include_all_incorrect = isset($_POST['include_all_incorrect']) && $_POST['include_all_incorrect'] === 'true';

    $question_ids = [];

    if ($include_all_incorrect) {
        // Mode 1: Get all questions the user has EVER answered incorrectly.
        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0",
            $user_id
        ));
    } else {
        // Mode 2: Get questions the user has NEVER answered correctly.
        // First, get all questions ever answered correctly by the user.
        $correctly_answered_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1",
            $user_id
        ));

        // Then, get all questions the user has explicitly ANSWERED (not skipped).
        $all_answered_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'",
            $user_id
        ));

        // The questions to practice are those answered but never answered correctly.
        $question_ids = array_diff($all_answered_qids, $correctly_answered_qids);
    }

    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No incorrect questions found to practice.']);
    }

    // Randomize the order of questions
    shuffle($question_ids);

    // Get the Session Page URL from settings
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    // Create a special settings snapshot for this session
    $session_settings = [
        'practice_mode'   => 'Incorrect Que. Practice', // Our new mode name
        'timer_enabled'   => false,
    ];

    // Create the new session record
    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode(array_values($question_ids)) // Re-index array
    ]);
    $session_id = $wpdb->insert_id;

    // Build the redirect URL and send it back
    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_qp_start_incorrect_practice_session', 'qp_start_incorrect_practice_session_ajax');


/**
 * AJAX handler to start a MOCK TEST session.
 */
function qp_start_mock_test_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    global $wpdb;

    // --- Settings Gathering ---
    $subjects = isset($_POST['mock_subjects']) && is_array($_POST['mock_subjects']) ? $_POST['mock_subjects'] : [];
    $topics = isset($_POST['mock_topics']) && is_array($_POST['mock_topics']) ? $_POST['mock_topics'] : [];
    $num_questions = isset($_POST['qp_mock_num_questions']) ? absint($_POST['qp_mock_num_questions']) : 20;
    $distribution = isset($_POST['question_distribution']) ? sanitize_key($_POST['question_distribution']) : 'random';

    $session_settings = [
        'practice_mode'       => 'mock_test',
        'subjects'            => $subjects,
        'topics'              => $topics,
        'num_questions'       => $num_questions,
        'distribution'        => $distribution,
        'marks_correct'       => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
        'marks_incorrect'     => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
        'timer_enabled'       => true,
        'timer_seconds'       => (isset($_POST['qp_mock_timer_minutes']) ? absint($_POST['qp_mock_timer_minutes']) : 30) * 60,
    ];

    // --- Table Names ---
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // --- Build the initial query to get a pool of eligible questions ---
    $where_clauses = ["q.status = 'publish'"];
    $query_params = [];
    $joins = "LEFT JOIN {$g_table} g ON q.group_id = g.group_id";

    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    if (!empty($reported_question_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $reported_question_ids));
        $where_clauses[] = "q.question_id NOT IN ($ids_placeholder)";
    }

    $subjects_selected = !empty($subjects) && !in_array('all', $subjects);
    $topics_selected = !empty($topics) && !in_array('all', $topics);

    if ($topics_selected) {
        $term_ids_to_filter = array_map('absint', $topics);
        $joins .= " JOIN {$rel_table} topic_rel ON q.question_id = topic_rel.object_id AND topic_rel.object_type = 'question'";
        $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
        $where_clauses[] = $wpdb->prepare("topic_rel.term_id IN ($ids_placeholder)", $term_ids_to_filter);
    } elseif ($subjects_selected) {
        $term_ids_to_filter = array_map('absint', $subjects);
        $joins .= " JOIN {$rel_table} subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'";
        $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
        $where_clauses[] = $wpdb->prepare("subject_rel.term_id IN ($ids_placeholder)", $term_ids_to_filter);
    }

    $base_where_sql = implode(' AND ', $where_clauses);
    $query = "SELECT q.question_id, (SELECT term_id FROM {$rel_table} WHERE object_id = q.question_id AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE parent != 0) LIMIT 1) as topic_id FROM {$q_table} q {$joins} WHERE {$base_where_sql}";

    $question_pool = $wpdb->get_results($wpdb->prepare($query, $query_params));

    if (empty($question_pool)) {
        wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
    }

    // --- Apply distribution logic ---
    $final_question_ids = [];
    if ($distribution === 'equal' && $topics_selected) {
        $questions_by_topic = [];
        foreach ($question_pool as $q) {
            if ($q->topic_id) { // Only consider questions that have a topic
                $questions_by_topic[$q->topic_id][] = $q->question_id;
            }
        }

        $num_topics = count($questions_by_topic);
        $questions_per_topic = $num_topics > 0 ? floor($num_questions / $num_topics) : 0;
        $remainder = $num_topics > 0 ? $num_questions % $num_topics : 0;

        foreach ($questions_by_topic as $topic_id => $q_ids) {
            shuffle($q_ids);
            $num_to_take = $questions_per_topic;
            if ($remainder > 0) {
                $num_to_take++;
                $remainder--;
            }
            $final_question_ids = array_merge($final_question_ids, array_slice($q_ids, 0, $num_to_take));
        }
    } else { // Default to 'random'
        shuffle($question_pool);
        $final_question_ids = array_slice(wp_list_pluck($question_pool, 'question_id'), 0, $num_questions);
    }

    if (empty($final_question_ids)) {
        wp_send_json_error(['html' => '<div class="qp-container"><p>Could not gather enough unique questions for the test. Please select more topics or reduce the number of questions.</p><button onclick="window.location.reload();" class="qp-button qp-button-secondary">Go Back</button></div>']);
    }

    shuffle($final_question_ids); // Final shuffle for randomness

    // --- Create the session ---
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => get_current_user_id(),
        'status'                  => 'mock_test',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($final_question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_qp_start_mock_test_session', 'qp_start_mock_test_session_ajax');

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // We can re-use the function from our shortcode class to get the form HTML
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}
add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax');


/**
 * AJAX handler for the dashboard progress tab.
 * Gets sources that have questions for a given subject.
 */
function qp_get_sources_for_subject_progress_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_term_id) {
        wp_send_json_success(['sources' => []]);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // 1. Find all question groups linked to the selected subject term.
    $group_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'group'",
        $subject_term_id
    ));

    if (empty($group_ids)) {
        wp_send_json_success(['sources' => []]);
    }
    $group_ids_placeholder = implode(',', $group_ids);

    // 2. Find all source AND section terms linked to questions WITHIN those specific groups.
    $related_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.term_id, t.parent 
         FROM {$wpdb->prefix}qp_questions q
         JOIN {$rel_table} r ON q.question_id = r.object_id AND r.object_type = 'question'
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE q.group_id IN ($group_ids_placeholder) AND t.taxonomy_id = %d",
        $source_tax_id
    ));

    if (empty($related_terms)) {
        wp_send_json_success(['sources' => []]);
    }

    // 3. Trace all terms back to their top-level parent (the source).
    $top_level_source_ids = [];
    foreach ($related_terms as $term) {
        if ($term->parent == 0) {
            $top_level_source_ids[] = $term->term_id;
        } else {
            // This is a section or sub-section, trace to top-level parent
            $current_term_id = $term->term_id;
            $parent_id = $term->parent;
            while ($parent_id != 0) {
                $current_term_id = $parent_id;
                $parent_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $current_term_id));
            }
            $top_level_source_ids[] = $current_term_id;
        }
    }

    $unique_source_ids = array_unique(array_filter($top_level_source_ids));

    if (empty($unique_source_ids)) {
        wp_send_json_success(['sources' => []]);
    }

    $source_ids_placeholder = implode(',', $unique_source_ids);

    // 4. Fetch the names of the unique, top-level sources.
    $sources = $wpdb->get_results(
        "SELECT term_id as source_id, name as source_name 
         FROM {$term_table} 
         WHERE term_id IN ($source_ids_placeholder)
         ORDER BY name ASC"
    );

    wp_send_json_success(['sources' => $sources]);
}
add_action('wp_ajax_get_sources_for_subject_progress', 'qp_get_sources_for_subject_progress_ajax');

/**
 * AJAX handler for the dashboard progress tab.
 * Calculates and returns the hierarchical progress data.
 */
function qp_get_progress_data_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $source_term_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $user_id = get_current_user_id();

    if (!$source_term_id || !$user_id) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // 1. Get all descendant terms for the selected source
    $descendant_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, parent, name FROM {$term_table} WHERE term_id = %d OR parent = %d OR parent IN (SELECT term_id FROM {$term_table} WHERE parent = %d)",
        $source_term_id, $source_term_id, $source_term_id
    ));

    if (empty($descendant_terms)) {
        wp_send_json_success(['html' => '<p>No topics or sections found for this source.</p>']);
        return;
    }

    $all_term_ids = wp_list_pluck($descendant_terms, 'term_id');
    $terms_placeholder = implode(',', $all_term_ids);

    // 2. Get all questions linked to this source or any of its descendants
    $all_qids = $wpdb->get_col("SELECT DISTINCT object_id FROM {$rel_table} WHERE term_id IN ({$terms_placeholder}) AND object_type = 'question'");

    if (empty($all_qids)) {
        wp_send_json_success(['html' => '<p>No questions found for this source.</p>']);
        return;
    }
    $qids_placeholder = implode(',', $all_qids);

    // 3. Get user's completed attempts for these questions
    $exclude_incorrect = isset($_POST['exclude_incorrect']) && $_POST['exclude_incorrect'] === 'true';
    $attempt_status_clause = $exclude_incorrect ? "AND is_correct = 1" : "AND status = 'answered'";
    $completed_qids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND question_id IN ($qids_placeholder) $attempt_status_clause",
        $user_id
    ));

    // 4. Get the term relationships for all relevant questions to build the progress map
    $question_term_map = $wpdb->get_results("SELECT object_id, term_id FROM {$rel_table} WHERE object_id IN ($qids_placeholder) AND object_type = 'question'", OBJECT_K);

    // 5. Build a hierarchical data structure
    $term_tree = [];
    $terms_by_id = [];
    foreach ($descendant_terms as $term) {
        $term->children = [];
        $term->total = 0;
        $term->completed = 0;
        $terms_by_id[$term->term_id] = $term;
    }

    foreach ($all_qids as $qid) {
        if (isset($question_term_map[$qid])) {
            $term_id = $question_term_map[$qid]->term_id;
            $is_completed = in_array($qid, $completed_qids);
            
            // Increment counts up the tree
            $current_term_id = $term_id;
            while(isset($terms_by_id[$current_term_id])) {
                $terms_by_id[$current_term_id]->total++;
                if ($is_completed) {
                    $terms_by_id[$current_term_id]->completed++;
                }
                $current_term_id = $terms_by_id[$current_term_id]->parent;
            }
        }
    }
    
    foreach ($terms_by_id as $term) {
        if (isset($terms_by_id[$term->parent])) {
            $terms_by_id[$term->parent]->children[] = $term;
        } else {
            $term_tree[] = $term;
        }
    }

    // 6. Generate the HTML response
    ob_start();
    function qp_render_progress_tree_recursive($terms) {
        $level_classes = ['subject-level', 'topic-level', 'section-level'];
        foreach ($terms as $term) {
            $percentage = $term->total > 0 ? round(($term->completed / $term->total) * 100) : 0;
            $level_class = $level_classes[count(get_ancestors($term->term_id, 'source'))] ?? 'section-level';

            echo '<div class="qp-progress-item ' . $level_class . ' qp-topic-toggle" data-topic-id="' . esc_attr($term->term_id) . '">';
            echo '<div class="qp-progress-bar-bg" style="width: ' . esc_attr($percentage) . '%;"></div>';
            echo '<div class="qp-progress-label"><span class="dashicons dashicons-arrow-right-alt2"></span>' . esc_html($term->name) . ' <span class="qp-progress-percentage">' . esc_html($percentage) . '%</span></div>';
            echo '</div>';
            
            if (!empty($term->children)) {
                echo '<div class="qp-topic-sections-container" data-parent-topic="' . esc_attr($term->term_id) . '" style="display: none;">';
                qp_render_progress_tree_recursive($term->children);
                echo '</div>';
            }
        }
    }
    echo '<div class="qp-progress-tree">';
    qp_render_progress_tree_recursive($term_tree);
    echo '</div>';
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_get_progress_data', 'qp_get_progress_data_ajax');

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
    $t_table = $wpdb->prefix . 'qp_topics';
    $src_table = $wpdb->prefix . 'qp_sources';
    $sec_table = $wpdb->prefix . 'qp_source_sections';
    $o_table = $wpdb->prefix . 'qp_options';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $user_id = get_current_user_id();

    // --- Fetch question data ---
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            q.question_id, q.custom_question_id, q.question_text, q.question_number_in_section,
            g.direction_text, g.direction_image_id,
            subject_term.name AS subject_name,
            topic_term.name AS topic_name,
            CASE 
                WHEN linked_source_term.parent != 0 THEN parent_source_term.name 
                ELSE linked_source_term.name 
            END AS source_name,
            CASE 
                WHEN linked_source_term.parent != 0 THEN linked_source_term.name 
                ELSE NULL 
            END AS section_name
        FROM {$q_table} q
        LEFT JOIN {$g_table} g ON q.group_id = g.group_id
        LEFT JOIN {$wpdb->prefix}qp_term_relationships subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group' AND subject_rel.term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE parent = 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'))
        LEFT JOIN {$wpdb->prefix}qp_terms subject_term ON subject_rel.term_id = subject_term.term_id
        LEFT JOIN {$wpdb->prefix}qp_term_relationships topic_rel ON q.question_id = topic_rel.object_id AND topic_rel.object_type = 'question' AND topic_rel.term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE parent != 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject'))
        LEFT JOIN {$wpdb->prefix}qp_terms topic_term ON topic_rel.term_id = topic_term.term_id
        LEFT JOIN {$wpdb->prefix}qp_term_relationships source_rel ON q.question_id = source_rel.object_id AND source_rel.object_type = 'question' AND source_rel.term_id IN (SELECT term_id FROM {$wpdb->prefix}qp_terms WHERE taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'source'))
        LEFT JOIN {$wpdb->prefix}qp_terms linked_source_term ON source_rel.term_id = linked_source_term.term_id
        LEFT JOIN {$wpdb->prefix}qp_terms parent_source_term ON linked_source_term.parent = parent_source_term.term_id
        WHERE q.question_id = %d
        GROUP BY q.question_id",
        $question_id
    ), ARRAY_A);

    $previous_attempt_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$a_table} WHERE user_id = %d AND question_id = %d",
        $user_id,
        $question_id,
        $session_id
    ));

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    $options = get_option('qp_settings');
    $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
    $user = wp_get_current_user();
    $user_can_view = !empty(array_intersect((array)$user->roles, (array)$allowed_roles));

    if (!$user_can_view) {
        unset($question_data['source_name'], $question_data['section_name'], $question_data['question_number_in_section']);
    }

    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;

    // **THE FIX**: Fetch the 'is_correct' status along with the options
    $options_from_db = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$o_table} WHERE question_id = %d", $question_id), ARRAY_A);

    $correct_option_id = null;
    $options_for_frontend = [];
    foreach ($options_from_db as $option) {
        if ($option['is_correct']) {
            $correct_option_id = (int) $option['option_id'];
        }
        // We only send the text and id to the frontend, not the answer status
        $options_for_frontend[] = [
            'option_id' => $option['option_id'],
            'option_text' => $option['option_text']
        ];
    }
    $question_data['options'] = $options_for_frontend;

    if (!empty($question_data['question_text'])) {
        $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text']));
    }
    if (!empty($question_data['direction_text'])) {
        $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text']));
    }

    // --- State Checks ---
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d AND status = 'answered' AND session_id != %d", $user_id, $question_id, $session_id));
    $review_table = $wpdb->prefix . 'qp_review_later';
    $is_marked = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$review_table} WHERE user_id = %d AND question_id = %d", $user_id, $question_id));

    // **THIS IS THE CRITICAL ADDITION**
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $is_reported_by_user = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$reports_table} WHERE user_id = %d AND question_id = %d AND status = 'open'", $user_id, $question_id));

    // --- Send Final Response ---
    wp_send_json_success([
        'question'             => $question_data,
        'correct_option_id'    => $correct_option_id,
        'previous_attempt_count' => (int) $previous_attempt_count,
        'is_revision'          => ($attempt_count > 0),
        'is_admin'             => $user_can_view,
        'is_marked_for_review' => $is_marked,
        'is_reported_by_user'  => $is_reported_by_user // Send the authoritative state
    ]);
}
add_action('wp_ajax_get_question_data', 'qp_get_question_data_ajax');

/**
 * AJAX handler to get all active report reasons.
 */
function qp_get_report_reasons_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    global $wpdb;
    $reasons_table = $wpdb->prefix . 'qp_report_reasons';

    $reasons = $wpdb->get_results(
        "SELECT reason_id, reason_text FROM {$reasons_table} WHERE is_active = 1 ORDER BY reason_id ASC"
    );

    wp_send_json_success(['reasons' => $reasons]);
}
add_action('wp_ajax_get_report_reasons', 'qp_get_report_reasons_ajax');

/**
 * AJAX handler to submit a new question report from the modal.
 */
function qp_submit_question_report_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $reasons = isset($_POST['reasons']) && is_array($_POST['reasons']) ? array_map('absint', $_POST['reasons']) : [];
    $user_id = get_current_user_id();

    if (empty($question_id) || empty($reasons)) {
        wp_send_json_error(['message' => 'Invalid data provided.']);
    }

    global $wpdb;
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    foreach ($reasons as $reason_id) {
        $wpdb->insert(
            $reports_table,
            [
                'question_id' => $question_id,
                'user_id'     => $user_id,
                'reason_id'   => $reason_id,
                'report_date' => current_time('mysql'),
                'status'      => 'open'
            ]
        );
    }

    // Add a log entry for the admin panel
    $custom_id = get_question_custom_id($question_id);
    $wpdb->insert("{$wpdb->prefix}qp_logs", [
        'log_type'    => 'User Report',
        'log_message' => sprintf('User reported question #%s.', $custom_id),
        'log_data'    => wp_json_encode(['user_id' => $user_id, 'session_id' => $session_id, 'question_id' => $question_id, 'reasons' => $reasons])
    ]);

    wp_send_json_success(['message' => 'Report submitted.']);
}
add_action('wp_ajax_submit_question_report', 'qp_submit_question_report_ajax');

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
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    $wpdb->update($sessions_table, ['last_activity' => current_time('mysql')], ['session_id' => $session_id]);

    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

    // --- **THE FIX**: This is the new logic ---
    $session = $wpdb->get_row($wpdb->prepare("SELECT settings_snapshot FROM $sessions_table WHERE session_id = %d", $session_id));
    $settings = json_decode($session->settings_snapshot, true);

    // Always record in the main attempts table
    $wpdb->insert($attempts_table, [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'selected_option_id' => $option_id,
        'is_correct' => $is_correct ? 1 : 0,
        'status' => 'answered',
        'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null
    ]);

    // If it's a revision session, also record in the revision table
    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
        $topic_id = $wpdb->get_var($wpdb->prepare("SELECT topic_id FROM {$wpdb->prefix}qp_questions WHERE question_id = %d", $question_id));
        if ($topic_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}qp_revision_attempts (user_id, question_id, topic_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE attempt_date = NOW()",
                get_current_user_id(),
                $question_id,
                $topic_id
            ));
        }
    }
    // --- End of new logic ---

    wp_send_json_success(['is_correct' => $is_correct, 'correct_option_id' => $correct_option_id]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

/**
 * AJAX handler to save a user's selected answer during a mock test without checking it.
 */
function qp_save_mock_attempt_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;

    if (!$session_id || !$question_id || !$option_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $user_id = get_current_user_id();

    // --- FIX: Explicitly check for an existing attempt before inserting/updating ---
    $existing_attempt_id = $wpdb->get_var($wpdb->prepare(
        "SELECT attempt_id FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
        $session_id,
        $question_id
    ));

    if ($existing_attempt_id) {
        // If an attempt already exists, UPDATE it with the new option
        $wpdb->update(
            $attempts_table,
            [
                'selected_option_id' => $option_id,
                'attempt_time' => current_time('mysql'),
                'status' => 'answered' // Ensure status is 'answered'
            ],
            ['attempt_id' => $existing_attempt_id]
        );
    } else {
        // If no attempt exists, INSERT a new one
        $wpdb->insert($attempts_table, [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'question_id' => $question_id,
            'selected_option_id' => $option_id,
            'is_correct' => null, // Graded at the end
            'status' => 'answered'
        ]);
    }

    // Also update the session's last activity to keep it from timing out
    $wpdb->update($wpdb->prefix . 'qp_user_sessions', ['last_activity' => current_time('mysql')], ['session_id' => $session_id]);

    wp_send_json_success(['message' => 'Answer saved.']);
}
add_action('wp_ajax_qp_save_mock_attempt', 'qp_save_mock_attempt_ajax');

/**
 * AJAX handler to update the status of a mock test question.
 * Handles statuses like viewed, marked_for_review, etc.
 */
function qp_update_mock_status_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $new_status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

    if (!$session_id || !$question_id || empty($new_status)) {
        wp_send_json_error(['message' => 'Invalid data provided for status update.']);
    }

    // A whitelist of allowed statuses to prevent arbitrary data injection.
    $allowed_statuses = ['viewed', 'answered', 'marked_for_review', 'answered_and_marked_for_review', 'not_viewed'];
    if (!in_array($new_status, $allowed_statuses)) {
        wp_send_json_error(['message' => 'Invalid status provided.']);
    }

    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $user_id = get_current_user_id();

    // Find the existing attempt for this question in this session.
    $existing_attempt_id = $wpdb->get_var($wpdb->prepare(
        "SELECT attempt_id FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
        $session_id,
        $question_id
    ));

    $data_to_update = ['mock_status' => $new_status];

    // If the user is clearing their response, we should also nullify their selected option.
    if ($new_status === 'viewed' || $new_status === 'marked_for_review') {
        $data_to_update['selected_option_id'] = null;
    }

    if ($existing_attempt_id) {
        // If an attempt record exists, update its mock_status.
        $wpdb->update($attempts_table, $data_to_update, ['attempt_id' => $existing_attempt_id]);
    } else {
        // If no record exists yet (e.g., the user just viewed it), create one.
        $data_to_update['session_id'] = $session_id;
        $data_to_update['user_id'] = $user_id;
        $data_to_update['question_id'] = $question_id;
        $data_to_update['status'] = 'viewed'; // The main status remains 'viewed' until answered.
        $wpdb->insert($attempts_table, $data_to_update);
    }

    wp_send_json_success(['message' => 'Status updated.']);
}
add_action('wp_ajax_qp_update_mock_status', 'qp_update_mock_status_ajax');

/**
 * AJAX handler to start a REVISION practice session.
 */
function qp_start_revision_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    global $wpdb;

    // --- Gather settings from the form ---
    $user_id = get_current_user_id();
    $subjects = isset($_POST['revision_subjects']) && is_array($_POST['revision_subjects']) ? $_POST['revision_subjects'] : [];
    $topics = isset($_POST['revision_topics']) && is_array($_POST['revision_topics']) ? $_POST['revision_topics'] : [];
    $questions_per_topic = isset($_POST['qp_revision_questions_per_topic']) ? absint($_POST['qp_revision_questions_per_topic']) : 10;
    $exclude_pyq = isset($_POST['exclude_pyq']);
    $choose_random = isset($_POST['choose_random']);

    $session_settings = [
        'practice_mode'       => 'revision',
        'subjects'            => $subjects,
        'topics'              => $topics,
        'questions_per'       => $questions_per_topic,
        'exclude_pyq'         => $exclude_pyq,
        'marks_correct'       => isset($_POST['scoring_enabled']) ? floatval($_POST['qp_marks_correct']) : null,
        'marks_incorrect'     => isset($_POST['scoring_enabled']) ? -abs(floatval($_POST['qp_marks_incorrect'])) : null,
        'timer_enabled'       => isset($_POST['qp_timer_enabled']),
        'timer_seconds'       => isset($_POST['qp_timer_seconds']) ? absint($_POST['qp_timer_seconds']) : 60
    ];
    
    // --- Table Names ---
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $revision_table = $wpdb->prefix . 'qp_revision_attempts';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // --- Determine the final list of topics to query ---
    $topic_ids_to_query = [];
    if (!empty($topics) && !in_array('all', $topics)) {
        $topic_ids_to_query = array_map('absint', array_filter($topics, 'is_numeric'));
    } else {
        $subject_ids_numeric = array_map('absint', array_filter($subjects, 'is_numeric'));
        if (!empty($subject_ids_numeric)) {
            $ids_placeholder = implode(',', $subject_ids_numeric);
            $topic_ids_to_query = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($ids_placeholder)");
        }
    }

    if (empty($topic_ids_to_query)) {
        wp_send_json_error(['message' => 'Please select at least one subject or topic to revise.']);
    }

    // --- Main Question Selection Logic ---
    $final_question_ids = [];
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    $exclude_reported_sql = !empty($reported_question_ids) ? ' AND q.question_id NOT IN (' . implode(',', array_map('absint', $reported_question_ids)) . ')' : '';

    foreach ($topic_ids_to_query as $topic_id) {
        $pyq_filter_sql = $exclude_pyq ? " AND g.is_pyq = 0" : "";

        // 1. Get the master list of ALL possible questions for this topic
        $master_pool_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT q.question_id 
            FROM {$questions_table} q
            JOIN {$groups_table} g ON q.group_id = g.group_id
            JOIN {$rel_table} r ON q.question_id = r.object_id
            WHERE r.term_id = %d AND r.object_type = 'question' AND q.status = 'publish'
            {$pyq_filter_sql} {$exclude_reported_sql}",
            $topic_id
        ));
        
        if (empty($master_pool_qids)) continue;

        // 2. Get questions already seen in revision for this topic by this user
        $revised_qids_for_topic = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $revision_table WHERE user_id = %d AND topic_id = %d", $user_id, $topic_id));
        
        // 3. Find the questions that have NOT yet been revised
        $available_qids = array_diff($master_pool_qids, $revised_qids_for_topic);

        // 4. If all questions have been revised, reset the history for this topic and start over
        if (empty($available_qids)) {
            $wpdb->delete($revision_table, ['user_id' => $user_id, 'topic_id' => $topic_id]);
            $available_qids = $master_pool_qids;
        }

        if (!empty($available_qids)) {
            $ids_placeholder = implode(',', array_map('absint', $available_qids));
            $order_by_sql = $choose_random ? "ORDER BY RAND()" : "ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.custom_question_id ASC";

            $q_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT q.question_id FROM {$questions_table} q WHERE q.question_id IN ($ids_placeholder) {$order_by_sql} LIMIT %d",
                $questions_per_topic
            ));
            $final_question_ids = array_merge($final_question_ids, $q_ids);
        }
    }

    // --- Create and Start the Session ---
    $question_ids = array_unique($final_question_ids);
    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No new questions were found for the selected criteria. You may have already revised them all.']);
    }
    shuffle($question_ids);

    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_start_revision_session', 'qp_start_revision_session_ajax');

/**
 * AJAX handler to mark a question as 'expired' for a session.
 */
function qp_expire_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;

    if (!$session_id || !$question_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'is_correct' => null,
        'status' => 'expired',
        'remaining_time' => 0 // Expired means 0 time left
    ]);

    wp_send_json_success();
}
add_action('wp_ajax_expire_question', 'qp_expire_question_ajax');

function qp_skip_question_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$session_id || !$question_id) {
        wp_send_json_error(['message' => 'Invalid data submitted.']);
    }

    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}qp_user_attempts", [
        'session_id' => $session_id,
        'user_id' => get_current_user_id(),
        'question_id' => $question_id,
        'is_correct' => null,
        'status' => 'skipped',
        'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null
    ]);

    wp_send_json_success();
}
add_action('wp_ajax_skip_question', 'qp_skip_question_ajax');

/**
 * Helper function to calculate final stats and update a session record.
 *
 * @param int    $session_id The ID of the session to finalize.
 * @param string $new_status The status to set for the session (e.g., 'completed', 'abandoned').
 * @param string|null $end_reason The reason the session ended.
 * @return array|null An array of summary data, or null if the session was empty.
 */
function qp_finalize_and_end_session($session_id, $new_status = 'completed', $end_reason = null)
{
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $pauses_table = $wpdb->prefix . 'qp_session_pauses';

    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
    if (!$session) {
        return null;
    }

    // Check for any answered attempts.
    $total_answered_attempts = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND status = 'answered'",
        $session_id
    ));

    // If there are no answered attempts, delete the session immediately and stop.
    if ($total_answered_attempts === 0) {
        $wpdb->delete($sessions_table, ['session_id' => $session_id]);
        $wpdb->delete($attempts_table, ['session_id' => $session_id]); // Also clear any skipped/expired attempts
        return null; // Indicate that the session was empty and deleted
    }

    // If we are here, it means there were attempts, so we proceed to finalize.
    $settings = json_decode($session->settings_snapshot, true);
    $marks_correct = $settings['marks_correct'] ?? 0;
    $marks_incorrect = $settings['marks_incorrect'] ?? 0;
    $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';

    if ($is_mock_test) {
        // Grade any unanswered mock test questions
        $answered_attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT attempt_id, question_id, selected_option_id FROM {$attempts_table} WHERE session_id = %d AND mock_status IN ('answered', 'answered_and_marked_for_review')",
            $session_id
        ));
        $options_table = $wpdb->prefix . 'qp_options';
        foreach ($answered_attempts as $attempt) {
            $is_correct = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT is_correct FROM {$options_table} WHERE option_id = %d AND question_id = %d",
                $attempt->selected_option_id,
                $attempt->question_id
            ));
            $wpdb->update($attempts_table, ['is_correct' => $is_correct ? 1 : 0], ['attempt_id' => $attempt->attempt_id]);
        }
        $all_question_ids_in_session = json_decode($session->question_ids_snapshot, true);
        $interacted_question_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE session_id = %d", $session_id));
        $not_viewed_ids = array_diff($all_question_ids_in_session, $interacted_question_ids);
        foreach ($not_viewed_ids as $question_id) {
            $wpdb->insert($attempts_table, [
                'session_id' => $session_id,
                'user_id' => $session->user_id,
                'question_id' => $question_id,
                'status' => 'skipped',
                'mock_status' => 'not_viewed'
            ]);
        }
        $wpdb->query($wpdb->prepare("UPDATE {$attempts_table} SET status = 'skipped' WHERE session_id = %d AND mock_status IN ('viewed', 'marked_for_review')", $session_id));
    }

    $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
    $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
    $total_attempted = $correct_count + $incorrect_count;
    $not_viewed_count = 0;
    if ($is_mock_test) {
        $unattempted_count = count(json_decode($session->question_ids_snapshot, true)) - $total_attempted;
        $not_viewed_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND mock_status = 'not_viewed'", $session_id));
    } else {
        $unattempted_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND status = 'skipped'", $session_id));
    }
    $skipped_count = $unattempted_count;
    $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);
    $end_time_for_calc = ($new_status === 'abandoned' && !empty($session->last_activity) && $session->last_activity !== '0000-00-00 00:00:00') ? $session->last_activity : current_time('mysql');
    $end_time_gmt = get_gmt_from_date($end_time_for_calc);
    $start_time_gmt = get_gmt_from_date($session->start_time);
    $total_session_duration = strtotime($end_time_gmt) - strtotime($start_time_gmt);
    $total_active_seconds = max(0, $total_session_duration);
    if (!$is_mock_test) {
        $pause_records = $wpdb->get_results($wpdb->prepare("SELECT pause_time, resume_time FROM {$pauses_table} WHERE session_id = %d", $session_id));
        $total_pause_duration = 0;
        foreach ($pause_records as $pause) {
            $resume_time_gmt = $pause->resume_time ? get_gmt_from_date($pause->resume_time) : $end_time_gmt;
            $pause_time_gmt = get_gmt_from_date($pause->pause_time);
            $total_pause_duration += strtotime($resume_time_gmt) - strtotime($pause_time_gmt);
        }
        $total_active_seconds = max(0, $total_session_duration - $total_pause_duration);
    }

    $wpdb->update($sessions_table, [
        'end_time' => $end_time_for_calc,
        'status' => $new_status,
        'end_reason' => $end_reason,
        'total_active_seconds' => $total_active_seconds,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
        'not_viewed_count' => $not_viewed_count,
        'marks_obtained' => $final_score
    ], ['session_id' => $session_id]);

    return [
        'final_score' => $final_score,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
        'not_viewed_count' => $not_viewed_count,
        'settings' => $settings,
    ];
}

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

    // Determine the reason based on whether it was a timer-based auto-submission
    $is_auto_submit = isset($_POST['is_auto_submit']) && $_POST['is_auto_submit'] === 'true';
    $end_reason = $is_auto_submit ? 'autosubmitted_timer' : 'user_submitted';

    $summary_data = qp_finalize_and_end_session($session_id, 'completed', $end_reason);

    if (is_null($summary_data)) {
        wp_send_json_success(['status' => 'no_attempts', 'message' => 'Session deleted as no questions were attempted.']);
    } else {
        wp_send_json_success($summary_data);
    }
}
add_action('wp_ajax_end_practice_session', 'qp_end_practice_session_ajax');

/**
 * AJAX handler to delete an empty/unterminated session record.
 */
function qp_delete_empty_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    // Delete the session record from the database
    $wpdb->delete($sessions_table, ['session_id' => $session_id], ['%d']);

    wp_send_json_success(['message' => 'Empty session deleted.']);
}
add_action('wp_ajax_delete_empty_session', 'qp_delete_empty_session_ajax');


// ADD THIS NEW FUNCTION to the end of the file
function qp_delete_user_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // **THE FIX**: Add server-side permission check
    $options = get_option('qp_settings');
    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
    if (empty(array_intersect($user_roles, $allowed_roles))) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
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
 * AJAX handler for deleting a user's entire revision and session history.
 */
function qp_delete_revision_history_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    // **THE FIX**: Add server-side permission check
    $options = get_option('qp_settings');
    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];
    if (empty(array_intersect($user_roles, $allowed_roles))) {
        wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
    }
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


function qp_get_quick_edit_form_ajax()
{
    // Nonce check remains the same
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error();
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';

    // --- Fetch current term relationships for this specific question ---
    $current_terms_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.parent, tax.taxonomy_name
        FROM {$rel_table} r
        JOIN {$term_table} t ON r.term_id = t.term_id
        JOIN {$tax_table} tax ON t.taxonomy_id = tax.taxonomy_id
        WHERE r.object_id = %d AND r.object_type = 'question'",
        $question_id
    ));

    $current_term_ids = [];
    foreach ($current_terms_raw as $term) {
        $current_term_ids[$term->taxonomy_name][] = $term->term_id;
        if ($term->taxonomy_name === 'subject' && $term->parent != 0) { // Topic
            $current_term_ids['topic'] = $term->term_id;
        }
        if ($term->taxonomy_name === 'source') { // Source or Section
            if ($term->parent != 0) {
                $current_term_ids['section'] = $term->term_id;
                $current_term_ids['source'] = $term->parent;
            } else {
                $current_term_ids['source'] = $term->term_id;
            }
        }
    }
    $current_labels = $current_term_ids['label'] ?? [];
    $current_topic_id = $current_term_ids['topic'] ?? 0;
    $current_source_id = $current_term_ids['source'] ?? 0;
    $current_section_id = $current_term_ids['section'] ?? 0;

    // --- Fetch question and group data ---
    $question = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, g.subject_id, g.direction_text, g.is_pyq, g.exam_id, g.pyq_year
         FROM {$wpdb->prefix}qp_questions q 
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id 
         WHERE q.question_id = %d",
        $question_id
    ));

    // --- Fetch all possible terms for the dropdowns ---
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
    $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");
    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");

    $all_subjects = $wpdb->get_results($wpdb->prepare("SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $subject_tax_id));
    $all_topics = $wpdb->get_results($wpdb->prepare("SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $subject_tax_id));
    $all_sources = $wpdb->get_results($wpdb->prepare("SELECT term_id AS source_id, name AS source_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $source_tax_id));
    $all_sections = $wpdb->get_results($wpdb->prepare("SELECT term_id AS section_id, name AS section_name, parent AS source_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $source_tax_id));
    $all_exams = $wpdb->get_results($wpdb->prepare("SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d", $exam_tax_id));
    $all_labels = $wpdb->get_results($wpdb->prepare("SELECT term_id as label_id, name as label_name FROM {$term_table} WHERE taxonomy_id = %d", $label_tax_id));
    $exam_subject_links = $wpdb->get_results("SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'");
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    $topics_by_subject = [];
    foreach ($all_topics as $topic) {
        $topics_by_subject[$topic->subject_id][] = ['id' => $topic->topic_id, 'name' => $topic->topic_name];
    }

    // --- NEW: Correctly build the sources_by_subject map ---
    $source_subject_links = $wpdb->get_results(
        "SELECT DISTINCT
        parent_source.term_id as source_id,
        subject_term.term_id as subject_id
    FROM {$wpdb->prefix}qp_questions q
    JOIN {$rel_table} subject_rel ON q.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'
    JOIN {$term_table} subject_term ON subject_rel.term_id = subject_term.term_id AND subject_term.parent = 0
    JOIN {$rel_table} source_rel ON q.question_id = source_rel.object_id AND source_rel.object_type = 'question'
    JOIN {$term_table} linked_source_term ON source_rel.term_id = linked_source_term.term_id
    JOIN {$term_table} parent_source ON (CASE WHEN linked_source_term.parent = 0 THEN linked_source_term.term_id ELSE linked_source_term.parent END) = parent_source.term_id"
    );
    $all_sources_map = [];
    foreach ($all_sources as $source) {
        $all_sources_map[$source->source_id] = $source->source_name;
    }
    $sources_by_subject = [];
    foreach ($source_subject_links as $link) {
        if (isset($all_sources_map[$link->source_id])) {
            $sources_by_subject[$link->subject_id][] = [
                'id' => $link->source_id,
                'name' => $all_sources_map[$link->source_id]
            ];
        }
    }
    // --- END NEW LOGIC ---

    $sections_by_source = [];
    foreach ($all_sections as $section) {
        $sections_by_source[$section->source_id][] = ['id' => $section->section_id, 'name' => $section->section_name];
    }

    // Start output buffering to capture the form HTML
    ob_start();
    ?>
    <script>
        var qp_quick_edit_data = {
            topics_by_subject: <?php echo json_encode($topics_by_subject); ?>,
            sources_by_subject: <?php echo json_encode($sources_by_subject); ?>, // This will be empty for now but that's okay
            sections_by_source: <?php echo json_encode($sections_by_source); ?>,
            all_exams: <?php echo json_encode($all_exams); ?>,
            exam_subject_links: <?php echo json_encode($exam_subject_links); ?>,
            current_topic_id: <?php echo json_encode($current_topic_id); ?>,
            current_source_id: <?php echo json_encode($current_source_id); ?>,
            current_section_id: <?php echo json_encode($current_section_id); ?>,
            current_exam_id: <?php echo json_encode($question->exam_id); ?>
        };
    </script>
    <form class="quick-edit-form-wrapper">
        <?php wp_nonce_field('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field'); ?>

        <div class="quick-edit-display-text">
            <?php if (!empty($question->direction_text)) : ?>
                <div class="display-group">
                    <strong>Direction:</strong>
                    <p><?php echo esc_html(wp_trim_words($question->direction_text, 50, '...')); ?></p>
                </div>
            <?php endif; ?>
            <div class="display-group">
                <strong>Question:</strong>
                <p><?php echo esc_html(wp_trim_words($question->question_text, 50, '...')); ?></p>
            </div>
        </div>

        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

        <div class="quick-edit-main-container">
            <div class="quick-edit-col-left">
                <label><strong>Correct Answer</strong></label>
                <div class="options-group">
                    <?php foreach ($options as $index => $option) : ?>
                        <label class="option-label">
                            <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                            <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="quick-edit-col-right">
                <div class="form-row-flex">
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                        <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>" class="qe-subject-select">
                            <?php foreach ($all_subjects as $subject) : ?>
                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $question->subject_id); ?>><?php echo esc_html($subject->subject_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-topic-<?php echo esc_attr($question_id); ?>"><strong>Topic</strong></label>
                        <select name="topic_id" id="qe-topic-<?php echo esc_attr($question_id); ?>" class="qe-topic-select" disabled>
                            <option value="">— Select subject first —</option>
                        </select>
                    </div>
                </div>
                <div class="form-row-flex">
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-source-<?php echo esc_attr($question_id); ?>"><strong>Source</strong></label>
                        <select name="source_id" id="qe-source-<?php echo esc_attr($question_id); ?>" class="qe-source-select" disabled>
                            <option value="">— Select Subject First —</option>
                        </select>
                    </div>
                    <div class="form-group-half qe-right-dropdowns">
                        <label for="qe-section-<?php echo esc_attr($question_id); ?>"><strong>Section</strong></label>
                        <select name="section_id" id="qe-section-<?php echo esc_attr($question_id); ?>" class="qe-section-select" disabled>
                            <option value="">— Select Source First —</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-flex qe-pyq-fields-wrapper" style="align-items: center;">
                    <div class="form-group-shrink">
                        <label class="inline-checkbox">
                            <input type="checkbox" name="is_pyq" value="1" class="qe-is-pyq-checkbox" <?php checked($question->is_pyq, 1); ?>> Is PYQ?
                        </label>
                    </div>
                    <div class="form-group-expand qe-pyq-fields" style="<?php echo $question->is_pyq ? '' : 'display: none;'; ?>">
                        <div class="form-group-half">
                            <select name="exam_id" class="qe-exam-select">
                                <option value="">— Select Exam —</option>
                                <?php foreach ($all_exams as $exam) : ?>
                                    <option value="<?php echo esc_attr($exam->exam_id); ?>" <?php selected($exam->exam_id, $question->exam_id); ?>><?php echo esc_html($exam->exam_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-half">
                            <input type="number" name="pyq_year" value="<?php echo esc_attr($question->pyq_year); ?>" placeholder="Year (e.g., 2023)">
                        </div>
                    </div>
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
        .quick-edit-display-text {
            background-color: #f6f7f7;
            border: 1px solid #e0e0e0;
            padding: 10px 20px;
            margin: 20px 20px 10px;
            border-radius: 4px
        }

        .quick-edit-display-text .display-group {
            margin-bottom: 10px
        }

        .options-group label:last-child,
        .quick-edit-display-text .display-group:last-child,
        .quick-edit-form-wrapper .form-row:last-child {
            margin-bottom: 0
        }

        .quick-edit-display-text p {
            margin: 5px 0 0;
            padding-left: 10px;
            border-left: 3px solid #ccc;
            color: #555;
            font-style: italic
        }

        .quick-edit-form-wrapper h4 {
            font-size: 16px;
            margin-top: 20px;
            margin-bottom: 10px;
            padding: 10px 20px
        }

        .inline-edit-row .submit {
            padding: 20px
        }

        .quick-edit-form-wrapper .title {
            font-size: 15px;
            font-weight: 500;
            color: #555
        }

        .quick-edit-form-wrapper .form-row,
        .quick-edit-form-wrapper .form-row-flex {
            margin-bottom: 1rem
        }

        .quick-edit-form-wrapper label,
        .quick-edit-form-wrapper strong {
            font-weight: 600;
            display: block;
            margin-bottom: 0rem
        }

        .quick-edit-form-wrapper select {
            width: 100%
        }

        .quick-edit-main-container {
            display: flex;
            gap: 20px;
            margin-bottom: 1rem;
            padding: 0 20px
        }

        .form-row-flex .qe-pyq-fields {
            display: flex;
            gap: 1rem;
        }

        .form-row-flex .qe-right-dropdowns {
            display: flex;
            flex-direction: row;
        }

        .form-row-flex .qe-right-dropdowns label {
            margin-right: 1rem;
            width: 15%;
        }

        .labels-group,
        .options-group {
            display: flex;
            padding: .5rem;
            border: 1px solid #ddd;
            background: #fff
        }

        .quick-edit-col-left {
            flex: 0 0 40%
        }

        .form-group-half,
        .quick-edit-col-right {
            flex: 1
        }

        .options-group {
            flex-direction: column;
            justify-content: space-between;
            height: auto;
            box-sizing: border-box;
            gap: 10px;
        }

        .option-label {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: .5rem
        }

        .option-label input[type=radio] {
            margin-top: 0;
            align-self: center
        }

        .option-label input[type=text] {
            width: 90%;
            background-color: #f0f0f1
        }

        .form-row-flex {
            display: flex;
            gap: 1rem
        }

        .quick-edit-form-wrapper p.submit button.button-secondary {
            margin-right: 10px
        }

        .labels-group {
            flex-wrap: wrap;
            gap: .5rem 1rem
        }

        .inline-checkbox {
            white-space: nowrap
        }
    </style>
    <?php
    wp_send_json_success(['form' => ob_get_clean()]);
}
add_action('wp_ajax_get_quick_edit_form', 'qp_get_quick_edit_form_ajax');

function qp_save_quick_edit_data_ajax()
{
    // Use the nonce field name directly from the form data
    check_ajax_referer('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field');

    // The data is now directly in the $_POST variable, no need to parse
    $data = $_POST;

    $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID in form data.']);
    }

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';

    // --- Get necessary IDs ---
    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM $q_table WHERE question_id = %d", $question_id));
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
    $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");
    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");

    // --- Update Group-Level Data (PYQ status) ---
    if ($group_id) {
        $wpdb->update($g_table, [
            'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
            'pyq_year' => isset($data['is_pyq']) ? sanitize_text_field($data['pyq_year']) : null
        ], ['group_id' => $group_id]);
    }

    // --- Update Group-Level Relationships (Subject & Exam) ---
    if ($group_id) {
        // Delete old subject and exam relationships for the group
        $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN (%d, %d))", $group_id, $subject_tax_id, $exam_tax_id));

        // Insert new subject relationship
        $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['subject_id']), 'object_type' => 'group']);

        // Insert new exam relationship if it's a PYQ
        if (isset($data['is_pyq']) && !empty($data['exam_id'])) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['exam_id']), 'object_type' => 'group']);
        }
    }

    // --- Update Question-Level Relationships (Topic, Source/Section, Labels) ---
    // Delete all old taxonomy relationships for this specific question
    $wpdb->delete($rel_table, ['object_id' => $question_id, 'object_type' => 'question']);

    $term_ids_to_link = [];
    if (!empty($data['topic_id'])) $term_ids_to_link[] = absint($data['topic_id']);
    if (!empty($data['section_id'])) {
        $term_ids_to_link[] = absint($data['section_id']); // Prefer section
    } elseif (!empty($data['source_id'])) {
        $term_ids_to_link[] = absint($data['source_id']); // Fallback to source
    }
    if (!empty($data['labels'])) {
        $term_ids_to_link = array_merge($term_ids_to_link, array_map('absint', $data['labels']));
    }

    foreach (array_unique($term_ids_to_link) as $term_id) {
        $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $term_id, 'object_type' => 'question']);
    }

    // --- Update Options (Correct Answer) ---
    $correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
    if ($correct_option_id) {
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 0], ['question_id' => $question_id]);
        $wpdb->update("{$wpdb->prefix}qp_options", ['is_correct' => 1], ['option_id' => $correct_option_id, 'question_id' => $question_id]);
    }

    // --- Re-render the updated row for the UI ---
    $list_table = new QP_Questions_List_Table();
    // We need to manually set the filters so prepare_items() finds the question
    // Use the status passed back from the form to look in the correct list (e.g., 'publish', 'draft')
    $_REQUEST['status'] = isset($_POST['question_status']) ? sanitize_key($_POST['question_status']) : 'publish';

    $list_table->prepare_items();
    $found_item = null;
    foreach ($list_table->items as $item) {
        if ($item['question_id'] == $question_id) {
            $found_item = $item;
            break;
        }
    }

    if ($found_item) {
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

/**
 * Schedules the session cleanup event if it's not already scheduled.
 */
function qp_schedule_session_cleanup()
{
    if (!wp_next_scheduled('qp_cleanup_abandoned_sessions_event')) {
        wp_schedule_event(time(), 'hourly', 'qp_cleanup_abandoned_sessions_event');
    }
}
add_action('wp', 'qp_schedule_session_cleanup');

/**
 * The function that runs on the scheduled cron event to clean up old sessions.
 */
function qp_cleanup_abandoned_sessions()
{
    global $wpdb;
    $options = get_option('qp_settings');
    $timeout_minutes = isset($options['session_timeout']) ? absint($options['session_timeout']) : 20;

    if ($timeout_minutes < 5) {
        $timeout_minutes = 20;
    }

    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // --- 1. Handle Expired Mock Tests ---
    $active_mock_tests = $wpdb->get_results(
        "SELECT session_id, start_time, settings_snapshot FROM {$sessions_table} WHERE status = 'mock_test'"
    );

    foreach ($active_mock_tests as $test) {
        $settings = json_decode($test->settings_snapshot, true);
        $duration_seconds = $settings['timer_seconds'] ?? 0;

        if ($duration_seconds <= 0) continue;

        $start_time_gmt = get_gmt_from_date($test->start_time);
        $start_timestamp = strtotime($start_time_gmt);
        $end_timestamp = $start_timestamp + $duration_seconds;

        // If the current time is past the test's official end time, finalize it as abandoned.
        if (time() > $end_timestamp) {
            // Our updated function will delete it if empty, or mark as abandoned if there are attempts.
            qp_finalize_and_end_session($test->session_id, 'abandoned', 'abandoned_system');
        }
    }

    // --- 2. Handle Abandoned 'active' or 'paused' sessions ---
    $abandoned_session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT session_id FROM {$sessions_table}
         WHERE status IN ('active', 'paused') AND last_activity < NOW() - INTERVAL %d MINUTE",
        $timeout_minutes
    ));

    if (!empty($abandoned_session_ids)) {
        foreach ($abandoned_session_ids as $session_id) {
            // Our updated function will delete if empty, or mark as abandoned if there are attempts.
            qp_finalize_and_end_session($session_id, 'abandoned', 'abandoned_system');
        }
    }
}
add_action('qp_cleanup_abandoned_sessions_event', 'qp_cleanup_abandoned_sessions');


/**
 * Handles the complete, on-demand data migration and cleanup process.
 * This is safe to run multiple times, as each step checks for completion.
 */
function qp_run_unified_data_migration()
{
    // Check if the trigger is present in the URL and if the user has permission
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_unified_migration' || !current_user_can('manage_options')) {
        return;
    }

    // Security check
    check_admin_referer('qp_unified_migration_nonce');

    global $wpdb;
    $messages = [];
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';
    $sources_table = $wpdb->prefix . 'qp_sources';
    $subjects_table = $wpdb->prefix . 'qp_subjects';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $exams_table = $wpdb->prefix . 'qp_exams';

    // === NEW STEP: ADD PAUSE TRACKING SCHEMA ===
    $sessions_columns = $wpdb->get_col("DESC $sessions_table", 0);
    if (!in_array('total_active_seconds', $sessions_columns)) {
        $wpdb->query("ALTER TABLE {$sessions_table} ADD COLUMN `total_active_seconds` INT UNSIGNED DEFAULT NULL AFTER `status`;");
        $messages[] = "Step 0.1: Added `total_active_seconds` column to the sessions table.";
    }
    $table_session_pauses = $wpdb->prefix . 'qp_session_pauses';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_session_pauses'") != $table_session_pauses) {
        $sql_session_pauses = "CREATE TABLE $table_session_pauses (
            pause_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT(20) UNSIGNED NOT NULL,
            pause_time DATETIME NOT NULL,
            resume_time DATETIME DEFAULT NULL,
            PRIMARY KEY (pause_id),
            KEY session_id (session_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_session_pauses);
        $messages[] = "Step 0.2: Created the `{$table_session_pauses}` table for tracking session pauses.";
    }

    // === NEW STEP: ADD end_reason COLUMN FOR SESSION STATUS ===
    $sessions_columns = $wpdb->get_col("DESC $sessions_table", 0);
    if (!in_array('end_reason', $sessions_columns)) {
        $wpdb->query("ALTER TABLE {$sessions_table} ADD COLUMN `end_reason` VARCHAR(50) DEFAULT NULL AFTER `marks_obtained`;");
        $messages[] = "Step 0.3: Added `end_reason` column to the sessions table for detailed status.";
    }

    // === STEP 0: MIGRATE qp_user_sessions TABLE SCHEMA ===
    $sessions_columns = $wpdb->get_col("DESC $sessions_table", 0);
    $columns_to_add = [
        'status' => "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER `end_time`",
        'last_activity' => "ADD COLUMN `last_activity` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER `start_time`",
        'question_ids_snapshot' => "ADD COLUMN `question_ids_snapshot` LONGTEXT AFTER `settings_snapshot`"
    ];

    foreach ($columns_to_add as $column_name => $query_part) {
        if (!in_array($column_name, $sessions_columns)) {
            $wpdb->query("ALTER TABLE {$sessions_table} {$query_part};");
            $messages[] = "Step 0: Added `{$column_name}` column to the sessions table.";
        }
    }
    $wpdb->query("UPDATE {$sessions_table} SET `last_activity` = `start_time` WHERE `last_activity` = '0000-00-00 00:00:00'");

    // === STEP 1 (CORRECTED): PARSE LEGACY PYQ TAGS FROM QUESTION TEXT ===
    // This now checks the OLD `is_pyq` flag directly on the questions table.
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'is_pyq'")) {
        $pyq_questions_to_parse = $wpdb->get_results(
            "SELECT q.question_id, q.question_text, q.group_id
             FROM {$questions_table} q
             JOIN {$groups_table} g ON q.group_id = g.group_id
             WHERE q.question_text LIKE '%[%' AND g.exam_id IS NULL AND q.is_pyq = 1"
        );

        if (!empty($pyq_questions_to_parse)) {
            $parsed_count = 0;
            foreach ($pyq_questions_to_parse as $question) {
                preg_match('/\[([A-Z]+)\s*(\d{4})(?:\(\w+\))?\]$/', trim($question->question_text), $matches);

                if (count($matches) === 3) {
                    $exam_name = sanitize_text_field($matches[1]);
                    $pyq_year = sanitize_text_field($matches[2]);

                    $exam_id = $wpdb->get_var($wpdb->prepare("SELECT exam_id FROM {$exams_table} WHERE exam_name = %s", $exam_name));
                    if (!$exam_id) {
                        $wpdb->insert($exams_table, ['exam_name' => $exam_name]);
                        $exam_id = $wpdb->insert_id;
                    }

                    if ($exam_id && $question->group_id) {
                        $wpdb->update(
                            $groups_table,
                            ['exam_id' => $exam_id, 'pyq_year' => $pyq_year],
                            ['group_id' => $question->group_id]
                        );

                        $clean_text = preg_replace('/\[([A-Z]+)\s*(\d{4})(?:\(\w+\))?\]$/', '', trim($question->question_text));
                        $wpdb->update(
                            $questions_table,
                            ['question_text' => trim($clean_text)],
                            ['question_id' => $question->question_id]
                        );
                        $parsed_count++;
                    }
                }
            }
            if ($parsed_count > 0) {
                $messages[] = "Step 1: Parsed and migrated Exam/Year data for {$parsed_count} PYQ questions.";
            }
        }
    }


    // === STEP 2: MIGRATE LEGACY source_file to qp_sources TABLE ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'source_file'")) {
        $old_source_files = $wpdb->get_col("SELECT DISTINCT source_file FROM {$questions_table} WHERE source_file IS NOT NULL AND source_file != ''");
        if (!empty($old_source_files)) {
            $migrated_source_count = 0;
            foreach ($old_source_files as $source_file_name) {
                $existing_source_id = $wpdb->get_var($wpdb->prepare("SELECT source_id FROM {$sources_table} WHERE source_name = %s", $source_file_name));
                if (null === $existing_source_id) {
                    $wpdb->insert($sources_table, ['source_name' => $source_file_name, 'description' => 'Migrated from old source file.', 'subject_id' => 0]);
                    $new_source_id = $wpdb->insert_id;
                    $migrated_source_count++;
                } else {
                    $new_source_id = $existing_source_id;
                }
                $wpdb->update($questions_table, ['source_id' => $new_source_id], ['source_file' => $source_file_name]);
            }
            if ($migrated_source_count > 0) $messages[] = "Step 2: Created {$migrated_source_count} new entries in the Sources table from legacy data.";
        }
    }

    // === STEP 3: ASSIGN ORPHANED SOURCES TO "UNCATEGORIZED" SUBJECT ===
    $uncategorized_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM {$subjects_table} WHERE subject_name = %s", 'Uncategorized'));
    if ($uncategorized_id) {
        $updated_rows = $wpdb->update($sources_table, ['subject_id' => $uncategorized_id], ['subject_id' => 0]);
        if ($updated_rows > 0) $messages[] = "Step 3: Assigned {$updated_rows} orphaned sources to the 'Uncategorized' subject.";
    }

    // === STEP 4: MIGRATE LEGACY source_number TO question_number_in_section ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'source_number'")) {
        $questions_to_migrate_num = $wpdb->get_results("SELECT question_id, source_number FROM {$questions_table} WHERE source_number IS NOT NULL AND (question_number_in_section IS NULL OR question_number_in_section = '')");
        if (!empty($questions_to_migrate_num)) {
            foreach ($questions_to_migrate_num as $q) {
                $wpdb->update($questions_table, ['question_number_in_section' => $q->source_number], ['question_id' => $q->question_id]);
            }
            $messages[] = "Step 4: Migrated question numbers for " . count($questions_to_migrate_num) . " questions.";
        }
    }

    // === STEP 5 (IMPROVED): MIGRATE PYQ STATUS TO GROUPS ===
    if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE 'is_pyq'")) {
        $groups_to_check = $wpdb->get_col("SELECT group_id FROM {$groups_table} WHERE is_pyq = 0");
        $migrated_groups_count = 0;
        if (!empty($groups_to_check)) {
            foreach ($groups_to_check as $group_id) {
                $is_legacy_pyq = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$questions_table} WHERE group_id = %d AND is_pyq = 1",
                    $group_id
                ));

                if ($is_legacy_pyq > 0) {
                    $wpdb->update($groups_table, ['is_pyq' => 1], ['group_id' => $group_id]);
                    $migrated_groups_count++;
                }
            }
            if ($migrated_groups_count > 0) $messages[] = "Step 5: Updated PYQ status for {$migrated_groups_count} question group(s).";
        }
    }

    // === STEP 6 (IMPROVED): ROBUST DATABASE CLEANUP ===
    $columns_to_drop = ['source_file', 'source_page', 'source_number', 'is_pyq', 'exam_id', 'pyq_year'];
    $dropped_columns = [];
    foreach ($columns_to_drop as $column) {
        if ($wpdb->get_col("SHOW COLUMNS FROM {$questions_table} LIKE '{$column}'")) {
            $wpdb->query("ALTER TABLE {$questions_table} DROP COLUMN {$column};");
            $dropped_columns[] = "<code>{$column}</code>";
        }
    }
    if (!empty($dropped_columns)) {
        $messages[] = "Step 6: Finalized cleanup by removing old columns: " . implode(', ', $dropped_columns) . " from the questions table.";
    }

    // --- Final Redirect ---
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($messages)) {
            $_SESSION['qp_admin_message'] = 'Database is already fully up-to-date. No migration steps were needed.';
            $_SESSION['qp_admin_message_type'] = 'info';
        } else {
            $_SESSION['qp_admin_message'] = '<strong>Migration & Cleanup Report:</strong><br> - ' . implode('<br> - ', $messages);
            $_SESSION['qp_admin_message_type'] = 'success';
        }
    }
    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}

/**
 * Handles the v3.3+ migration from separate taxonomy tables to the new unified term-based system.
 * This function is idempotent and safe to run multiple times.
 */
function qp_run_v3_taxonomy_migration()
{
    // Check if the trigger is present, user has permission, and nonce is valid
    if (!isset($_GET['action']) || $_GET['action'] !== 'qp_v3_taxonomy_migration' || !current_user_can('manage_options')) {
        return;
    }
    check_admin_referer('qp_v3_taxonomy_migration_nonce');

    global $wpdb;
    $messages = [];

    // Table names
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $meta_table = $wpdb->prefix . 'qp_term_meta';

    $wpdb->query("START TRANSACTION;");

    try {
        // Step 1: Define the taxonomies in the new system (Unchanged)
        $taxonomies = [
            'subject' => ['label' => 'Subjects', 'hierarchical' => 1],
            'label'   => ['label' => 'Labels', 'hierarchical' => 0],
            'exam'    => ['label' => 'Exams', 'hierarchical' => 0],
            'source'  => ['label' => 'Sources', 'hierarchical' => 1],
        ];
        foreach ($taxonomies as $name => $data) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = %s", $name));
            if (!$exists) {
                $wpdb->insert($tax_table, ['taxonomy_name'  => $name, 'taxonomy_label' => $data['label'], 'hierarchical'   => $data['hierarchical']]);
            }
        }
        $messages[] = "Step 1: Core taxonomies registered.";

        // Step 2: Migrate Subjects and Topics (Unchanged)
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        $old_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects");
        $subject_map = []; // [old_id => new_term_id]
        foreach ($old_subjects as $subject) {
            $subject_map[$subject->subject_id] = qp_get_or_create_term($subject->subject_name, $subject_tax_id, 0);
        }
        $old_topics = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_topics");
        $topic_map = []; // [old_id => new_term_id]
        foreach ($old_topics as $topic) {
            $parent_term_id = $subject_map[$topic->subject_id] ?? 0;
            $topic_map[$topic->topic_id] = qp_get_or_create_term($topic->topic_name, $subject_tax_id, $parent_term_id);
        }
        $messages[] = "Step 2: Migrated " . count($subject_map) . " subjects and " . count($topic_map) . " topics.";

        // Step 3 & 4: Migrate Labels and Exams (Unchanged)
        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");
        $old_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels");
        $label_map = [];
        foreach ($old_labels as $label) {
            $new_term_id = qp_get_or_create_term($label->label_name, $label_tax_id);
            $label_map[$label->label_id] = $new_term_id;
            qp_update_term_meta($new_term_id, 'color', $label->label_color);
            qp_update_term_meta($new_term_id, 'description', $label->description);
            qp_update_term_meta($new_term_id, 'is_default', $label->is_default);
        }
        $messages[] = "Step 3: Migrated " . count($label_map) . " labels.";

        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
        $old_exams = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_exams");
        $exam_map = [];
        foreach ($old_exams as $exam) {
            $exam_map[$exam->exam_id] = qp_get_or_create_term($exam->exam_name, $exam_tax_id);
        }
        $messages[] = "Step 4: Migrated " . count($exam_map) . " exams.";

        // Step 5: Migrate Relationships (REVISED)
        // Topics -> Questions & Labels -> Questions
        $question_topics = $wpdb->get_results("SELECT question_id, topic_id FROM {$wpdb->prefix}qp_questions WHERE topic_id > 0");
        foreach ($question_topics as $qt) {
            if (isset($topic_map[$qt->topic_id])) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'question')", $qt->question_id, $topic_map[$qt->topic_id]));
            }
        }
        $question_labels = $wpdb->get_results("SELECT question_id, label_id FROM {$wpdb->prefix}qp_question_labels");
        foreach ($question_labels as $ql) {
            if (isset($label_map[$ql->label_id])) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'question')", $ql->question_id, $label_map[$ql->label_id]));
            }
        }
        
        // REVISED LOGIC: Reconstruct Group -> Subject relationships
        $group_subject_pairs = $wpdb->get_results(
            "SELECT DISTINCT q.group_id, t.subject_id
             FROM {$wpdb->prefix}qp_questions q
             JOIN {$wpdb->prefix}qp_topics t ON q.topic_id = t.topic_id
             WHERE q.group_id > 0 AND t.subject_id > 0"
        );
        foreach ($group_subject_pairs as $pair) {
            if (isset($subject_map[$pair->subject_id])) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'group')", $pair->group_id, $subject_map[$pair->subject_id]));
            }
        }

        // Exams -> Question Groups
        $group_exams = $wpdb->get_results("SELECT group_id, exam_id FROM {$wpdb->prefix}qp_question_groups WHERE exam_id > 0");
        foreach ($group_exams as $ge) {
            if (isset($exam_map[$ge->exam_id])) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'group')", $ge->group_id, $exam_map[$ge->exam_id]));
            }
        }
        $messages[] = "Step 5: Migrated relationships for subjects, topics, labels, and exams.";

        // Step 6: Migrate Source Hierarchy (Unchanged from our last correct version)
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");
        $hierarchy_map = [];
        $old_sources_raw = $wpdb->get_results("SELECT source_id, source_name FROM {$wpdb->prefix}qp_sources", OBJECT_K);
        $old_topics_raw = $wpdb->get_results("SELECT topic_id, topic_name FROM {$wpdb->prefix}qp_topics", OBJECT_K);
        $old_sections_raw = $wpdb->get_results("SELECT section_id, section_name FROM {$wpdb->prefix}qp_source_sections", OBJECT_K);
        $unique_hierarchies = $wpdb->get_results("SELECT DISTINCT source_id, topic_id, section_id FROM {$wpdb->prefix}qp_questions WHERE source_id > 0");
        
        foreach ($unique_hierarchies as $path) {
            $source_id = $path->source_id; $topic_id = $path->topic_id; $section_id = $path->section_id;
            $source_name = $old_sources_raw[$source_id]->source_name ?? 'Unknown Source';
            $new_source_term_id = qp_get_or_create_term($source_name, $source_tax_id, 0);
            if (!isset($hierarchy_map[$source_id])) $hierarchy_map[$source_id] = [];
            $hierarchy_map[$source_id][0][0] = $new_source_term_id;
            if ($topic_id > 0) {
                $topic_name = $old_topics_raw[$topic_id]->topic_name ?? 'Unknown Topic';
                $new_topic_term_id = qp_get_or_create_term($topic_name, $source_tax_id, $new_source_term_id);
                if (!isset($hierarchy_map[$source_id][$topic_id])) $hierarchy_map[$source_id][$topic_id] = [];
                $hierarchy_map[$source_id][$topic_id][0] = $new_topic_term_id;
                if ($section_id > 0) {
                    $section_name = $old_sections_raw[$section_id]->section_name ?? 'Unknown Section';
                    $new_section_term_id = qp_get_or_create_term($section_name, $source_tax_id, $new_topic_term_id);
                    $hierarchy_map[$source_id][$topic_id][$section_id] = $new_section_term_id;
                }
            }
        }
        $all_questions = $wpdb->get_results("SELECT question_id, source_id, topic_id, section_id FROM {$wpdb->prefix}qp_questions");
        foreach ($all_questions as $question) {
            $term_to_link = null;
            if (isset($hierarchy_map[$question->source_id][$question->topic_id][$question->section_id])) {
                $term_to_link = $hierarchy_map[$question->source_id][$question->topic_id][$question->section_id];
            } elseif (isset($hierarchy_map[$question->source_id][$question->topic_id][0])) {
                $term_to_link = $hierarchy_map[$question->source_id][$question->topic_id][0];
            } elseif (isset($hierarchy_map[$question->source_id][0][0])) {
                $term_to_link = $hierarchy_map[$question->source_id][0][0];
            }
            if ($term_to_link) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'question')", $question->question_id, $term_to_link));
            }
        }
        $messages[] = "Step 6: Migrated and linked source hierarchy.";

        // NEW Step 7: Create Source-to-Subject links
        $source_subject_links = $wpdb->get_results(
            "SELECT DISTINCT q.source_id, t.subject_id
             FROM {$wpdb->prefix}qp_questions q
             JOIN {$wpdb->prefix}qp_topics t ON q.topic_id = t.topic_id
             WHERE q.source_id > 0 AND t.subject_id > 0"
        );
        $links_created = 0;
        foreach ($source_subject_links as $link) {
            if (isset($hierarchy_map[$link->source_id][0][0]) && isset($subject_map[$link->subject_id])) {
                $source_term_id = $hierarchy_map[$link->source_id][0][0];
                $subject_term_id = $subject_map[$link->subject_id];
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'source_subject_link')",
                    $source_term_id,
                    $subject_term_id
                ));
                $links_created++;
            }
        }
        $messages[] = "Step 7: Created {$links_created} Source-to-Subject links.";

        // OLD Step 7 is now removed as it's obsolete.

        $wpdb->query("COMMIT;");
        $_SESSION['qp_admin_message'] = '<strong>Taxonomy Migration Report:</strong><br> - ' . implode('<br> - ', $messages);
        $_SESSION['qp_admin_message_type'] = 'success';
    } catch (Exception $e) {
        $wpdb->query("ROLLBACK;");
        $_SESSION['qp_admin_message'] = 'An error occurred during migration: ' . $e->getMessage();
        $_SESSION['qp_admin_message_type'] = 'error';
    }

    wp_safe_redirect(remove_query_arg(['action', '_wpnonce']));
    exit;
}

/**
 * AJAX handler to update the last_activity timestamp for a session.
 */
function qp_update_session_activity_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    if ($session_id > 0) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'qp_user_sessions',
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id]
        );
    }
    wp_send_json_success();
}
add_action('wp_ajax_update_session_activity', 'qp_update_session_activity_ajax');


/**
 * AJAX handler to add or remove a question from the user's review list.
 */
function qp_toggle_review_later_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    // --- THIS IS THE CORRECTED LOGIC ---
    // We explicitly check if the string from AJAX is 'true'.
    // Anything else, including 'false', will result in a boolean false.
    $is_marked = isset($_POST['is_marked']) && $_POST['is_marked'] === 'true';
    // --- END OF FIX ---
    $user_id = get_current_user_id();

    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid question ID.']);
    }

    global $wpdb;
    $review_table = $wpdb->prefix . 'qp_review_later';

    if ($is_marked) {
        // This block now only runs when the box is checked.
        $wpdb->insert(
            $review_table,
            ['user_id' => $user_id, 'question_id' => $question_id],
            ['%d', '%d']
        );
    } else {
        // This block now correctly runs when the box is unchecked.
        $wpdb->delete(
            $review_table,
            ['user_id' => $user_id, 'question_id' => $question_id],
            ['%d', '%d']
        );
    }

    wp_send_json_success();
}
add_action('wp_ajax_qp_toggle_review_later', 'qp_toggle_review_later_ajax');



/**
 * AJAX handler to start a special session with only the questions marked for review.
 */
function qp_start_review_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    global $wpdb;
    $user_id = get_current_user_id();

    // Get all question IDs from the user's review list
    $review_question_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT question_id FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d ORDER BY review_id ASC",
        $user_id
    ));

    if (empty($review_question_ids)) {
        wp_send_json_error(['message' => 'Your review list is empty.']);
    }

    // Get the Session Page URL from settings
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    // Create a special settings snapshot for this review session
    $session_settings = [
        'subject_id'      => 'review', // Special identifier
        'topic_id'        => 'all',
        'sheet_label_id'  => 'all',
        'pyq_only'        => false,
        'revise_mode'     => true, // Treat it as revision
        'marks_correct'   => 1.0,  // Or any default you prefer
        'marks_incorrect' => 0,
        'timer_enabled'   => false,
    ];

    // Create the new session record
    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode($review_question_ids)
    ]);
    $session_id = $wpdb->insert_id;

    // Build the redirect URL and send it back
    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url]);
}
add_action('wp_ajax_qp_start_review_session', 'qp_start_review_session_ajax');

/**
 * AJAX handler to get the full data for a single question for the review popup.
 */
function qp_get_single_question_for_review_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid question ID.']);
    }

    global $wpdb;

    // Fetch question details using the new taxonomy system
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_text, q.custom_question_id, g.direction_text, 
                (SELECT t.name FROM {$wpdb->prefix}qp_terms t JOIN {$wpdb->prefix}qp_term_relationships r ON t.term_id = r.term_id WHERE r.object_id = g.group_id AND r.object_type = 'group' AND t.parent = 0) as subject_name
         FROM {$wpdb->prefix}qp_questions q
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    // Fetch options (this part remains the same)
    $options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC",
        $question_id
    ), ARRAY_A);

    $question_data['options'] = $options;

    wp_send_json_success($question_data);
}
add_action('wp_ajax_get_single_question_for_review', 'qp_get_single_question_for_review_ajax');

/**
 * AJAX handler to terminate an active session.
 */
function qp_terminate_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $user_id = get_current_user_id();

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    // --- THE FIX: Delete the session and its attempts directly ---
    $wpdb->delete($attempts_table, ['session_id' => $session_id]);
    $wpdb->delete($sessions_table, ['session_id' => $session_id]);

    wp_send_json_success(['message' => 'Session terminated and removed successfully.']);
}
add_action('wp_ajax_qp_terminate_session', 'qp_terminate_session_ajax');

/**
 * AJAX handler to pause a session.
 */
function qp_pause_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $user_id = get_current_user_id();

    if (!$session_id) {
        wp_send_json_error(['message' => 'Invalid session ID.']);
    }

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $pauses_table = $wpdb->prefix . 'qp_session_pauses';

    // Security check: ensure the session belongs to the current user
    $session_owner = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $sessions_table WHERE session_id = %d", $session_id));
    if ((int)$session_owner !== $user_id) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    // Update the session status to 'paused' and update the activity time
    $wpdb->update(
        $sessions_table,
        [
            'status' => 'paused',
            'last_activity' => current_time('mysql')
        ],
        ['session_id' => $session_id]
    );

    // Log this pause event in the new table
    $wpdb->insert(
        $pauses_table,
        [
            'session_id' => $session_id,
            'pause_time' => current_time('mysql') // Use GMT time for consistency
        ]
    );

    wp_send_json_success(['message' => 'Session paused successfully.']);
}
add_action('wp_ajax_qp_pause_session', 'qp_pause_session_ajax');


function qp_handle_log_settings_forms()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['tab']) || $_GET['tab'] !== 'log_settings') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'qp_report_reasons';

    // Add/Update Reason
    if (isset($_POST['action']) && ($_POST['action'] === 'add_reason' || $_POST['action'] === 'update_reason') && check_admin_referer('qp_add_edit_reason_nonce')) {
        $reason_text = sanitize_text_field($_POST['reason_text']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $data = ['reason_text' => $reason_text, 'is_active' => $is_active];

        if ($_POST['action'] === 'update_reason') {
            $wpdb->update($table_name, $data, ['reason_id' => absint($_POST['reason_id'])]);
        } else {
            $wpdb->insert($table_name, $data);
        }
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=1'));
        exit;
    }

    // Delete Reason
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['reason_id']) && check_admin_referer('qp_delete_reason_' . absint($_GET['reason_id']))) {
        $wpdb->delete($table_name, ['reason_id' => absint($_GET['reason_id'])]);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=2'));
        exit;
    }
}
add_action('admin_init', 'qp_handle_log_settings_forms');

function qp_handle_report_actions()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['action'])) {
        return;
    }

    global $wpdb;
    $reports_table = "{$wpdb->prefix}qp_question_reports";

    // Handle single resolve action
    if ($_GET['action'] === 'resolve_report' && isset($_GET['question_id'])) {
        $question_id = absint($_GET['question_id']);
        check_admin_referer('qp_resolve_report_' . $question_id);
        $wpdb->update($reports_table, ['status' => 'resolved'], ['question_id' => $question_id, 'status' => 'open']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&message=3'));
        exit;
    }

    // Handle single re-open action
    if ($_GET['action'] === 'reopen_report' && isset($_GET['question_id'])) {
        $question_id = absint($_GET['question_id']);
        check_admin_referer('qp_reopen_report_' . $question_id);
        $wpdb->update($reports_table, ['status' => 'open'], ['question_id' => $question_id, 'status' => 'resolved']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=4'));
        exit;
    }

    // Handle clearing all resolved reports
    if ($_GET['action'] === 'clear_resolved_reports') {
        check_admin_referer('qp_clear_all_reports_nonce');
        $wpdb->delete($reports_table, ['status' => 'resolved']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=5'));
        exit;
    }
}
add_action('admin_init', 'qp_handle_report_actions');

/**
 * Handles resolving all open reports for a group from the question editor page.
 */
function qp_handle_resolve_from_editor()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-edit-group' || !isset($_GET['action']) || $_GET['action'] !== 'resolve_group_reports') {
        return;
    }

    $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
    if (!$group_id) return;

    check_admin_referer('qp_resolve_group_reports_' . $group_id);

    global $wpdb;
    $questions_in_group_ids = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d", $group_id));

    if (!empty($questions_in_group_ids)) {
        $ids_placeholder = implode(',', $questions_in_group_ids);
        $wpdb->query("UPDATE {$wpdb->prefix}qp_question_reports SET status = 'resolved' WHERE question_id IN ({$ids_placeholder}) AND status = 'open'");
    }

    // Redirect back to the editor page with a success message
    wp_safe_redirect(admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1'));
    exit;
}
add_action('admin_init', 'qp_handle_resolve_from_editor');
