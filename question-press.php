<?php

/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           3.4.4
 * Author:            Himanshu
 */

if (!defined('ABSPATH')) exit;

/**
 * Start session on init hook.
 */
function qp_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
add_action('init', 'qp_start_session', 1); // Run early on init

// Define constants and include files
define('QP_PLUGIN_FILE', __FILE__);
define('QP_PLUGIN_DIR', plugin_dir_path(QP_PLUGIN_FILE));
define('QP_PLUGIN_URL', plugin_dir_url(QP_PLUGIN_FILE));

require_once QP_PLUGIN_DIR . 'admin/class-qp-subjects-page.php';
require_once QP_PLUGIN_DIR . 'admin/class-qp-labels-page.php';
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
require_once QP_PLUGIN_DIR . 'admin/class-qp-entitlements-list-table.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-shortcodes.php';
require_once QP_PLUGIN_DIR . 'public/class-qp-dashboard.php';
require_once QP_PLUGIN_DIR . 'api/class-qp-rest-api.php';

// Activation, Deactivation, Uninstall Hooks
function qp_activate_plugin()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table: Groups (Migrate some columns from question table here & Remove some columns after update)
    $table_groups = $wpdb->prefix . 'qp_question_groups';
    $sql_groups = "CREATE TABLE $table_groups (
        group_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        direction_text LONGTEXT,
        direction_image_id BIGINT(20) UNSIGNED,
        is_pyq BOOLEAN NOT NULL DEFAULT 0,
        pyq_year VARCHAR(4) DEFAULT NULL,
        PRIMARY KEY (group_id),
        KEY is_pyq (is_pyq)
    ) $charset_collate;";
    dbDelta($sql_groups);

    // Table: Questions (Remove some columns after update)
    $table_questions = $wpdb->prefix . 'qp_questions';
    $sql_questions = "CREATE TABLE $table_questions (
        question_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT(20) UNSIGNED,
        question_number_in_section VARCHAR(20) DEFAULT NULL,
        question_text LONGTEXT NOT NULL,
        question_text_hash VARCHAR(32) NOT NULL,
        duplicate_of BIGINT(20) UNSIGNED DEFAULT NULL,
        import_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        last_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (question_id),
        KEY group_id (group_id),
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

    // Table: Session Pauses
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


    // Table: User Attempts
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

    // Table: Logs (Needs Attention if it's relevant or not)
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

    // Table: Review Later Questions
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

    // Table: Question Reports (Needs Attention for better handling)
    $table_question_reports = $wpdb->prefix . 'qp_question_reports';
    $sql_question_reports = "CREATE TABLE $table_question_reports (
        report_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        reason_term_ids TEXT NOT NULL,
        comment TEXT,
        report_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        PRIMARY KEY (report_id),
        KEY question_id (question_id),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_question_reports);

    // Table: Revision Mode Attempts (Needs Attention on how it retains data)
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

    // === NEW TAXONOMY TABLES ===

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

    // 5. Courses Table
    $table_courses = $wpdb->prefix . 'qp_courses';
    $sql_courses = "CREATE TABLE $table_courses (
        course_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        description LONGTEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        author_id BIGINT(20) UNSIGNED NOT NULL,
        created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modified_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        menu_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (course_id),
        UNIQUE KEY slug (slug(191)),
        KEY status (status),
        KEY author_id (author_id)
    ) $charset_collate;";
    dbDelta($sql_courses);

    // 6. Course Sections Table
    $table_course_sections = $wpdb->prefix . 'qp_course_sections';
    $sql_course_sections = "CREATE TABLE $table_course_sections (
        section_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        course_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        section_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (section_id),
        KEY course_id (course_id),
        KEY section_order (section_order)
    ) $charset_collate;";
    dbDelta($sql_course_sections);

    // 7. Course Items Table
    $table_course_items = $wpdb->prefix . 'qp_course_items';
    $sql_course_items = "CREATE TABLE $table_course_items (
        item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        section_id BIGINT(20) UNSIGNED NOT NULL,
        course_id BIGINT(20) UNSIGNED NOT NULL, /* Denormalized for easier queries */
        title VARCHAR(255) NOT NULL,
        item_order INT NOT NULL DEFAULT 0,
        content_type VARCHAR(50) NOT NULL, /* e.g., 'test_series', 'pdf', 'video', 'lesson' */
        content_config LONGTEXT, /* JSON configuration for the item */
        PRIMARY KEY (item_id),
        KEY section_id (section_id),
        KEY course_id (course_id), /* Index denormalized course_id */
        KEY item_order (item_order)
    ) $charset_collate;";
    dbDelta($sql_course_items);

    // 8. User Courses Table (Enrollment & Overall Progress)
    $table_user_courses = $wpdb->prefix . 'qp_user_courses';
    $sql_user_courses = "CREATE TABLE $table_user_courses (
        user_course_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        course_id BIGINT(20) UNSIGNED NOT NULL,
        enrollment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completion_date DATETIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'enrolled', /* e.g., enrolled, in_progress, completed */
        progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_accessed_item_id BIGINT(20) UNSIGNED DEFAULT NULL, /* Optional: For resuming */
        PRIMARY KEY (user_course_id),
        UNIQUE KEY user_course (user_id, course_id),
        KEY user_id (user_id),
        KEY course_id (course_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_user_courses);

    // 9. User Items Progress Table
    $table_user_items_progress = $wpdb->prefix . 'qp_user_items_progress';
    $sql_user_items_progress = "CREATE TABLE $table_user_items_progress (
        user_item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        item_id BIGINT(20) UNSIGNED NOT NULL,
        course_id BIGINT(20) UNSIGNED NOT NULL, /* Denormalized */
        status VARCHAR(20) NOT NULL DEFAULT 'not_started', /* e.g., not_started, in_progress, completed */
        completion_date DATETIME DEFAULT NULL,
        result_data TEXT DEFAULT NULL, /* JSON for score/details */
        last_viewed DATETIME DEFAULT NULL,
        PRIMARY KEY (user_item_id),
        UNIQUE KEY user_item (user_id, item_id),
        KEY user_id (user_id),
        KEY item_id (item_id),
        KEY course_id (course_id), /* Index denormalized course_id */
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_user_items_progress);

    // === NEW USER ENTITLEMENTS TABLE ===
    $table_user_entitlements = $wpdb->prefix . 'qp_user_entitlements';
    $sql_user_entitlements = "CREATE TABLE $table_user_entitlements (
        entitlement_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        plan_id BIGINT(20) UNSIGNED NOT NULL,
        order_id BIGINT(20) UNSIGNED NOT NULL,
        start_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
        expiry_date DATETIME DEFAULT NULL,
        remaining_attempts INT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL,
        PRIMARY KEY  (entitlement_id),
        KEY user_id (user_id),
        KEY plan_id (plan_id),
        KEY order_id (order_id),
        KEY status (status),
        KEY expiry_date (expiry_date)
    ) $charset_collate;";
    dbDelta($sql_user_entitlements);

    // === SCHEDULE CRON JOBS ===
    if (!wp_next_scheduled('qp_check_entitlement_expiration_hook')) {
        // Schedule to run daily, around midnight server time. Adjust 'daily' if needed.
        wp_schedule_event(time(), 'daily', 'qp_check_entitlement_expiration_hook');
        error_log("QP Cron: Scheduled entitlement expiration check.");
    }

    $default_taxonomies = [
        ['taxonomy_name' => 'subject', 'taxonomy_label' => 'Subjects', 'hierarchical' => 1],
        ['taxonomy_name' => 'label', 'taxonomy_label' => 'Labels', 'hierarchical' => 0],
        ['taxonomy_name' => 'exam', 'taxonomy_label' => 'Exams', 'hierarchical' => 0],
        ['taxonomy_name' => 'source', 'taxonomy_label' => 'Sources', 'hierarchical' => 1],
        ['taxonomy_name' => 'report_reason', 'taxonomy_label' => 'Report Reasons', 'hierarchical' => 0],
    ];

    foreach ($default_taxonomies as $tax) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM $table_taxonomies WHERE taxonomy_name = %s", $tax['taxonomy_name']));
        if (!$exists) {
            $wpdb->insert($table_taxonomies, $tax);
        }
    }

    // Get the taxonomy IDs for labels and report reasons
    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'label'");
    $reason_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'report_reason'");

    // Define and insert default labels
    $default_labels = [
        ['name' => 'Wrong Answer', 'color' => '#ff5733', 'description' => 'Reported by users for having an incorrect answer key.'],
        ['name' => 'No Answer', 'color' => '#ffc300', 'description' => 'Reported by users because the question has no correct option provided.'],
        ['name' => 'Incorrect Formatting', 'color' => '#900c3f', 'description' => 'Reported by users for formatting or display issues.'],
        ['name' => 'Wrong Subject', 'color' => '#581845', 'description' => 'Reported by users for being in the wrong subject category.'],
        ['name' => 'Duplicate', 'color' => '#c70039', 'description' => 'Automatically marked as a duplicate of another question during import.']
    ];

    if ($label_tax_id) {
        foreach ($default_labels as $label) {
            $term_id = qp_get_or_create_term($label['name'], $label_tax_id);
            if ($term_id) {
                qp_update_term_meta($term_id, 'color', $label['color']);
                qp_update_term_meta($term_id, 'description', $label['description']);
                qp_update_term_meta($term_id, 'is_default', '1');
            }
        }
    }

    // Define and insert default report reasons
    $default_reasons = [
        ['text' => 'Wrong Answer', 'type' => 'report'],
        ['text' => 'Options are incorrect', 'type' => 'report'],
        ['text' => 'Image is not loading', 'type' => 'report'],
        ['text' => 'No Answer Provided', 'type' => 'report'],
        ['text' => 'Other (Error)', 'type' => 'report'],
        ['text' => 'Wrong Formatting', 'type' => 'suggestion'],
        ['text' => 'Language Mistakes', 'type' => 'suggestion'],
        ['text' => 'Question is confusing', 'type' => 'suggestion'],
        ['text' => 'Other (Suggestion)', 'type' => 'suggestion']
    ];

    if ($reason_tax_id) {
        foreach ($default_reasons as $reason) {
            $term_id = qp_get_or_create_term($reason['text'], $reason_tax_id);
            if ($term_id) {
                qp_update_term_meta($term_id, 'is_active', '1');
                qp_update_term_meta($term_id, 'type', $reason['type']); // Add the type meta
            }
        }
    }

    // Set default options 
    if (!get_option('qp_jwt_secret_key')) {
        add_option('qp_jwt_secret_key', wp_generate_password(64, true, true), '', 'no');
    }

    // Get the current settings, or an empty array if they don't exist.
    $options = get_option('qp_settings', []);

    // Define the pages that need to be created.
    $pages_to_create = [
        'practice_page'  => ['title' => 'Practice', 'content' => '[question_press_practice]'],
        'dashboard_page' => ['title' => 'Dashboard', 'content' => '[question_press_dashboard]'],
        'session_page'   => ['title' => 'Session', 'content' => '[question_press_session]'],
        'review_page'    => ['title' => 'Review', 'content' => '[question_press_review]'],
    ];

    foreach ($pages_to_create as $option_key => $page_details) {
        // Check if the page ID is already saved and if the page still exists.
        $page_id = isset($options[$option_key]) ? $options[$option_key] : 0;
        if (empty($page_id) || !get_post($page_id)) {
            // Check if a page with this title already exists to avoid duplicates.
            $existing_page = get_page_by_title($page_details['title'], 'OBJECT', 'page');

            if ($existing_page) {
                // If a page with the same title exists, use it.
                $new_page_id = $existing_page->ID;
            } else {
                // If no page exists, create a new one.
                $new_page_id = wp_insert_post([
                    'post_title'   => wp_strip_all_tags($page_details['title']),
                    'post_content' => $page_details['content'],
                    'post_status'  => 'publish',
                    'post_author'  => 1,
                    'post_type'    => 'page',
                ]);
            }

            // Save the new page ID to our settings.
            if ($new_page_id) {
                $options[$option_key] = $new_page_id;
            }
        }
    }

    // Save the updated settings with the new page IDs.
    update_option('qp_settings', $options);
}

register_activation_hook(QP_PLUGIN_FILE, 'qp_activate_plugin');

function qp_deactivate_plugin() {}
register_deactivation_hook(QP_PLUGIN_FILE, 'qp_deactivate_plugin');

/**
 * Register the 'Course' Custom Post Type.
 */
function qp_register_course_post_type() {
    $labels = [
        'name'                  => _x('Courses', 'Post type general name', 'question-press'),
        'singular_name'         => _x('Course', 'Post type singular name', 'question-press'),
        'menu_name'             => _x('Courses', 'Admin Menu text', 'question-press'),
        'name_admin_bar'        => _x('Course', 'Add New on Toolbar', 'question-press'),
        'add_new'               => __('Add New', 'question-press'),
        'add_new_item'          => __('Add New Course', 'question-press'),
        'new_item'              => __('New Course', 'question-press'),
        'edit_item'             => __('Edit Course', 'question-press'),
        'view_item'             => __('View Course', 'question-press'),
        'all_items'             => __('All Courses', 'question-press'),
        'search_items'          => __('Search Courses', 'question-press'),
        'parent_item_colon'     => __('Parent Course:', 'question-press'),
        'not_found'             => __('No courses found.', 'question-press'),
        'not_found_in_trash'    => __('No courses found in Trash.', 'question-press'),
        'featured_image'        => _x('Course Cover Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'question-press'),
        'set_featured_image'    => _x('Set cover image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'question-press'),
        'remove_featured_image' => _x('Remove cover image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'question-press'),
        'use_featured_image'    => _x('Use as cover image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'question-press'),
        'archives'              => _x('Course archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'question-press'),
        'insert_into_item'      => _x('Insert into course', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'question-press'),
        'uploaded_to_this_item' => _x('Uploaded to this course', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'question-press'),
        'filter_items_list'     => _x('Filter courses list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'question-press'),
        'items_list_navigation' => _x('Courses list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'question-press'),
        'items_list'            => _x('Courses list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'question-press'),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false, // Not publicly viewable on the frontend directly via its slug
        'publicly_queryable' => false, // Not queryable in the main WP query
        'show_ui'            => true,  // Show in the admin UI
        'show_in_menu'       => true,  // Show as a top-level menu item
        'query_var'          => false, // No query variable needed
        'rewrite'            => false, // No URL rewriting needed
        'capability_type'    => 'post', // Use standard post capabilities
        'has_archive'        => false, // No archive page needed
        'hierarchical'       => false, // Courses are not hierarchical like pages
        'menu_position'      => 26,    // Position below Question Press (usually 25)
        'menu_icon'          => 'dashicons-welcome-learn-more', // Choose an appropriate icon
        'supports'           => ['title', 'editor', 'author'], // Features we want initially
        'show_in_rest'       => false, // Disable Block Editor support for now
    ];

    register_post_type('qp_course', $args);
}
add_action('init', 'qp_register_course_post_type'); // Register the CPT on init

/**
 * Register the 'Plan' Custom Post Type for monetization.
 */
function qp_register_plan_post_type() {
    $labels = [
        'name'                  => _x('Plans', 'Post type general name', 'question-press'),
        'singular_name'         => _x('Plan', 'Post type singular name', 'question-press'),
        'menu_name'             => _x('Monetization Plans', 'Admin Menu text', 'question-press'),
        'name_admin_bar'        => _x('Plan', 'Add New on Toolbar', 'question-press'),
        'add_new'               => __('Add New Plan', 'question-press'),
        'add_new_item'          => __('Add New Plan', 'question-press'),
        'new_item'              => __('New Plan', 'question-press'),
        'edit_item'             => __('Edit Plan', 'question-press'),
        'view_item'             => __('View Plan', 'question-press'), // Should not be viewable on frontend
        'all_items'             => __('All Plans', 'question-press'),
        'search_items'          => __('Search Plans', 'question-press'),
        'parent_item_colon'     => __('Parent Plan:', 'question-press'), // Not applicable, but standard label
        'not_found'             => __('No plans found.', 'question-press'),
        'not_found_in_trash'    => __('No plans found in Trash.', 'question-press'),
    ];

    $args = [
        'labels'             => $labels,
        'description'        => __('Defines access plans for Question Press features.', 'question-press'),
        'public'             => false, // Not publicly viewable on frontend
        'publicly_queryable' => false, // Not queryable directly
        'show_ui'            => true,  // Show in admin UI
        'show_in_menu'       => 'question-press', // Show under the main Question Press menu
        'query_var'          => false,
        'rewrite'            => false,
        'capability_type'    => 'post', // Use standard post capabilities (adjust if needed)
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => null, // Will appear as submenu
        'supports'           => ['title', 'editor'], // Only title needed initially, details via meta
        'show_in_rest'       => false, // Disable Gutenberg for this CPT
    ];

    register_post_type('qp_plan', $args);
}
add_action('init', 'qp_register_plan_post_type'); // Register the CPT on init

/**
 * Add meta box for Plan Details.
 */
function qp_add_plan_details_meta_box() {
    add_meta_box(
        'qp_plan_details_meta_box',           // Unique ID
        __('Plan Details', 'question-press'), // Box title
        'qp_render_plan_details_meta_box',    // Callback function
        'qp_plan',                            // Post type
        'normal',                             // Context (normal = main column)
        'high'                                // Priority
    );
}
add_action('add_meta_boxes_qp_plan', 'qp_add_plan_details_meta_box'); // Hook specifically for qp_plan

/**
 * Render the HTML content for the Plan Details meta box.
 */
function qp_render_plan_details_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_plan_details_meta', 'qp_plan_details_nonce');

    // Get existing meta values
    $plan_type = get_post_meta($post->ID, '_qp_plan_type', true);
    $duration_value = get_post_meta($post->ID, '_qp_plan_duration_value', true);
    $duration_unit = get_post_meta($post->ID, '_qp_plan_duration_unit', true);
    $attempts = get_post_meta($post->ID, '_qp_plan_attempts', true);
    $course_access_type = get_post_meta($post->ID, '_qp_plan_course_access_type', true);
    $linked_courses_raw = get_post_meta($post->ID, '_qp_plan_linked_courses', true);
    $linked_courses = is_array($linked_courses_raw) ? $linked_courses_raw : []; // Ensure it's an array
    $description = get_post_meta($post->ID, '_qp_plan_description', true);

    // Get all published courses for selection
    $courses = get_posts([
        'post_type' => 'qp_course',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    ?>
    <style>
        .qp-plan-meta-box table { width: 100%; border-collapse: collapse; }
        .qp-plan-meta-box th, .qp-plan-meta-box td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .qp-plan-meta-box th { width: 150px; font-weight: 600; }
        .qp-plan-meta-box select, .qp-plan-meta-box input[type="number"], .qp-plan-meta-box textarea { width: 100%; max-width: 350px; box-sizing: border-box; }
        .qp-plan-meta-box .description { font-size: 0.9em; color: #666; }
        .qp-plan-meta-box .conditional-field { display: none; } /* Hide conditional fields initially */
        .qp-plan-meta-box .course-select-list { max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff; }
        .qp-plan-meta-box .course-select-list label { display: block; margin-bottom: 5px; }
    </style>

    <div class="qp-plan-meta-box">
        <table>
            <tbody>
                <tr>
                    <th><label for="qp_plan_type">Plan Type</label></th>
                    <td>
                        <select name="_qp_plan_type" id="qp_plan_type">
                            <option value="">— Select Type —</option>
                            <option value="time_limited" <?php selected($plan_type, 'time_limited'); ?>>Time Limited</option>
                            <option value="attempt_limited" <?php selected($plan_type, 'attempt_limited'); ?>>Attempt Limited</option>
                            <option value="course_access" <?php selected($plan_type, 'course_access'); ?>>Course Access Only</option>
                            <option value="unlimited" <?php selected($plan_type, 'unlimited'); ?>>Unlimited (Time & Attempts)</option>
                            <option value="combined" <?php selected($plan_type, 'combined'); ?>>Combined (Time, Attempts, Courses)</option>
                        </select>
                        <p class="description">Select the primary restriction type for this plan.</p>
                    </td>
                </tr>

                <tr class="conditional-field" data-depends-on="time_limited combined">
                    <th><label for="qp_plan_duration_value">Duration</label></th>
                    <td>
                        <input type="number" name="_qp_plan_duration_value" id="qp_plan_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" style="width: 80px; margin-right: 10px;">
                        <select name="_qp_plan_duration_unit" id="qp_plan_duration_unit">
                            <option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
                            <option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
                            <option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
                        </select>
                        <p class="description">How long the access lasts after purchase.</p>
                    </td>
                </tr>

                <tr class="conditional-field" data-depends-on="attempt_limited combined">
                    <th><label for="qp_plan_attempts">Number of Attempts</label></th>
                    <td>
                        <input type="number" name="_qp_plan_attempts" id="qp_plan_attempts" value="<?php echo esc_attr($attempts); ?>" min="1">
                        <p class="description">How many attempts the user gets with this plan.</p>
                    </td>
                </tr>

                <tr class="conditional-field" data-depends-on="course_access combined">
                    <th><label for="qp_plan_course_access_type">Course Access</label></th>
                    <td>
                        <select name="_qp_plan_course_access_type" id="qp_plan_course_access_type">
                            <option value="all" <?php selected($course_access_type, 'all'); ?>>All Courses</option>
                            <option value="specific" <?php selected($course_access_type, 'specific'); ?>>Specific Courses</option>
                        </select>
                    </td>
                </tr>

                <tr class="conditional-field" data-depends-on="course_access combined" data-sub-depends-on="specific">
                    <th><label>Select Courses</label></th>
                    <td>
                        <div class="course-select-list">
                            <?php if (!empty($courses)) : ?>
                                <?php foreach ($courses as $course) : ?>
                                    <label>
                                        <input type="checkbox" name="_qp_plan_linked_courses[]" value="<?php echo esc_attr($course->ID); ?>" <?php checked(in_array($course->ID, $linked_courses)); ?>>
                                        <?php echo esc_html($course->post_title); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No courses found. Please create courses first.</p>
                            <?php endif; ?>
                        </div>
                         <p class="description">Select the specific courses included in this plan.</p>
                    </td>
                </tr>

                 <tr>
                    <th><label for="qp_plan_description">Description</label></th>
                    <td>
                        <textarea name="_qp_plan_description" id="qp_plan_description" rows="3"><?php echo esc_textarea($description); ?></textarea>
                         <p class="description">Optional user-facing description (e.g., for display on product page or user dashboard).</p>
                    </td>
                </tr>

            </tbody>
        </table>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            const planTypeSelect = $('#qp_plan_type');
            const courseAccessSelect = $('#qp_plan_course_access_type');
            const metaBox = $('.qp-plan-meta-box');

            function toggleFields() {
                const selectedType = planTypeSelect.val();
                const selectedCourseAccess = courseAccessSelect.val();

                metaBox.find('.conditional-field').each(function() {
                    const $fieldRow = $(this);
                    const dependsOn = $fieldRow.data('depends-on') ? $fieldRow.data('depends-on').split(' ') : [];
                    const subDependsOn = $fieldRow.data('sub-depends-on'); // For specific course selection

                    let show = false;
                    if (dependsOn.includes(selectedType)) {
                        show = true;
                        // Handle sub-dependency for specific courses
                        if (subDependsOn === 'specific' && selectedCourseAccess !== 'specific') {
                            show = false;
                        }
                    }

                    if (show) {
                        $fieldRow.slideDown(200);
                        // Make inputs required if needed (optional)
                         //$fieldRow.find('input, select').prop('required', true);
                    } else {
                        $fieldRow.slideUp(200);
                        // Remove required attribute if hidden (optional)
                         //$fieldRow.find('input, select').prop('required', false);
                    }
                });
            }

            // Initial toggle on page load
            toggleFields();

            // Retoggle when plan type or course access type changes
            planTypeSelect.on('change', toggleFields);
            courseAccessSelect.on('change', toggleFields);
        });
    </script>
    <?php
}

/**
 * Save the meta box data when the 'qp_plan' post type is saved.
 */
function qp_save_plan_details_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_plan_details_nonce']) || !wp_verify_nonce($_POST['qp_plan_details_nonce'], 'qp_save_plan_details_meta')) {
        return $post_id;
    }

    // Check if the current user has permission to save the post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Don't save if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $post_id;
    }

    // Check post type is correct
    if ('qp_plan' !== get_post_type($post_id)) {
        return $post_id;
    }

    // Sanitize and save meta fields
    $fields_to_save = [
        '_qp_plan_type' => 'sanitize_key',
        '_qp_plan_duration_value' => 'absint',
        '_qp_plan_duration_unit' => 'sanitize_key',
        '_qp_plan_attempts' => 'absint',
        '_qp_plan_course_access_type' => 'sanitize_key',
        '_qp_plan_description' => 'sanitize_textarea_field',
    ];

    foreach ($fields_to_save as $meta_key => $sanitize_func) {
        if (isset($_POST[$meta_key])) {
            $value = call_user_func($sanitize_func, $_POST[$meta_key]);
            // Handle potentially empty values for numbers if needed
             if (($sanitize_func === 'absint' || $sanitize_func === 'intval') && $value === 0 && !isset($_POST[$meta_key])) {
                 // If the field wasn't submitted (e.g., hidden conditionally), don't save 0, save empty or delete meta
                 delete_post_meta($post_id, $meta_key);
                 continue;
             }
            update_post_meta($post_id, $meta_key, $value);
        } else {
             // If field is not set (e.g. conditional fields that are hidden), delete existing meta
            delete_post_meta($post_id, $meta_key);
        }
    }

    // Handle the linked courses array separately
    if (isset($_POST['_qp_plan_linked_courses']) && is_array($_POST['_qp_plan_linked_courses'])) {
        $linked_courses = array_map('absint', $_POST['_qp_plan_linked_courses']);
        update_post_meta($post_id, '_qp_plan_linked_courses', $linked_courses);
    } else {
         // If no courses are selected or the field is hidden, ensure the meta is removed or empty
        update_post_meta($post_id, '_qp_plan_linked_courses', []);
    }
}
add_action('save_post_qp_plan', 'qp_save_plan_details_meta'); // Hook specifically for qp_plan

/**
 * Add meta box for Course Access Settings (Revised).
 */
function qp_add_course_access_meta_box() {
    add_meta_box(
        'qp_course_access_meta_box',          // Unique ID
        __('Course Access & Monetization', 'question-press'), // Updated Box title
        'qp_render_course_access_meta_box',   // Callback function
        'qp_course',                          // Post type
        'side',                               // Context (side = right column)
        'high'                                // Priority
    );
}
add_action('add_meta_boxes_qp_course', 'qp_add_course_access_meta_box'); // Hook specifically for qp_course

/**
 * Render the HTML content for the Course Access meta box (Revised).
 */
function qp_render_course_access_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_course_access_meta', 'qp_course_access_nonce');

    // Get existing meta values
    $access_mode = get_post_meta($post->ID, '_qp_course_access_mode', true) ?: 'free'; // Default to free
    $duration_value = get_post_meta($post->ID, '_qp_course_access_duration_value', true);
    $duration_unit = get_post_meta($post->ID, '_qp_course_access_duration_unit', true) ?: 'day'; // Default unit
    $linked_product_id = get_post_meta($post->ID, '_qp_linked_product_id', true);
    $auto_plan_id = get_post_meta($post->ID, '_qp_course_auto_plan_id', true); // Get the auto-generated plan ID

    // Get all published WooCommerce products for selection
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'return' => 'objects',
    ]);

    ?>
    <style>
        #qp_course_access_meta_box p { margin-bottom: 15px; }
        #qp_course_access_meta_box label { font-weight: 600; display: block; margin-bottom: 5px; }
        #qp_course_access_meta_box select,
        #qp_course_access_meta_box input[type="number"] { width: 100%; box-sizing: border-box; margin-bottom: 5px;}
        #qp_course_access_meta_box .duration-group { display: flex; align-items: center; gap: 10px; }
        #qp_course_access_meta_box .duration-group input[type="number"] { width: 80px; flex-shrink: 0; }
        #qp_course_access_meta_box .duration-group select { flex-grow: 1; }
        #qp-purchase-fields { display: <?php echo ($access_mode === 'requires_purchase') ? 'block' : 'none'; ?>; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;}
        #qp_course_access_meta_box small.description { font-size: 0.9em; color: #666; display: block; margin-top: 3px; }
        #qp-auto-plan-info { font-style: italic; color: #666; font-size: 0.9em; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ddd; }
    </style>

    <p>
        <label for="qp_course_access_mode"><?php _e('Access Mode:', 'question-press'); ?></label>
        <select name="_qp_course_access_mode" id="qp_course_access_mode">
            <option value="free" <?php selected($access_mode, 'free'); ?>><?php _e('Free (Public Enrollment)', 'question-press'); ?></option>
            <option value="requires_purchase" <?php selected($access_mode, 'requires_purchase'); ?>><?php _e('Requires Purchase', 'question-press'); ?></option>
        </select>
    </p>

    <div id="qp-purchase-fields">
        <p>
            <label><?php _e('Access Duration:', 'question-press'); ?></label>
            <div class="duration-group">
                <input type="number" name="_qp_course_access_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" placeholder="e.g., 30">
                <select name="_qp_course_access_duration_unit">
                    <option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
                    <option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
                    <option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
                </select>
            </div>
             <small class="description"><?php _e('How long access lasts after purchase. Leave blank for lifetime access.', 'question-press'); ?></small>
        </p>

        <p>
            <label for="qp_linked_product_id"><?php _e('Linked WooCommerce Product:', 'question-press'); ?></label>
            <select name="_qp_linked_product_id" id="qp_linked_product_id">
                <option value="">— <?php _e('Select Product', 'question-press'); ?> —</option>
                <?php
                if ($products) {
                    foreach ($products as $product) {
                        if ($product->is_type('simple') || $product->is_type('variable')) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr($product->get_id()),
                                selected($linked_product_id, $product->get_id(), false),
                                esc_html($product->get_name()) . ' (#' . $product->get_id() . ')'
                            );
                        }
                    }
                }
                ?>
            </select>
            <small class="description"><?php _e('Product users click "Purchase" for. Ensure this product is linked to the correct auto-generated or manual plan.', 'question-press'); ?></small>
        </p>

        <?php if ($auto_plan_id && get_post($auto_plan_id)) : ?>
             <p id="qp-auto-plan-info">
                 This course automatically manages Plan ID #<?php echo esc_html($auto_plan_id); ?>.
                 <a href="<?php echo esc_url(get_edit_post_link($auto_plan_id)); ?>" target="_blank">View Plan</a><br>
                 Ensure your Linked Product above uses this Plan ID.
             </p>
        <?php elseif ($access_mode === 'requires_purchase') : ?>
             <p id="qp-auto-plan-info">
                 A Plan will be automatically created/updated when you save this course. Link your WC Product to that Plan ID.
             </p>
        <?php endif; ?>

    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#qp_course_access_mode').on('change', function() {
                if ($(this).val() === 'requires_purchase') {
                    $('#qp-purchase-fields').slideDown(200);
                } else {
                    $('#qp-purchase-fields').slideUp(200);
                    // Clear fields when switching to free
                    // $('#qp-purchase-fields input[type="number"]').val('');
                    // $('#qp-purchase-fields select').val('');
                }
            }).trigger('change'); // Trigger on load to set initial state
        });
    </script>
    <?php
}

