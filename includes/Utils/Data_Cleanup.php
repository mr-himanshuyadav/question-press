<?php

namespace QuestionPress\Utils;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use QuestionPress\Admin\Admin_Utils;
use QuestionPress\Admin\Meta_Boxes;

/**
 * Handles miscellaneous data cleanup and maintenance tasks.
 *
 * @package QuestionPress\Utils
 */
class Data_Cleanup
{

    /**
     * Prevents deletion of a qp_plan or Product if it's linked to a qp_course.
     * Hooks into 'before_delete_post' with priority 5.
     *
     * @param int $post_id The ID of the post being deleted.
     * @return void
     */
    public static function prevent_deletion_if_linked($post_id)
    {
        global $wpdb;
        $post = get_post($post_id);
        if (! $post) {
            return; // Post doesn't exist, do nothing
        }
        $post_id = $post->ID; // Ensure we have the ID
        $post_type = $post->post_type;
        $post_title = $post->post_title;

        $error_message = '';
        $linked_course_ids = [];

        if ($post_type === 'qp_plan') {
            // Check if this plan is an auto-generated plan linked to a course
            $linked_course_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_qp_course_auto_plan_id' AND meta_value = %d",
                $post_id
            ));

            if (! empty($linked_course_ids)) {
                // --- Updated Plan Deletion Message ---
                $links_html = [];
                foreach ($linked_course_ids as $cid) {
                    $course_post = get_post($cid);
                    if ($course_post) {
                        $edit_link = esc_url(get_edit_post_link($cid));
                        $course_title = esc_html($course_post->post_title);
                        $links_html[] = sprintf(
                            '<strong>%s (ID: %d)</strong> [<a href="%s">View Course</a>]',
                            $course_title,
                            $cid,
                            $edit_link
                        );
                    }
                }
                $error_message = sprintf(
                    '<strong>%s</strong> can\'t be deleted. It is auto-linked to: %s. Please change the course access mode to "Free" or delete the course(s) first.',
                    esc_html($post_title),
                    implode(', ', $links_html)
                );
                // --- End Update ---
            }
        } elseif ($post_type === 'product') {

            // Rule 1: Is this product MANUALLY linked from a course?
            $linked_course_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_qp_linked_product_id' AND meta_value = %d",
                $post_id
            ));
            if (! empty($linked_course_ids)) {
                // --- Updated Manual Course Link Message ---
                $links_html = [];
                foreach ($linked_course_ids as $cid) {
                    $course_post = get_post($cid);
                    if ($course_post) {
                        $edit_link = esc_url(get_edit_post_link($cid));
                        $course_title = esc_html($course_post->post_title);
                        $links_html[] = sprintf(
                            '<strong>%s (ID: %d)</strong> [<a href="%s">View Course</a>]',
                            $course_title,
                            $cid,
                            $edit_link
                        );
                    }
                }
                $error_message = sprintf(
                    '<strong>%s</strong> can\'t be deleted. It is manually linked to: %s. Please unlink the product from these courses first.',
                    esc_html($post_title),
                    implode(', ', $links_html)
                );
                // --- End Update ---
            }

            // Rule 2: Is this an AUTO-generated product linked TO a COURSE?
            if ( empty($error_message) && get_post_meta( $post_id, '_qp_is_auto_generated', true ) === 'true' ) {
                $course_id = get_post_meta( $post_id, '_qp_linked_course_id', true );
                if ( $course_id > 0 ) {
                    $course_post = get_post( $course_id );
                    if ( $course_post && $course_post->post_status !== 'trash' ) {
                        // --- Updated Auto-Course Link Message ---
                        $edit_link = esc_url(get_edit_post_link($course_id));
                        $course_title = esc_html($course_post->post_title);
                        $error_message = sprintf(
                            '<strong>%s</strong> can\'t be deleted. It is auto-linked to <strong>%s (ID: %d)</strong> [<a href="%s">View Course</a>]. Please change the course access mode to "Free" or delete the course first.',
                            esc_html($post_title),
                            $course_title,
                            $course_id,
                            $edit_link
                        );
                        // --- End Update ---
                    }
                }
            }

            // Rule 3: Is this an AUTO-generated product linked TO a MANUAL PLAN?
            if ( empty($error_message) && get_post_meta( $post_id, '_qp_is_auto_generated', true ) === 'true' ) {
                $plan_id = get_post_meta( $post_id, '_qp_linked_plan_id', true );
                if ( $plan_id > 0 ) {
                    $course_id = get_post_meta( $post_id, '_qp_linked_course_id', true );
                    
                    if ( empty($course_id) ) { 
                        $plan_post = get_post( $plan_id );
                        if ( $plan_post && $plan_post->post_status !== 'trash' ) {
                            // --- Updated Auto-Plan Link Message ---
                            $edit_link = esc_url(get_edit_post_link($plan_id));
                            $plan_title = esc_html($plan_post->post_title);
                            $error_message = sprintf(
                                '<strong>%s</strong> can\'t be deleted. It is auto-linked to <strong>%s (ID: %d)</strong> [<a href="%s">View Plan</a>]. Please delete the plan first.',
                                esc_html($post_title),
                                $plan_title,
                                $plan_id,
                                $edit_link
                            );
                            // --- End Update ---
                        }
                    }
                }
            }
        }

        // If we found an error, set the message and redirect
        if (! empty($error_message)) {
            // Use Admin_Utils to set the session-based admin notice
            Admin_Utils::set_message($error_message, 'error');

            // Get the URL of the page we came from (the post list table)
            $sendback = wp_get_referer();
            if (! $sendback) {
                $sendback = admin_url("edit.php?post_type={$post_type}");
            }

            // Remove query args like 'trashed=1' since the action failed
            $sendback = remove_query_arg(['trashed', 'deleted', 'ids'], $sendback);

            // Redirect back to the list table
            wp_safe_redirect($sendback);

            // Use exit to stop the deletion from proceeding
            exit;
        }
    }

    /**
     * Cleans up related data when a qp_course post is permanently deleted.
     * Hooks into 'before_delete_post'.
     */
    public static function cleanup_course_data_on_delete($post_id)
    {
        // Check if the post being deleted is actually a 'qp_course'
        if (get_post_type($post_id) === 'qp_course') {
            global $wpdb;
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';
            $progress_table     = $wpdb->prefix . 'qp_user_items_progress';

            // Delete item progress records
            $wpdb->delete($progress_table, ['course_id' => $post_id], ['%d']);

            // Delete the main enrollment records
            $wpdb->delete($user_courses_table, ['course_id' => $post_id], ['%d']);

            remove_action('pre_trash_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5);
            remove_action('before_delete_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5);

            // --- UPDATED: Delete the linked auto-generated plan AND product ---
            $auto_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);
            if (! empty($auto_plan_id) && get_post_meta($auto_plan_id, '_qp_is_auto_generated', true) === 'true') {
                wp_delete_post($auto_plan_id, true); // 'true' forces permanent deletion
                error_log("QP Data Cleanup: Deleted auto-plan #{$auto_plan_id} linked to deleted course #{$post_id}.");
            }

            $auto_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
            if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
                wp_delete_post($auto_product_id, true); // 'true' forces permanent deletion
                error_log("QP Data Cleanup: Deleted auto-product #{$auto_product_id} linked to deleted course #{$post_id}.");
            }
            // --- END UPDATED ---

            add_action('pre_trash_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5, 1);
            add_action('before_delete_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5, 1);
        }
    }

    /**
     * Cleans up all related plugin data when a WordPress user is deleted.
     * Hooks into 'delete_user'.
     *
     * @param int $user_id The ID of the user being deleted.
     * @return void
     */
    public static function cleanup_user_data_on_delete($user_id)
    {
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table     = $wpdb->prefix . 'qp_user_items_progress';
        $sessions_table     = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table     = $wpdb->prefix . 'qp_user_attempts';
        $review_table       = $wpdb->prefix . 'qp_review_later';
        $reports_table      = $wpdb->prefix . 'qp_question_reports';

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
     * Moves the associated auto-plan to the trash when a course is trashed.
     * Hooks into 'wp_trash_post'.
     */
    public static function sync_plan_on_course_trash($post_id)
    {
        if (get_post_type($post_id) !== 'qp_course') {
            return;
        }

        // 1. Trash the Plan
        $auto_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);
        if (! empty($auto_plan_id) && get_post_meta($auto_plan_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_plan_id) !== 'trash') {
                wp_trash_post($auto_plan_id);
            }
        }

        // 2. Trash the Product
        $auto_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
        if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_product_id) !== 'trash') {
                wp_trash_post($auto_product_id);
            }
        }
    }

    /**
     * Restores the associated auto-plan from the trash when a course is restored.
     * Hooks into 'untrash_post'.
     */
    public static function sync_plan_on_course_untrash($post_id)
    {
        if (get_post_type($post_id) !== 'qp_course') {
            return;
        }

        // 1. Untrash the Plan
        $auto_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);
        if (! empty($auto_plan_id) && get_post_meta($auto_plan_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_plan_id) === 'trash') {
                wp_untrash_post($auto_plan_id);
            }
        }

        // 2. Untrash the Product
        $auto_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
        if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_product_id) === 'trash') {
                wp_untrash_post($auto_product_id);
            }
        }

        // 3. Re-sync status for both (this will set them to publish/draft correctly)
        // This function now handles both plan and product status
        \QuestionPress\Admin\Meta_Boxes::sync_course_plan($post_id);
    }

    /**
     * Recalculates overall course progress for all enrolled users when a course is saved.
     * Hooks into 'save_post_qp_course' after the structure meta is saved.
     *
     * @param int $post_id The ID of the course post being saved.
     * @return void
     */
    public static function recalculate_course_progress_on_save($post_id)
    {
        // Check nonce (from the meta box save action)
        if (! isset($_POST['qp_course_structure_nonce']) || ! wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) {
            return; // Nonce check failed or not our save action
        }

        // Check if the current user has permission
        if (! current_user_can('edit_post', $post_id)) {
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
        $items_table        = $wpdb->prefix . 'qp_course_items';
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table     = $wpdb->prefix . 'qp_user_items_progress';
        $course_id          = $post_id; // For clarity

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
                $user_id,
                $course_id
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
                    'completion_date'  => $completion_date_to_set, // Set potentially updated completion date
                ],
                [
                    'user_id'   => $user_id,
                    'course_id' => $course_id,
                ],
                ['%d', '%s', '%s'], // Data formats
                ['%d', '%d']  // Where formats
            );
        }
    }

    /**
     * Cleans up related data when a qp_plan post is permanently deleted.
     * Only runs for MANUAL plans (not course-auto-generated ones).
     */
    public static function cleanup_plan_data_on_delete($post_id)
    {
        if (get_post_type($post_id) === 'qp_plan') {
            // DO NOT run this for plans that are auto-generated by courses
            if (get_post_meta($post_id, '_qp_is_auto_generated', true) === 'true') {
                return;
            }

            // Temporarily unhook our deletion blocker
            remove_action('pre_trash_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5);
            remove_action('before_delete_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5);

            // Find and delete the product auto-generated by this plan
            $auto_product_id = get_post_meta($post_id, '_qp_auto_product_id', true);
            if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
                wp_delete_post($auto_product_id, true); // 'true' forces permanent deletion
                error_log("QP Data Cleanup: Deleted auto-product #{$auto_product_id} linked to deleted manual plan #{$post_id}.");
            }

            // Add the hooks back
            add_action('pre_trash_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5, 1);
            add_action('before_delete_post', [Data_Cleanup::class, 'prevent_deletion_if_linked'], 5, 1);
        }
    }

    /**
     * Moves the associated auto-product to the trash when a manual plan is trashed.
     */
    public static function sync_product_on_plan_trash($post_id)
    {
        if (get_post_type($post_id) !== 'qp_plan' || get_post_meta($post_id, '_qp_is_auto_generated', true) === 'true') {
            return; // Not a manual plan, so do nothing
        }

        // 1. Trash the auto-generated Product
        $auto_product_id = get_post_meta($post_id, '_qp_auto_product_id', true);
        if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_product_id) !== 'trash') {
                wp_trash_post($auto_product_id);
            }
        }
    }

    /**
     * Restores the associated auto-product from the trash when a manual plan is restored.
     */
    public static function sync_product_on_plan_untrash($post_id)
    {
        if (get_post_type($post_id) !== 'qp_plan' || get_post_meta($post_id, '_qp_is_auto_generated', true) === 'true') {
            return; // Not a manual plan, so do nothing
        }

        // 1. Untrash the Product
        $auto_product_id = get_post_meta($post_id, '_qp_auto_product_id', true);
        if (! empty($auto_product_id) && get_post_meta($auto_product_id, '_qp_is_auto_generated', true) === 'true') {
            if (get_post_status($auto_product_id) === 'trash') {
                wp_untrash_post($auto_product_id);
            }
        }

        // 2. Re-sync status (this will set it to publish/draft correctly)
        Meta_Boxes::sync_plan_product($post_id);
    }
}
