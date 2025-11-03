<?php
namespace QuestionPress; // PSR-4 Namespace

use QuestionPress\Database\Terms_DB;
use QuestionPress\Core\Rewrites;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @package QuestionPress
 */
class Activator {

    /**
     * Activation hook callback.
     * Creates database tables, sets default options, etc.
     */
    public static function activate() {
        
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
        course_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        item_order INT NOT NULL DEFAULT 0,
        content_type VARCHAR(50) NOT NULL,
        content_config LONGTEXT,
        PRIMARY KEY (item_id),
        KEY section_id (section_id),
        KEY course_id (course_id),
        KEY item_order (item_order)
    ) $charset_collate;";
    dbDelta($sql_course_items);

    // 8. User Courses Table (Enrollment & Overall Progress)
    $table_user_courses = $wpdb->prefix . 'qp_user_courses';
    $sql_user_courses = "CREATE TABLE $table_user_courses (
        user_course_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        course_id BIGINT(20) UNSIGNED NOT NULL,
        entitlement_id BIGINT(20) UNSIGNED DEFAULT NULL,
        enrollment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completion_date DATETIME DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'enrolled',
        progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_accessed_item_id BIGINT(20) UNSIGNED DEFAULT NULL,
        PRIMARY KEY (user_course_id),
        UNIQUE KEY user_course (user_id, course_id, entitlement_id),
        KEY user_id (user_id),
        KEY course_id (course_id),
        KEY entitlement_id (entitlement_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_user_courses);

    // 9. User Items Progress Table
    $table_user_items_progress = $wpdb->prefix . 'qp_user_items_progress';
    $sql_user_items_progress = "CREATE TABLE $table_user_items_progress (
        user_item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        item_id BIGINT(20) UNSIGNED NOT NULL,
        course_id BIGINT(20) UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'not_started',
        completion_date DATETIME DEFAULT NULL,
        result_data TEXT DEFAULT NULL,
        last_viewed DATETIME DEFAULT NULL,
        attempt_count INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (user_item_id),
        UNIQUE KEY user_item (user_id, item_id),
        KEY user_id (user_id),
        KEY item_id (item_id),
        KEY course_id (course_id),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_user_items_progress);

    // 10. USER ENTITLEMENTS TABLE
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

    // 11. OTP Verification Table
    $table_otp = $wpdb->prefix . 'qp_otp_verification';
    $sql_otp = "CREATE TABLE $table_otp (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(100) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        PRIMARY KEY (id),
        KEY email (email),
        KEY expires_at (expires_at),
        KEY status (status)
    ) $charset_collate;";
    dbDelta($sql_otp);

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
            $term_id = Terms_DB::get_or_create($label['name'], $label_tax_id);
            if ($term_id) {
                Terms_DB::update_meta($term_id, 'color', $label['color']);
                Terms_DB::update_meta($term_id, 'description', $label['description']);
                Terms_DB::update_meta($term_id, 'is_default', '1');
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
            $term_id = Terms_DB::get_or_create($reason['text'], $reason_tax_id);
            if ($term_id) {
                Terms_DB::update_meta($term_id, 'is_active', '1');
                Terms_DB::update_meta($term_id, 'type', $reason['type']); // Add the type meta
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
        'signup_page'    => ['title' => 'Signup', 'content' => '[question_press_signup]']
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


        // Ensure flush_rewrite_rules() is called if needed for CPTs/Taxonomies
        // (We'll move CPT registration later, but keep flush in mind)
        Rewrites::add_dashboard_rewrite_rules();
        flush_rewrite_rules();
    }

} // End class Activator