/**
 * Save the meta box data when the 'qp_course' post type is saved (Revised).
 * This function ONLY saves the course meta. Auto-plan logic will be separate.
 */
function qp_save_course_access_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) {
        return $post_id;
    }

    // Check permissions, autosave, post type
    if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id)) {
        return $post_id;
    }

    // Save Access Mode
    $access_mode = isset($_POST['_qp_course_access_mode']) ? sanitize_key($_POST['_qp_course_access_mode']) : 'free';
    update_post_meta($post_id, '_qp_course_access_mode', $access_mode);

    // Save fields only if requires_purchase is selected
    if ($access_mode === 'requires_purchase') {
        // Save Duration Value (allow empty for lifetime)
        $duration_value = isset($_POST['_qp_course_access_duration_value']) ? absint($_POST['_qp_course_access_duration_value']) : '';
        update_post_meta($post_id, '_qp_course_access_duration_value', $duration_value);

        // Save Duration Unit
        $duration_unit = isset($_POST['_qp_course_access_duration_unit']) ? sanitize_key($_POST['_qp_course_access_duration_unit']) : 'day';
        update_post_meta($post_id, '_qp_course_access_duration_unit', $duration_unit);

        // Save Linked Product ID
        $product_id = isset($_POST['_qp_linked_product_id']) ? absint($_POST['_qp_linked_product_id']) : '';
        update_post_meta($post_id, '_qp_linked_product_id', $product_id);

    } else {
        // Delete monetization meta if mode is free
        delete_post_meta($post_id, '_qp_course_access_duration_value');
        delete_post_meta($post_id, '_qp_course_access_duration_unit');
        delete_post_meta($post_id, '_qp_linked_product_id');
        // We keep '_qp_course_auto_plan_id' even if switched to free,
        // so we don't lose the link if switched back later.
    }
}
// Hook *after* the structure save but *before* the auto-plan logic
add_action('save_post_qp_course', 'qp_save_course_access_meta', 30, 1);

/**
 * Automatically creates or updates a qp_plan post based on course settings.
 * Triggered after the course meta is saved.
 *
 * @param int $post_id The ID of the qp_course post being saved.
 */
function qp_sync_course_plan($post_id) {
    // Basic checks (already done in qp_save_course_access_meta, but good practice)
    if ( (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id) || !current_user_can('edit_post', $post_id) ) {
        return;
    }
    // Verify nonce again, just to be safe, using the nonce from the access meta save
    if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) {
        return;
    }

    $access_mode = get_post_meta($post_id, '_qp_course_access_mode', true);

    // Only proceed if the course requires purchase
    if ($access_mode !== 'requires_purchase') {
        // Optional: If switched from paid to free, we could potentially update the linked plan's status,
        // but for now, we'll just leave the plan as is to preserve access for past purchasers.
        return;
    }

    // Get the course details needed for the plan
    $course_title = get_the_title($post_id);
    $duration_value = get_post_meta($post_id, '_qp_course_access_duration_value', true);
    $duration_unit = get_post_meta($post_id, '_qp_course_access_duration_unit', true);
    $existing_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);

    // Determine plan type based on duration
    $plan_type = !empty($duration_value) ? 'time_limited' : 'unlimited'; // Course access implies unlimited attempts

    // Prepare plan post data
    $plan_post_args = [
        'post_title' => 'Auto: Access Plan for Course "' . $course_title . '"',
        'post_content' => '', // Content not needed
        'post_status' => 'publish', // Auto-publish the plan
        'post_type' => 'qp_plan',
        'meta_input' => [ // Use meta_input for direct meta saving/updating
            '_qp_is_auto_generated' => 'true', // Flag this as auto-managed
            '_qp_plan_type' => $plan_type,
            '_qp_plan_duration_value' => !empty($duration_value) ? absint($duration_value) : null,
            '_qp_plan_duration_unit' => !empty($duration_value) ? sanitize_key($duration_unit) : null,
            '_qp_plan_attempts' => null, // Course access plans grant unlimited attempts within duration
            '_qp_plan_course_access_type' => 'specific',
            '_qp_plan_linked_courses' => [$post_id], // Link specifically to this course ID
            // '_qp_plan_description' => 'Automatically generated plan for ' . $course_title, // Optional description
        ],
    ];

    $plan_id_to_save = 0;

    // Check if a plan already exists and is valid
    if (!empty($existing_plan_id)) {
         $existing_plan_post = get_post($existing_plan_id);
         // Check if the post exists and is indeed a qp_plan
         if ($existing_plan_post && $existing_plan_post->post_type === 'qp_plan') {
             // Update existing plan
             $plan_post_args['ID'] = $existing_plan_id; // Add ID for update
             $updated_plan_id = wp_update_post($plan_post_args, true); // true returns WP_Error on failure
             if (!is_wp_error($updated_plan_id)) {
                 $plan_id_to_save = $updated_plan_id;
                 error_log("QP Auto Plan: Updated Plan ID #{$plan_id_to_save} for Course ID #{$post_id}");
             } else {
                 error_log("QP Auto Plan: FAILED to update Plan ID #{$existing_plan_id} for Course ID #{$post_id}. Error: " . $updated_plan_id->get_error_message());
             }
         } else {
             // The linked ID was invalid, clear it and create a new one
             delete_post_meta($post_id, '_qp_course_auto_plan_id');
             $existing_plan_id = 0; // Force creation below
         }
    }

    // Create new plan if no valid existing one was found/updated
    if (empty($plan_id_to_save) && empty($existing_plan_id)) {
        $new_plan_id = wp_insert_post($plan_post_args, true); // true returns WP_Error on failure
        if (!is_wp_error($new_plan_id)) {
            $plan_id_to_save = $new_plan_id;
            // Save the new plan ID back to the course meta
            update_post_meta($post_id, '_qp_course_auto_plan_id', $plan_id_to_save);
            error_log("QP Auto Plan: CREATED Plan ID #{$plan_id_to_save} for Course ID #{$post_id}");
        } else {
            error_log("QP Auto Plan: FAILED to create new Plan for Course ID #{$post_id}. Error: " . $new_plan_id->get_error_message());
        }
    }

}
// Hook with a later priority, ensuring course meta is saved first
add_action('save_post_qp_course', 'qp_sync_course_plan', 40, 1);

/**
 * Checks if a user has access to a specific course via entitlement OR existing enrollment.
 *
 * @param int $user_id   The ID of the user to check.
 * @param int $course_id The ID of the course (qp_course post ID) to check access for.
 * @return bool True if the user has access, false otherwise.
 */
function qp_user_can_access_course($user_id, $course_id) {
    if (empty($user_id) || empty($course_id)) {
        return false;
    }

    // 1. Admins always have access
    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    // 2. Check if the course is explicitly marked as free
    $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true);
    if ($access_mode === 'free') {
        return true; // Free courses are always accessible
    }

    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $user_courses_table = $wpdb->prefix . 'qp_user_courses'; // <<< Add enrollment table name
    $current_time = current_time('mysql');

    // 3. Check for ANY active entitlement granting access
    $active_entitlements = $wpdb->get_results($wpdb->prepare(
        "SELECT entitlement_id, plan_id
         FROM {$entitlements_table}
         WHERE user_id = %d
         AND status = 'active'
         AND (expiry_date IS NULL OR expiry_date > %s)",
        $user_id,
        $current_time
    ));

    if (!empty($active_entitlements)) {
        // Check each active plan to see if it grants access to this course
        foreach ($active_entitlements as $entitlement) {
            $plan_id = $entitlement->plan_id;
            $course_access_type = get_post_meta($plan_id, '_qp_plan_course_access_type', true);
            $linked_courses_raw = get_post_meta($plan_id, '_qp_plan_linked_courses', true);
            $linked_courses = is_array($linked_courses_raw) ? $linked_courses_raw : [];

            if ($course_access_type === 'all' || ($course_access_type === 'specific' && in_array($course_id, $linked_courses))) {
                return true; // Access granted via entitlement
            }
        }
    }

    // 4. *** NEW CHECK ***: Check for existing enrollment if no entitlement granted access
    $is_enrolled = $wpdb->get_var($wpdb->prepare(
        "SELECT user_course_id FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
        $user_id,
        $course_id
    ));

    if ($is_enrolled) {
        return true; // Access granted due to existing enrollment
    }
    // --- END NEW CHECK ---

    // 5. If none of the above grant access, deny.
    return false;
}

/**
 * Ensures the entitlement expiration cron job is scheduled.
 * Runs on WordPress initialization.
 */
function qp_ensure_cron_scheduled() {
    if (!wp_next_scheduled('qp_check_entitlement_expiration_hook')) {
        wp_schedule_event(time(), 'daily', 'qp_check_entitlement_expiration_hook');
        error_log("QP Cron: Re-scheduled entitlement expiration check on init.");
    }
}
add_action('init', 'qp_ensure_cron_scheduled');

/**
 * The callback function executed by the WP-Cron job to update expired entitlements.
 */
function qp_run_entitlement_expiration_check() {
    error_log("QP Cron: Running entitlement expiration check...");
    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');

    // Find entitlement records that are 'active' but whose expiry date is in the past
    $expired_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT entitlement_id
         FROM {$entitlements_table}
         WHERE status = 'active'
         AND expiry_date IS NOT NULL
         AND expiry_date <= %s",
        $current_time
    ));

    if (!empty($expired_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $expired_ids));

        // Update the status of these records to 'expired'
        $updated_count = $wpdb->query(
            "UPDATE {$entitlements_table}
             SET status = 'expired'
             WHERE entitlement_id IN ($ids_placeholder)"
        );

        if ($updated_count !== false) {
             error_log("QP Cron: Marked {$updated_count} entitlements as expired.");
        } else {
             error_log("QP Cron: Error updating expired entitlements. DB Error: " . $wpdb->last_error);
        }
    } else {
        error_log("QP Cron: No expired entitlements found to update.");
    }
}
// Hook the callback function to the scheduled event's action name
add_action('qp_check_entitlement_expiration_hook', 'qp_run_entitlement_expiration_check');

/**
 * Add custom field to WooCommerce Product Data > General tab for Simple products.
 */
function qp_add_plan_link_to_simple_products() {
    global $post;

    // Get all published 'qp_plan' posts
    $plans = get_posts([
        'post_type' => 'qp_plan',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $options = ['' => __('— Select a Question Press Plan —', 'question-press')];
    if ($plans) {
        foreach ($plans as $plan) {
            $options[$plan->ID] = esc_html($plan->post_title);
        }
    }

    // Output the WooCommerce field
    woocommerce_wp_select([
        'id'          => '_qp_linked_plan_id',
        'label'       => __('Question Press Plan', 'question-press'),
        'description' => __('Link this product to a Question Press monetization plan. This grants access when the order is completed.', 'question-press'),
        'desc_tip'    => true,
        'options'     => $options,
        'value'       => get_post_meta($post->ID, '_qp_linked_plan_id', true), // Get current value
    ]);
}
add_action('woocommerce_product_options_general_product_data', 'qp_add_plan_link_to_simple_products');

/**
 * Save the custom field for Simple products.
 */
function qp_save_plan_link_simple_product($post_id) {
    $plan_id = isset($_POST['_qp_linked_plan_id']) ? absint($_POST['_qp_linked_plan_id']) : '';
    update_post_meta($post_id, '_qp_linked_plan_id', $plan_id);
}
add_action('woocommerce_process_product_meta_simple', 'qp_save_plan_link_simple_product');
// Use the generic hook as well if needed for other simple types like external etc.
// add_action('woocommerce_process_product_meta', 'qp_save_plan_link_simple_product');

/**
 * Add custom field to WooCommerce Product Data > Variations tab for Variable products.
 */
function qp_add_plan_link_to_variable_products($loop, $variation_data, $variation) {
    // Get all published 'qp_plan' posts (reuse logic or query again)
    $plans = get_posts([
        'post_type' => 'qp_plan',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $options = ['' => __('— Select a Question Press Plan —', 'question-press')];
    if ($plans) {
        foreach ($plans as $plan) {
            $options[$plan->ID] = esc_html($plan->post_title);
        }
    }

    // Output the WooCommerce field for variations
    woocommerce_wp_select([
        'id'            => "_qp_linked_plan_id[{$loop}]", // Needs array index for variations
        'label'         => __('Question Press Plan', 'question-press'),
        'description'   => __('Link this variation to a Question Press monetization plan.', 'question-press'),
        'desc_tip'      => true,
        'options'       => $options,
        'value'         => get_post_meta($variation->ID, '_qp_linked_plan_id', true), // Get value for this variation ID
        'wrapper_class' => 'form-row form-row-full', // Ensure it takes full width in variation options
    ]);
}
add_action('woocommerce_product_after_variable_attributes', 'qp_add_plan_link_to_variable_products', 10, 3);

/**
 * Save the custom field for Variable products (variations).
 */
function qp_save_plan_link_variable_product($variation_id, $i) {
    $plan_id = isset($_POST['_qp_linked_plan_id'][$i]) ? absint($_POST['_qp_linked_plan_id'][$i]) : '';
    update_post_meta($variation_id, '_qp_linked_plan_id', $plan_id);
}
add_action('woocommerce_save_product_variation', 'qp_save_plan_link_variable_product', 10, 2);

/**
 * Add meta box for Course Structure.
 */
function qp_add_course_structure_meta_box() {
    add_meta_box(
        'qp_course_structure_meta_box', // Unique ID
        __('Course Structure', 'question-press'), // Box title
        'qp_render_course_structure_meta_box', // Callback function
        'qp_course', // Post type
        'normal', // Context (normal = main column)
        'high' // Priority
    );
}
add_action('add_meta_boxes', 'qp_add_course_structure_meta_box');

/**
 * Render the HTML content for the Course Structure meta box.
 * (Initial static structure - JS will make it dynamic later)
 */
function qp_render_course_structure_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_course_structure_meta', 'qp_course_structure_nonce');

    // Basic structure - we will load saved data and make this dynamic later
    ?>
    <div id="qp-course-structure-container">
        <p>Define the sections and content items for this course below. Drag and drop to reorder.</p>

        <div id="qp-sections-list">
            <?php
            // --- Placeholder for loading existing sections/items later ---
            // For now, it's empty, ready for JS.
            ?>
        </div>

        <p>
            <button type="button" id="qp-add-section-btn" class="button button-secondary">
                <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> Add Section
            </button>
        </p>
    </div>

    <?php
    // --- Add some basic CSS (will be moved/refined later) ---
    ?>
    <style>
        #qp-sections-list .qp-section {
            border: 1px solid #ccd0d4;
            margin-bottom: 15px;
            background: #fff;
            border-radius: 4px;
        }
        .qp-section-header {
            padding: 10px 15px;
            background: #f6f7f7;
            border-bottom: 1px solid #ccd0d4;
            cursor: move;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .qp-section-header h3 {
            margin: 0;
            font-size: 1.1em;
            display: inline-block;
        }
        .qp-section-title-input {
            font-size: 1.1em;
            font-weight: bold;
            border: none;
            box-shadow: none;
            padding: 2px 5px;
            margin-left: 5px;
            background: transparent;
        }
        .qp-section-controls button, .qp-item-controls button {
            margin-left: 5px;
        }
        .qp-section-content {
            padding: 15px;
        }
        .qp-items-list {
            margin-left: 10px;
            border-left: 3px solid #eef2f5;
            padding-left: 15px;
            min-height: 30px; /* Area to drop items */
        }
        .qp-course-item {
            border: 1px dashed #dcdcde;
            padding: 10px;
            margin-bottom: 10px;
            background: #fdfdfd;
            border-radius: 3px;
        }
         .qp-item-header {
             display: flex; justify-content: space-between; align-items: center;
             margin-bottom: 10px; cursor: move; padding-bottom: 5px; border-bottom: 1px solid #eee;
         }
         .qp-item-title-input { font-weight: bold; border: none; box-shadow: none; padding: 2px 5px; background: transparent; flex-grow: 1; }
        .qp-item-config { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
        .qp-config-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px; }
        .qp-config-row label { display: block; font-weight: 500; margin-bottom: 3px; font-size: 0.9em; }
        .qp-config-row select, .qp-config-row input { width: 100%; box-sizing: border-box; }
        .qp-item-config .qp-marks-group { display: flex; gap: 10px; }
        .qp-item-config .qp-marks-group > div { flex: 1; }
    </style>
    <?php
}

/**
 * Save the course structure data when the 'qp_course' post type is saved.
 * Handles updates, inserts, and deletions intelligently.
 * Cleans up user progress for deleted items.
 */
function qp_save_course_structure_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_course_structure_nonce']) || !wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) {
        return $post_id;
    }

    // Check if the current user has permission to save the post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Don't save if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $post_id;
    }

    // Check post type
    if ('qp_course' !== get_post_type($post_id)) {
        return $post_id;
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';

    // --- Data processing ---

    // 1. Fetch Existing Structure IDs from DB
    $existing_section_ids = $wpdb->get_col($wpdb->prepare("SELECT section_id FROM $sections_table WHERE course_id = %d", $post_id));
    $existing_item_ids = $wpdb->get_col($wpdb->prepare("SELECT item_id FROM $items_table WHERE course_id = %d", $post_id));

    $submitted_section_ids = [];
    $submitted_item_ids = [];
    $processed_item_ids = []; // Keep track of item IDs processed (inserted or updated)

    // 2. Loop through submitted sections and items: Update or Insert
    if (isset($_POST['course_sections']) && is_array($_POST['course_sections'])) {
        foreach ($_POST['course_sections'] as $section_order => $section_data) {
            $section_id = isset($section_data['section_id']) ? absint($section_data['section_id']) : 0;
            $section_title = sanitize_text_field($section_data['title'] ?? 'Untitled Section');

            $section_db_data = [
                'course_id' => $post_id,
                'title' => $section_title,
                'section_order' => $section_order + 1 // Ensure correct 1-based order
            ];

            if ($section_id > 0 && in_array($section_id, $existing_section_ids)) {
                // UPDATE existing section
                $wpdb->update($sections_table, $section_db_data, ['section_id' => $section_id]);
                $submitted_section_ids[] = $section_id;
            } else {
                // INSERT new section
                $wpdb->insert($sections_table, $section_db_data);
                $section_id = $wpdb->insert_id; // Get the new ID for items below
                 if (!$section_id) {
                    // Handle potential insert error, maybe log it
                    continue; // Skip items for this failed section insert
                 }
                 $submitted_section_ids[] = $section_id;
            }

            // Process Items within this section
            if ($section_id && isset($section_data['items']) && is_array($section_data['items'])) {
                foreach ($section_data['items'] as $item_order => $item_data) {
                    $item_id = isset($item_data['item_id']) ? absint($item_data['item_id']) : 0;
                    $item_title = sanitize_text_field($item_data['title'] ?? 'Untitled Item');
                    $content_type = sanitize_key($item_data['content_type'] ?? 'test_series'); // Default to test_series

                    // --- Process Configuration ---
                    $config = [];
                    if ($content_type === 'test_series' && isset($item_data['config'])) {
                         $raw_config = $item_data['config'];
                        $config = [
                            'time_limit'      => isset($raw_config['time_limit']) ? absint($raw_config['time_limit']) : 0,
                            'scoring_enabled' => isset($raw_config['scoring_enabled']) ? 1 : 0,
                            'marks_correct'   => isset($raw_config['marks_correct']) ? floatval($raw_config['marks_correct']) : 1,
                            'marks_incorrect' => isset($raw_config['marks_incorrect']) ? floatval($raw_config['marks_incorrect']) : 0,
                        ];
                        // Process selected questions (string to array)
                        if (isset($raw_config['selected_questions']) && !empty($raw_config['selected_questions'])) {
                            $question_ids_str = sanitize_text_field($raw_config['selected_questions']);
                            $question_ids = array_filter(array_map('absint', explode(',', $question_ids_str)));
                            if (!empty($question_ids)) {
                                $config['selected_questions'] = $question_ids;
                            }
                        }
                    } // Add 'else if' blocks here for other content types

                    $item_db_data = [
                        'section_id' => $section_id,
                        'course_id' => $post_id,
                        'title' => $item_title,
                        'item_order' => $item_order + 1,
                        'content_type' => $content_type,
                        'content_config' => wp_json_encode($config)
                    ];

                    if ($item_id > 0 && in_array($item_id, $existing_item_ids)) {
                        // UPDATE existing item
                        $wpdb->update($items_table, $item_db_data, ['item_id' => $item_id]);
                        $submitted_item_ids[] = $item_id;
                        $processed_item_ids[] = $item_id; // Track processed item
                    } else {
                        // INSERT new item
                        $wpdb->insert($items_table, $item_db_data);
                         $new_item_id = $wpdb->insert_id;
                         if ($new_item_id) {
                            $submitted_item_ids[] = $new_item_id;
                            $processed_item_ids[] = $new_item_id; // Track processed item
                         } else {
                            // Handle potential insert error
                         }
                    }
                } // end foreach item
            } // end if section_id and items exist
        } // end foreach section
    } // end if course_sections exist

    // 3. Identify Sections and Items to Delete
    $section_ids_to_delete = array_diff($existing_section_ids, $submitted_section_ids);
    $item_ids_to_delete = array_diff($existing_item_ids, $processed_item_ids); // Use processed_item_ids

    // 4. *** CRITICAL STEP: Clean up User Progress for Deleted Items ***
    if (!empty($item_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
        $wpdb->query("DELETE FROM $progress_table WHERE item_id IN ($ids_placeholder)");
        // Log this action (optional)
        error_log('QP Course Save: Cleaned up progress for deleted item IDs: ' . $ids_placeholder);
    }

    // 5. Delete Orphaned Items (associated with kept sections but removed in UI, or from deleted sections)
    if (!empty($item_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
        $wpdb->query("DELETE FROM $items_table WHERE item_id IN ($ids_placeholder)");
    }

    // 6. Delete Orphaned Sections (and implicitly cascade delete remaining items if DB constraints were set, although we deleted items explicitly above)
    if (!empty($section_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $section_ids_to_delete));
        // We already deleted items, just need to delete sections now
        $wpdb->query("DELETE FROM $sections_table WHERE section_id IN ($ids_placeholder)");
    }

    // Note: No explicit return needed as this hooks into save_post action
}
add_action('save_post_qp_course', 'qp_save_course_structure_meta'); // Hook into the CPT's save action

/**
 * Initialize all plugin features that hook into WordPress.
 */
function qp_init_plugin()
{
    QP_Rest_Api::init();
}
add_action('init', 'qp_init_plugin');

function qp_admin_menu()
{
    // Add top-level menu page for "All Questions" and store the hook
    $hook = add_menu_page('All Questions', 'Question Press', 'manage_options', 'question-press', 'qp_all_questions_page_cb', 'dashicons-forms', 25);

    // Screen Options for All questions page
    add_action("load-{$hook}", 'qp_add_screen_options');

    add_submenu_page('question-press', 'All Questions', 'All Questions', 'manage_options', 'question-press', 'qp_all_questions_page_cb');
    add_submenu_page('question-press', 'Add New', 'Add New', 'manage_options', 'qp-question-editor', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page('question-press', 'Organize', 'Organize', 'manage_options', 'qp-organization', 'qp_render_organization_page');
    add_submenu_page('question-press', 'Tools', 'Tools', 'manage_options', 'qp-tools', 'qp_render_tools_page');
    add_submenu_page('question-press', 'Reports', 'Reports', 'manage_options', 'qp-logs-reports', ['QP_Logs_Reports_Page', 'render']);
    add_submenu_page('question-press', 'User Entitlements', 'User Entitlements', 'manage_options', 'qp-user-entitlements', 'qp_render_user_entitlements_page');
    add_submenu_page('question-press', 'Settings', 'Settings', 'manage_options', 'qp-settings', ['QP_Settings_Page', 'render']);

    // Hidden pages (Indirectly required)
    add_submenu_page(null, 'Edit Question', 'Edit Question', 'manage_options', 'qp-edit-group', ['QP_Question_Editor_Page', 'render']);
    add_submenu_page(null, 'Merge Terms', 'Merge Terms', 'manage_options', 'qp-merge-terms', 'qp_render_merge_terms_page');
}
add_action('admin_menu', 'qp_admin_menu');



/**
 * Adds a "(Question Press)" indicator to the plugin's pages in the admin list.
 *
 * @param array   $post_states An array of post states.
 * @param WP_Post $post        The current post object.
 * @return array  The modified array of post states.
 */
function qp_add_page_indicator($post_states, $post)
{
    // Get the saved IDs of our plugin's pages
    $qp_settings = get_option('qp_settings', []);
    $qp_page_ids = [
        $qp_settings['practice_page'] ?? 0,
        $qp_settings['dashboard_page'] ?? 0,
        $qp_settings['session_page'] ?? 0,
        $qp_settings['review_page'] ?? 0,
    ];

    // Check if the current page's ID is one of our plugin's pages
    if (in_array($post->ID, $qp_page_ids)) {
        $post_states['question_press_page'] = 'Question Press';
    }

    return $post_states;
}
add_filter('display_post_states', 'qp_add_page_indicator', 10, 2);

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
    $taxonomy_name = sanitize_key($_REQUEST['taxonomy']);
    $taxonomy_label = sanitize_text_field($_REQUEST['taxonomy_label']);
    $ids_placeholder = implode(',', $term_ids_to_merge);

    $term_table = $wpdb->prefix . 'qp_terms';
    $terms_to_merge = $wpdb->get_results("SELECT * FROM {$term_table} WHERE term_id IN ({$ids_placeholder})");

    // The first selected term will be the master, its data pre-fills the form
    $master_term = $terms_to_merge[0];
    $master_description = qp_get_term_meta($master_term->term_id, 'description', true);

    // Get all possible parent terms for the dropdown (excluding the ones being merged)
    $parent_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND term_id NOT IN ({$ids_placeholder}) ORDER BY name ASC",
        $master_term->taxonomy_id
    ));

    // --- NEW: Fetch all children for all terms being merged ---
    $all_children = $wpdb->get_results("SELECT term_id, name, parent FROM {$term_table} WHERE parent IN ({$ids_placeholder}) ORDER BY name ASC");
    $children_by_parent = [];
    foreach ($all_children as $child) {
        $children_by_parent[$child->parent][] = $child;
    }
?>
    <div class="wrap">
        <h1>Merge <?php echo esc_html($taxonomy_label); ?>s</h1>
        <p>You are about to merge multiple items. All questions associated with the source items will be reassigned to the final destination item.</p>

        <form method="post" action="admin.php?page=qp-organization&tab=<?php echo esc_attr($taxonomy_name); ?>s">
            <?php wp_nonce_field('qp_perform_merge_nonce'); ?>
            <input type="hidden" name="action" value="perform_merge">
            <?php foreach ($term_ids_to_merge as $term_id) : ?>
                <input type="hidden" name="source_term_ids[]" value="<?php echo esc_attr($term_id); ?>">
            <?php endforeach; ?>

            <h2>Step 1: Choose the Destination Item</h2>
            <p>Select which item you want to merge the others into. Its details will be used as the default for the final merged item.</p>
            <fieldset style="margin-bottom: 2rem;">
                <?php foreach ($terms_to_merge as $index => $term) : ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="radio" name="destination_term_id" value="<?php echo esc_attr($term->term_id); ?>" <?php checked($index, 0); ?>>
                        <strong><?php echo esc_html($term->name); ?></strong> (ID: <?php echo esc_html($term->term_id); ?>)
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <h2>Step 2: Final Merged Item Details</h2>
            <p>Review and edit the details for the final merged item below.</p>
            <table class="form-table">
                <tr class="form-field form-required">
                    <th scope="row"><label for="term-name">Final Name</label></th>
                    <td><input name="term_name" id="term-name" type="text" value="<?php echo esc_attr($master_term->name); ?>" size="40" required></td>
                </tr>
                <tr class="form-field">
                    <th scope="row"><label for="parent-term">Final Parent</label></th>
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
                    <th scope="row"><label for="term-description">Final Description</label></th>
                    <td><textarea name="term_description" id="term-description" rows="5" cols="50"><?php echo esc_textarea($master_description); ?></textarea></td>
                </tr>
            </table>

            <h2 style="margin-top: 2rem;">Step 3: Merge Child Items</h2>
            <p>For each child item (e.g., a section), choose where its questions should be moved. You can merge them into an existing child of the destination, or move them to the top-level destination item.</p>

            <table class="wp-list-table widefat striped" id="child-merge-table" style="margin-top: 1rem;">
                <thead>
                    <tr>
                        <th>Child Item to Merge</th>
                        <th>Parent</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>

            <p class="submit" style="margin-top: 2rem;">
                <input type="submit" class="button button-primary button-large" value="Confirm Merge">
                <a href="javascript:history.back()" class="button button-secondary">Cancel</a>
            </p>
        </form>
    </div>
    <script>
        jQuery(document).ready(function($) {
            var allTerms = <?php echo json_encode($terms_to_merge); ?>;
            var allChildren = <?php echo json_encode($children_by_parent); ?>;

            function populateMergeTable() {
                var destinationId = $('input[name="destination_term_id"]:checked').val();
                var $tableBody = $('#child-merge-table tbody').empty();
                var destinationChildren = allChildren[destinationId] || [];

                allTerms.forEach(function(parentTerm) {
                    if (parentTerm.term_id == destinationId) return; // Skip destination parent

                    var sourceChildren = allChildren[parentTerm.term_id] || [];
                    if (sourceChildren.length === 0) return;

                    sourceChildren.forEach(function(child) {
                        var row = '<tr>';
                        row += '<td><strong>' + child.name + '</strong> (ID: ' + child.term_id + ')</td>';
                        row += '<td>' + parentTerm.name + '</td>';
                        row += '<td>';
                        row += '<select name="child_merges[' + child.term_id + ']" style="width: 100%;">';
                        // Option 1: Merge to parent destination
                        row += '<option value="' + destinationId + '">Merge into: ' + $('input[name="term_name"]').val() + ' (Parent)</option>';
                        // Option 2: Merge into existing children of destination
                        destinationChildren.forEach(function(destChild) {
                            row += '<option value="' + destChild.term_id + '">Merge into: ' + destChild.name + '</option>';
                        });
                        row += '</select>';
                        row += '</td></tr>';
                        $tableBody.append(row);
                    });
                });
                if ($tableBody.children().length === 0) {
                    $tableBody.append('<tr><td colspan="3">No child items to merge for the selected sources.</td></tr>');
                }
            }

            // Update form defaults and merge table when the destination changes
            $('input[name="destination_term_id"]').on('change', function() {
                var selectedId = $(this).val();
                var selectedTerm = allTerms.find(term => term.term_id == selectedId);
                if (selectedTerm) {
                    $('#term-name').val(selectedTerm.name);
                    // You would need an AJAX call to get the description if it's not pre-loaded
                }
                populateMergeTable();
            });

            // Initial population
            populateMergeTable();
        });
    </script>
<?php
}

