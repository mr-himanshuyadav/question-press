<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles user access and permission checks.
 */
class User_Access {

	/**
	 * Determines the allowed subject term IDs for a given user based on their scope settings.
	 * Replaces the old qp_get_allowed_subject_ids_for_user() function.
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
		// Note: 'editor' may be too broad, adjust if you have a custom 'Question Manager' role
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

}