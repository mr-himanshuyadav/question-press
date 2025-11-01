<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           3.5.1
 * Author:            Himanshu
 * Text Domain:       question-press
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// --- NEW: Load Composer autoloader ---
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
} else {
    // Add admin notice if dependencies missing (keep this check)
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Question Press requires Composer dependencies. Please run "composer install" in the plugin directory.', 'question-press' );
        echo '</p></div>';
    });
    return; // Stop loading if dependencies are missing
}

// --- NEW: Define Plugin File Constant ---
if ( ! defined( 'QP_PLUGIN_FILE' ) ) {
    define( 'QP_PLUGIN_FILE', __FILE__ );
}

// --- NEW: Use statements for namespaced classes we will create ---
use QuestionPress\Plugin;
use QuestionPress\Activator; // (Keep commented for now)
use QuestionPress\Deactivator; // (Keep commented for now)
use QuestionPress\Database\Questions_DB;
use QuestionPress\Database\Terms_DB;
use QuestionPress\Admin\Backup\Backup_Manager;


/**
 * --- NEW: Main function for returning the Plugin instance ---
 */
function QuestionPress() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
    return Plugin::instance();
}

// --- NEW: Get Plugin running ---
QuestionPress();

// --- NEW: Activation / Deactivation Hooks (Keep commented for now) ---
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// =========================================================================
// --- BELOW IS YOUR ORIGINAL CODE - MODIFY AS INSTRUCTED ---
// =========================================================================

/**
 * Start session on init hook.
 */
function qp_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

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

/**
 * Save the custom field for Simple products.
 */
function qp_save_plan_link_simple_product($post_id) {
    $plan_id = isset($_POST['_qp_linked_plan_id']) ? absint($_POST['_qp_linked_plan_id']) : '';
    update_post_meta($post_id, '_qp_linked_plan_id', $plan_id);
}

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

/**
 * Save the custom field for Variable products (variations).
 */
function qp_save_plan_link_variable_product($variation_id, $i) {
    $plan_id = isset($_POST['_qp_linked_plan_id'][$i]) ? absint($_POST['_qp_linked_plan_id'][$i]) : '';
    update_post_meta($variation_id, '_qp_linked_plan_id', $plan_id);
}

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

/**
 * Register custom query variables for dashboard routing.
 *
 * @param array $vars Existing query variables.
 * @return array Modified query variables.
 */
function qp_register_query_vars($vars) {
    $vars[] = 'qp_tab';          // To identify the main dashboard section (e.g., 'history', 'courses')
    $vars[] = 'qp_course_slug'; // To identify a specific course by its slug
    return $vars;
}

/**
 * Add rewrite rules for the dynamic dashboard URLs.
 */
function qp_add_dashboard_rewrite_rules() {
    $options = get_option('qp_settings');
    $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;

    if ($dashboard_page_id <= 0) {
        return; // No page set, do nothing.
    }

    // Get the page's path relative to the home URL (e.g., "dashboard")
    // This will be an EMPTY STRING if the page is the site's front page.
    $dashboard_path = get_page_uri($dashboard_page_id);
    $is_front_page = ($dashboard_path === ''); // Check if it's the front page
    
    // Define the known tabs
    $tabs = ['overview', 'history', 'review', 'progress', 'courses', 'profile'];
    $tab_regex = implode('|', $tabs);

    if ( ! $is_front_page ) {
        // --- CASE 1: Dashboard is a SUB-PAGE (e.g., /dashboard/) ---
        
        // Rule for specific course: /dashboard-path/courses/course-slug/
        add_rewrite_rule(
            '^' . $dashboard_path . '/courses/([^/]+)/?$',
            // --- FIX: Use page_id instead of pagename ---
            'index.php?page_id=' . $dashboard_page_id . '&qp_tab=courses&qp_course_slug=$matches[1]',
            'top'
        );

        // Rule for a specific tab: /dashboard-path/tab-name/
        add_rewrite_rule(
            '^' . $dashboard_path . '/(' . $tab_regex . ')/?$',
            // --- FIX: Use page_id instead of pagename ---
            'index.php?page_id=' . $dashboard_page_id . '&qp_tab=$matches[1]',
            'top'
        );
        
        // Rule for the base dashboard URL: /dashboard-path/
        add_rewrite_rule(
            '^' . $dashboard_path . '/?$',
            // --- FIX: Use page_id instead of pagename ---
            'index.php?page_id=' . $dashboard_page_id,
            'top'
        );

    } else {
        // --- CASE 2: Dashboard IS THE FRONT PAGE (path is an empty string) ---
        
        // Rule for specific course on front page: /tab/courses/course-slug/
        add_rewrite_rule(
            '^tab/courses/([^/]+)/?$', // (This was correct from last time)
            'index.php?page_id=' . $dashboard_page_id . '&qp_tab=courses&qp_course_slug=$matches[1]',
            'top'
        );

        // Rule for a specific tab on front page: /tab/tab-name/
        add_rewrite_rule(
            '^tab/(' . $tab_regex . ')/?$', // (This was correct from last time)
            'index.php?page_id=' . $dashboard_page_id . '&qp_tab=$matches[1]',
            'top'
        );
        
        // The base front page (/) is already handled by WordPress.
    }
}

/**
 * Flush rewrite rules on plugin activation.
 */
function qp_flush_rewrite_rules_on_activate() {
    // Ensure our rules are added before flushing
    qp_add_dashboard_rewrite_rules();
    // Flush the rules
    flush_rewrite_rules();
}
// Make sure QP_PLUGIN_FILE is defined correctly (it should be from your main plugin file)
if (defined('QP_PLUGIN_FILE')) {
    register_activation_hook(QP_PLUGIN_FILE, 'qp_flush_rewrite_rules_on_activate');
}


/**
 * Flush rewrite rules on plugin deactivation.
 */
function qp_flush_rewrite_rules_on_deactivate() {
    // Flush the rules to remove ours
    flush_rewrite_rules();
}

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}


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

/**
 * Schedules the session cleanup event if it's not already scheduled.
 */
function qp_schedule_session_cleanup()
{
    if (!wp_next_scheduled('qp_cleanup_abandoned_sessions_event')) {
        wp_schedule_event(time(), 'hourly', 'qp_cleanup_abandoned_sessions_event');
    }
}

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
            \QuestionPress\Utils\Session_Manager::finalize_and_end_session($test->session_id, 'abandoned', 'abandoned_by_system');
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
                \QuestionPress\Utils\Session_Manager::finalize_and_end_session($session->session_id, 'abandoned', 'abandoned_by_system');
            }
        }
    }
}




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
    if (!\QuestionPress\Utils\User_Access::can_access_course($user_id, $course_id)) {
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