function qp_render_tools_page()
{
    $tabs = [
        'import' => ['label' => 'Import', 'callback' => ['QP_Import_Page', 'render']],
        'export'   => ['label' => 'Export', 'callback' => ['QP_Export_Page', 'render']],
        'backup_restore'   => ['label' => 'Backup & Restore', 'callback' => ['QP_Backup_Restore_Page', 'render']],
        'user_attempts'    => ['label' => 'User Attempts', 'callback' => 'qp_render_user_attempts_tool_page'],
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
            call_user_func($tabs[$active_tab]['callback']);
            ?>
        </div>
    </div>
<?php
}

/**
 * Callback function to render the User Entitlements admin page.
 * NOW INCLUDES user search and scope management section.
 */
function qp_render_user_entitlements_page() {
    // --- NEW: Handle User Search ---
    $user_id_searched = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
    $user_info = null;
    if ($user_id_searched > 0) {
        $user_info = get_userdata($user_id_searched);
    }
    // --- END NEW ---

?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php _e('User Entitlements & Scope', 'question-press'); ?></h1>
        <?php // Display any notices if needed (e.g., after saving scope)
            if (isset($_GET['message']) && $_GET['message'] === 'scope_updated') {
                 echo '<div id="message" class="notice notice-success is-dismissible"><p>' . __('User scope updated successfully.', 'question-press') . '</p></div>';
            }
            settings_errors('qp_entitlements_notices');
        ?>

        <?php // --- NEW: User Search Form --- ?>
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="margin-bottom: 2rem;">
            <input type="hidden" name="page" value="qp-user-entitlements" />
            <label for="qp_user_id_search"><strong><?php _e('Enter User ID:', 'question-press'); ?></strong></label><br>
            <input type="number" id="qp_user_id_search" name="user_id" value="<?php echo esc_attr($user_id_searched ?: ''); ?>" min="1" required>
            <input type="submit" class="button button-secondary" value="<?php _e('Find User & Manage Scope', 'question-press'); ?>">
             <?php if ($user_id_searched > 0 && !$user_info): ?>
                <p style="color: red;"><?php _e('Error: User ID not found.', 'question-press'); ?></p>
             <?php endif; ?>
        </form>
        <?php // --- END NEW --- ?>

        <hr class="wp-header-end">

        <?php // --- NEW: Conditional Display based on user search ---
        if ($user_id_searched > 0 && $user_info) :
        ?>
            <h2><?php printf(__('Managing Scope & Entitlements for: %s (#%d)', 'question-press'), esc_html($user_info->display_name), $user_id_searched); ?></h2>

            <?php // --- NEW: Scope Management Section Placeholder --- ?>
            <div id="qp-user-scope-management" style="margin-bottom: 2rem; padding: 1.5rem; background-color: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3><?php _e('User\'s Subject Scope', 'question-press'); ?></h3>
                <p><?php _e('Define which subjects this user can access based on Exams or direct Subject assignments. Leave both sections empty to allow access to all subjects.', 'question-press'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php // Nonce field will be added in Step 1.3 ?>
                    <?php // Form fields (multi-selects) will be added in Step 1.3 ?>
                    <?php // Save button will be added in Step 1.3 ?>
                    <?php
                    global $wpdb;
                    $term_table = $wpdb->prefix . 'qp_terms';
                    $tax_table = $wpdb->prefix . 'qp_taxonomies';

                    // Get Tax IDs
                    $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
                    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");

                    // Get all available Exams
                    $all_exams = [];
                    if ($exam_tax_id) {
                        $all_exams = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d ORDER BY name ASC", $exam_tax_id));
                    }

                    // Get all available top-level Subjects
                    $all_subjects = [];
                    if ($subject_tax_id) {
                        $all_subjects = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND name != 'Uncategorized' ORDER BY name ASC", $subject_tax_id));
                    }

                    // Get current user settings from usermeta
                    $current_allowed_exams_json = get_user_meta($user_id_searched, '_qp_allowed_exam_term_ids', true);
                    $current_allowed_subjects_json = get_user_meta($user_id_searched, '_qp_allowed_subject_term_ids', true);

                    // Decode JSON, default to empty array if invalid or null
                    $current_allowed_exams = json_decode($current_allowed_exams_json, true);
                    $current_allowed_subjects = json_decode($current_allowed_subjects_json, true);
                    if (!is_array($current_allowed_exams)) { $current_allowed_exams = []; }
                    if (!is_array($current_allowed_subjects)) { $current_allowed_subjects = []; }

                    // Add Nonce and Action fields
                    wp_nonce_field('qp_save_user_scope_nonce', '_qp_scope_nonce');
                    ?>
                    <input type="hidden" name="action" value="qp_save_user_scope">
                    <input type="hidden" name="user_id_to_update" value="<?php echo esc_attr($user_id_searched); ?>">

                    <table class="form-table">
                        <tbody>
                            <tr valign="top">
                                <th scope="row">
                                    <label><?php _e('Allowed Exams', 'question-press'); ?></label>
                                </th>
                                <td>
                                    <fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">
                                        <?php if (!empty($all_exams)): ?>
                                            <?php foreach ($all_exams as $exam): ?>
                                                <label style="display: block; margin-bottom: 5px;">
                                                    <input type="checkbox" name="allowed_exams[]" value="<?php echo esc_attr($exam->term_id); ?>" <?php checked(in_array($exam->term_id, $current_allowed_exams)); ?>>
                                                    <?php echo esc_html($exam->name); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p><em><?php _e('No exams found.', 'question-press'); ?></em></p>
                                        <?php endif; ?>
                                    </fieldset>
                                    <p class="description"><?php _e('Allows access to all subjects linked to the selected exam(s).', 'question-press'); ?></p>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">
                                    <label><?php _e('Directly Allowed Subjects', 'question-press'); ?></label>
                                </th>
                                <td>
                                    <fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #fff;">
                                        <?php if (!empty($all_subjects)): ?>
                                            <?php foreach ($all_subjects as $subject): ?>
                                                <label style="display: block; margin-bottom: 5px;">
                                                    <input type="checkbox" name="allowed_subjects[]" value="<?php echo esc_attr($subject->term_id); ?>" <?php checked(in_array($subject->term_id, $current_allowed_subjects)); ?>>
                                                    <?php echo esc_html($subject->name); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p><em><?php _e('No subjects found.', 'question-press'); ?></em></p>
                                        <?php endif; ?>
                                    </fieldset>
                                    <p class="description"><?php _e('Allows access ONLY to these specific subjects (in addition to subjects from allowed exams).', 'question-press'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="submit">
                        <input type="submit" class="button button-primary" value="<?php _e('Save Scope Settings', 'question-press'); ?>">
                    </p>
                </form>
            </div>
            <?php // --- END NEW --- ?>

            <hr> <?php // Separator ?>

            <h3><?php _e('User\'s Entitlement Records', 'question-press'); ?></h3>
            <?php
            // Instantiate the List Table
            $entitlements_list_table = new QP_Entitlements_List_Table();

            // --- MODIFIED: Pass user_id to prepare_items for filtering ---
            // We will modify prepare_items in the next step to handle this. For now, just pass it.
            $_REQUEST['user_id_filter'] = $user_id_searched; // Use a temporary request variable

            // Fetch, prepare, sort, and filter data (will be filtered by user ID later)
            $entitlements_list_table->prepare_items();
            ?>
            <form method="get">
                <?php // Keep existing page parameters ?>
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                 <input type="hidden" name="user_id" value="<?php echo esc_attr($user_id_searched); ?>" /> <?php // Keep user_id in subsequent requests (sorting, pagination) ?>
                <?php
                    // Add the search box (will search WITHIN this user's entitlements if we modify prepare_items later)
                    // $entitlements_list_table->search_box(__('Search Entitlements'), 'entitlement'); // Keep commented for now
                ?>
                <?php
                    // Display the list table
                    $entitlements_list_table->display();
                ?>
            </form>

        <?php // --- NEW: End conditional display ---
        else:
             echo '<p>' . __('Please search for a User ID above to manage their scope and view their entitlements.', 'question-press') . '</p>';
        endif;
        ?>

    </div>
<?php
}

/**
 * Renders the User Attempts management tool page.
 */
function qp_render_user_attempts_tool_page() {
    // --- Form Handling Logic ---
    $user_id_searched = null;
    $current_attempts = 'N/A';
    $user_info = null;

    // Check if the user search form was submitted
    // Check if the update attempts form was submitted
if (isset($_POST['action']) && $_POST['action'] === 'qp_update_attempts' && isset($_POST['user_id_to_update'])) {
    // Verify the nonce for the update action
    check_admin_referer('qp_update_user_attempts_nonce');

    $user_id_to_update = absint($_POST['user_id_to_update']);
    // Make sure the new attempts value is a non-negative integer
    $new_attempts = isset($_POST['qp_new_attempts']) ? max(0, intval($_POST['qp_new_attempts'])) : 0;

    if ($user_id_to_update > 0) {
        $user_info = get_userdata($user_id_to_update); // Get user data again to confirm existence
        if ($user_info) {
            // Update the user meta value
            update_user_meta($user_id_to_update, 'qp_remaining_attempts', $new_attempts);
            add_settings_error('qp_user_attempts_notices', 'attempts_updated', 'Attempts updated successfully for ' . esc_html($user_info->display_name) . '. New count: ' . $new_attempts, 'success');
            // We need to re-set these variables so the form displays the *updated* info immediately
            $user_id_searched = $user_id_to_update;
            $current_attempts = $new_attempts;
        } else {
            add_settings_error('qp_user_attempts_notices', 'update_user_not_found', 'Error: Could not update attempts because User ID ' . esc_html($user_id_to_update) . ' was not found.', 'error');
        }
    } else {
         add_settings_error('qp_user_attempts_notices', 'update_invalid_user_id', 'Error: Invalid User ID for update.', 'error');
    }
}
    elseif (isset($_POST['action']) && $_POST['action'] === 'qp_search_user' && isset($_POST['qp_user_id_search'])) {
        // Verify the nonce
        check_admin_referer('qp_search_user_attempts_nonce');

        $user_id_searched = absint($_POST['qp_user_id_search']);
        if ($user_id_searched > 0) {
            $user_info = get_userdata($user_id_searched); // Fetch user data object

            if ($user_info) {
                // User found, get their attempts meta
                $attempts_meta = get_user_meta($user_id_searched, 'qp_remaining_attempts', true);
                // Set current attempts (handle case where meta doesn't exist yet)
                $current_attempts = ($attempts_meta !== '') ? (int)$attempts_meta : 0;
                add_settings_error('qp_user_attempts_notices', 'user_found', 'User found: ' . esc_html($user_info->display_name), 'success');
            } else {
                // User ID entered, but no user found
                add_settings_error('qp_user_attempts_notices', 'user_not_found', 'Error: User ID ' . esc_html($user_id_searched) . ' not found.', 'error');
                $user_id_searched = absint($_POST['qp_user_id_search']); // Keep the searched ID for display
            }
        } else {
            add_settings_error('qp_user_attempts_notices', 'invalid_user_id', 'Error: Please enter a valid User ID.', 'error');
        }
    }
     // --- End Form Handling Logic ---


    // --- Display the Form ---
?>
    <div class="wrap">
        <h2>Manage User Attempts</h2>
        <p>View and update the number of remaining question attempts for a specific user.</p>

        <?php settings_errors('qp_user_attempts_notices'); // Display feedback messages ?>

        <form method="post" action="admin.php?page=qp-tools&tab=user_attempts" style="margin-bottom: 2rem;">
            <?php wp_nonce_field('qp_search_user_attempts_nonce'); ?>
            <input type="hidden" name="action" value="qp_search_user">
            <label for="qp_user_id_search"><strong>Enter User ID:</strong></label><br>
            <input type="number" id="qp_user_id_search" name="qp_user_id_search" value="<?php echo esc_attr($user_id_searched); ?>" min="1" required>
            <input type="submit" class="button button-secondary" value="Find User">
        </form>

        <?php if ($user_id_searched): // Only show update form if a user *ID* was searched for ?>
            <hr>
            <h3>Update Attempts for User: <?php echo esc_html($user_info ? $user_info->display_name . ' (ID: ' . $user_id_searched . ')' : 'Not Found'); ?></h3>
            <?php if ($user_info): // Only show update fields if the user was actually found ?>
                <form method="post" action="admin.php?page=qp-tools&tab=user_attempts">
                    <?php wp_nonce_field('qp_update_user_attempts_nonce'); ?>
                    <input type="hidden" name="action" value="qp_update_attempts">
                    <input type="hidden" name="user_id_to_update" value="<?php echo esc_attr($user_id_searched); ?>">

                    <p><strong>Current Remaining Attempts:</strong> <?php echo esc_html($current_attempts); ?></p>

                    <label for="qp_new_attempts"><strong>Set New Attempt Count:</strong></label><br>
                    <input type="number" id="qp_new_attempts" name="qp_new_attempts" value="<?php echo esc_attr($current_attempts !== 'N/A' ? $current_attempts : 0); ?>" min="0" required>
                    <p class="description">Enter the total number of attempts the user should have. Use 0 to remove access.</p>

                    <input type="submit" class="button button-primary" value="Update Attempts">
                </form>
            <?php else: ?>
                <?php // Error message is handled by settings_errors() above ?>
            <?php endif; ?>
        <?php endif; ?>

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

/**
 * Add screen options for the Entitlements list table.
 */
function qp_add_entitlements_screen_options() {
    $screen = get_current_screen();
    // Check if we are on the correct screen
    if ($screen && $screen->id === 'question-press_page_qp-user-entitlements') {
        QP_Entitlements_List_Table::add_screen_options();
    }
}
add_action('admin_head', 'qp_add_entitlements_screen_options');

// Filter to save the screen option (reuse existing function if desired, or keep separate)
function qp_save_entitlements_screen_options($status, $option, $value) {
    if ('entitlements_per_page' === $option) {
        return $value;
    }
    // Important: Return the original status for other options
    return $status;
}
add_filter('set-screen-option', 'qp_save_entitlements_screen_options', 10, 3);

function qp_save_screen_options($status, $option, $value)
{
    if ('qp_questions_per_page' === $option) {
        return $value;
    }
    return $status;
}
add_filter('set-screen-option', 'qp_save_screen_options', 10, 3);

/**
 * Helper function to retrieve the existing course structure for the editor.
 *
 * @param int $course_id The ID of the course post.
 * @return array The structured course data.
 */
function qp_get_course_structure_for_editor($course_id) {
    if (!$course_id) {
        return ['sections' => []]; // Return empty structure for new courses
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $structure = ['sections' => []];

    $sections = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
        $course_id
    ));

    if (empty($sections)) {
        return $structure;
    }

    $section_ids = wp_list_pluck($sections, 'section_id');
    $ids_placeholder = implode(',', array_map('absint', $section_ids));

    $items_raw = $wpdb->get_results("SELECT item_id, section_id, title, item_order, content_type, content_config FROM $items_table WHERE section_id IN ($ids_placeholder) ORDER BY item_order ASC");

    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->content_config = json_decode($item->content_config, true); // Decode JSON
        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    foreach ($sections as $section) {
        $structure['sections'][] = [
            'id' => $section->section_id,
            'title' => $section->title,
            'description' => $section->description,
            'order' => $section->section_order,
            'items' => $items_by_section[$section->section_id] ?? []
        ];
    }

    return $structure;
}

/**
 * UPDATED: qp_get_test_series_options_for_js
 * Also fetches source terms and source-subject links needed for modal filters.
 */
function qp_get_test_series_options_for_js() {
    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';

    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // Fetch ALL subjects and topics together
    $all_subject_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
        $subject_tax_id
    ), ARRAY_A); // Fetch as associative arrays for JS

    // Fetch ALL source terms (including sections)
    $all_source_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
        $source_tax_id
    ), ARRAY_A);

     // Fetch source-subject links
     $source_subject_links = $wpdb->get_results(
        "SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'",
        ARRAY_A
     );


    return [
        'allSubjectTerms' => $all_subject_terms,
        'allSourceTerms' => $all_source_terms, // Add source terms
        'sourceSubjectLinks' => $source_subject_links, // Add source-subject links
    ];
}


