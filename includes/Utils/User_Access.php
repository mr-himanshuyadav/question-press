<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles user access and permission checks for subjects and courses.
 *
 * @package QuestionPress\Utils
 */
class User_Access {

	/**
	 * Determines the allowed subject term IDs for a given user based on their scope settings.
	 *
	 * @param int $user_id The user's ID.
	 * @return array|string Returns an array of allowed subject IDs, or 'all' if unrestricted.
	 */
	public static function get_allowed_subject_ids( $user_id ) {
		global $wpdb;
		$term_table = $wpdb->prefix . 'qp_terms';
		$tax_table  = $wpdb->prefix . 'qp_taxonomies';
		$rel_table  = $wpdb->prefix . 'qp_term_relationships';

		// Check for admin/unrestricted role
		$user = get_userdata( $user_id );
		if ( $user && array_intersect( ['administrator', 'editor'], $user->roles ) ) {
			return 'all'; // Admins/Editors can access all
		}

		// Get user's scope settings
		$allowed_exam_ids_json    = get_user_meta( $user_id, '_qp_allowed_exam_term_ids', true );
		$allowed_subject_ids_json = get_user_meta( $user_id, '_qp_allowed_subject_term_ids', true );

		$allowed_exam_ids    = json_decode( $allowed_exam_ids_json, true );
		$allowed_subject_ids = json_decode( $allowed_subject_ids_json, true );

		if ( ! is_array( $allowed_exam_ids ) ) {
			$allowed_exam_ids = [];
		}
		if ( ! is_array( $allowed_subject_ids ) ) {
			$allowed_subject_ids = [];
		}

		// If both are empty, the user has access to everything
		if ( empty( $allowed_exam_ids ) && empty( $allowed_subject_ids ) ) {
			return 'all';
		}

		// If exams are specified, get all subjects linked to those exams
		if ( ! empty( $allowed_exam_ids ) ) {
			$exam_ids_placeholder = implode( ',', array_map( 'absint', $allowed_exam_ids ) );

			$subjects_from_exams = $wpdb->get_col(
				"SELECT DISTINCT term_id
				 FROM {$rel_table}
				 WHERE object_type = 'exam_subject_link' AND object_id IN ($exam_ids_placeholder)"
			);

			$allowed_subject_ids = array_merge( $allowed_subject_ids, $subjects_from_exams );
		}

		return array_unique( array_map( 'absint', $allowed_subject_ids ) );
	}

	/**
	 * Checks if a user has access to a specific course via a relevant entitlement OR existing enrollment.
	 *
	 * @param int  $user_id                 The user's ID.
	 * @param int  $course_id               The course (post) ID.
	 * @param bool $ignore_enrollment_check If true, only checks for a valid *purchase* entitlement (e.g., for 'Enroll' button).
	 * @return bool True if the user has access, false otherwise.
	 */
	public static function can_access_course( $user_id, $course_id, $ignore_enrollment_check = false ) {
		global $wpdb;
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		// Admins always have access
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$current_time       = current_time( 'mysql' );

		// 1. Check for an existing, active enrollment (if not ignored)
		if ( ! $ignore_enrollment_check ) {
			$is_enrolled = $wpdb->get_var( $wpdb->prepare(
				"SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d AND status IN ('enrolled', 'in_progress', 'completed')", // Also allow access if completed
				$user_id,
				$course_id
			) );
			if ( $is_enrolled ) {
				return true; // Already enrolled, access granted
			}
		}

		// 2. Check for an active, non-expired entitlement that grants access to this specific course.
		// Get the auto-generated plan ID linked to this course
		$auto_plan_id = get_post_meta( $course_id, '_qp_course_auto_plan_id', true );
		if ( empty( $auto_plan_id ) ) {
			// This course might be free (check access mode) or misconfigured
			$access_mode = get_post_meta( $course_id, '_qp_course_access_mode', true ) ?: 'free';
			return ($access_mode === 'free'); // Grant access if it's free, deny if it's paid but misconfigured
		}
		$auto_plan_id = absint( $auto_plan_id );

		// Check for an entitlement matching this specific plan ID
		$has_valid_entitlement = $wpdb->get_var( $wpdb->prepare(
			"SELECT entitlement_id
			 FROM {$entitlements_table}
			 WHERE user_id = %d
			   AND plan_id = %d
			   AND status = 'active'
			   AND (expiry_date IS NULL OR expiry_date > %s)",
			$user_id,
			$auto_plan_id,
			$current_time
		) );

		return (bool) $has_valid_entitlement;
	}

}