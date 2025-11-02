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
     * Cleans up related data when a qp_course post is permanently deleted.
     * Hooks into 'before_delete_post'.
     */
    public static function cleanup_course_data_on_delete( $post_id ) {
        // Check if the post being deleted is actually a 'qp_course'
        if ( get_post_type( $post_id ) === 'qp_course' ) {
            global $wpdb;
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';
            $progress_table     = $wpdb->prefix . 'qp_user_items_progress';

            // Delete item progress records
            $wpdb->delete( $progress_table, [ 'course_id' => $post_id ], [ '%d' ] );

            // Delete the main enrollment records
            $wpdb->delete( $user_courses_table, [ 'course_id' => $post_id ], [ '%d' ] );

            // --- UPDATED: Delete the linked auto-generated plan AND product ---
            $auto_plan_id = get_post_meta( $post_id, '_qp_course_auto_plan_id', true );
            if ( ! empty( $auto_plan_id ) && get_post_meta( $auto_plan_id, '_qp_is_auto_generated', true ) === 'true' ) {
                wp_delete_post( $auto_plan_id, true ); 
                error_log( "QP Data Cleanup: Deleted auto-plan #{$auto_plan_id} linked to deleted course #{$post_id}." );
            }
            
            $auto_product_id = get_post_meta( $post_id, '_qp_linked_product_id', true );
            if ( ! empty( $auto_product_id ) && get_post_meta( $auto_product_id, '_qp_is_auto_generated', true ) === 'true' ) {
                wp_delete_post( $auto_product_id, true ); 
                error_log( "QP Data Cleanup: Deleted auto-product #{$auto_product_id} linked to deleted course #{$post_id}." );
            }
            // --- END UPDATED ---
        }
    }

    /**
     * Prevents an auto-generated plan from being deleted if its course still exists.
     * Hooks into 'before_delete_post'.
     */
    public static function prevent_auto_plan_deletion( $post_id ) {
        if ( get_post_type( $post_id ) === 'qp_plan' ) {
            if ( get_post_meta( $post_id, '_qp_is_auto_generated', true ) === 'true' ) {
                $linked_courses = get_post_meta( $post_id, '_qp_plan_linked_courses', true );
                $course_id = ( is_array( $linked_courses ) && ! empty( $linked_courses ) ) ? absint( $linked_courses[0] ) : 0;
                
                if ( $course_id > 0 ) {
                    $course_post = get_post( $course_id );
                    // Check if course exists AND is not in the trash
                    if ( $course_post && $course_post->post_status !== 'trash' ) {
                        $course_edit_link = get_edit_post_link( $course_id );
                        $message = sprintf(
                            __( 'This is an auto-generated plan and cannot be deleted because its course, "%s" (ID: %d), still exists. %sIf you want to remove this plan, you must delete the course. If you want to make the course free, set its Access Mode to "Free" to move this plan to drafts.', 'question-press' ),
                            esc_html( $course_post->post_title ),
                            $course_id,
                            '<br/><br/>'
                        );
                        if ($course_edit_link) {
                            $message .= ' <a href="' . esc_url($course_edit_link) . '">Edit the course here</a>.';
                        }
                        wp_die( $message, 'Deletion Restricted', [ 'response' => 403, 'back_link' => true ] );
                    }
                }
            }
        }
    }

    /**
     * Cleans up all related plugin data when a WordPress user is deleted.
     * Hooks into 'delete_user'.
     *
     * @param int $user_id The ID of the user being deleted.
     * @return void
     */
    public static function cleanup_user_data_on_delete( $user_id ) {
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table     = $wpdb->prefix . 'qp_user_items_progress';
        $sessions_table     = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table     = $wpdb->prefix . 'qp_user_attempts';
        $review_table       = $wpdb->prefix . 'qp_review_later';
        $reports_table      = $wpdb->prefix . 'qp_question_reports';

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
     * NEW: Prevents an auto-generated product from being deleted if its course still exists.
     * Hooks into 'before_delete_post'.
     *
     * @param int $post_id The ID of the post (product) being deleted.
     * @return void
     */
    public static function prevent_auto_product_deletion( $post_id ) {
        // Check if the post being deleted is a 'product'
        if ( get_post_type( $post_id ) === 'product' ) {
            
            // Check if it's an auto-generated product
            if ( get_post_meta( $post_id, '_qp_is_auto_generated', true ) === 'true' ) {
                
                // Find its linked course
                $course_id = get_post_meta( $post_id, '_qp_linked_course_id', true );
                
                if ( $course_id > 0 ) {
                    // Check if the course post still exists (and is not in the trash)
                    $course_post = get_post( $course_id );
                    if ( $course_post && $course_post->post_status !== 'trash' ) {
                        // The course exists, so block deletion.
                        $course_edit_link = get_edit_post_link( $course_id );
                        $message = sprintf(
                            __( 'This is an auto-generated product and cannot be deleted because its course, "%s" (ID: %d), still exists. %sIf you want to remove this product, you must delete the course. If you want to make the course free, set its Access Mode to "Free" to move this product to drafts.', 'question-press' ),
                            esc_html( $course_post->post_title ),
                            $course_id,
                            '<br/><br/>'
                        );
                        
                        if ($course_edit_link) {
                            $message .= ' <a href="' . esc_url($course_edit_link) . '">Edit the course here</a>.';
                        }
                        
                        wp_die( $message, 'Deletion Restricted', [ 'response' => 403, 'back_link' => true ] );
                    }
                }
            }
        }
    }

    /**
     * Moves the associated auto-plan to the trash when a course is trashed.
     * Hooks into 'wp_trash_post'.
     */
    public static function sync_plan_on_course_trash( $post_id ) {
        if ( get_post_type( $post_id ) !== 'qp_course' ) {
            return;
        }

        // 1. Trash the Plan
        $auto_plan_id = get_post_meta( $post_id, '_qp_course_auto_plan_id', true );
        if ( ! empty( $auto_plan_id ) && get_post_meta( $auto_plan_id, '_qp_is_auto_generated', true ) === 'true' ) {
            if ( get_post_status( $auto_plan_id ) !== 'trash' ) {
                wp_trash_post( $auto_plan_id );
            }
        }
        
        // 2. Trash the Product
        $auto_product_id = get_post_meta( $post_id, '_qp_linked_product_id', true );
        if ( ! empty( $auto_product_id ) && get_post_meta( $auto_product_id, '_qp_is_auto_generated', true ) === 'true' ) {
            if ( get_post_status( $auto_product_id ) !== 'trash' ) {
                wp_trash_post( $auto_product_id );
            }
        }
    }

    /**
     * Restores the associated auto-plan from the trash when a course is restored.
     * Hooks into 'untrash_post'.
     */
    public static function sync_plan_on_course_untrash( $post_id ) {
        if ( get_post_type( $post_id ) !== 'qp_course' ) {
            return;
        }

        // 1. Untrash the Plan
        $auto_plan_id = get_post_meta( $post_id, '_qp_course_auto_plan_id', true );
        if ( ! empty( $auto_plan_id ) && get_post_meta( $auto_plan_id, '_qp_is_auto_generated', true ) === 'true' ) {
            if ( get_post_status( $auto_plan_id ) === 'trash' ) {
                wp_untrash_post( $auto_plan_id );
            }
        }
        
        // 2. Untrash the Product
        $auto_product_id = get_post_meta( $post_id, '_qp_linked_product_id', true );
        if ( ! empty( $auto_product_id ) && get_post_meta( $auto_product_id, '_qp_is_auto_generated', true ) === 'true' ) {
            if ( get_post_status( $auto_product_id ) === 'trash' ) {
                wp_untrash_post( $auto_product_id );
            }
        }

        // 3. Re-sync status for both (this will set them to publish/draft correctly)
        // This function now handles both plan and product status
        \QuestionPress\Admin\Meta_Boxes::sync_course_plan( $post_id );
    }

    /**
     * Recalculates overall course progress for all enrolled users when a course is saved.
     * Hooks into 'save_post_qp_course' after the structure meta is saved.
     *
     * @param int $post_id The ID of the course post being saved.
     * @return void
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