function qp_admin_enqueue_scripts($hook_suffix)
{
    if ($hook_suffix === 'question-press_page_qp-tools') {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
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
    // Check if we are on the 'Add New' or 'Edit' screen for the 'qp_course' post type
    global $pagenow, $typenow;
    if (($pagenow == 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] == 'qp_course') ||
        ($pagenow == 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) == 'qp_course')) {

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue our new course editor script
        $course_editor_js_version = filemtime(QP_PLUGIN_DIR . 'admin/assets/js/course-editor.js'); // For cache busting
        wp_enqueue_script('qp-course-editor-script', QP_PLUGIN_URL . 'admin/assets/js/course-editor.js', ['jquery', 'jquery-ui-sortable'], $course_editor_js_version, true);

        // Localize data needed by the script (like existing structure and dropdown options)
        global $post; // Get the current post object
        $course_structure_data = qp_get_course_structure_for_editor($post ? $post->ID : 0); // We will create this helper function next
        $test_series_options = qp_get_test_series_options_for_js(); // And this one too

        wp_localize_script('qp-course-editor-script', 'qpCourseEditorData', [
            'ajax_url' => admin_url('admin-ajax.php'), // Add ajaxurl for convenience
            'save_nonce' => wp_create_nonce('qp_save_course_structure_meta'), // Keep existing save nonce
            'select_nonce' => wp_create_nonce('qp_course_editor_select_nonce'), // Add the NEW nonce
            'structure' => $course_structure_data,
            'testSeriesOptions' => $test_series_options
        ]);
        // Enqueue course editor CSS
        $course_editor_css_version = filemtime(QP_PLUGIN_DIR . 'admin/assets/css/course-editor.css');
        wp_enqueue_style('qp-course-editor-style', QP_PLUGIN_URL . 'admin/assets/css/course-editor.css', [], $course_editor_css_version);
    }
    if ($hook_suffix === 'question-press_page_qp-organization' && isset($_GET['tab']) && $_GET['tab'] === 'labels') {
        add_action('admin_footer', function () {
            echo '<script>jQuery(document).ready(function($){$(".qp-color-picker").wpColorPicker();});</script>';
        });
    }

    if ($hook_suffix === 'question-press_page_qp-organization') {
        wp_enqueue_script('qp-organization-script', QP_PLUGIN_URL . 'admin/assets/js/organization-page.js', ['jquery'], '1.0.0', true);
    }

    if ($hook_suffix === 'question-press_page_qp-settings') {
        wp_enqueue_script('qp-settings-script', QP_PLUGIN_URL . 'admin/assets/js/settings-page.js', ['jquery'], '1.0.0', true);
    }

    if (
        $hook_suffix === 'question-press_page_qp-settings' ||
        $hook_suffix === 'toplevel_page_question-press' ||
        $hook_suffix === 'question-press_page_qp-question-editor' ||
        $hook_suffix === 'admin_page_qp-edit-group'
    ) {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
    }

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
        wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
        wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);

        wp_add_inline_script('katex-auto-render', "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof renderMathInElement === 'function') {
                renderMathInElement(document.getElementById('the-list'), {
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

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_PLUGIN_URL . 'admin/assets/js/quick-edit.js', ['jquery'], '1.0.2', true);
        // Add a nonce specifically for our new admin filters
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

        // all sources (top-level terms under the source taxonomy)
        $all_sources = $wpdb->get_results($wpdb->prepare("SELECT term_id AS source_id, name AS source_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $source_tax_id));

        // Get all sections (child terms under the source taxonomy)
        $all_sections = $wpdb->get_results($wpdb->prepare("SELECT term_id AS section_id, name AS section_name, parent AS source_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $source_tax_id));

        // Build the source-to-subject relationship map
        $source_subject_links = $wpdb->get_results(
            "SELECT object_id AS source_id, term_id AS subject_id
             FROM {$rel_table}
             WHERE object_type = 'source_subject_link'"
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
            'source_subject_links' => $source_subject_links,
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

// Used on export page

/**
 * Helper function to get all descendant term IDs for a given parent, including the parent itself.
 *
 * @param int    $parent_id The starting term_id.
 * @param object $wpdb      The WordPress database object.
 * @param string $term_table The name of the terms table.
 * @return array An array of term IDs.
 */
function get_all_descendant_ids($parent_id, $wpdb, $term_table)
{
    $descendant_ids = [$parent_id];
    $current_parent_ids = [$parent_id];
    for ($i = 0; $i < 10; $i++) { // Safety break
        if (empty($current_parent_ids)) break;
        $ids_placeholder = implode(',', $current_parent_ids);
        $child_ids = $wpdb->get_col("SELECT term_id FROM $term_table WHERE parent IN ($ids_placeholder)");
        if (!empty($child_ids)) {
            $descendant_ids = array_merge($descendant_ids, $child_ids);
            $current_parent_ids = $child_ids;
        } else {
            break;
        }
    }
    return array_unique($descendant_ids);
}

// Used on export page
/**
 * Helper function to trace a term's lineage back to the root and return an array of names.
 *
 * @param int    $term_id      The starting term_id.
 * @param object $wpdb         The WordPress database object.
 * @param string $term_table   The name of the terms table.
 * @return array An ordered array of names from parent to child.
 */
function qp_get_term_lineage_names($term_id, $wpdb, $term_table)
{
    $lineage = [];
    $current_id = $term_id;
    for ($i = 0; $i < 10; $i++) { // Safety break
        if (!$current_id) break;
        $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM {$term_table} WHERE term_id = %d", $current_id));
        if ($term) {
            array_unshift($lineage, $term->name);
            $current_id = $term->parent;
        } else {
            break;
        }
    }
    return $lineage;
}

/**
 * Determines the allowed subject term IDs for a given user based on their scope settings.
 * Reads _qp_allowed_exam_term_ids and _qp_allowed_subject_term_ids from usermeta.
 *
 * @param int $user_id The ID of the user to check.
 * @return string|array Returns 'all' if access is unrestricted, or an array of allowed subject term IDs. Returns empty array if user_id is invalid.
 */
function qp_get_allowed_subject_ids_for_user($user_id) {
    $user_id = absint($user_id);
    if (empty($user_id)) {
        return []; // No access for non-logged-in or invalid ID
    }

    // Admins always have full access (capability check)
    if (user_can($user_id, 'manage_options')) {
        return 'all';
    }

    // Get stored scope settings from user meta
    $allowed_exams_json = get_user_meta($user_id, '_qp_allowed_exam_term_ids', true);
    $direct_subjects_json = get_user_meta($user_id, '_qp_allowed_subject_term_ids', true);

    // Decode JSON, default to empty array if invalid, null, or not set
    $allowed_exam_ids = json_decode($allowed_exams_json, true);
    $direct_subject_ids = json_decode($direct_subjects_json, true);

    // Ensure they are arrays after decoding
    if (!is_array($allowed_exam_ids)) { $allowed_exam_ids = []; }
    if (!is_array($direct_subject_ids)) { $direct_subject_ids = []; }

    // If both settings are empty arrays (meaning unrestricted), grant access to all subjects
    if (empty($allowed_exam_ids) && empty($direct_subject_ids)) {
        return 'all';
    }

    global $wpdb;
    // Start with the directly allowed subjects
    $final_allowed_subject_ids = $direct_subject_ids;

    // If specific exams are allowed, find subjects linked to them
    if (!empty($allowed_exam_ids)) {
        // Ensure IDs are integers before using in query
        $exam_ids_sanitized = array_map('absint', $allowed_exam_ids);
        // Prevent query errors if array becomes empty after sanitization
        if (!empty($exam_ids_sanitized)) {
             $exam_ids_placeholder = implode(',', $exam_ids_sanitized);
             $rel_table = $wpdb->prefix . 'qp_term_relationships';

             // Find subject term_ids linked to the allowed exam object_ids
             $subjects_from_exams = $wpdb->get_col(
                "SELECT DISTINCT term_id
                 FROM {$rel_table}
                 WHERE object_type = 'exam_subject_link'
                 AND object_id IN ($exam_ids_placeholder)"
             );

             // If subjects are found, merge them with the directly allowed ones
             if (!empty($subjects_from_exams)) {
                // Ensure these are also integers
                $subjects_from_exams_int = array_map('absint', $subjects_from_exams);
                $final_allowed_subject_ids = array_merge($final_allowed_subject_ids, $subjects_from_exams_int);
             }
        }
    }

    // Return the unique list of combined subject IDs (ensure all are integers)
    return array_unique(array_map('absint', $final_allowed_subject_ids));
}

/**
 * Helper function to migrate term relationships from questions to their parent groups for a specific taxonomy.
 *
 * @param string $taxonomy_name The name of the taxonomy to process (e.g., 'subject' for topics, 'source' for sources/sections).
 * @param string $log_prefix A prefix for logging messages (e.g., 'Topic', 'Source/Section').
 * @return array A report of the migration containing counts and skipped items.
 */
function qp_migrate_taxonomy_relationships($taxonomy_name, $log_prefix)
{
    global $wpdb;
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $q_table = $wpdb->prefix . 'qp_questions';

    $migrated_count = 0;
    $deleted_count = 0;
    $skipped = [];

    // Get the taxonomy ID for the given taxonomy name
    $taxonomy_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $taxonomy_name));
    if (!$taxonomy_id) {
        $skipped[] = "{$log_prefix} Migration: Taxonomy '{$taxonomy_name}' not found. Skipping.";
        return ['migrated' => 0, 'deleted' => 0, 'skipped' => $skipped];
    }

    // 1. Find all unique group-term pairs by inspecting question relationships.
    // For each group, we find the single, most representative term.
    $group_to_term_map = [];
    $question_relationships = $wpdb->get_results($wpdb->prepare("
        SELECT q.group_id, r.term_id, t.parent
        FROM {$q_table} q
        JOIN {$rel_table} r ON q.question_id = r.object_id AND r.object_type = 'question'
        JOIN {$term_table} t ON r.term_id = t.term_id
        WHERE t.taxonomy_id = %d AND q.group_id > 0
    ", $taxonomy_id));

    foreach ($question_relationships as $rel) {
        // For topics (subject taxonomy), we only care about children (parent != 0)
        if ($taxonomy_name === 'subject' && $rel->parent == 0) {
            continue;
        }
        // For a group, we always want to store the most specific (deepest) term.
        // A child term (parent != 0) is always more specific than a parent term.
        if (!isset($group_to_term_map[$rel->group_id]) || $rel->parent != 0) {
            $group_to_term_map[$rel->group_id] = $rel->term_id;
        }
    }

    // 2. Insert the new group-level relationships.
    foreach ($group_to_term_map as $group_id => $term_id) {
        if ($group_id > 0 && $term_id > 0) {
            // First, delete any existing relationship for this group and taxonomy to avoid conflicts
            $wpdb->query($wpdb->prepare(
                "DELETE r FROM {$rel_table} r
                 JOIN {$term_table} t ON r.term_id = t.term_id
                 WHERE r.object_id = %d AND r.object_type = 'group' AND t.taxonomy_id = %d",
                $group_id,
                $taxonomy_id
            ));

            // Now insert the new, correct relationship
            $result = $wpdb->insert($rel_table, [
                'object_id' => $group_id,
                'term_id' => $term_id,
                'object_type' => 'group'
            ]);

            if ($result) {
                $migrated_count++;
            }
        } else {
            $skipped[] = "{$log_prefix} Link: Skipped relationship for Group ID {$group_id} and Term ID {$term_id} due to invalid ID.";
        }
    }

    // 3. Delete all old question-level relationships for this taxonomy.
    $deleted_count = $wpdb->query($wpdb->prepare(
        "DELETE r FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE r.object_type = 'question' AND t.taxonomy_id = %d",
        $taxonomy_id
    ));

    return ['migrated' => $migrated_count, 'deleted' => $deleted_count, 'skipped' => $skipped];
}

// FORM & ACTION HANDLERS
function qp_handle_form_submissions()
{
    if (isset($_GET['page']) && $_GET['page'] === 'qp-organization') {
        QP_Sources_Page::handle_forms();
        QP_Subjects_Page::handle_forms();
        QP_Labels_Page::handle_forms();
        QP_Exams_Page::handle_forms();
    }
    QP_Export_Page::handle_export_submission();
    QP_Backup_Restore_Page::handle_forms();
    QP_Settings_Page::register_settings();
    qp_handle_save_question_group();
}
add_action('admin_init', 'qp_handle_form_submissions');

/**
 * Handles saving the user's subject scope (allowed exams/subjects) from the User Entitlements page.
 */
function qp_handle_save_user_scope() {
    // 1. Security Checks
    if (
        !isset($_POST['_qp_scope_nonce']) ||
        !wp_verify_nonce($_POST['_qp_scope_nonce'], 'qp_save_user_scope_nonce')
    ) {
        wp_die(__('Security check failed.', 'question-press'));
    }

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to manage user scope.', 'question-press'));
    }

    // 2. Get and Sanitize User ID
    $user_id = isset($_POST['user_id_to_update']) ? absint($_POST['user_id_to_update']) : 0;
    if ($user_id <= 0 || !get_userdata($user_id)) {
         wp_die(__('Invalid User ID specified.', 'question-press'));
    }

    // 3. Get and Sanitize Selected Exams and Subjects
    // If the checkbox array is not submitted (nothing checked), default to an empty array.
    $allowed_exams = isset($_POST['allowed_exams']) && is_array($_POST['allowed_exams'])
                     ? array_map('absint', $_POST['allowed_exams'])
                     : [];

    $allowed_subjects = isset($_POST['allowed_subjects']) && is_array($_POST['allowed_subjects'])
                        ? array_map('absint', $_POST['allowed_subjects'])
                        : [];

    // 4. Update User Meta
    // Store as JSON for easier handling on retrieval
    update_user_meta($user_id, '_qp_allowed_exam_term_ids', json_encode($allowed_exams));
    update_user_meta($user_id, '_qp_allowed_subject_term_ids', json_encode($allowed_subjects));

    // 5. Redirect back with success message
    $redirect_url = add_query_arg([
        'page' => 'qp-user-entitlements',
        'user_id' => $user_id,
        'message' => 'scope_updated' // Use this to display success notice on the page
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_qp_save_user_scope', 'qp_handle_save_user_scope');

function qp_all_questions_page_cb()
{
    $list_table = new QP_Questions_List_Table();
    $list_table->prepare_items();
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">All Questions</h1>
        <a href="<?php echo admin_url('admin.php?page=qp-question-editor'); ?>" class="page-title-action">Add New</a>
        <?php
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        if (isset($_GET['message'])) {
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
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

    if (!$subject_id) {
        wp_send_json_success(['sources' => []]);
        return;
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // Step 1: Get the relevant group IDs based on subject/topic filter
    $term_ids_to_check = [];
    if ($topic_id > 0) {
        $term_ids_to_check = [$topic_id];
    } else {
        $term_ids_to_check = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM $term_table WHERE parent = %d", $subject_id));
    }

    $group_ids = [];
    if (!empty($term_ids_to_check)) {
        $term_ids_placeholder = implode(',', $term_ids_to_check);
        $group_ids = $wpdb->get_col("SELECT object_id FROM $rel_table WHERE term_id IN ($term_ids_placeholder) AND object_type = 'group'");
    }

    if (empty($group_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }
    $group_ids_placeholder = implode(',', $group_ids);

    // Step 2: Find all source/section terms linked to questions within those groups
    $linked_term_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT t.term_id
         FROM {$term_table} t
         JOIN {$rel_table} r ON t.term_id = r.term_id
         WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d",
        $source_tax_id
    ));

    if (empty($linked_term_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }

    // Step 3: Fetch the full lineage (parents) for every linked term
    $full_lineage_ids = [];
    foreach ($linked_term_ids as $term_id) {
        $current_id = $term_id;
        for ($i = 0; $i < 10; $i++) { // Safety break
            if (!$current_id || in_array($current_id, $full_lineage_ids)) break;
            $full_lineage_ids[] = $current_id;
            $current_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $current_id));
        }
    }
    $all_relevant_term_ids = array_unique($full_lineage_ids);
    $all_relevant_term_ids_placeholder = implode(',', $all_relevant_term_ids);

    // Step 4: Fetch all details for the relevant terms
    $all_terms_data = $wpdb->get_results("SELECT term_id, name, parent FROM $term_table WHERE term_id IN ($all_relevant_term_ids_placeholder)");

    // Step 5: Build a hierarchical tree from the flat list
    $terms_by_id = [];
    foreach ($all_terms_data as $term) {
        $terms_by_id[$term->term_id] = $term;
        $term->children = [];
    }

    $tree = [];
    foreach ($terms_by_id as $term_id => &$term) {
        if ($term->parent != 0 && isset($terms_by_id[$term->parent])) {
            $terms_by_id[$term->parent]->children[] = &$term;
        } elseif ($term->parent == 0) {
            $tree[] = &$term;
        }
    }

    // Sort the top-level sources by name
    usort($tree, function ($a, $b) {
        return strcmp($a->name, $b->name);
    });

    wp_send_json_success(['sources' => $tree]);
}
add_action('wp_ajax_get_sources_for_list_table_filter', 'qp_get_sources_for_list_table_filter_ajax');

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
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';
    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($_POST['subject_id']) || empty($questions_from_form)) {
        // A subject is required to save a group.
        // Silently fail if no subject is selected to avoid errors on page load.
        return;
    }

    // --- Save Group Data ---
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

    // --- CONSOLIDATED Group-Level Term Relationship Handling ---
    if ($group_id) {
        $rel_table = "{$wpdb->prefix}qp_term_relationships";
        $term_table = "{$wpdb->prefix}qp_terms";
        $tax_table = "{$wpdb->prefix}qp_taxonomies";

        $group_taxonomies_to_manage = ['subject', 'source', 'exam'];
        $tax_ids_to_clear = [];
        foreach ($group_taxonomies_to_manage as $tax_name) {
            $tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $tax_name));
            if ($tax_id) $tax_ids_to_clear[] = $tax_id;
        }

        // 1. Delete all existing relationships for this group across managed taxonomies
        if (!empty($tax_ids_to_clear)) {
            $tax_ids_placeholder = implode(',', $tax_ids_to_clear);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN ($tax_ids_placeholder))",
                $group_id
            ));
        }

        // 2. Determine the new terms to apply
        $terms_to_apply_to_group = [];
        // Subject/Topic: Use the most specific topic selected, which represents the entire subject hierarchy.
        if (!empty($_POST['topic_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['topic_id']);
        } elseif (!empty($_POST['subject_id'])) {
            // Fallback to parent subject if no topic is chosen
            $terms_to_apply_to_group[] = absint($_POST['subject_id']);
        }

        // Source/Section: Use the most specific term selected.
        if (!empty($_POST['section_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['section_id']);
        } elseif (!empty($_POST['source_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['source_id']);
        }

        // Exam: Apply if PYQ is checked and an exam is selected.
        if ($is_pyq && !empty($_POST['exam_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['exam_id']);
        }

        // 3. Insert the new, clean relationships for the group
        foreach (array_unique($terms_to_apply_to_group) as $term_id) {
            if ($term_id > 0) {
                $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $term_id, 'object_type' => 'group']);
            }
        }
    }

    // --- Process Individual Questions (and their Label relationships) ---
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
        if (empty(trim($question_text))) continue;

        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        $is_question_complete = !empty($q_data['correct_option_id']);

        $question_db_data = [
            'group_id' => $group_id,
            'question_number_in_section' => isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '',
            'question_text' => wp_kses_post($question_text),
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
            'status' => $is_question_complete ? 'publish' : 'draft',
        ];

        if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
        } else {
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id;
        }
        $submitted_q_ids[] = $question_id;

        if ($question_id > 0) {
            // Handle Question-Level Relationships (LABELS ONLY)
            $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
            if ($label_tax_id) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $question_id, $label_tax_id));
            }
            $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
            foreach ($labels as $label_id) {
                if ($label_id > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $label_id, 'object_type' => 'question']);
                }
            }

            // Handle Options (No changes needed here)
            process_question_options($question_id, $q_data);
        }
    }

    // --- Clean up removed questions ---
    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $rel_table WHERE object_id IN ($ids_placeholder) AND object_type = 'question'");
        $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
    }

    // --- Final Redirect ---
    if ($is_editing && empty($submitted_q_ids)) {
        $wpdb->delete("{$wpdb->prefix}qp_question_groups", ['group_id' => $group_id]);
        wp_safe_redirect(admin_url('admin.php?page=question-press&message=1'));
        exit;
    }

    $redirect_url = $is_editing
        ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1')
        : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Re-evaluates all attempts for a specific question after its correct answer has changed.
 * It also recalculates and updates the stats for all affected sessions.
 *
 * @param int $question_id The ID of the question that was updated.
 * @param int $new_correct_option_id The ID of the new correct option.
 */
function qp_re_evaluate_question_attempts($question_id, $new_correct_option_id)
{
    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // 1. Find all session IDs that have an attempt for this question.
    $affected_session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT session_id FROM {$attempts_table} WHERE question_id = %d",
        $question_id
    ));

    if (empty($affected_session_ids)) {
        return; // No attempts to update.
    }

    // 2. Update the is_correct status for all attempts of this question.
    // Set is_correct = 1 where the selected option matches the new correct option.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$attempts_table} SET is_correct = 1 WHERE question_id = %d AND selected_option_id = %d",
        $question_id,
        $new_correct_option_id
    ));
    // Set is_correct = 0 for all other attempts of this question.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$attempts_table} SET is_correct = 0 WHERE question_id = %d AND selected_option_id != %d",
        $question_id,
        $new_correct_option_id
    ));

    // 3. Loop through each affected session and recalculate its score.
    foreach ($affected_session_ids as $session_id) {
        $session = $wpdb->get_row($wpdb->prepare("SELECT settings_snapshot FROM {$sessions_table} WHERE session_id = %d", $session_id));
        if (!$session) continue;

        $settings = json_decode($session->settings_snapshot, true);
        $marks_correct = $settings['marks_correct'] ?? 0;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;

        // Recalculate counts directly from the attempts table for this session
        $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 1", $session_id));
        $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 0", $session_id));

        $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

        // Update the session record with the new, accurate counts and score.
        $wpdb->update(
            $sessions_table,
            [
                'correct_count' => $correct_count,
                'incorrect_count' => $incorrect_count,
                'marks_obtained' => $final_score
            ],
            ['session_id' => $session_id]
        );
    }
}

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
    // If the correct answer has changed, trigger the re-evaluation function.
    if ($original_correct_option_id != $correct_option_id_from_form) {
        qp_re_evaluate_question_attempts($question_id, absint($correct_option_id_from_form));
    }
}

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
        'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
        'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
        'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
        'qp_terms', 'qp_term_meta', 'qp_term_relationships',
    ];
    $full_table_names = array_map(fn($table) => $wpdb->prefix . $table, $tables_to_backup);

    $backup_data = [];
    foreach ($full_table_names as $table) {
        $table_name_without_prefix = str_replace($wpdb->prefix, '', $table);
        $backup_data[$table_name_without_prefix] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
    }

    $backup_data['plugin_settings'] = ['qp_settings' => get_option('qp_settings')];

    // *** THIS IS THE FIX: Part 1 - Create the Image Map ***
    $image_ids = $wpdb->get_col("SELECT DISTINCT direction_image_id FROM {$wpdb->prefix}qp_question_groups WHERE direction_image_id IS NOT NULL AND direction_image_id > 0");
    $image_map = [];
    $images_to_zip = [];
    if (!empty($image_ids)) {
        foreach ($image_ids as $image_id) {
            $image_path = get_attached_file($image_id);
            if ($image_path && file_exists($image_path)) {
                $image_filename = basename($image_path);
                $image_map[$image_id] = $image_filename; // Map ID to filename
                $images_to_zip[$image_filename] = $image_path; // Store unique paths to zip
            }
        }
    }
    $backup_data['image_map'] = $image_map; // Add the map to the backup data

    $json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json_filename = 'database.json';
    $temp_json_path = trailingslashit($backup_dir) . $json_filename;
    file_put_contents($temp_json_path, $json_data);

    $prefix = ($type === 'auto') ? 'qp-auto-backup-' : 'qp-backup-';
    $timestamp = current_time('mysql');
    $datetime = new DateTime($timestamp);
    $timezone_abbr = 'IST';
    $backup_filename = $prefix . $datetime->format('Y-m-d_H-i-s') . '_' . $timezone_abbr . '.zip';
    $zip_path = trailingslashit($backup_dir) . $backup_filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return ['success' => false, 'message' => 'Cannot create ZIP archive.'];
    }

    $zip->addFile($temp_json_path, $json_filename);

    if (!empty($images_to_zip)) {
        $zip->addEmptyDir('images');
        foreach ($images_to_zip as $filename => $path) {
            $zip->addFile($path, 'images/' . $filename);
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
    $backups = file_exists($backup_dir) ? array_diff(scandir($backup_dir), ['..', '.']) : [];

    // --- NEW SORTING LOGIC ---
    $sorted_backups = [];
    if (!empty($backups)) {
        $files_with_time = [];
        foreach ($backups as $backup_file) {
            $file_path = trailingslashit($backup_dir) . $backup_file;
            if (is_dir($file_path)) continue;
            $files_with_time[$backup_file] = filemtime($file_path);
        }

        // Sort by time descending, then by name ascending for tie-breaking
        uksort($files_with_time, function ($a, $b) use ($files_with_time) {
            if ($files_with_time[$a] == $files_with_time[$b]) {
                return strcmp($a, $b); // Sort by name if times are identical
            }
            // Primary sort by modification time, descending
            return $files_with_time[$b] <=> $files_with_time[$a];
        });
        $sorted_backups = array_keys($files_with_time);
    }
    // --- END NEW SORTING LOGIC ---

    ob_start();

    if (empty($sorted_backups)) { // Use the new sorted array
        echo '<tr class="no-items"><td class="colspanchange" colspan="4">No local backups found.</td></tr>';
    } else {
        foreach ($sorted_backups as $backup_file) { // Iterate over the new sorted array
            $file_path = trailingslashit($backup_dir) . $backup_file;
            $file_url = trailingslashit($backup_url_base) . $backup_file;

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

    // --- Image ID Mapping ---
    $old_to_new_id_map = [];
    $images_dir = trailingslashit($temp_extract_dir) . 'images';
    if (isset($backup_data['image_map']) && is_array($backup_data['image_map']) && file_exists($images_dir)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        foreach ($backup_data['image_map'] as $old_id => $image_filename) {
            $image_path = trailingslashit($images_dir) . $image_filename;
            if (file_exists($image_path)) {
                $existing_attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like($image_filename)));
                
                if ($existing_attachment_id) {
                    $new_id = $existing_attachment_id;
                } else {
                    $new_id = media_handle_sideload(['name' => $image_filename, 'tmp_name' => $image_path], 0);
                }

                if (!is_wp_error($new_id)) {
                    $old_to_new_id_map[$old_id] = $new_id;
                }
            }
        }
    }

    if (isset($backup_data['qp_question_groups']) && !empty($old_to_new_id_map)) {
        foreach ($backup_data['qp_question_groups'] as &$group) {
            if (!empty($group['direction_image_id']) && isset($old_to_new_id_map[$group['direction_image_id']])) {
                $group['direction_image_id'] = $old_to_new_id_map[$group['direction_image_id']];
            }
        }
        unset($group);
    }
    
    // --- Clear Existing Data ---
    $tables_to_clear = [
        'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
        'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
        'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
        'qp_terms', 'qp_term_meta', 'qp_term_relationships',
    ];
    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables_to_clear as $table) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$table}");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

    // --- Deduplicate Attempts and Calculate Stats ---
    $duplicates_handled = 0;
    if (!empty($backup_data['qp_user_attempts'])) {
        $original_attempt_count = count($backup_data['qp_user_attempts']);
        $unique_attempts = [];
        foreach ($backup_data['qp_user_attempts'] as $attempt) {
            $key = $attempt['session_id'] . '-' . $attempt['question_id'];
            if (!isset($unique_attempts[$key])) {
                $unique_attempts[$key] = $attempt;
            } else {
                $existing_attempt = $unique_attempts[$key];
                if (!empty($attempt['selected_option_id']) && empty($existing_attempt['selected_option_id'])) {
                    $unique_attempts[$key] = $attempt;
                }
            }
        }
        $final_attempts = array_values($unique_attempts);
        $duplicates_handled = $original_attempt_count - count($final_attempts);
        $backup_data['qp_user_attempts'] = $final_attempts;
    }

    // *** THIS IS THE FIX: Calculate stats AFTER data processing ***
    $stats = [
        'questions' => isset($backup_data['qp_questions']) ? count($backup_data['qp_questions']) : 0,
        'options' => isset($backup_data['qp_options']) ? count($backup_data['qp_options']) : 0,
        'sessions' => isset($backup_data['qp_user_sessions']) ? count($backup_data['qp_user_sessions']) : 0,
        'attempts' => isset($backup_data['qp_user_attempts']) ? count($backup_data['qp_user_attempts']) : 0,
        'reports' => isset($backup_data['qp_question_reports']) ? count($backup_data['qp_question_reports']) : 0,
        'duplicates_handled' => $duplicates_handled
    ];
    // *** END FIX ***

    // --- Insert Restored Data into Database ---
    $restore_order = [
        'qp_taxonomies', 'qp_terms', 'qp_term_meta', 'qp_term_relationships', 'qp_question_groups', 
        'qp_questions', 'qp_options', 'qp_report_reasons', 'qp_question_reports', 'qp_logs', 
        'qp_user_sessions', 'qp_session_pauses', 'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts'
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
 * Helper function to get the full source hierarchy for a given question.
 *
 * @param int $question_id The ID of the question.
 * @return array An array containing the names of the source, section, etc., in order.
 */
function qp_get_source_hierarchy_for_question($question_id)
{
    global $wpdb;
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $questions_table = $wpdb->prefix . 'qp_questions';

    // Step 1: Get the group_id for the given question.
    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$questions_table} WHERE question_id = %d", $question_id));

    if (!$group_id) {
        return [];
    }

    // Step 2: Find the most specific source term linked to the GROUP.
    $term_id = $wpdb->get_var($wpdb->prepare(
        "SELECT r.term_id
         FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE r.object_id = %d AND r.object_type = 'group'
         AND t.taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source')
         LIMIT 1",
        $group_id
    ));

    if (!$term_id) {
        return []; // Return an empty array if no source is found for the group
    }

    $lineage = [];
    $current_term_id = $term_id;

    // Step 3: Loop up the hierarchy to trace back to the top-level parent (source).
    for ($i = 0; $i < 10; $i++) { // Safety limit to prevent infinite loops
        if (!$current_term_id) break;
        $term = $wpdb->get_row($wpdb->prepare("SELECT name, parent FROM {$term_table} WHERE term_id = %d", $current_term_id));
        if ($term) {
            array_unshift($lineage, $term->name); // Add to the beginning of the array.
            $current_term_id = $term->parent;
        } else {
            break;
        }
    }

    return $lineage; // Return the simple array of names
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



function qp_get_quick_edit_form_ajax()
{
    // =========================================================================
    // Step 0: Initial Setup & Security
    // =========================================================================
    check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'No Question ID provided.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $groups_table = $wpdb->prefix . 'qp_question_groups';
    $options_table = $wpdb->prefix . 'qp_options';

    // =========================================================================
    // Step 1: Fetch Current Data & Determine Subject/Topic Hierarchy
    // =========================================================================
    $question = $wpdb->get_row($wpdb->prepare("SELECT q.question_text, q.group_id, g.direction_text, g.is_pyq, g.pyq_year FROM {$questions_table} q LEFT JOIN {$groups_table} g ON q.group_id = g.group_id WHERE q.question_id = %d", $question_id));
    if (!$question) {
        wp_send_json_error(['message' => 'Question not found.']);
    }
    $group_id = $question->group_id;

    // --- 1a: Find the most specific topic linked to the group and trace its lineage ---
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
    $linked_topic_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0)", $group_id, $subject_tax_id));

    $current_topic_id = 0;
    $current_subject_id = 0;

    if ($linked_topic_id) {
        $current_topic_id = $linked_topic_id;
        $parent_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $linked_topic_id));
        // Trace upwards to find the top-level subject (where parent = 0)
        for ($i = 0; $i < 10; $i++) { // Safety break
            if ($parent_id == 0) {
                $current_subject_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM $term_table WHERE term_id = %d", $linked_topic_id));
                break;
            }
            $current_term = $wpdb->get_row($wpdb->prepare("SELECT term_id, parent FROM $term_table WHERE term_id = %d", $parent_id));
            if (!$current_term) break;
            $linked_topic_id = $current_term->term_id;
            $parent_id = $current_term->parent;
        }
    } else {
        // If no topic is linked, check for a direct subject link
        $current_subject_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0)", $group_id, $subject_tax_id));
    }


    // --- 1b: Fetch group-level source/section and trace its lineage ---
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
    $linked_source_term_id = $wpdb->get_var($wpdb->prepare(
        "SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)",
        $group_id,
        $source_tax_id
    ));

    $current_source_id = 0;
    $current_section_id = 0;

    if ($linked_source_term_id) {
        $term = $wpdb->get_row($wpdb->prepare("SELECT term_id, parent FROM $term_table WHERE term_id = %d", $linked_source_term_id));
        if ($term && $term->parent != 0) {
            // It's a section or subsection, so this is our selected "section"
            $current_section_id = $term->term_id;

            // Now, find its top-level parent (the source)
            $parent_id = $term->parent;
            for ($i = 0; $i < 10; $i++) { // Safety break
                $parent_term = $wpdb->get_row($wpdb->prepare("SELECT term_id, parent FROM $term_table WHERE term_id = %d", $parent_id));
                if (!$parent_term || $parent_term->parent == 0) {
                    $current_source_id = $parent_id;
                    break;
                }
                $parent_id = $parent_term->parent;
            }
        } else if ($term) {
            // It's a top-level source
            $current_source_id = $term->term_id;
        }
    }

    // --- 1c: Fetch other term relationships (Labels, Exam) ---
    // (This part of the original function remains the same)
    $question_terms_raw = $wpdb->get_results($wpdb->prepare("SELECT t.term_id, t.parent, tax.taxonomy_name FROM {$rel_table} r JOIN {$term_table} t ON r.term_id = t.term_id JOIN {$tax_table} tax ON t.taxonomy_id = tax.taxonomy_id WHERE r.object_id = %d AND r.object_type = 'question'", $question_id));
    $current_labels = [];
    foreach ($question_terms_raw as $term) {
        if ($term->taxonomy_name === 'label') {
            $current_labels[] = $term->term_id;
        }
    }
    $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");
    $current_exam_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $group_id, $exam_tax_id));


    // =========================================================================
    // Step 2: Fetch All Possible Terms for Form Dropdowns
    // =========================================================================
    $all_subjects = $wpdb->get_results($wpdb->prepare("SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $subject_tax_id));
    // Fetch ALL subjects and topics together for JS to build the hierarchy
    $all_subject_terms = $wpdb->get_results($wpdb->prepare("SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d", $subject_tax_id));

    $source_tax_id  = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
    $all_source_terms = $wpdb->get_results($wpdb->prepare("SELECT term_id as id, name, parent as parent_id FROM {$term_table} WHERE taxonomy_id = %d", $source_tax_id));

    $all_exams    = $wpdb->get_results($wpdb->prepare("SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d", $exam_tax_id));
    $label_tax_id   = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
    $all_labels   = $wpdb->get_results($wpdb->prepare("SELECT term_id as label_id, name as label_name FROM {$term_table} WHERE taxonomy_id = %d", $label_tax_id));

    $exam_subject_links   = $wpdb->get_results("SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'");
    $source_subject_links = $wpdb->get_results("SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'");
    $options = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_text, is_correct FROM {$options_table} WHERE question_id = %d ORDER BY option_id ASC", $question_id));

    // =========================================================================
    // Step 3 & 4: Prepare Data and Send Form HTML
    // =========================================================================
    ob_start();
    ?>
    <script>
        // This global object holds all the data our dynamic form needs.
        var qp_quick_edit_data = <?php echo wp_json_encode([
                                        'all_subjects'        => $all_subjects,
                                        'all_subject_terms'   => $all_subject_terms, // Used to build topic hierarchy
                                        'all_source_terms'    => $all_source_terms,
                                        'all_exams'           => $all_exams,
                                        'all_labels'          => $all_labels,
                                        'exam_subject_links'  => $exam_subject_links,
                                        'source_subject_links' => $source_subject_links,
                                        'current_subject_id'  => $current_subject_id,
                                        'current_topic_id'    => $current_topic_id,
                                        'current_source_id'   => $current_source_id,
                                        'current_section_id'  => $current_section_id,
                                        'current_exam_id'     => $current_exam_id,
                                        'current_labels'      => $current_labels,
                                    ]); ?>;
    </script>

    <form class="quick-edit-form-wrapper">
        <?php wp_nonce_field('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field'); ?>

        <div class="quick-edit-display-text">
            <?php if (!empty($question->direction_text)) : ?>
                <div class="display-group">
                    <strong>Direction:</strong>
                    <p><?php echo wp_kses_post(nl2br($question->direction_text)); ?></p>
                </div>
            <?php endif; ?>
            <div class="display-group">
                <strong>Question:</strong>
                <p><?php echo wp_kses_post(nl2br($question->question_text)); ?></p>
            </div>
        </div>

        <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

        <div class="quick-edit-main-container">
            <div class="quick-edit-col-left">
                <label><strong>Correct Answer</strong></label>
                <div class="options-group">
                    <?php foreach ($options as $option) : ?>
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
                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $current_subject_id); ?>>
                                    <?php echo esc_html($subject->subject_name); ?>
                                </option>
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
                                    <option value="<?php echo esc_attr($exam->exam_id); ?>" <?php selected($exam->exam_id, $current_exam_id); ?>>
                                        <?php echo esc_html($exam->exam_name); ?>
                                    </option>
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
                            <label class="inline-checkbox">
                                <input type="checkbox" name="labels[]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_labels)); ?>>
                                <?php echo esc_html($label->label_name); ?>
                            </label>
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
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
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
    // Send the captured HTML back as a successful JSON response.
    wp_send_json_success(['form' => ob_get_clean()]);
}
add_action('wp_ajax_qp_get_quick_edit_form', 'qp_get_quick_edit_form_ajax');

