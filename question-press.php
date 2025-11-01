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

// New Development - Subscriptions


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