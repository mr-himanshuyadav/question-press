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
			return [];
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
	 * @return bool|int True if access is granted by admin/free, int (entitlement_id) if by plan, false otherwise.
	 */
	public static function can_access_course( $user_id, $course_id, $ignore_enrollment_check = false ) {
		global $wpdb;
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		// 1. Admins always have access
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$current_time       = current_time( 'mysql' );

		// 2. Check for an existing, active enrollment (if not ignored)
		if ( ! $ignore_enrollment_check ) {
			$is_enrolled = $wpdb->get_var( $wpdb->prepare(
				"SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d AND status IN ('enrolled', 'in_progress', 'completed')",
				$user_id,
				$course_id
			) );
			if ( $is_enrolled ) {
				return true;
			}
		}

		// 3. Check if the course is free
		$access_mode = get_post_meta( $course_id, '_qp_course_access_mode', true ) ?: 'free';
		if ( $access_mode === 'free' ) {
			return true;
		}

		// 4. Get ALL active, non-expired entitlements for the user
		$active_entitlements = $wpdb->get_results( $wpdb->prepare(
			"SELECT plan_id, entitlement_id FROM {$entitlements_table}
			 WHERE user_id = %d
			   AND status = 'active'
			   AND (expiry_date IS NULL OR expiry_date > %s)",
			$user_id,
			$current_time
		) );

		if ( empty( $active_entitlements ) ) {
			return false;
		}

		// 5. Schema-Aware Access Loop
		// We loop through each active plan and check for access based on its path (Course Only vs Combined).
		foreach ( $active_entitlements as $entitlement ) {
			$plan_id        = absint($entitlement->plan_id);
			$entitlement_id = absint($entitlement->entitlement_id);
			
			// Identify the plan schema (Course Only vs Combined/Legacy)
			$plan_schema = get_post_meta( $plan_id, '_qp_plan_schema', true );

			// PATH 1: Course Only Schema
			if ( $plan_schema === 'course_only' ) {
				$scope = get_post_meta( $plan_id, '_qp_plan_course_scope', true ) ?: 'all';
				
				// "All Courses" grants access immediately
				if ( $scope === 'all' ) {
					return $entitlement_id;
				}
				
				// "Single" or "Multiple" check the linked courses list
				$linked_courses = get_post_meta( $plan_id, '_qp_plan_linked_courses', true );
				if ( is_array( $linked_courses ) && in_array( $course_id, $linked_courses, true ) ) {
					return $entitlement_id;
				}
				
				continue; // Skip Path 2 checks for this specific plan
			}

			// PATH 2: Combined Schema (or Legacy / Auto-Generated Plans)
			// These plans use the '_qp_plan_course_access_type' logic.
			$access_type = get_post_meta( $plan_id, '_qp_plan_course_access_type', true ) ?: 'none';

			// If explicitly set to 'none', this plan never grants course enrollment
			if ( $access_type === 'none' ) {
				continue;
			}

			// Case A: The plan grants "All Courses" (Legacy or unrestricted Combined)
			if ( $access_type === 'all' ) {
				return $entitlement_id;
			}

			// Case B: The plan grants "Specific Courses" (New Combined or Auto-Plans)
			if ( $access_type === 'specific' ) {
				$linked_courses = get_post_meta( $plan_id, '_qp_plan_linked_courses', true );
				
				// Verify if the course is in the allowed list
				if ( is_array( $linked_courses ) && in_array( $course_id, $linked_courses, true ) ) {
					return $entitlement_id;
				}
			}
		}

		return false; // No plan granted access.
	}

}