/**
 * AJAX handler to save the data from the quick edit form.
 *
 * This function is rewritten to correctly update the new taxonomy system. It handles:
 * - Updating group-level data (PYQ status).
 * - Updating group-level term relationships (Subject, Exam).
 * - Updating question-level term relationships (Topic, Source/Section, Labels).
 * - Updating the correct answer option.
 * - Re-rendering the updated table row and sending it back to the browser.
 */
function qp_save_quick_edit_data_ajax()
{
    // Step 1: Security check and data validation
    check_ajax_referer('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field');

    $data = $_POST;
    $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid Question ID provided.']);
    }

    // Step 2: Setup database variables
    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $o_table = $wpdb->prefix . 'qp_options';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';

    // Step 3: Get necessary IDs for processing
    $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM $q_table WHERE question_id = %d", $question_id));

    // Step 4: Update Group-Level Data (PYQ status)
    if ($group_id) {
        $wpdb->update($g_table, [
            'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
            'pyq_year' => (isset($data['is_pyq']) && !empty($data['pyq_year'])) ? sanitize_text_field($data['pyq_year']) : null
        ], ['group_id' => $group_id]);
    }

    // Step 5: CONSOLIDATED Group and Question-Level Term Relationships
    if ($group_id) {
        // --- 5a: Handle ALL Group-Level Relationships ---
        $group_taxonomies = ['subject', 'source', 'exam'];
        $tax_ids_to_clear = [];

        foreach ($group_taxonomies as $tax_name) {
            $tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $tax_name));
            if ($tax_id) $tax_ids_to_clear[] = $tax_id;
        }

        // Delete all existing group relationships for these taxonomies in one query
        if (!empty($tax_ids_to_clear)) {
            $tax_ids_placeholder = implode(',', $tax_ids_to_clear);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rel_table} 
                 WHERE object_id = %d AND object_type = 'group' 
                 AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN ($tax_ids_placeholder))",
                $group_id
            ));
        }

        // Insert new relationships for the group
        $group_terms_to_apply = [];
        // Subject/Topic: Link the group to the most specific topic selected.
        if (!empty($data['topic_id'])) $group_terms_to_apply[] = absint($data['topic_id']);

        // Source/Section: Link the group to the most specific term (section > source).
        if (!empty($data['section_id'])) {
            $group_terms_to_apply[] = absint($data['section_id']);
        } elseif (!empty($data['source_id'])) {
            $group_terms_to_apply[] = absint($data['source_id']);
        }

        // Exam: Link the group if it's a PYQ and an exam is selected
        if (isset($data['is_pyq']) && !empty($data['exam_id'])) {
            $group_terms_to_apply[] = absint($data['exam_id']);
        }

        // Insert all new group relationships
        foreach (array_unique($group_terms_to_apply) as $term_id) {
            if ($term_id > 0) {
                $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $term_id, 'object_type' => 'group']);
            }
        }
    }

    // --- 5b: Handle Question-Level Relationships (Labels) ---
    // (This part remains the same as it correctly targets the question)
    $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
    if ($label_tax_id) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$rel_table} 
             WHERE object_id = %d AND object_type = 'question' 
             AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)",
            $question_id,
            $label_tax_id
        ));
    }

    if (!empty($data['labels']) && is_array($data['labels'])) {
        foreach ($data['labels'] as $label_id) {
            if (absint($label_id) > 0) {
                $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => absint($label_id), 'object_type' => 'question']);
            }
        }
    }

    // Step 6: Update the Correct Answer Option and Re-evaluate
    $new_correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
    if ($new_correct_option_id > 0) {
        // Get the original correct option ID before making any changes.
        $original_correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$o_table} WHERE question_id = %d AND is_correct = 1", $question_id));

        // Update the database
        $wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]);
        $wpdb->update($o_table, ['is_correct' => 1], ['option_id' => $new_correct_option_id, 'question_id' => $question_id]);

        // If the correct answer has changed, trigger the re-evaluation.
        if ($original_correct_option_id != $new_correct_option_id) {
            qp_re_evaluate_question_attempts($question_id, $new_correct_option_id);
        }
    }

    // Step 7: Re-render the updated table row and send it back
    $list_table = new QP_Questions_List_Table();
    $filters = ['status', 'filter_by_subject', 'filter_by_topic', 'filter_by_source', 'filter_by_label', 's'];
    foreach ($filters as $filter) {
        if ($filter === 'status' && isset($_POST['status'])) {
            $_REQUEST[$filter] = sanitize_key($_POST['status']);
        } elseif (isset($_POST[$filter])) {
            $_REQUEST[$filter] = $_POST[$filter];
        }
    }

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
    } else {
        wp_send_json_success(['row_html' => '']);
    }

    wp_send_json_error(['message' => 'Could not retrieve the updated row data. Please refresh the page.']);
}
add_action('wp_ajax_save_quick_edit_data', 'qp_save_quick_edit_data_ajax');



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

            .qp-organization-table .column-name {
                width: 35%;
            }

            .qp-organization-table .column-description {
                width: 50%;
            }

            .qp-organization-table .column-count {
                width: 15%;
                text-align: center;
            }
        </style>
    <?php
    }
}
add_action('admin_head', 'qp_admin_head_styles_for_list_table');

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

// 
// 
// Public-facing hooks and AJAX handlers
// 
// 

function qp_public_init()
{
    add_shortcode('question_press_practice', ['QP_Shortcodes', 'render_practice_form']);
    add_shortcode('question_press_session', ['QP_Shortcodes', 'render_session_page']);
    add_shortcode('question_press_review', ['QP_Shortcodes', 'render_review_page']);
    add_shortcode('question_press_dashboard', ['QP_Dashboard', 'render']);
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
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            $dashboard_css_version = filemtime(QP_PLUGIN_DIR . 'public/assets/css/dashboard.css'); // Get version for cache busting
            wp_enqueue_style('qp-dashboard-styles', QP_PLUGIN_URL . 'public/assets/css/dashboard.css', ['qp-practice-styles'], $dashboard_css_version); // Make it dependent on practice styles
        }
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

        $options = get_option('qp_settings');
        $shop_page_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/');
        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'enroll_nonce'       => wp_create_nonce('qp_enroll_course_nonce'), // <-- ADD THIS
            'start_course_test_nonce' => wp_create_nonce('qp_start_course_test_nonce'), // <-- ADD THIS
            'dashboard_page_url' => isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/'),
            'practice_page_url'  => isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/'),
            'review_page_url'    => isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/'),
            'session_page_url'   => isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/'),
            'question_order_setting'   => isset($options['question_order']) ? $options['question_order'] : 'random',
            'shop_page_url'      => $shop_page_url,
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

            wp_enqueue_script('hammer-js', 'https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js', [], '2.0.8', true);
            wp_enqueue_script('qp-practice-script', QP_PLUGIN_URL . 'public/assets/js/practice.js', ['jquery', 'hammer-js'], $practice_js_version, true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
            $qp_settings = get_option('qp_settings');
            wp_localize_script('qp-practice-script', 'qp_practice_settings', [
                'show_counts' => !empty($qp_settings['show_question_counts']),
                'show_topic_meta' => !empty($qp_settings['show_topic_meta'])
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

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}
add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax');

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

    $grouped_topics = [];
    foreach ($topics_by_subject_id as $data) {
        $grouped_topics[$data['name']] = $data['topics'];
    }

    wp_send_json_success(['topics' => $grouped_topics]);
}
add_action('wp_ajax_get_topics_for_subject', 'qp_get_topics_for_subject_ajax');


// Attention! Migrate this to a completely new mode

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
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // 1. Find all groups linked to the selected topic.
    $group_ids = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'group'", $topic_id));

    if (empty($group_ids)) {
        wp_send_json_success(['sections' => []]);
        return;
    }
    $group_ids_placeholder = implode(',', $group_ids);

    // 2. Find all source/section terms linked to those groups.
    $source_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.term_id, t.name, t.parent 
         FROM {$term_table} t
         JOIN {$rel_table} r ON t.term_id = r.term_id
         WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d
         ORDER BY t.parent, t.name ASC",
        $source_tax_id
    ));

    // 3. Get all question IDs the user has already attempted.
    $attempted_q_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id));
    $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

    $results = [];
    foreach ($source_terms as $term) {
        if ($term->parent > 0) { // We are only interested in sections
            $parent_source_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $term_table WHERE term_id = %d", $term->parent));

            // Subquery to count unattempted questions in this specific section and topic
            $unattempted_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(q.question_id)
                 FROM {$wpdb->prefix}qp_questions q
                 JOIN {$rel_table} r_group_topic ON q.group_id = r_group_topic.object_id AND r_group_topic.object_type = 'group'
                 JOIN {$rel_table} r_group_section ON q.group_id = r_group_section.object_id AND r_group_section.object_type = 'group'
                 WHERE r_group_topic.term_id = %d 
                 AND r_group_section.term_id = %d
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
    $tax_table = $wpdb->prefix . 'qp_taxonomies';

    // 1. Get all question IDs the user has already answered.
    $attempted_q_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT question_id FROM {$a_table} WHERE user_id = %d AND status = 'answered'",
        $user_id
    ));
    $attempted_q_ids_placeholder = !empty($attempted_q_ids) ? implode(',', array_map('absint', $attempted_q_ids)) : '0';

    // 2. Get all unattempted questions and trace them to their parent subject.
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

    // This query joins from the unattempted question, up to its group, to its linked term (topic),
    // and finally to that term's parent (subject).
    $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            t.term_id as topic_id,
            t.parent as subject_id
        FROM {$q_table} q
        JOIN {$rel_table} r ON q.group_id = r.object_id AND r.object_type = 'group'
        JOIN {$term_table} t ON r.term_id = t.term_id
        WHERE q.status = 'publish' 
          AND q.question_id NOT IN ({$attempted_q_ids_placeholder})
          AND t.taxonomy_id = %d
          AND t.parent != 0
    ", $subject_tax_id));

    // 3. Process the results into a structured count array for the frontend.
    $counts = [
        'by_subject' => [],
        'by_topic'   => [],
    ];

    foreach ($results as $row) {
        // Increment count for the specific topic
        if (!isset($counts['by_topic'][$row->topic_id])) {
            $counts['by_topic'][$row->topic_id] = 0;
        }
        $counts['by_topic'][$row->topic_id]++;

        // Increment count for the parent subject
        if (!isset($counts['by_subject'][$row->subject_id])) {
            $counts['by_subject'][$row->subject_id] = 0;
        }
        $counts['by_subject'][$row->subject_id]++;
    }

    wp_send_json_success(['counts' => $counts]);
}
add_action('wp_ajax_get_unattempted_counts', 'qp_get_unattempted_counts_ajax');

