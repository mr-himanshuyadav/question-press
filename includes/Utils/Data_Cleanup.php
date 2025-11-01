<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles miscellaneous data cleanup and maintenance tasks.
 *
 * @package QuestionPress\Utils
 */
class Data_Cleanup {

    /**
     * Cleans up related enrollment and progress data when a qp_course post is deleted.
     * Hooks into 'before_delete_post'.
     *
     * @param int $post_id The ID of the post being deleted.
     */
    public static function cleanup_course_data_on_delete( $post_id ) {
        // Check if the post being deleted is actually a 'qp_course'
        if ( get_post_type( $post_id ) === 'qp_course' ) {
            global $wpdb;
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';
            $progress_table     = $wpdb->prefix . 'qp_user_items_progress';

            // Delete item progress records associated with this course first
            $wpdb->delete( $progress_table, [ 'course_id' => $post_id ], [ '%d' ] );

            // Then delete the main enrollment records for this course
            $wpdb->delete( $user_courses_table, [ 'course_id' => $post_id ], [ '%d' ] );
        }
    }

    /**
     * Cleans up related course enrollment and progress data when a WordPress user is deleted.
     * Hooks into 'delete_user'.
     *
     * @param int $user_id The ID of the user being deleted.
     */
    public static function cleanup_user_data_on_delete( $user_id ) {
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table     = $wpdb->prefix . 'qp_user_items_progress';
        $sessions_table     = $wpdb->prefix . 'qp_user_sessions'; // Added sessions
        $attempts_table     = $wpdb->prefix . 'qp_user_attempts'; // Added attempts
        $review_table       = $wpdb->prefix . 'qp_review_later'; // Added review list
        $reports_table      = $wpdb->prefix . 'qp_question_reports'; // Added reports

        // Sanitize the user ID just in case
        $user_id_to_delete = absint( $user_id );
        if ( $user_id_to_delete <= 0 ) {
            return; // Invalid user ID
        }

        // Delete item progress first
        $wpdb->delete( $progress_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );

        // Then delete enrollments
        $wpdb->delete( $user_courses_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );

        // Also delete sessions, attempts, review list, and reports by this user
        $wpdb->delete( $attempts_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );
        $wpdb->delete( $sessions_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );
        $wpdb->delete( $review_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );
        $wpdb->delete( $reports_table, [ 'user_id' => $user_id_to_delete ], [ '%d' ] );

    }

    /**
     * Recalculates overall course progress for all enrolled users when a course is saved.
     * Hooks into 'save_post_qp_course' after the structure meta is saved.
     *
     * @param int $post_id The ID of the course post being saved.
     */
    public static function recalculate_course_progress_on_save( $post_id ) {
        // Check nonce (from the meta box save action)
        if ( ! isset( $_POST['qp_course_structure_nonce'] ) || ! wp_verify_nonce( $_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta' ) ) {
            return; // Nonce check failed or not our save action
        }

        // Check if the current user has permission
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Don't run on autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check post type is correct
        if ( 'qp_course' !== get_post_type( $post_id ) ) {
            return;
        }

        global $wpdb;
        $items_table        = $wpdb->prefix . 'qp_course_items';
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table     = $wpdb->prefix . 'qp_user_items_progress';
        $course_id          = $post_id; // For clarity

        // 1. Get the NEW total number of items in this course
        $total_items = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
            $course_id
        ) );

        // 2. Get all users enrolled in this course
        $enrolled_user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM $user_courses_table WHERE course_id = %d",
            $course_id
        ) );

        if ( empty( $enrolled_user_ids ) ) {
            return; // No users enrolled, nothing to update
        }

        // 3. Loop through each enrolled user and update their progress
        foreach ( $enrolled_user_ids as $user_id ) {
            // Get the number of items this user has completed for this course
            $completed_items = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
                $user_id,
                $course_id
            ) );

            // Calculate the new progress percentage
            $progress_percent = ( $total_items > 0 ) ? round( ( $completed_items / $total_items ) * 100 ) : 0;

            // Determine the new overall course status for the user
            $new_course_status = 'in_progress'; // Default
            if ( $total_items > 0 && $completed_items >= $total_items ) {
                $new_course_status = 'completed';
            }

            // Get the current completion date (if any) to avoid overwriting it
            $current_completion_date = $wpdb->get_var( $wpdb->prepare(
                "SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
                $user_id, $course_id
            ) );
            $completion_date_to_set = $current_completion_date; // Keep existing by default
            if ( $new_course_status === 'completed' && is_null( $current_completion_date ) ) {
                $completion_date_to_set = current_time( 'mysql' ); // Set completion date only if newly completed
            } elseif ( $new_course_status !== 'completed' ) {
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
                [ '%d', '%s', '%s' ], // Data formats
                [ '%d', '%d' ]  // Where formats
            );
        }
    }
}