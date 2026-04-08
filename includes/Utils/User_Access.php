<?php

namespace QuestionPress\Utils;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

use QuestionPress\Database\Terms_DB;

/**
 * Handles user access and permission checks for subjects and courses.
 *
 * @package QuestionPress\Utils
 */
class User_Access
{

	// Inside includes/Utils/User_Access.php

	public static function get_allowed_subject_ids($user_id)
	{
		$user = get_userdata($user_id);
		if ($user && array_intersect(['administrator', 'editor'], $user->roles)) {
			return 'all';
		}

		$vault = Vault_Manager::get_vault($user_id);
		if (!$vault) return [];

		// Access the pre-resolved cache!
		$subjects = $vault->access_scope['resolved_subjects'] ?? [];

		// Fallback: If cache is empty, try a quick manual resolve 
		if (empty($subjects)) {
			$exams = $vault->access_scope['exams'] ?? [];
			$manual = $vault->access_scope['manual_subjects'] ?? [];
			if (!empty($exams) || !empty($manual)) {
				// Perform one-time recovery update
				Vault_Manager::update_access_scope($user_id, $exams, $manual);
				$vault = Vault_Manager::get_vault($user_id);
				return $vault->access_scope['resolved_subjects'] ?? [];
			}
		}

		return $subjects;
	}

	/**
     * Gets the user's allowed scope formatted as a hierarchy (Exams, Subjects -> Topics).
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_scope_hierarchy(int $user_id): array
    {
        $scope = Vault_Manager::get_access_scope($user_id);
        $exam_ids = $scope['exams'] ?? [];
        $subject_ids = $scope['resolved_subjects'] ?? [];

        global $wpdb;
        $term_table = Terms_DB::get_terms_table_name();

        $allowed_exams = [];
        $allowed_subjects = [];

        // 1. Fetch Exams
        if (!empty($exam_ids)) {
            $placeholders = implode(',', array_fill(0, count($exam_ids), '%d'));
            $allowed_exams = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id as id, name FROM $term_table WHERE term_id IN ($placeholders) ORDER BY name ASC",
                ...$exam_ids
            ), ARRAY_A);
        }

        // 2. Fetch Subjects and child Topics
        if (!empty($subject_ids)) {
            $placeholders = implode(',', array_fill(0, count($subject_ids), '%d'));

            // Get Subjects
            $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id as id, name FROM $term_table WHERE term_id IN ($placeholders) ORDER BY name ASC",
                ...$subject_ids
            ), ARRAY_A);

            // Get Topics (where parent is one of our allowed subjects)
            $topics = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id as id, name, parent as subject_id FROM $term_table WHERE parent IN ($placeholders) ORDER BY name ASC",
                ...$subject_ids
            ), ARRAY_A);

            // Group topics by their parent subject_id
            $topics_by_subject = [];
            foreach ($topics as $t) {
                $topics_by_subject[$t['subject_id']][] = [
                    'id'   => (string) $t['id'],
                    'name' => $t['name']
                ];
            }

            // Build the final hierarchical array
            foreach ($subjects as $s) {
                $allowed_subjects[] = [
                    'id'     => (string) $s['id'],
                    'name'   => $s['name'],
                    'topics' => $topics_by_subject[$s['id']] ?? []
                ];
            }
        }

        return [
            'exams'    => $allowed_exams,
            'subjects' => $allowed_subjects
        ];
    }

	/**
	 * Checks if a user has access to a specific course via a relevant entitlement OR existing enrollment.
	 *
	 * @param int  $user_id                 The user's ID.
	 * @param int  $course_id               The course (post) ID.
	 * @param bool $ignore_enrollment_check If true, only checks for a valid *purchase* entitlement (e.g., for 'Enroll' button).
	 * @return bool|int True if access is granted by admin/free, int (entitlement_id) if by plan, false otherwise.
	 */
	public static function can_access_course($user_id, $course_id, $ignore_enrollment_check = false)
	{
		global $wpdb;
		$user_id   = absint($user_id);
		$course_id = absint($course_id);
		if (! $user_id || ! $course_id) {
			return false;
		}

		// 1. Admins always have access
		if (user_can($user_id, 'manage_options')) {
			return true;
		}

		$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$current_time       = current_time('mysql');

		// 2. Check for an existing, active enrollment (if not ignored)
		if (! $ignore_enrollment_check) {
			$is_enrolled = $wpdb->get_var($wpdb->prepare(
				"SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d AND status IN ('enrolled', 'in_progress', 'completed')",
				$user_id,
				$course_id
			));
			if ($is_enrolled) {
				return true;
			}
		}

		// 3. Check if the course is free
		$access_mode = get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free';
		if ($access_mode === 'free') {
			return true;
		}

		// 4. Get ALL active, non-expired entitlements for the user
		$active_entitlements = $wpdb->get_results($wpdb->prepare(
			"SELECT plan_id, entitlement_id FROM {$entitlements_table}
			 WHERE user_id = %d
			   AND status = 'active'
			   AND (expiry_date IS NULL OR expiry_date > %s)",
			$user_id,
			$current_time
		));

		if (empty($active_entitlements)) {
			return false;
		}

		// 5. Schema-Aware Access Loop
		// We loop through each active plan and check for access based on its path (Course Only vs Combined).
		foreach ($active_entitlements as $entitlement) {
			$plan_id        = absint($entitlement->plan_id);
			$entitlement_id = absint($entitlement->entitlement_id);

			// Identify the plan schema (Course Only vs Combined/Legacy)
			$plan_schema = get_post_meta($plan_id, '_qp_plan_schema', true);

			// PATH 1: Course Only Schema
			if ($plan_schema === 'course_only') {
				$scope = get_post_meta($plan_id, '_qp_plan_course_scope', true) ?: 'all';

				// "All Courses" grants access immediately
				if ($scope === 'all') {
					return $entitlement_id;
				}

				// "Single" or "Multiple" check the linked courses list
				$linked_courses = get_post_meta($plan_id, '_qp_plan_linked_courses', true);
				if (is_array($linked_courses) && in_array($course_id, $linked_courses, true)) {
					return $entitlement_id;
				}

				continue; // Skip Path 2 checks for this specific plan
			}

			// PATH 2: Combined Schema (or Legacy / Auto-Generated Plans)
			// These plans use the '_qp_plan_course_access_type' logic.
			$access_type = get_post_meta($plan_id, '_qp_plan_course_access_type', true) ?: 'none';

			// If explicitly set to 'none', this plan never grants course enrollment
			if ($access_type === 'none') {
				continue;
			}

			// Case A: The plan grants "All Courses" (Legacy or unrestricted Combined)
			if ($access_type === 'all') {
				return $entitlement_id;
			}

			// Case B: The plan grants "Specific Courses" (New Combined or Auto-Plans)
			if ($access_type === 'specific') {
				$linked_courses = get_post_meta($plan_id, '_qp_plan_linked_courses', true);

				// Verify if the course is in the allowed list
				if (is_array($linked_courses) && in_array($course_id, $linked_courses, true)) {
					return $entitlement_id;
				}
			}
		}

		return false; // No plan granted access.
	}
}