function qp_start_practice_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');

    global $wpdb;
    $user_id = get_current_user_id();
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $pauses_table = $wpdb->prefix . 'qp_session_pauses';

    // --- Session Settings ---
    $subjects_raw = isset($_POST['qp_subject']) && is_array($_POST['qp_subject']) ? $_POST['qp_subject'] : [];
    $topics_raw = isset($_POST['qp_topic']) && is_array($_POST['qp_topic']) ? $_POST['qp_topic'] : [];
    $section_id = isset($_POST['qp_section']) && is_numeric($_POST['qp_section']) ? absint($_POST['qp_section']) : 'all';

    $practice_mode = ($section_id !== 'all') ? 'Section Wise Practice' : 'normal';

    if ($practice_mode === 'normal' && empty($subjects_raw)) {
        wp_send_json_error(['message' => 'Please select at least one subject.']);
        return;
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
    
    // --- Table Names ---
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // *** NEW LOGIC: Find Existing Session BEFORE Building Query ***
    $session_id = 0;
    $is_updating_session = false;
    if ($practice_mode === 'Section Wise Practice') {
        $existing_sessions = $wpdb->get_results($wpdb->prepare("SELECT session_id, settings_snapshot FROM {$sessions_table} WHERE user_id = %d AND status IN ('completed', 'paused')", $user_id));
        foreach ($existing_sessions as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            if (isset($settings['section_id']) && (int)$settings['section_id'] === $section_id) {
                $session_id = $session->session_id;
                $is_updating_session = true; // Set our flag
                break;
            }
        }
    }

    // --- Build Question Pool based on NEW Group Hierarchy ---
    $joins = " FROM {$q_table} q JOIN {$g_table} g ON q.group_id = g.group_id";
    $where_conditions = ["q.status = 'publish'"];

    // 1. Determine the set of TOPIC term IDs to filter by.
    $topic_term_ids_to_filter = [];
    $subjects_selected = !empty($subjects_raw) && !in_array('all', $subjects_raw);
    $topics_selected = !empty($topics_raw) && !in_array('all', $topics_raw);

    if ($topics_selected) {
        $topic_term_ids_to_filter = array_map('absint', $topics_raw);
    } elseif ($subjects_selected) {
        $subject_ids_placeholder = implode(',', array_map('absint', $subjects_raw));
        $topic_term_ids_to_filter = $wpdb->get_col("SELECT term_id FROM {$term_table} WHERE parent IN ($subject_ids_placeholder)");
    }

    // 2. Find all groups linked to the selected topics (or all topics if no selection).
    if (!empty($topic_term_ids_to_filter)) {
        $topic_ids_placeholder = implode(',', $topic_term_ids_to_filter);
        $where_conditions[] = "g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id IN ($topic_ids_placeholder))";
    }

    // 3. Handle Section selection (which is a type of source term).
    if ($practice_mode === 'Section Wise Practice') {
        $where_conditions[] = $wpdb->prepare("g.group_id IN (SELECT object_id FROM {$rel_table} WHERE object_type = 'group' AND term_id = %d)", $section_id);
    }

    // 4. Apply PYQ filter.
    if ($session_settings['pyq_only']) {
        $where_conditions[] = "g.is_pyq = 1";
    }

    // 5. Exclude previously attempted questions if specified, UNLESS we are updating a session.
    if (!$session_settings['include_attempted'] && !$is_updating_session) {
        $attempted_q_ids_sql = $wpdb->prepare("SELECT DISTINCT question_id FROM $a_table WHERE user_id = %d AND status = 'answered'", $user_id);
        $where_conditions[] = "q.question_id NOT IN ($attempted_q_ids_sql)";
    }

    // 6. Exclude questions with open reports.
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    if (!empty($reported_question_ids)) {
        $reported_ids_placeholder = implode(',', $reported_question_ids);
        $where_conditions[] = "q.question_id NOT IN ($reported_ids_placeholder)";
    }

    // --- Determine Order and Finalize Query ---
    $options = get_option('qp_settings');
    $admin_order_setting = isset($options['question_order']) ? $options['question_order'] : 'random';
    $order_by_sql = '';

    if ($practice_mode === 'Section Wise Practice') {
        $order_by_sql = 'ORDER BY CAST(q.question_number_in_section AS UNSIGNED) ASC, q.question_id ASC';
    } else {
        $order_by_sql = ($admin_order_setting === 'in_order') ? 'ORDER BY q.question_id ASC' : 'ORDER BY RAND()';
    }

    $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);
    $query = "SELECT q.question_id, q.question_number_in_section {$joins} {$where_sql} {$order_by_sql}";

    $question_results = $wpdb->get_results($query);
    $question_ids = wp_list_pluck($question_results, 'question_id');

    // --- Session Creation (Common Logic) ---
    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
    }
    
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    // Add question numbers to settings for section practice
    if ($practice_mode === 'Section Wise Practice') {
        $session_settings['question_numbers'] = wp_list_pluck($question_results, 'question_number_in_section', 'question_id');
    }

    if ($session_id > 0) {
        // An existing session was found, so we update it.
        // Get the last activity time to use as the pause time.
        $end_time = $wpdb->get_var($wpdb->prepare("SELECT end_time FROM {$sessions_table} WHERE session_id = %d", $session_id));

        if ($end_time) {
            // Add a pause record from the last activity until now.
            $wpdb->insert($pauses_table, [
                'session_id' => $session_id,
                'pause_time' => $end_time,
                'resume_time' => current_time('mysql')
            ]);
        }
        
        // Now, update the session to be active again.
        $wpdb->update($sessions_table, [
            'status'                  => 'active',
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($question_ids)
        ], ['session_id' => $session_id]);
    } else {
        // No existing session found, create a new one
        $wpdb->insert($sessions_table, [
            'user_id'                 => $user_id,
            'status'                  => 'active',
            'start_time'              => current_time('mysql'),
            'last_activity'           => current_time('mysql'),
            'settings_snapshot'       => wp_json_encode($session_settings),
            'question_ids_snapshot'   => wp_json_encode($question_ids)
        ]);
        $session_id = $wpdb->insert_id;
    }

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
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // Exclude questions with open reports
    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    $exclude_sql = !empty($reported_question_ids) ? 'AND q.question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';

    $include_all_incorrect = isset($_POST['include_all_incorrect']) && $_POST['include_all_incorrect'] === 'true';
    $question_ids = [];

    if ($include_all_incorrect) {
        // Mode 1: Get all questions the user has EVER answered incorrectly.
        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT a.question_id 
             FROM {$attempts_table} a
             JOIN {$questions_table} q ON a.question_id = q.question_id
             WHERE a.user_id = %d AND a.is_correct = 0 AND q.status = 'publish' {$exclude_sql}",
            $user_id
        ));
    } else {
        // Mode 2: Get questions the user has NEVER answered correctly.
        $correctly_answered_qids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1",
            $user_id
        ));
        $correctly_answered_placeholder = !empty($correctly_answered_qids) ? implode(',', $correctly_answered_qids) : '0';

        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT a.question_id 
             FROM {$attempts_table} a
             JOIN {$questions_table} q ON a.question_id = q.question_id
             WHERE a.user_id = %d AND a.status = 'answered' AND q.status = 'publish'
             AND a.question_id NOT IN ({$correctly_answered_placeholder}) {$exclude_sql}",
            $user_id
        ));
    }

    if (empty($question_ids)) {
        wp_send_json_error(['message' => 'No incorrect questions found to practice.']);
    }

    shuffle($question_ids);

    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $session_settings = [
        'practice_mode'   => 'Incorrect Que. Practice',
        'marks_correct'   => null,
        'marks_incorrect' => null,
        'timer_enabled'   => false,
    ];

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'active',
        'start_time'              => current_time('mysql'),
        'last_activity'           => current_time('mysql'),
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode(array_values($question_ids))
    ]);
    $session_id = $wpdb->insert_id;

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
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // --- Build the initial query to get a pool of eligible questions ---
    $where_clauses = ["q.status = 'publish'"];
    $query_params = [];
    $joins = "FROM {$q_table} q JOIN {$g_table} g ON q.group_id = g.group_id";

    $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
    if (!empty($reported_question_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $reported_question_ids));
        $where_clauses[] = "q.question_id NOT IN ($ids_placeholder)";
    }

    $subjects_selected = !empty($subjects) && !in_array('all', $subjects);
    $topics_selected = !empty($topics) && !in_array('all', $topics);

    // **FIX START**: Correctly join through the group to find the topic.
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
    // This join finds the topic (a term with a parent in the 'subject' taxonomy) linked to the group.
    $joins .= " LEFT JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
    $joins .= " LEFT JOIN {$term_table} topic_term ON topic_rel.term_id = topic_term.term_id AND topic_term.taxonomy_id = " . (int)$subject_tax_id . " AND topic_term.parent != 0";

    if ($topics_selected) {
        $term_ids_to_filter = array_map('absint', $topics);
        $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
        // Filter by the topic_id we found in the join.
        $where_clauses[] = $wpdb->prepare("topic_term.term_id IN ($ids_placeholder)", $term_ids_to_filter);
    } elseif ($subjects_selected) {
        $term_ids_to_filter = array_map('absint', $subjects);
        $ids_placeholder = implode(',', array_fill(0, count($term_ids_to_filter), '%d'));
        // Filter by the topic's parent (which is the subject).
        $where_clauses[] = $wpdb->prepare("topic_term.parent IN ($ids_placeholder)", $term_ids_to_filter);
    }

    $base_where_sql = implode(' AND ', $where_clauses);
    // The corrected query now selects the topic_id directly from the join.
    $query = "SELECT q.question_id, topic_term.term_id as topic_id {$joins} WHERE {$base_where_sql}";
    // **FIX END**

    $question_pool = $wpdb->get_results($wpdb->prepare($query, $query_params));

    if (empty($question_pool)) {
        wp_send_json_error(['message' => 'No questions were found for the selected criteria. Please try different options.']);
    }

    // --- Apply distribution logic ---
    $final_question_ids = [];
    if ($distribution === 'equal' && ($topics_selected || $subjects_selected)) { // Ensure there's a topic context
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
    } else {
        if (!empty($questions_by_topic)) {
            // Step 1: Pick one question from each topic first
            foreach ($questions_by_topic as $topic_id => $q_ids) {
                if (count($final_question_ids) < $num_questions) {
                    $random_key = array_rand($q_ids);
                    $final_question_ids[] = $q_ids[$random_key];
                } else {
                    break; // Stop if we've already reached the desired number of questions
                }
            }

            // Step 2: Fill the remaining slots randomly from the entire pool
            $remaining_needed = $num_questions - count($final_question_ids);
            if ($remaining_needed > 0) {
                $remaining_pool = array_diff(wp_list_pluck($question_pool, 'question_id'), $final_question_ids);
                shuffle($remaining_pool);
                $final_question_ids = array_merge($final_question_ids, array_slice($remaining_pool, 0, $remaining_needed));
            }
        } else {
            // Fallback for questions with no topics
            shuffle($question_pool);
            $final_question_ids = array_slice(wp_list_pluck($question_pool, 'question_id'), 0, $num_questions);
        }
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

    // --- THIS IS THE FIX ---
    // Use the existing helper function to get ALL descendant topics and sub-topics,
    // not just the direct children. This includes the parent subject ID itself.
    $topic_ids = get_all_descendant_ids($subject_term_id, $wpdb, $term_table);
    // --- END FIX ---

    if (empty($topic_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }
    $topic_ids_placeholder = implode(',', $topic_ids);

    // Step 2: Find all question groups linked to those topics.
    $group_ids = $wpdb->get_col("SELECT object_id FROM $rel_table WHERE term_id IN ($topic_ids_placeholder) AND object_type = 'group'");

    if (empty($group_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }
    $group_ids_placeholder = implode(',', $group_ids);

    // Step 3: Find all source AND section terms linked to the relevant groups.
    $all_linked_source_term_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT r.term_id
         FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d",
        $source_tax_id
    ));

    if (empty($all_linked_source_term_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }

    // Step 4: For each linked term, trace up to find its top-level parent (the source).
    $top_level_source_ids = [];
    foreach ($all_linked_source_term_ids as $term_id) {
        $current_id = $term_id;
        for ($i = 0; $i < 10; $i++) { // Safety break to prevent infinite loops
            $parent_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $current_id));
            if ($parent_id == 0) {
                $top_level_source_ids[] = $current_id;
                break;
            }
            $current_id = $parent_id;
        }
    }

    $unique_source_ids = array_unique($top_level_source_ids);

    if (empty($unique_source_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }

    $source_ids_placeholder = implode(',', $unique_source_ids);

    // Step 5: Fetch the names of the unique, top-level sources.
    $source_terms = $wpdb->get_results(
        "SELECT term_id as source_id, name as source_name 
         FROM {$term_table} 
         WHERE term_id IN ($source_ids_placeholder)
         ORDER BY name ASC"
    );

    // Step 6: Format for the dropdown.
    $sources = [];
    foreach ($source_terms as $term) {
        $sources[] = [
            'source_id' => $term->source_id,
            'source_name' => $term->source_name
        ];
    }

    wp_send_json_success(['sources' => $sources]);
}
add_action('wp_ajax_get_sources_for_subject_progress', 'qp_get_sources_for_subject_progress_ajax');

// Add these two new functions at the end of question-press.php

/**
 * AJAX handler to get sources linked to a specific subject.
 */
function qp_get_sources_for_subject_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

    if (!$subject_id) {
        wp_send_json_error(['message' => 'Invalid subject ID.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    
    // Find source terms (object_id) linked to the given subject term (term_id)
    $source_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM {$rel_table} WHERE term_id = %d AND object_type = 'source_subject_link'",
        $subject_id
    ));

    if (empty($source_ids)) {
        wp_send_json_success(['sources' => []]);
        return;
    }

    $ids_placeholder = implode(',', $source_ids);
    $sources = $wpdb->get_results("SELECT term_id, name FROM {$term_table} WHERE term_id IN ($ids_placeholder) ORDER BY name ASC");

    wp_send_json_success(['sources' => $sources]);
}
add_action('wp_ajax_get_sources_for_subject', 'qp_get_sources_for_subject_ajax');

/**
 * AJAX handler to get child terms (sections) for a given parent term.
 */
function qp_get_child_terms_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $parent_term_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;

    if (!$parent_term_id) {
        wp_send_json_error(['message' => 'Invalid parent ID.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    $child_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id, name FROM {$term_table} WHERE parent = %d ORDER BY name ASC",
        $parent_term_id
    ));

    wp_send_json_success(['children' => $child_terms]);
}
add_action('wp_ajax_get_child_terms', 'qp_get_child_terms_ajax');

/**
 * AJAX handler for the dashboard progress tab.
 * Calculates and returns the hierarchical progress data.
 */
function qp_get_progress_data_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
    $source_term_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
    $user_id = get_current_user_id();

    if (!$source_term_id || !$user_id || !$subject_term_id) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // Step 1: Get all term IDs in both hierarchies
    $all_subject_term_ids = get_all_descendant_ids($subject_term_id, $wpdb, $term_table);
    $all_source_term_ids = get_all_descendant_ids($source_term_id, $wpdb, $term_table);

    $subject_terms_placeholder = implode(',', $all_subject_term_ids);
    $source_terms_placeholder = implode(',', $all_source_term_ids);

    // Step 2: Find intersecting groups
    $relevant_group_ids = $wpdb->get_col("
        SELECT DISTINCT r1.object_id
        FROM {$rel_table} r1
        INNER JOIN {$rel_table} r2 ON r1.object_id = r2.object_id AND r1.object_type = 'group' AND r2.object_type = 'group'
        WHERE r1.term_id IN ($subject_terms_placeholder)
          AND r2.term_id IN ($source_terms_placeholder)
    ");

    if (empty($relevant_group_ids)) {
        wp_send_json_success(['html' => '<p>No questions found for this subject and source combination.</p>']);
        return;
    }
    $group_ids_placeholder = implode(',', $relevant_group_ids);

    // Step 3: Get all questions in scope
    $all_qids_in_scope = $wpdb->get_col("SELECT question_id FROM {$questions_table} WHERE group_id IN ($group_ids_placeholder)");

    if (empty($all_qids_in_scope)) {
        wp_send_json_success(['html' => '<p>No questions found for this source.</p>']);
        return;
    }
    $qids_placeholder = implode(',', $all_qids_in_scope);

    // Step 4: Get user's completed questions
    $exclude_incorrect = isset($_POST['exclude_incorrect']) && $_POST['exclude_incorrect'] === 'true';
    $attempt_status_clause = $exclude_incorrect ? "AND is_correct = 1" : "AND status = 'answered'";
    $completed_qids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND question_id IN ($qids_placeholder) $attempt_status_clause",
        $user_id
    ));
    
    // Step 4b: Get all section practice sessions for this user
    $section_sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, status, settings_snapshot FROM {$sessions_table} WHERE user_id = %d",
        $user_id
    ));
    
    $session_info_by_section = [];
    foreach ($section_sessions as $session) {
        $settings = json_decode($session->settings_snapshot, true);
        if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice' && isset($settings['section_id'])) {
            $section_id = $settings['section_id'];
            if (!isset($session_info_by_section[$section_id])) {
                $session_info_by_section[$section_id] = [
                    'session_id' => $session->session_id,
                    'status' => $session->status
                ];
            }
        }
    }

    // Step 5: Prepare data for the tree
    $all_terms_data = $wpdb->get_results("SELECT term_id, name, parent FROM $term_table WHERE term_id IN ($source_terms_placeholder)");
    $question_group_map = $wpdb->get_results("SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)", OBJECT_K);
    $group_term_map_raw = $wpdb->get_results("SELECT object_id, term_id FROM {$rel_table} WHERE object_id IN ($group_ids_placeholder) AND object_type = 'group' AND term_id IN ($source_terms_placeholder)");
    
    $group_term_map = [];
    foreach ($group_term_map_raw as $row) {
        $group_term_map[$row->object_id][] = $row->term_id;
    }

    $terms_by_id = [];
    foreach ($all_terms_data as $term) {
        $term->children = [];
        $term->total = 0;
        $term->completed = 0;
        $term->is_fully_attempted = false; // Add new property
        $term->session_info = $session_info_by_section[$term->term_id] ?? null;
        $terms_by_id[$term->term_id] = $term;
    }

    // Populate counts and check completion status
    foreach ($all_qids_in_scope as $qid) {
        $is_completed = in_array($qid, $completed_qids);
        $gid = $question_group_map[$qid]->group_id;

        if (isset($group_term_map[$gid])) {
            $term_ids_for_group = $group_term_map[$gid];
            $processed_parents = [];

            foreach ($term_ids_for_group as $term_id) {
                $current_term_id = $term_id;
                while (isset($terms_by_id[$current_term_id]) && !in_array($current_term_id, $processed_parents)) {
                    $terms_by_id[$current_term_id]->total++;
                    if ($is_completed) {
                        $terms_by_id[$current_term_id]->completed++;
                    }
                    $processed_parents[] = $current_term_id;
                    $current_term_id = $terms_by_id[$current_term_id]->parent;
                }
            }
        }
    }

    // Final completion check for each term
    foreach ($terms_by_id as $term) {
        if ($term->total > 0 && $term->completed >= $term->total) {
            $term->is_fully_attempted = true;
        }
    }

    // Assemble the final tree structure
    $source_term_object = null;
    foreach ($terms_by_id as $term) {
        if ($term->term_id == $source_term_id) {
            $source_term_object = $term;
        }
        if (isset($terms_by_id[$term->parent])) {
            $terms_by_id[$term->parent]->children[] = $term;
        }
    }
    
    $options = get_option('qp_settings');
    $review_page_url = isset($options['review_page']) ? get_permalink($options['review_page']) : '';
    $session_page_url = isset($options['session_page']) ? get_permalink($options['session_page']) : '';


    ob_start();
    $subject_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$term_table} WHERE term_id = %d", $subject_term_id));
    $subject_percentage = $source_term_object->total > 0 ? round(($source_term_object->completed / $source_term_object->total) * 100) : 0;
    ?>
    <div class="qp-progress-tree">
        <div class="qp-progress-item subject-level">
            <div class="qp-progress-bar-bg" style="width: <?php echo esc_attr($subject_percentage); ?>%;"></div>
            <div class="qp-progress-label">
                <strong><?php echo esc_html($subject_name); ?></strong>
                <span class="qp-progress-percentage">
                    <?php echo esc_html($subject_percentage); ?>% (<?php echo esc_html($source_term_object->completed); ?>/<?php echo esc_html($source_term_object->total); ?>)
                </span>
            </div>
        </div>
        <div class="qp-source-children-container" style="padding-left: 20px;">
            <?php
            function qp_render_progress_tree_recursive($terms, $review_page_url, $session_page_url, $subject_term_id)
            {
                usort($terms, fn($a, $b) => strcmp($a->name, $b->name));

                foreach ($terms as $term) {
                    $percentage = $term->total > 0 ? round(($term->completed / $term->total) * 100) : 0;
                    $has_children = !empty($term->children);
                    $level_class = $has_children ? 'topic-level qp-topic-toggle' : 'section-level';

                    echo '<div class="qp-progress-item ' . $level_class . '" data-topic-id="' . esc_attr($term->term_id) . '">';
                    echo '<div class="qp-progress-bar-bg" style="width: ' . esc_attr($percentage) . '%;"></div>';
                    echo '<div class="qp-progress-label">';
                    
                    echo '<span class="qp-progress-item-name">';
                    if ($has_children) {
                        echo '<span class="dashicons dashicons-arrow-right-alt2"></span>';
                    }
                    echo esc_html($term->name);
                    echo '</span>';

                    echo '<div class="qp-progress-item-details">';
                    echo '<span class="qp-progress-percentage">' . esc_html($percentage) . '% (' . $term->completed . '/' . $term->total . ')</span>';
                    
                    // *** THIS IS THE FINAL FIX ***
                    if (!$has_children) {
                        $session = $term->session_info;
                        if ($session && $session['status'] === 'paused') {
                            $url = esc_url(add_query_arg('session_id', $session['session_id'], $session_page_url));
                            echo '<a href="' . $url . '" class="qp-button qp-button-primary qp-progress-action-btn">Resume</a>';
                        } elseif ($term->is_fully_attempted && $session) {
                            $url = esc_url(add_query_arg('session_id', $session['session_id'], $review_page_url));
                            echo '<a href="' . $url . '" class="qp-button qp-button-secondary qp-progress-action-btn">Review</a>';
                        } else {
                            echo '<button class="qp-button qp-button-primary qp-progress-start-btn qp-progress-action-btn" data-subject-id="' . esc_attr($subject_term_id) . '" data-section-id="' . esc_attr($term->term_id) . '">Start</button>';
                        }
                    }
                    
                    echo '</div>'; 
                    
                    echo '</div>';
                    echo '</div>'; 

                    if ($has_children) {
                        echo '<div class="qp-topic-sections-container" data-parent-topic="' . esc_attr($term->term_id) . '" style="display: none; padding-left: 20px;">';
                        qp_render_progress_tree_recursive($term->children, $review_page_url, $session_page_url, $subject_term_id);
                        echo '</div>';
                    }
                }
            }
            if ($source_term_object && !empty($source_term_object->children)) {
                qp_render_progress_tree_recursive($source_term_object->children, $review_page_url, $session_page_url, $subject_term_id);
            }
            ?>
        </div>
    </div>
<?php
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
    $o_table = $wpdb->prefix . 'qp_options';
    $a_table = $wpdb->prefix . 'qp_user_attempts';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $user_id = get_current_user_id();

    // 1. Fetch the basic question data first
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT q.question_id, q.question_text, q.question_number_in_section, g.group_id, g.direction_text, g.direction_image_id
         FROM {$q_table} q
         LEFT JOIN {$g_table} g ON q.group_id = g.group_id
         WHERE q.question_id = %d",
        $question_id
    ), ARRAY_A);

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    $group_id = $question_data['group_id'];

    // 2. Get Subject/Topic Hierarchy
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
    $subject_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $group_id, $subject_tax_id));
    $question_data['subject_lineage'] = $subject_term_id ? qp_get_term_lineage_names($subject_term_id, $wpdb, $term_table) : [];

    // 3. Get Source/Section Hierarchy
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
    $source_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $group_id, $source_tax_id));
    $question_data['source_lineage'] = $source_term_id ? qp_get_term_lineage_names($source_term_id, $wpdb, $term_table) : [];

    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $previous_attempt_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$a_table} WHERE user_id = %d AND question_id = %d AND session_id != %d",
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
        // Unset the source lineage if the user doesn't have permission.
        unset($question_data['source_lineage']);
        unset($question_data['question_number_in_section']);
    }

    $question_data['direction_image_url'] = $question_data['direction_image_id'] ? wp_get_attachment_url($question_data['direction_image_id']) : null;
    unset($question_data['direction_image_id']); // Clean up data sent to frontend

    // Fetch the 'is_correct' status along with the options
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
    $attempt = $wpdb->get_row($wpdb->prepare("SELECT attempt_id FROM $a_table WHERE user_id = %d AND question_id = %d AND session_id = %d", $user_id, $question_id, $session_id));
    $attempt_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $a_table WHERE user_id = %d AND question_id = %d AND status = 'answered' AND session_id != %d", $user_id, $question_id, $session_id));
    $review_table = $wpdb->prefix . 'qp_review_later';
    $is_marked = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$review_table} WHERE user_id = %d AND question_id = %d", $user_id, $question_id));

    // --- CORRECTED: Fetch detailed report info ---
    $reports_table = $wpdb->prefix . 'qp_question_reports';
    $terms_table = $wpdb->prefix . 'qp_terms';
    $meta_table = $wpdb->prefix . 'qp_term_meta';

    // Get all reason ID strings for open reports for this question by this user
    $reason_id_strings = $wpdb->get_col($wpdb->prepare(
        "SELECT reason_term_ids FROM {$reports_table} WHERE user_id = %d AND status = 'open' AND question_id = %d",
        $user_id,
        $question_id
    ));

    $report_info = [
        'has_report' => false,
        'has_suggestion' => false,
    ];
    $all_reason_ids = [];

    // Collect all unique reason IDs from all reports for this question
    foreach ($reason_id_strings as $id_string) {
        $ids = array_filter(explode(',', $id_string));
        if (!empty($ids)) {
            $all_reason_ids = array_merge($all_reason_ids, $ids);
        }
    }
    $all_reason_ids = array_unique(array_map('absint', $all_reason_ids));

    // If there are any reason IDs, query their types
    if (!empty($all_reason_ids)) {
        $ids_placeholder = implode(',', $all_reason_ids);
        $reason_types = $wpdb->get_col("
            SELECT m.meta_value
            FROM {$terms_table} t
            JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
            WHERE t.term_id IN ({$ids_placeholder})
        ");

        if (in_array('report', $reason_types)) {
            $report_info['has_report'] = true;
        }
        if (in_array('suggestion', $reason_types)) {
            $report_info['has_suggestion'] = true;
        }
    }
    // --- END CORRECTION ---


    // --- Send Final Response ---
    wp_send_json_success([
        'question'             => $question_data,
        'correct_option_id'    => $correct_option_id,
        'attempt_id'           => $attempt ? (int) $attempt->attempt_id : null,
        'previous_attempt_count' => (int) $attempt_count,
        'is_revision'          => ($attempt_count > 0),
        'is_admin'             => $user_can_view,
        'is_marked_for_review' => $is_marked,
        'reported_info'  => $report_info
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
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $term_table = $wpdb->prefix . 'qp_terms';
    $meta_table = $wpdb->prefix . 'qp_term_meta';

    $reason_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'report_reason'");

    $reasons_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            t.term_id as reason_id, 
            t.name as reason_text,
            MAX(CASE WHEN m.meta_key = 'type' THEN m.meta_value END) as type
         FROM {$term_table} t
         LEFT JOIN {$meta_table} m ON t.term_id = m.term_id
         WHERE t.taxonomy_id = %d AND (
            NOT EXISTS (SELECT 1 FROM {$meta_table} meta_active WHERE meta_active.term_id = t.term_id AND meta_active.meta_key = 'is_active') 
            OR 
            (SELECT meta_active.meta_value FROM {$meta_table} meta_active WHERE meta_active.term_id = t.term_id AND meta_active.meta_key = 'is_active') = '1'
         )
         GROUP BY t.term_id
         ORDER BY t.name ASC",
        $reason_tax_id
    ));

    $reasons_by_type = [
        'report' => [],
        'suggestion' => []
    ];

    // --- THIS IS THE FIX ---
    $other_reasons = [];
    foreach ($reasons_raw as $reason) {
        $type = !empty($reason->type) ? $reason->type : 'report';
        // Separate any reason containing "Other" into a temporary array
        if (strpos($reason->reason_text, 'Other') !== false) {
            $other_reasons[$type][] = $reason;
        } else {
            $reasons_by_type[$type][] = $reason;
        }
    }

    // Append the "Other" reasons to the end of their respective lists
    if (isset($other_reasons['report'])) {
        $reasons_by_type['report'] = array_merge($reasons_by_type['report'], $other_reasons['report']);
    }
    if (isset($other_reasons['suggestion'])) {
        $reasons_by_type['suggestion'] = array_merge($reasons_by_type['suggestion'], $other_reasons['suggestion']);
    }
    // --- END FIX ---

    ob_start();

    if (!empty($reasons_by_type['report'])) {
        echo '<div class="qp-report-type-header">Reports (for errors)</div>';
        foreach ($reasons_by_type['report'] as $reason) {
            echo '<label class="qp-custom-checkbox qp-report-reason-report">
                    <input type="checkbox" name="report_reasons[]" value="' . esc_attr($reason->reason_id) . '">
                    <span></span>
                    ' . esc_html($reason->reason_text) . '
                  </label>';
        }
    }

    if (!empty($reasons_by_type['suggestion'])) {
        if (!empty($reasons_by_type['report'])) {
            echo '<hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid #ddd;">';
        }
        echo '<div class="qp-report-type-header">Suggestions<br><span style="font-size:0.8em;font-weight:400;">You can still attempt question after.</span></div>';
        foreach ($reasons_by_type['suggestion'] as $reason) {
            echo '<label class="qp-custom-checkbox qp-report-reason-suggestion">
                    <input type="checkbox" name="report_reasons[]" value="' . esc_attr($reason->reason_id) . '">
                    <span></span>
                    ' . esc_html($reason->reason_text) . '
                  </label>';
        }
    }

    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
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
    $comment = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : '';
    $user_id = get_current_user_id();

    if (empty($question_id) || empty($reasons)) {
        wp_send_json_error(['message' => 'Invalid data provided.']);
    }

    global $wpdb;
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    // Serialize the array of reason IDs into a comma-separated string
    $reason_ids_string = implode(',', $reasons);

    // Insert a single row with all the data
    $wpdb->insert(
        $reports_table,
        [
            'question_id'     => $question_id,
            'user_id'         => $user_id,
            'reason_term_ids' => $reason_ids_string,
            'comment'         => $comment,
            'report_date'     => current_time('mysql'),
            'status'          => 'open'
        ]
    );


    // Add a log entry for the admin panel
    $wpdb->insert("{$wpdb->prefix}qp_logs", [
        'log_type'    => 'User Report',
        'log_message' => sprintf('User reported question #%s.', $question_id),
        'log_data'    => wp_json_encode(['user_id' => $user_id, 'session_id' => $session_id, 'question_id' => $question_id, 'reasons' => $reasons, 'comment' => $comment])
    ]);

    // --- NEW: Fetch and return the updated report status ---
$terms_table = $wpdb->prefix . 'qp_terms';
$meta_table = $wpdb->prefix . 'qp_term_meta';
$ids_placeholder = implode(',', $reasons);

$reason_types = $wpdb->get_col("
    SELECT m.meta_value 
    FROM {$terms_table} t
    JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'type'
    WHERE t.term_id IN ($ids_placeholder)
");

$report_info = [
    'has_report' => in_array('report', $reason_types),
    'has_suggestion' => in_array('suggestion', $reason_types),
];

wp_send_json_success(['message' => 'Report submitted.', 'reported_info' => $report_info]);
}
add_action('wp_ajax_submit_question_report', 'qp_submit_question_report_ajax');

/**
 * Adds a notification bubble with the count of open reports to the admin menu.
 */
function qp_add_report_count_to_menu()
{
    global $wpdb, $menu, $submenu;

    // Only show the count to users who can manage the plugin
    if (!current_user_can('manage_options')) {
        return;
    }

    $reports_table = $wpdb->prefix . 'qp_question_reports';
    // Get the count of open reports (not just distinct questions)
    $open_reports_count = (int) $wpdb->get_var("SELECT COUNT(report_id) FROM {$reports_table} WHERE status = 'open'");

    if ($open_reports_count > 0) {
        // Create the bubble HTML using standard WordPress classes
        $bubble = " <span class='awaiting-mod'><span class='count-{$open_reports_count}'>{$open_reports_count}</span></span>";

        // Determine if we are on a Question Press admin page.
        $is_qp_page = (isset($_GET['page']) && strpos($_GET['page'], 'qp-') === 0) || (isset($_GET['page']) && $_GET['page'] === 'question-press');

        // Only add the bubble to the top-level menu if we are NOT on a Question Press page.
        if (!$is_qp_page) {
            foreach ($menu as $key => $value) {
                if ($value[2] == 'question-press') {
                    $menu[$key][0] .= $bubble;
                    break;
                }
            }
        }

        // Always add the bubble to the "Reports" submenu item regardless of the current page.
        if (isset($submenu['question-press'])) {
            foreach ($submenu['question-press'] as $key => $value) {
                if ($value[2] == 'qp-logs-reports') {
                    $submenu['question-press'][$key][0] .= $bubble;
                    break;
                }
            }
        }
    }
}
add_action('admin_menu', 'qp_add_report_count_to_menu', 99);

/**
 * AJAX handler for checking an answer in non-mock test modes.
 * Includes access check and attempt decrement logic using entitlements table.
 */
function qp_check_answer_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');

    // --- Access Control Check ---
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to answer questions.', 'code' => 'not_logged_in']);
        return;
    }
    $user_id = get_current_user_id();

    // --- NEW: Entitlement Check & Decrement ---
    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');
    $entitlement_to_decrement = null;

    // Find an active entitlement with attempts remaining (prioritize non-NULL attempts, maybe oldest expiry first?)
    // For simplicity, find the first one with attempts > 0, then check for NULL if none found.
    $active_entitlements = $wpdb->get_results($wpdb->prepare(
        "SELECT entitlement_id, remaining_attempts
         FROM {$entitlements_table}
         WHERE user_id = %d
         AND status = 'active'
         AND (expiry_date IS NULL OR expiry_date > %s)
         ORDER BY remaining_attempts ASC, expiry_date ASC", // Prioritize finite attempts first, then soonest expiry
        $user_id,
        $current_time
    ));

    $has_access = false;
    if (!empty($active_entitlements)) {
        foreach ($active_entitlements as $entitlement) {
            if (!is_null($entitlement->remaining_attempts)) {
                if ((int)$entitlement->remaining_attempts > 0) {
                    $entitlement_to_decrement = $entitlement;
                    $has_access = true;
                    break; // Found one with finite attempts > 0
                }
                // If attempts are 0, continue checking other entitlements
            } else {
                // Found an unlimited attempt entitlement
                $has_access = true;
                // No need to decrement, but break the loop as access is confirmed
                break;
            }
        }
    }

    if (!$has_access) {
        error_log("QP Check Answer: User #{$user_id} denied access. No suitable active entitlement found.");
        wp_send_json_error([
            'message' => 'You have run out of attempts or your subscription has expired.',
            'code' => 'access_denied'
        ]);
        return;
    }

    // Decrement attempts if a specific entitlement was identified
    if ($entitlement_to_decrement) {
        $new_attempts = max(0, (int)$entitlement_to_decrement->remaining_attempts - 1);
        $wpdb->update(
            $entitlements_table,
            ['remaining_attempts' => $new_attempts],
            ['entitlement_id' => $entitlement_to_decrement->entitlement_id]
        );
        error_log("QP Check Answer: User #{$user_id} used attempt from Entitlement #{$entitlement_to_decrement->entitlement_id}. Remaining on this plan: {$new_attempts}");
    } else {
         error_log("QP Check Answer: User #{$user_id} used attempt via an unlimited plan.");
    }
    // --- END NEW Entitlement Check & Decrement ---

    // --- Proceed with checking the answer (Original logic, slightly adjusted) ---
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0;

    if (!$session_id || !$question_id || !$option_id) {
        // This case should ideally not happen if access was granted, but good to keep
        wp_send_json_error(['message' => 'Invalid data submitted after access check.']);
        return;
    }

    $o_table = $wpdb->prefix . 'qp_options';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $revision_table = $wpdb->prefix . 'qp_revision_attempts'; // For revision mode

    // Update session activity
    $wpdb->update($sessions_table, ['last_activity' => $current_time], ['session_id' => $session_id]);

    // Check correctness
    $is_correct = (bool) $wpdb->get_var($wpdb->prepare("SELECT is_correct FROM $o_table WHERE question_id = %d AND option_id = %d", $question_id, $option_id));
    $correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $o_table WHERE question_id = %d AND is_correct = 1", $question_id));

    // Get session settings for revision mode check
    $session_settings_json = $wpdb->get_var($wpdb->prepare("SELECT settings_snapshot FROM $sessions_table WHERE session_id = %d", $session_id));
    $settings = $session_settings_json ? json_decode($session_settings_json, true) : [];

    // Record the attempt
    $wpdb->replace( // Use REPLACE to handle potential re-attempts within the same session if needed
        $attempts_table,
        [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'question_id' => $question_id,
            'selected_option_id' => $option_id,
            'is_correct' => $is_correct ? 1 : 0,
            'status' => 'answered',
            'mock_status' => null, // Not applicable for this mode
            'remaining_time' => isset($_POST['remaining_time']) ? absint($_POST['remaining_time']) : null,
            'attempt_time' => $current_time // Use the time check was performed
        ]
    );
     $attempt_id = $wpdb->insert_id; // Get attempt ID after insert/replace


    // If it's a revision session, also record in the revision table
    if (isset($settings['practice_mode']) && $settings['practice_mode'] === 'revision') {
         // **FIX START**: Get topic ID directly from group relationship
         $q_table = $wpdb->prefix . 'qp_questions';
         $rel_table = $wpdb->prefix . 'qp_term_relationships';
         $term_table = $wpdb->prefix . 'qp_terms';
         $tax_table = $wpdb->prefix . 'qp_taxonomies';
         $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");

         $topic_id = $wpdb->get_var($wpdb->prepare(
             "SELECT r.term_id
              FROM {$q_table} q
              JOIN {$rel_table} r ON q.group_id = r.object_id AND r.object_type = 'group'
              JOIN {$term_table} t ON r.term_id = t.term_id
              WHERE q.question_id = %d AND t.taxonomy_id = %d AND t.parent != 0",
             $question_id,
             $subject_tax_id
         ));
         // **FIX END**

        if ($topic_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$revision_table} (user_id, question_id, topic_id) VALUES (%d, %d, %d) ON DUPLICATE KEY UPDATE attempt_date = NOW()",
                $user_id,
                $question_id,
                $topic_id
            ));
        }
    }

    wp_send_json_success([
        'is_correct' => $is_correct,
        'correct_option_id' => $correct_option_id,
        'attempt_id' => $attempt_id // Return attempt ID
    ]);
}
add_action('wp_ajax_check_answer', 'qp_check_answer_ajax');

/**
 * AJAX handler to save a user's selected answer during a mock test.
 * Includes access check and attempt decrement logic using entitlements table.
 */
function qp_save_mock_attempt_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce');

    // --- Access Control Check ---
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in to answer questions.', 'code' => 'not_logged_in']);
        return;
    }
    $user_id = get_current_user_id();

    // --- NEW: Entitlement Check & Decrement ---
    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');
    $entitlement_to_decrement = null;

    // Find an active entitlement with attempts remaining
    $active_entitlements = $wpdb->get_results($wpdb->prepare(
        "SELECT entitlement_id, remaining_attempts
         FROM {$entitlements_table}
         WHERE user_id = %d
         AND status = 'active'
         AND (expiry_date IS NULL OR expiry_date > %s)
         ORDER BY remaining_attempts ASC, expiry_date ASC",
        $user_id,
        $current_time
    ));

    $has_access = false;
    if (!empty($active_entitlements)) {
        foreach ($active_entitlements as $entitlement) {
            if (!is_null($entitlement->remaining_attempts)) {
                if ((int)$entitlement->remaining_attempts > 0) {
                    $entitlement_to_decrement = $entitlement;
                    $has_access = true;
                    break;
                }
            } else {
                $has_access = true;
                break;
            }
        }
    }

    if (!$has_access) {
        error_log("QP Mock Save: User #{$user_id} denied access. No suitable active entitlement found.");
        wp_send_json_error([
            'message' => 'You have run out of attempts or your subscription has expired.',
            'code' => 'access_denied'
        ]);
        return;
    }

    // Decrement attempts if needed
    if ($entitlement_to_decrement) {
        $new_attempts = max(0, (int)$entitlement_to_decrement->remaining_attempts - 1);
        $wpdb->update(
            $entitlements_table,
            ['remaining_attempts' => $new_attempts],
            ['entitlement_id' => $entitlement_to_decrement->entitlement_id]
        );
        error_log("QP Mock Save: User #{$user_id} used attempt from Entitlement #{$entitlement_to_decrement->entitlement_id}. Remaining on this plan: {$new_attempts}");
    } else {
         error_log("QP Mock Save: User #{$user_id} used attempt via an unlimited plan.");
    }
    // --- END NEW Entitlement Check & Decrement ---

    // --- Proceed with saving the mock attempt (Original logic, slightly adjusted) ---
    $session_id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;
    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    $option_id = isset($_POST['option_id']) ? absint($_POST['option_id']) : 0; // Can be 0 if clearing response

    if (!$session_id || !$question_id) { // Option ID can be 0 when clearing
        wp_send_json_error(['message' => 'Invalid data submitted after access check.']);
        return;
    }

    $attempts_table = $wpdb->prefix . 'qp_user_attempts';

    // Check if an attempt record already exists for this question in this session
    $existing_attempt = $wpdb->get_row($wpdb->prepare(
        "SELECT attempt_id, mock_status FROM {$attempts_table} WHERE session_id = %d AND question_id = %d",
        $session_id,
        $question_id
    ));

    // Determine the correct mock_status based on whether an option is selected and previous status
    $current_mock_status = $existing_attempt ? $existing_attempt->mock_status : 'viewed'; // Default to viewed if no record
    $new_mock_status = $current_mock_status; // Keep current status unless changed below

    if ($option_id > 0) { // An answer is being saved
        if ($current_mock_status == 'marked_for_review' || $current_mock_status == 'answered_and_marked_for_review') {
            $new_mock_status = 'answered_and_marked_for_review';
        } else {
            $new_mock_status = 'answered';
        }
    }
    // Note: Clearing the response (option_id=0) is handled by qp_update_mock_status_ajax, not this function directly.
    // This function assumes an answer is being *selected*.

    $attempt_data = [
        'session_id' => $session_id,
        'user_id' => $user_id,
        'question_id' => $question_id,
        'selected_option_id' => $option_id > 0 ? $option_id : null, // Store NULL if clearing
        'is_correct' => null, // Graded only at the end
        'status' => $option_id > 0 ? 'answered' : 'viewed', // Main status: 'answered' if option selected, 'viewed' if cleared
        'mock_status' => $new_mock_status,
        'attempt_time' => $current_time
    ];

    if ($existing_attempt) {
        // Update existing attempt
        $wpdb->update($attempts_table, $attempt_data, ['attempt_id' => $existing_attempt->attempt_id]);
    } else {
        // Insert new attempt
        $wpdb->insert($attempts_table, $attempt_data);
    }

    // Update session activity time
    $wpdb->update($wpdb->prefix . 'qp_user_sessions', ['last_activity' => $current_time], ['session_id' => $session_id]);

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
            JOIN {$rel_table} r ON g.group_id = r.object_id
            WHERE r.term_id = %d AND r.object_type = 'group' AND q.status = 'publish'
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
            // Correct the ORDER BY clause to prioritize the section (source term) first.
            $order_by_sql = $choose_random ? "ORDER BY RAND()" : "ORDER BY r_source.term_id, CAST(q.question_number_in_section AS UNSIGNED) ASC, q.question_id ASC";

            $q_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT q.question_id 
                 FROM {$questions_table} q
                 LEFT JOIN {$rel_table} r_source ON q.group_id = r_source.object_id AND r_source.object_type = 'group'
                 WHERE q.question_id IN ($ids_placeholder) 
                 {$order_by_sql} 
                 LIMIT %d",
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

    // --- NEW: Update Course Item Progress if applicable ---
    if (($new_status === 'completed' || $new_status === 'abandoned') && // Only update progress if session truly ended
        isset($settings['course_id']) && isset($settings['item_id'])) {

        $course_id = absint($settings['course_id']);
        $item_id = absint($settings['item_id']);
        $user_id = $session->user_id; // Get user ID from the session object
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $items_table = $wpdb->prefix . 'qp_course_items'; // <<< Keep this variable definition

        // *** START NEW CHECK ***
        // Check if the course item still exists before trying to update progress
        $item_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE item_id = %d AND course_id = %d",
            $item_id,
            $course_id
        ));

        if ($item_exists) {
            // *** Item exists, proceed with updating progress ***

            // Prepare result data (customize as needed)
            $result_data = json_encode([
                'score' => $final_score,
                'correct' => $correct_count,
                'incorrect' => $incorrect_count,
                'skipped' => $skipped_count,
                'not_viewed' => $not_viewed_count, // Include if relevant (from mock tests)
                'total_attempted' => $total_attempted,
                'session_id' => $session_id // Store the session ID for potential review linking
            ]);

            // Use REPLACE INTO for simplicity
            $wpdb->query($wpdb->prepare(
                "REPLACE INTO {$progress_table} (user_id, item_id, course_id, status, completion_date, result_data, last_viewed)
                 VALUES (%d, %d, %d, %s, %s, %s, %s)",
                $user_id,
                $item_id,
                $course_id,
                'completed', // Mark item as completed when session ends
                current_time('mysql'), // Completion date
                $result_data,
                current_time('mysql') // Update last viewed as well
            ));

            // Note: Calculation and update of overall course progress should happen ONLY if the item exists
            // --- Calculate and Update Overall Course Progress ---
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';

            // Get total number of items in the course
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
                $course_id
            ));

            // Get number of completed items for the user in this course
            $completed_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
                $user_id,
                $course_id
            ));

            // Calculate percentage
            $progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;

            // Check if course is now fully complete
            $new_course_status = 'in_progress'; // Default
            if ($total_items > 0 && $completed_items >= $total_items) {
                $new_course_status = 'completed';
            }

            // Get the current completion date (if any) to avoid overwriting it
            $current_completion_date = $wpdb->get_var($wpdb->prepare(
                "SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
                $user_id, $course_id
            ));
            $completion_date_to_set = $current_completion_date;

            if ($new_course_status === 'completed' && is_null($current_completion_date)) {
                $completion_date_to_set = current_time('mysql');
            } elseif ($new_course_status !== 'completed') {
                 $completion_date_to_set = null;
            }

            // Update the user's overall course record
            $wpdb->update(
                $user_courses_table,
                [
                    'progress_percent' => $progress_percent,
                    'status'           => $new_course_status,
                    'completion_date'  => $completion_date_to_set
                ],
                [ 'user_id'   => $user_id, 'course_id' => $course_id ],
                ['%d', '%s', '%s'],
                ['%d', '%d']
            );
            // --- End Overall Course Progress Update ---

        } else {
            // *** Item does NOT exist, skip progress update ***
            // Optional: Log this occurrence for debugging
            error_log("QP Session Finalize: Skipped progress update for user {$user_id}, course {$course_id}, because item {$item_id} no longer exists.");
        }
        // *** END NEW CHECK ***

    } // This closing brace corresponds to the "if (isset($settings['course_id']) ...)" check
    // --- END Course Item Progress Update ---

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

/**
 * AJAX Handler to delete a session from user history
 */

function qp_delete_user_session_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
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

    $wpdb->delete($attempts_table, ['user_id' => $user_id], ['%d']);
    $wpdb->delete($sessions_table, ['user_id' => $user_id], ['%d']);

    wp_send_json_success(['message' => 'Your practice and revision history has been successfully deleted.']);
}
add_action('wp_ajax_delete_revision_history', 'qp_delete_revision_history_ajax');

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
            qp_finalize_and_end_session($test->session_id, 'abandoned', 'abandoned_by_system');
        }
    }

    // --- 2. Handle Abandoned 'active' sessions ---
    $abandoned_sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, settings_snapshot FROM {$sessions_table}
         WHERE status = 'active' AND last_activity < NOW() - INTERVAL %d MINUTE",
        $timeout_minutes
    ));

    if (!empty($abandoned_sessions)) {
        foreach ($abandoned_sessions as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            $is_section_practice = isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice';

            if ($is_section_practice) {
                // For section practice, just pause the session instead of abandoning it.
                $wpdb->update(
                    $sessions_table,
                    ['status' => 'paused'],
                    ['session_id' => $session->session_id]
                );
            } else {
                // For all other modes, use the standard abandon/delete logic.
                qp_finalize_and_end_session($session->session_id, 'abandoned', 'abandoned_by_system');
            }
        }
    }
}
add_action('qp_cleanup_abandoned_sessions_event', 'qp_cleanup_abandoned_sessions');

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
    // Security check
    if (
        !(check_ajax_referer('qp_practice_nonce', 'nonce', false) ||
            check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce', false))
    ) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
    if (!$question_id) {
        wp_send_json_error(['message' => 'Invalid question ID.']);
    }

    global $wpdb;

    // **FIX START**: This new query correctly finds the group's topic and then traces back to the top-level subject.
    $question_data = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            q.question_id, 
            q.question_text, 
            g.direction_text, 
            g.direction_image_id, 
            parent_term.name AS subject_name
         FROM {$wpdb->prefix}qp_questions q
         LEFT JOIN {$wpdb->prefix}qp_question_groups g ON q.group_id = g.group_id
         LEFT JOIN {$wpdb->prefix}qp_term_relationships r ON g.group_id = r.object_id AND r.object_type = 'group'
         LEFT JOIN {$wpdb->prefix}qp_terms child_term ON r.term_id = child_term.term_id
         LEFT JOIN {$wpdb->prefix}qp_terms parent_term ON child_term.parent = parent_term.term_id
         WHERE q.question_id = %d 
           AND parent_term.taxonomy_id = (SELECT taxonomy_id FROM {$wpdb->prefix}qp_taxonomies WHERE taxonomy_name = 'subject')",
        $question_id
    ), ARRAY_A);
    // **FIX END**

    if (!$question_data) {
        wp_send_json_error(['message' => 'Question not found.']);
    }

    // Get the image URL if an ID exists
    if (!empty($question_data['direction_image_id'])) {
        $question_data['direction_image_url'] = wp_get_attachment_url($question_data['direction_image_id']);
    } else {
        $question_data['direction_image_url'] = null;
    }

    // Apply nl2br to convert newlines to <br> tags for HTML display.
    if (!empty($question_data['direction_text'])) {
        $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text']));
    }
    if (!empty($question_data['question_text'])) {
        $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text']));
    }

    // Fetch options
    $options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_id, option_text, is_correct FROM {$wpdb->prefix}qp_options WHERE question_id = %d ORDER BY option_id ASC",
        $question_id
    ), ARRAY_A);

    foreach ($options as &$option) {
        if (!empty($option['option_text'])) {
            $option['option_text'] = wp_kses_post(nl2br($option['option_text']));
        }
    }
    unset($option);

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
    $term_table = $wpdb->prefix . 'qp_terms';

    // Add/Update Reason
    if (isset($_POST['action']) && ($_POST['action'] === 'add_reason' || $_POST['action'] === 'update_reason') && check_admin_referer('qp_add_edit_reason_nonce')) {
        $reason_text = sanitize_text_field($_POST['reason_text']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $reason_type = isset($_POST['reason_type']) ? sanitize_key($_POST['reason_type']) : 'report';
        $taxonomy_id = absint($_POST['taxonomy_id']);

        $term_data = [
            'name' => $reason_text,
            'slug' => sanitize_title($reason_text),
            'taxonomy_id' => $taxonomy_id,
        ];

        if ($_POST['action'] === 'update_reason') {
            $term_id = absint($_POST['term_id']);
            $wpdb->update($term_table, $term_data, ['term_id' => $term_id]);
        } else {
            $wpdb->insert($term_table, $term_data);
            $term_id = $wpdb->insert_id;
        }

        if ($term_id) {
            qp_update_term_meta($term_id, 'is_active', $is_active);
            qp_update_term_meta($term_id, 'type', $reason_type);
        }

        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=1'));
        exit;
    }

    // Delete Reason
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['reason_id']) && check_admin_referer('qp_delete_reason_' . absint($_GET['reason_id']))) {
        $term_id_to_delete = absint($_GET['reason_id']);
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Check if the reason is in use by any reports
        $usage_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$reports_table} WHERE reason_term_id = %d",
            $term_id_to_delete
        ));

        if ($usage_count > 0) {
            // If it's in use, set an error message and redirect
            $message = sprintf('This reason cannot be deleted because it is currently used in %d report(s).', $usage_count);
            QP_Sources_Page::set_message($message, 'error');
        } else {
            // If not in use, proceed with deletion
            $wpdb->delete($wpdb->prefix . 'qp_term_meta', ['term_id' => $term_id_to_delete]);
            $wpdb->delete($term_table, ['term_id' => $term_id_to_delete]);
            QP_Sources_Page::set_message('Reason deleted successfully.', 'updated');
        }

        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings'));
        exit;
    }
}
add_action('admin_init', 'qp_handle_log_settings_forms');




// New Development - Subscriptions

/**
 * Grant Question Press entitlement when a specific WooCommerce order is completed.
 * Reads linked plan data and creates a record in wp_qp_user_entitlements.
 *
 * @param int $order_id The ID of the completed order.
 */
function qp_grant_access_on_order_complete($order_id) {
    error_log("QP Access Hook: Processing Order #{$order_id}"); // Log start
    $order = wc_get_order($order_id);

    // Check if the order is valid and paid (or processing if allowing access before full payment)
    if (!$order || !$order->is_paid()) { // Stricter check: use is_paid() for completed orders
        error_log("QP Access Hook: Order #{$order_id} not valid or not paid.");
        return;
    }

    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log("QP Access Hook: No user ID associated with Order #{$order_id}. Cannot grant entitlement.");
        return; // Cannot grant entitlement to guest users
    }

    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $granted_entitlement = false;

    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $target_id = $variation_id > 0 ? $variation_id : $product_id; // Use variation ID if available

        // Get the linked plan ID from product/variation meta
        $linked_plan_id = get_post_meta($target_id, '_qp_linked_plan_id', true);

        if (!empty($linked_plan_id)) {
            $plan_id = absint($linked_plan_id);
            $plan_post = get_post($plan_id);

            // Ensure the linked plan exists and is published
            if ($plan_post && $plan_post->post_type === 'qp_plan' && $plan_post->post_status === 'publish') {
                error_log("QP Access Hook: Found linked Plan ID #{$plan_id} for item in Order #{$order_id}");

                // Get plan details from post meta
                $plan_type = get_post_meta($plan_id, '_qp_plan_type', true);
                $duration_value = get_post_meta($plan_id, '_qp_plan_duration_value', true);
                $duration_unit = get_post_meta($plan_id, '_qp_plan_duration_unit', true);
                $attempts = get_post_meta($plan_id, '_qp_plan_attempts', true);

                $start_date = current_time('mysql');
                $expiry_date = null;
                $remaining_attempts = null;

                // Calculate expiry date if applicable
                if (($plan_type === 'time_limited' || $plan_type === 'combined') && !empty($duration_value) && !empty($duration_unit)) {
                    try {
                         // Use WordPress timezone for calculation start point
                         $start_datetime = new DateTime($start_date, wp_timezone());
                         $start_datetime->modify('+' . absint($duration_value) . ' ' . sanitize_key($duration_unit));
                         $expiry_date = $start_datetime->format('Y-m-d H:i:s');
                         error_log("QP Access Hook: Calculated expiry date for Plan ID #{$plan_id}: {$expiry_date}");
                    } catch (Exception $e) {
                         error_log("QP Access Hook: Error calculating expiry date for Plan ID #{$plan_id} - " . $e->getMessage());
                         $expiry_date = null; // Fallback if calculation fails
                    }
                } elseif ($plan_type === 'unlimited') {
                     $expiry_date = null; // Explicitly null for unlimited time
                     $remaining_attempts = null; // Explicitly null for unlimited attempts
                     error_log("QP Access Hook: Plan ID #{$plan_id} is Unlimited type.");
                }


                // Set remaining attempts if applicable
                if (($plan_type === 'attempt_limited' || $plan_type === 'combined') && !empty($attempts)) {
                    $remaining_attempts = absint($attempts);
                    error_log("QP Access Hook: Setting attempts for Plan ID #{$plan_id}: {$remaining_attempts}");
                } elseif ($plan_type === 'unlimited') {
                    $remaining_attempts = null; // Explicitly null for unlimited attempts
                }

                // Insert the new entitlement record
                $inserted = $wpdb->insert(
                    $entitlements_table,
                    [
                        'user_id' => $user_id,
                        'plan_id' => $plan_id,
                        'order_id' => $order_id,
                        'start_date' => $start_date,
                        'expiry_date' => $expiry_date, // NULL if not time-based or unlimited
                        'remaining_attempts' => $remaining_attempts, // NULL if not attempt-based or unlimited
                        'status' => 'active',
                    ],
                    [ // Data formats
                        '%d', // user_id
                        '%d', // plan_id
                        '%d', // order_id
                        '%s', // start_date
                        '%s', // expiry_date (can be NULL)
                        '%d', // remaining_attempts (can be NULL)
                        '%s', // status
                    ]
                );

                if ($inserted) {
                    error_log("QP Access Hook: Successfully inserted entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}");
                    $granted_entitlement = true;
                     // Optional: Add an order note
                     $order->add_order_note(sprintf('Granted Question Press access via Plan ID %d.', $plan_id));
                    // Consider breaking if you only want to grant one plan per order,
                    // or allow multiple plans if purchased together. Let's allow multiple for now.
                    // break;
                } else {
                     error_log("QP Access Hook: FAILED to insert entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}. DB Error: " . $wpdb->last_error);
                     $order->add_order_note(sprintf('ERROR: Failed to grant Question Press access for Plan ID %d. DB Error: %s', $plan_id, $wpdb->last_error), true); // Add as private note
                }
            } else {
                 error_log("QP Access Hook: Linked Plan ID #{$linked_plan_id} not found or not published for item in Order #{$order_id}");
            }
        } else {
             // error_log("QP Access Hook: No QP Plan linked for product/variation ID #{$target_id} in Order #{$order_id}"); // This might be too verbose if many unrelated products are ordered.
        }
    } // end foreach item

    if (!$granted_entitlement) {
        error_log("QP Access Hook: No Question Press entitlements were granted for Order #{$order_id}.");
    }
}
// Ensure the hook is still present (it was, but good to double-check)
add_action('woocommerce_order_status_completed', 'qp_grant_access_on_order_complete', 10, 1);

/**
 * AJAX handler to check remaining attempts/access for the current user based on entitlements table.
 * Provides a reason code for access denial.
 */
function qp_check_remaining_attempts_ajax() {
    // No nonce check needed for reads, but login is essential.
    if (!is_user_logged_in()) {
        wp_send_json_error(['has_access' => false, 'message' => 'Not logged in.', 'reason_code' => 'not_logged_in']);
        return;
    }

    $user_id = get_current_user_id();
    $has_access = false;
    $total_remaining = 0;
    $has_unlimited_attempts = false;
    $denial_reason_code = 'no_entitlements'; // Default reason if nothing is found

    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');

    // Query for ALL entitlement records for this user to determine the reason later
    $all_user_entitlements = $wpdb->get_results($wpdb->prepare(
        "SELECT entitlement_id, remaining_attempts, expiry_date, status
         FROM {$entitlements_table}
         WHERE user_id = %d",
        $user_id
    ));

    if (!empty($all_user_entitlements)) {
        $denial_reason_code = 'expired_or_inactive'; // Assume expired/inactive if records exist but don't grant access
        $found_active_non_expired = false;

        foreach ($all_user_entitlements as $entitlement) {
            // Check if the entitlement is currently valid (active status and not expired)
            $is_active = $entitlement->status === 'active';
            $is_expired = !is_null($entitlement->expiry_date) && $entitlement->expiry_date <= $current_time;

            if ($is_active && !$is_expired) {
                $found_active_non_expired = true; // Found at least one potentially valid plan
                $denial_reason_code = 'out_of_attempts'; // Assume out of attempts if active plans exist

                if (is_null($entitlement->remaining_attempts)) {
                    // Found an active plan with UNLIMITED attempts
                    $has_unlimited_attempts = true;
                    $has_access = true;
                    $total_remaining = -1;
                    break; // Access granted, stop checking
                } else {
                    // Add this plan's remaining attempts to the total
                    $total_remaining += (int) $entitlement->remaining_attempts;
                }
            }
        } // End foreach

        // If no unlimited plan was found among active/non-expired ones, check the total attempts
        if (!$has_unlimited_attempts && $found_active_non_expired && $total_remaining > 0) {
            $has_access = true;
        }
        // If $found_active_non_expired is true but $total_remaining is 0, $denial_reason_code remains 'out_of_attempts'
        // If $found_active_non_expired is false, $denial_reason_code remains 'expired_or_inactive'

    } else {
        // No entitlement records found at all for the user
        $denial_reason_code = 'no_entitlements';
    }


    if ($has_access) {
        wp_send_json_success(['has_access' => true, 'remaining' => $has_unlimited_attempts ? -1 : $total_remaining]);
    } else {
        // Send the specific reason code along with has_access = false
        wp_send_json_success(['has_access' => false, 'remaining' => 0, 'reason_code' => $denial_reason_code]);
    }
}
// Hook the AJAX action (this line should already exist and remain unchanged)
add_action('wp_ajax_qp_check_remaining_attempts', 'qp_check_remaining_attempts_ajax');


// Courses Section on Dashboard
/**
 * AJAX handler to fetch the structure (sections and items) for a specific course.
 * Also fetches the user's progress for items within that course.
 */
function qp_get_course_structure_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce'); // Re-use the existing frontend nonce

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
    $user_id = get_current_user_id();

    if (!$course_id) {
        wp_send_json_error(['message' => 'Invalid course ID.']);
    }

    // --- NEW: Check if user has access to this course before proceeding ---
    if (!qp_user_can_access_course($user_id, $course_id)) {
        wp_send_json_error(['message' => 'You do not have access to view this course structure.', 'code' => 'access_denied']);
        return; // Stop execution
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $course_title = get_the_title($course_id); // Get course title from wp_posts

    $structure = [
        'course_id' => $course_id,
        'course_title' => $course_title,
        'sections' => []
    ];

    // Get sections for the course
    $sections = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
        $course_id
    ));

    if (empty($sections)) {
        wp_send_json_success($structure); // Send structure with empty sections array
        return;
    }

    $section_ids = wp_list_pluck($sections, 'section_id');
    $ids_placeholder = implode(',', array_map('absint', $section_ids));

    // Get all items for these sections, including progress status and result data
    $items_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT i.item_id, i.section_id, i.title, i.item_order, i.content_type, p.status, p.result_data -- <<< ADD p.result_data
         FROM $items_table i
         LEFT JOIN {$wpdb->prefix}qp_user_items_progress p ON i.item_id = p.item_id AND p.user_id = %d AND p.course_id = %d
         WHERE i.section_id IN ($ids_placeholder)
         ORDER BY i.item_order ASC",
        $user_id,
        $course_id
    ));

    // Organize items by section
    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->status = $item->status ?? 'not_started'; // Use fetched status or default

        // --- ADD THIS BLOCK ---
        $item->session_id = null; // Default to null
        if (!empty($item->result_data)) {
            $result_data_decoded = json_decode($item->result_data, true);
            if (isset($result_data_decoded['session_id'])) {
                $item->session_id = absint($result_data_decoded['session_id']);
            }
        }
        unset($item->result_data); // Don't need to send the full result data to JS for this
        // --- END ADDED BLOCK ---

        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    // Get user's progress for these items in this course
    $progress_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT item_id, status FROM $progress_table WHERE user_id = %d AND course_id = %d",
        $user_id,
        $course_id
    ), OBJECT_K); // Keyed by item_id for easy lookup

    // Organize items by section
    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->status = $progress_raw[$item->item_id]->status ?? 'not_started'; // Add status
        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    // Build the final structure
    foreach ($sections as $section) {
        $structure['sections'][] = [
            'id' => $section->section_id,
            'title' => $section->title,
            'description' => $section->description,
            'order' => $section->section_order,
            'items' => $items_by_section[$section->section_id] ?? []
        ];
    }

    wp_send_json_success($structure);
}
add_action('wp_ajax_get_course_structure', 'qp_get_course_structure_ajax');

/**
 * AJAX handler to start a Test Series session launched from a course item.
 * Includes access check using entitlements table. Decrement happens on first answer.
 */
function qp_start_course_test_series_ajax() {
    check_ajax_referer('qp_start_course_test_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }
    $user_id = get_current_user_id();

    // --- NEW: Entitlement Check ONLY ---
    // (Decrement happens when the first answer is submitted in check_answer or save_mock_attempt)
    global $wpdb;

    $items_table = $wpdb->prefix . 'qp_course_items';

    // --- Get Course ID associated with the item ---
    $course_id = $wpdb->get_var($wpdb->prepare("SELECT course_id FROM $items_table WHERE item_id = %d", $item_id));
    if (!$course_id) {
        wp_send_json_error(['message' => 'Could not determine the course for this item.']);
        return;
    }
    // --- END Get Course ID ---


    // --- NEW: Check Course Access FIRST ---
    if (!qp_user_can_access_course($user_id, $course_id)) {
        wp_send_json_error(['message' => 'You do not have access to start tests in this course.', 'code' => 'access_denied']);
        return; // Stop execution
    }
    // --- Proceed with Attempt Check (Existing Logic from previous step) ---
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');
    $has_access_for_attempt = false; // Renamed variable for clarity

    $active_entitlements_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(entitlement_id)
         FROM {$entitlements_table}
         WHERE user_id = %d
         AND status = 'active'
         AND (expiry_date IS NULL OR expiry_date > %s)
         AND (remaining_attempts IS NULL OR remaining_attempts > 0)",
        $user_id,
        $current_time
    ));

    if ($active_entitlements_count > 0) {
        $has_access_for_attempt = true;
    }

    if (!$has_access_for_attempt) {
        error_log("QP Course Test Start: User #{$user_id} denied access. No suitable active entitlement found for attempt.");
        wp_send_json_error([
            'message' => 'You have run out of attempts or your subscription has expired.',
            'code' => 'access_denied' // Keep same code, JS handles message
        ]);
        return;
    }
    // --- END NEW Entitlement Check ---

    // --- Proceed with starting the session (Original logic) ---
    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    if (!$item_id) {
        wp_send_json_error(['message' => 'Invalid course item ID.']);
    }

    $items_table = $wpdb->prefix . 'qp_course_items';

    // Get the item details and configuration
    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT course_id, content_config FROM $items_table WHERE item_id = %d AND content_type = 'test_series'",
        $item_id
    ));

    if (!$item || empty($item->content_config)) {
        wp_send_json_error(['message' => 'Could not find test configuration for this item.']);
    }

    $config = json_decode($item->content_config, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($config)) {
         error_log("QP Course Test Start: Invalid JSON in content_config for item ID: " . $item_id . ". Error: " . json_last_error_msg());
        wp_send_json_error(['message' => 'Invalid test configuration data stored. Please contact an administrator.']);
        return;
    }

    // --- Determine Question IDs ---
    $final_question_ids = [];
    $q_table = $wpdb->prefix . 'qp_questions';
    $reports_table = $wpdb->prefix . 'qp_question_reports';

    if (isset($config['selected_questions']) && is_array($config['selected_questions']) && !empty($config['selected_questions'])) {
        $potential_ids = array_map('absint', $config['selected_questions']);
        if (!empty($potential_ids)) {
            $ids_placeholder = implode(',', $potential_ids);
            $reported_question_ids = $wpdb->get_col("SELECT DISTINCT question_id FROM {$reports_table} WHERE status = 'open'");
            $exclude_reported_sql = !empty($reported_question_ids) ? ' AND question_id NOT IN (' . implode(',', $reported_question_ids) . ')' : '';
            $verified_ids = $wpdb->get_col("SELECT question_id FROM {$q_table} WHERE question_id IN ($ids_placeholder) AND status = 'publish' {$exclude_reported_sql}");
            $final_question_ids = array_intersect($potential_ids, $verified_ids);
        }
    } else {
        wp_send_json_error(['message' => 'No questions have been manually selected for this test item. Please edit the course.']);
        return;
    }

    if (empty($final_question_ids)) {
         wp_send_json_error(['message' => 'None of the selected questions are currently available.']);
         return;
    }

    shuffle($final_question_ids);

    // --- Prepare Session Settings ---
    $options = get_option('qp_settings');
    $session_page_id = isset($options['session_page']) ? absint($options['session_page']) : 0;
    if (!$session_page_id) {
        wp_send_json_error(['message' => 'The administrator has not configured a session page.']);
    }

    $session_settings = [
        'practice_mode'       => 'mock_test',
        'course_id'           => $item->course_id,
        'item_id'             => $item_id,
        'num_questions'       => count($final_question_ids),
        'marks_correct'       => $config['scoring_enabled'] ? ($config['marks_correct'] ?? 1) : null,
        'marks_incorrect'     => $config['scoring_enabled'] ? -abs($config['marks_incorrect'] ?? 0) : null,
        'timer_enabled'       => ($config['time_limit'] > 0),
        'timer_seconds'       => ($config['time_limit'] ?? 0) * 60,
        'original_selection'  => $config['selected_questions'] ?? [],
    ];

    $wpdb->insert($wpdb->prefix . 'qp_user_sessions', [
        'user_id'                 => $user_id,
        'status'                  => 'mock_test',
        'start_time'              => $current_time, // Use current time
        'last_activity'           => $current_time,
        'settings_snapshot'       => wp_json_encode($session_settings),
        'question_ids_snapshot'   => wp_json_encode(array_values($final_question_ids))
    ]);
    $session_id = $wpdb->insert_id;

    if (!$session_id) {
         wp_send_json_error(['message' => 'Failed to create the session record.']);
    }

    $redirect_url = add_query_arg('session_id', $session_id, get_permalink($session_page_id));
    wp_send_json_success(['redirect_url' => $redirect_url, 'session_id' => $session_id]);
}
add_action('wp_ajax_start_course_test_series', 'qp_start_course_test_series_ajax');

/**
 * AJAX handler for enrolling a user in a course.
 * Includes check for course access entitlement if required.
 */
function qp_enroll_in_course_ajax() {
    check_ajax_referer('qp_enroll_course_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
    $user_id = get_current_user_id();

    if (!$course_id || get_post_type($course_id) !== 'qp_course') {
        wp_send_json_error(['message' => 'Invalid course ID.']);
    }

    // --- NEW: Check if course requires purchase AND if user has access ---
    $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free';

    if ($access_mode === 'requires_purchase') {
        // If it requires purchase, verify the user has a valid entitlement
        if (!qp_user_can_access_course($user_id, $course_id)) {
            wp_send_json_error(['message' => 'You do not have access to enroll in this course. Please purchase it first.', 'code' => 'access_denied']);
            return; // Stop execution
        }
        // If access check passes for a paid course, proceed to enrollment
    }
    // If access_mode is 'free', proceed to enrollment without entitlement check
    // --- END NEW CHECK ---

    global $wpdb;
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';

    // Check if already enrolled (keep this check)
    $is_enrolled = $wpdb->get_var($wpdb->prepare(
        "SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d",
        $user_id,
        $course_id
    ));

    if ($is_enrolled) {
        wp_send_json_success(['message' => 'Already enrolled.', 'already_enrolled' => true]);
        return;
    }

    // Enroll the user (keep this logic)
    $result = $wpdb->insert($user_courses_table, [
        'user_id' => $user_id,
        'course_id' => $course_id,
        'enrollment_date' => current_time('mysql'),
        'status' => 'enrolled', // Initial status, could change later if progress starts
        'progress_percent' => 0
    ]);

    if ($result) {
        wp_send_json_success(['message' => 'Successfully enrolled!']);
    } else {
        wp_send_json_error(['message' => 'Could not enroll in the course. Please try again.']);
    }
}
add_action('wp_ajax_enroll_in_course', 'qp_enroll_in_course_ajax');
add_action('wp_ajax_get_course_list_html', ['QP_Dashboard', 'get_course_list_ajax']);

/**
 * AJAX handler to search for questions for the course editor modal.
 */
function qp_search_questions_for_course_ajax() {
    // 1. Security Checks
    // Use a dedicated nonce for this action for better security
    check_ajax_referer('qp_course_editor_select_nonce', 'nonce');
    if (!current_user_can('manage_options')) { // Or a more specific capability if needed
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    // 2. Get and Sanitize Input Parameters
    $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
    $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;
    $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0; // Assuming source filter sends term_id
    // Add pagination parameters later if needed (e.g., $_POST['page'])

    // 3. Database Setup
    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';

    // 4. Build Query Parts
    $select = "SELECT DISTINCT q.question_id, q.question_text"; // Select distinct to avoid duplicates if multiple terms match
    $from = "FROM {$q_table} q";
    $joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id"; // Join groups needed for term relationships
    $where = ["q.status = 'publish'"]; // Only search published questions
    $params = [];
    $joins_added = []; // Helper

    // Add search term condition (ID or text)
    if (!empty($search_term)) {
        if (is_numeric($search_term)) {
            $where[] = $wpdb->prepare("q.question_id = %d", absint($search_term));
        } else {
            $like_term = '%' . $wpdb->esc_like($search_term) . '%';
            $where[] = $wpdb->prepare("q.question_text LIKE %s", $like_term);
        }
    }

    // Add Subject/Topic filtering (similar to list table)
    if ($topic_id) {
        // Filter by specific topic
        if (!in_array('topic_rel', $joins_added)) {
            $joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
            $joins_added[] = 'topic_rel';
        }
        $where[] = $wpdb->prepare("topic_rel.term_id = %d", $topic_id);
    } elseif ($subject_id) {
        // Filter by subject (find all child topics)
        $child_topic_ids = $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$term_table} WHERE parent = %d", $subject_id));
        if (!empty($child_topic_ids)) {
            $ids_placeholder = implode(',', $child_topic_ids);
            if (!in_array('topic_rel', $joins_added)) {
                $joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
                $joins_added[] = 'topic_rel';
            }
            $where[] = "topic_rel.term_id IN ($ids_placeholder)";
        } else {
             $where[] = "1=0"; // Subject has no topics, so no questions
        }
    }

    // Add Source filtering (only filtering by top-level source for now, sections can be added later)
    if ($source_id) {
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
        // Get all descendant IDs including the source itself
         $descendant_ids = get_all_descendant_ids($source_id, $wpdb, $term_table); // Use the global helper
         if (!empty($descendant_ids)) {
             $ids_placeholder = implode(',', $descendant_ids);
             if (!in_array('source_rel', $joins_added)) {
                 $joins .= " JOIN {$rel_table} source_rel ON g.group_id = source_rel.object_id AND source_rel.object_type = 'group'";
                 $joins_added[] = 'source_rel';
             }
             $where[] = "source_rel.term_id IN ($ids_placeholder)";
         } else {
             $where[] = "1=0"; // Source term not found or has no descendants
         }
    }

    // 5. Construct and Execute Query
    $sql = $select . " " . $from . " " . $joins . " WHERE " . implode(' AND ', $where) . " ORDER BY q.question_id DESC LIMIT 100"; // Add a LIMIT for now

    $results = $wpdb->get_results($wpdb->prepare($sql, $params)); // Use prepare if you added %s/%d placeholders

    // 6. Format and Send Response
    $formatted_results = [];
    if ($results) {
        foreach ($results as $question) {
            $formatted_results[] = [
                'id' => $question->question_id,
                // Simple text for now, strip tags and limit length
                'text' => wp_strip_all_tags(wp_trim_words($question->question_text, 15, '...'))
            ];
        }
    }

    wp_send_json_success(['questions' => $formatted_results]);
}
add_action('wp_ajax_qp_search_questions_for_course', 'qp_search_questions_for_course_ajax');

/**
 * Cleans up related enrollment and progress data when a qp_course post is deleted.
 * Hooks into 'before_delete_post'.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function qp_cleanup_course_data_on_delete($post_id) {
    // Check if the post being deleted is actually a 'qp_course'
    if (get_post_type($post_id) === 'qp_course') {
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';

        // Delete item progress records associated with this course first
        $wpdb->delete($progress_table, ['course_id' => $post_id], ['%d']);

        // Then delete the main enrollment records for this course
        $wpdb->delete($user_courses_table, ['course_id' => $post_id], ['%d']);
    }
}
add_action('before_delete_post', 'qp_cleanup_course_data_on_delete', 10, 1);

/**
 * Cleans up related course enrollment and progress data when a WordPress user is deleted.
 * Hooks into 'delete_user'.
 *
 * @param int $user_id The ID of the user being deleted.
 */
function qp_cleanup_user_data_on_delete($user_id) {
    global $wpdb;
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions'; // Added sessions
    $attempts_table = $wpdb->prefix . 'qp_user_attempts'; // Added attempts
    $review_table = $wpdb->prefix . 'qp_review_later'; // Added review later
    $reports_table = $wpdb->prefix . 'qp_question_reports'; // Added reports

    // Sanitize the user ID just in case
    $user_id_to_delete = absint($user_id);
    if ($user_id_to_delete <= 0) {
        return; // Invalid user ID
    }

    // Delete item progress first
    $wpdb->delete($progress_table, ['user_id' => $user_id_to_delete], ['%d']);

    // Then delete enrollments
    $wpdb->delete($user_courses_table, ['user_id' => $user_id_to_delete], ['%d']);

    // Also delete sessions, attempts, review list, and reports by this user
    $wpdb->delete($attempts_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($sessions_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($review_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($reports_table, ['user_id' => $user_id_to_delete], ['%d']);

}
add_action('delete_user', 'qp_cleanup_user_data_on_delete', 10, 1);

/**
 * Recalculates overall course progress for all enrolled users when a course is saved.
 * Hooks into 'save_post_qp_course' after the structure meta is saved.
 *
 * @param int $post_id The ID of the course post being saved.
 */
function qp_recalculate_course_progress_on_save($post_id) {
    // Check nonce (from the meta box save action)
    if (!isset($_POST['qp_course_structure_nonce']) || !wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) {
        return; // Nonce check failed or not our save action
    }

    // Check if the current user has permission
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Don't run on autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check post type is correct
    if ('qp_course' !== get_post_type($post_id)) {
        return;
    }

    global $wpdb;
    $items_table = $wpdb->prefix . 'qp_course_items';
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $course_id = $post_id; // For clarity

    // 1. Get the NEW total number of items in this course
    $total_items = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
        $course_id
    ));

    // 2. Get all users enrolled in this course
    $enrolled_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $user_courses_table WHERE course_id = %d",
        $course_id
    ));

    if (empty($enrolled_user_ids)) {
        return; // No users enrolled, nothing to update
    }

    // 3. Loop through each enrolled user and update their progress
    foreach ($enrolled_user_ids as $user_id) {
        // Get the number of items this user has completed for this course
        $completed_items = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
            $user_id,
            $course_id
        ));

        // Calculate the new progress percentage
        $progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;

        // Determine the new overall course status for the user
        $new_course_status = 'in_progress'; // Default
        if ($total_items > 0 && $completed_items >= $total_items) {
            $new_course_status = 'completed';
        }

        // Get the current completion date (if any) to avoid overwriting it
        $current_completion_date = $wpdb->get_var($wpdb->prepare(
            "SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
        $completion_date_to_set = $current_completion_date; // Keep existing by default
        if ($new_course_status === 'completed' && is_null($current_completion_date)) {
            $completion_date_to_set = current_time('mysql'); // Set completion date only if newly completed
        } elseif ($new_course_status !== 'completed') {
             $completion_date_to_set = null; // Reset completion date if no longer complete
        }


        // Update the user's course enrollment record
        $wpdb->update(
            $user_courses_table,
            [
                'progress_percent' => $progress_percent,
                'status'           => $new_course_status,
                'completion_date'  => $completion_date_to_set // Set potentially updated completion date
            ],
            [
                'user_id'   => $user_id,
                'course_id' => $course_id
            ],
            ['%d', '%s', '%s'], // Data formats
            ['%d', '%d']  // Where formats
        );
    }
}
// Hook with a priority later than the meta box save (default is 10)
add_action('save_post_qp_course', 'qp_recalculate_course_progress_on_save', 20, 1);