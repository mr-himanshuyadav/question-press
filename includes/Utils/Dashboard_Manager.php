<?php

namespace QuestionPress\Utils;

use QuestionPress\Database\Terms_DB;
use WP_Error;

/**
 * Handles the core business logic for retrieving dashboard data.
 * This class is the single source of truth for both web and API.
 */
class Dashboard_Manager {

    /**
	 * Gathers all data required for the dashboard overview section.
	 *
	 * @param int $user_id The ID of the current user.
	 * @return array An associative array containing all overview data.
	 */
	public static function get_overview_data( $user_id ): array {
		global $wpdb;
		$attempts_table = $wpdb->prefix . 'qp_user_attempts';
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';

		// 1. Fetch basic stats
		$stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted, COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct, COUNT(CASE WHEN is_correct = 0 THEN 1 END) as total_incorrect FROM {$attempts_table} WHERE user_id = %d", $user_id ) );
		$total_attempted  = $stats->total_attempted ?? 0;
		$total_correct    = $stats->total_correct ?? 0;
		$overall_accuracy = ( $total_attempted > 0 ) ? ( $total_correct / $total_attempted ) * 100 : 0;

		// 2. Fetch active sessions
		$active_sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('active', 'mock_test', 'paused') ORDER BY start_time DESC", $user_id ) );

		// 3. Fetch recent history
		$recent_history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC LIMIT 5", $user_id ) );

		// 4. Fetch review count
		$review_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d", $user_id ) );

		// 5. Fetch never-correct count
		$correctly_answered_qids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id ) );
		$all_answered_qids       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id ) );
		$never_correct_qids      = array_diff( $all_answered_qids, $correctly_answered_qids );
		$never_correct_count     = count( $never_correct_qids );

		// 6. Fetch page URLs and settings
		$options                 = get_option( 'qp_settings' );
		$practice_page_url       = isset( $options['practice_page'] ) ? get_permalink( $options['practice_page'] ) : home_url( '/' );
		$session_page_url        = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
		$review_page_url         = isset( $options['review_page'] ) ? get_permalink( $options['review_page'] ) : home_url( '/' );
		$allow_termination       = isset( $options['allow_session_termination'] ) ? $options['allow_session_termination'] : 0;
		$dashboard_page_id  = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;
		$base_dashboard_url = $dashboard_page_id ? trailingslashit( get_permalink( $dashboard_page_id ) ) : home_url( '/' );
		$is_front_page      = ( $dashboard_page_id > 0 && get_option( 'show_on_front' ) == 'page' && get_option( 'page_on_front' ) == $dashboard_page_id );
		$tab_prefix         = $is_front_page ? 'tab/' : '';
		$history_tab_url    = $base_dashboard_url . $tab_prefix . 'history/';

		// 7. Prefetch lineage data for active sessions
		list($lineage_cache_active, $group_to_topic_map_active, $question_to_group_map_active) = self::prefetch_lineage_data( $active_sessions );

		// 8. Prefetch lineage data for recent history
		list($lineage_cache_recent, $group_to_topic_map_recent, $question_to_group_map_recent) = self::prefetch_lineage_data( $recent_history );

		// 9. Prefetch accuracy stats for recent history
		$accuracy_stats      = [];
		$session_ids_history = wp_list_pluck( $recent_history, 'session_id' );
		if ( ! empty( $session_ids_history ) ) {
			$ids_placeholder = implode( ',', array_map( 'absint', $session_ids_history ) );
			$results         = $wpdb->get_results(
				"SELECT session_id,
				COUNT(CASE WHEN is_correct = 1 THEN 1 END) as correct,
				COUNT(CASE WHEN is_correct = 0 THEN 1 END) as incorrect
				FROM {$attempts_table}
				WHERE session_id IN ({$ids_placeholder}) AND status = 'answered'
				GROUP BY session_id"
			);
			foreach ( $results as $result ) {
				$total_attempted                = (int) $result->correct + (int) $result->incorrect;
				$accuracy                       = ( $total_attempted > 0 ) ? ( ( (int) $result->correct / $total_attempted ) * 100 ) : 0;
				$accuracy_stats[ $result->session_id ] = number_format( $accuracy, 2 ) . '%';
			}
		}

		return [
			'stats'                 => $stats,
			'overall_accuracy'      => $overall_accuracy,
			'active_sessions'       => $active_sessions,
			'recent_history'        => $recent_history,
			'review_count'          => $review_count,
			'never_correct_count'   => $never_correct_count,
			'practice_page_url'     => $practice_page_url,
			'session_page_url'      => $session_page_url,
			'review_page_url'       => $review_page_url,
			'allow_termination'     => $allow_termination,
			'history_tab_url'       => $history_tab_url,
			'lineage_cache_active'  => $lineage_cache_active,
			'group_to_topic_map_active' => $group_to_topic_map_active,
			'question_to_group_map_active' => $question_to_group_map_active,
			'lineage_cache_recent'  => $lineage_cache_recent,
			'group_to_topic_map_recent' => $group_to_topic_map_recent,
			'question_to_group_map_recent' => $question_to_group_map_recent,
			'accuracy_stats'        => $accuracy_stats,
		];
	}

	/**
	 * Gathers profile data for the dashboard profile tab.
	 * (Keep this helper method as is - no changes needed here)
	 *
	 * @param int $user_id The ID of the user.
	 * @return array An array containing profile details.
	 */
	public static function get_profile_data( $user_id ) {
		$user_info = get_userdata( $user_id );
		if ( ! $user_info ) {
			return [ // Return default empty values if user not found
				'display_name'          => 'User Not Found',
				'email'                 => '',
				'avatar_url'            => get_avatar_url( 0 ), // Default avatar
				'scope_description'     => 'N/A',
				'allowed_subjects_list' => [],
				'allowed_exams_list'    => [],
			];
		}
		$custom_avatar_id = get_user_meta( $user_id, '_qp_avatar_attachment_id', true );
		$avatar_url       = '';
		if ( ! empty( $custom_avatar_id ) ) {
			$avatar_url = wp_get_attachment_image_url( absint( $custom_avatar_id ), 'thumbnail' );
		}
		if ( empty( $avatar_url ) ) {
			$avatar_url = get_avatar_url( $user_id, [ 'size' => 128, 'default' => 'mystery' ] );
		}
		$scope_description       = 'All Subjects & Exams';
		$allowed_subjects_list   = [];
		$allowed_exams_list      = [];
		$allowed_subject_ids_or_all = User_Access::get_allowed_subject_ids( $user_id );
		if ( $allowed_subject_ids_or_all !== 'all' ) {
			global $wpdb;
			$term_table            = $wpdb->prefix . 'qp_terms';
			$allowed_subject_ids   = $allowed_subject_ids_or_all;
			if ( ! empty( $allowed_subject_ids ) ) {
				$subj_ids_placeholder  = implode( ',', array_map( 'absint', $allowed_subject_ids ) );
				$allowed_subjects_list = $wpdb->get_col( "SELECT name FROM {$term_table} WHERE term_id IN ($subj_ids_placeholder) AND parent = 0 ORDER BY name ASC" );
			}
			$direct_exams_json = get_user_meta( $user_id, '_qp_allowed_exam_term_ids', true );
			$direct_exam_ids   = json_decode( $direct_exams_json, true );
			if ( ! is_array( $direct_exam_ids ) ) {
				$direct_exam_ids = [];
			}
			$final_allowed_exam_ids = array_map( 'absint', $direct_exam_ids );
			if ( ! empty( $final_allowed_exam_ids ) ) {
				$exam_ids_placeholder = implode( ',', $final_allowed_exam_ids );
				$allowed_exams_list   = $wpdb->get_col( "SELECT name FROM {$term_table} WHERE term_id IN ($exam_ids_placeholder) ORDER BY name ASC" );
			}
			if ( empty( $allowed_subjects_list ) && empty( $allowed_exams_list ) ) {
				$scope_description = 'No specific scope assigned.';
			} else {
				$scope_parts = [];
				if ( ! empty( $allowed_exams_list ) ) {
					$scope_parts[] = 'Allowed Exams: ' . implode( ', ', array_map( 'esc_html', $allowed_exams_list ) );
				}
				if ( ! empty( $allowed_subjects_list ) ) {
					$scope_parts[] = 'Accessible Subjects: ' . implode( ', ', array_map( 'esc_html', $allowed_subjects_list ) );
				}
				$scope_description = implode( '; ', $scope_parts );
			}
		}
		return [
			'display_name'          => $user_info->display_name,
			'email'                 => $user_info->user_email,
			'avatar_url'            => $avatar_url,
			'scope_description'     => $scope_description,
			'allowed_subjects_list' => $allowed_subjects_list,
			'allowed_exams_list'    => $allowed_exams_list,
		];
	}


    /**
	 * NEW HELPER: Prefetches lineage data needed for session lists.
	 */
	public static function prefetch_lineage_data( $sessions ) {
		global $wpdb;
		$all_session_qids = [];
		foreach ( $sessions as $session ) {
			$qids = json_decode( $session->question_ids_snapshot, true );
			if ( is_array( $qids ) ) {
				$all_session_qids = array_merge( $all_session_qids, $qids );
			}
		}

		$lineage_cache         = [];
		$group_to_topic_map    = [];
		$question_to_group_map = [];

		if ( ! empty( $all_session_qids ) ) {
			$unique_qids = array_unique( array_map( 'absint', $all_session_qids ) );
			if ( empty( $unique_qids ) ) {
				return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ]; // Avoid empty IN clause
			}

			$qids_placeholder = implode( ',', $unique_qids );

			$tax_table       = $wpdb->prefix . 'qp_taxonomies';
			$term_table      = $wpdb->prefix . 'qp_terms';
			$rel_table       = $wpdb->prefix . 'qp_term_relationships';
			$questions_table = $wpdb->prefix . 'qp_questions';
			$subject_tax_id  = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'" );

			$q_to_g_results = $wpdb->get_results( "SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)" );
			foreach ( $q_to_g_results as $res ) {
				$question_to_group_map[ $res->question_id ] = $res->group_id;
			}

			$all_group_ids = array_unique( array_values( $question_to_group_map ) );
			if ( empty( $all_group_ids ) ) {
				return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ]; // Avoid empty IN clause
			}

			$group_ids_placeholder = implode( ',', $all_group_ids );

			$g_to_t_results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.object_id, r.term_id
				 FROM {$rel_table} r JOIN {$term_table} t ON r.term_id = t.term_id
				 WHERE r.object_type = 'group' AND r.object_id IN ($group_ids_placeholder) AND t.taxonomy_id = %d",
					$subject_tax_id
				)
			);
			foreach ( $g_to_t_results as $res ) {
				$group_to_topic_map[ $res->object_id ] = $res->term_id;
			}

			// Pre-populate lineage cache for all topics found
			$all_topic_ids = array_unique( array_values( $group_to_topic_map ) );
			if ( ! empty( $all_topic_ids ) ) {
				foreach ( $all_topic_ids as $topic_id ) {
					if ( ! isset( $lineage_cache[ $topic_id ] ) ) {
						$current_term_id   = $topic_id;
						$root_subject_name = 'N/A';
						for ( $i = 0; $i < 10; $i++ ) {
							$term = $wpdb->get_row( $wpdb->prepare( "SELECT name, parent FROM $term_table WHERE term_id = %d", $current_term_id ) );
							if ( ! $term || $term->parent == 0 ) {
								$root_subject_name = $term ? $term->name : 'N/A';
								break;
							}
							$current_term_id = $term->parent;
						}
						$lineage_cache[ $topic_id ] = $root_subject_name;
					}
				}
			}
		}
		return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ];
	}
    
    /**
	 * NEW HELPER: Determines the display name for a session's mode.
	 */
	public static function get_session_mode_name( $session, $settings ) {
		$mode = 'Practice'; // Default
		if ( $session->status === 'paused' ) {
			$mode = 'Paused Session';
		} elseif ( isset( $settings['practice_mode'] ) ) {
			switch ( $settings['practice_mode'] ) {
				case 'revision':
					$mode = 'Revision';
					break;
				case 'mock_test':
					$mode = 'Mock Test';
					break;
				case 'Incorrect Que. Practice':
					$mode = 'Incorrect Practice';
					break;
				case 'Section Wise Practice':
					$mode = 'Section Practice';
					break;
			}
		} elseif ( isset( $settings['subject_id'] ) && $settings['subject_id'] === 'review' ) {
			$mode = 'Review Session';
		}
		return $mode;
	}
    
    /**
	 * NEW HELPER: Gets the subject display string for a session.
	 */
	public static function get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map ) {
		global $wpdb;
		$term_table = $wpdb->prefix . 'qp_terms';

		$session_qids     = json_decode( $session->question_ids_snapshot, true );
		$subjects_display = 'N/A';

		if ( is_array( $session_qids ) && ! empty( $session_qids ) ) {
			$mode = self::get_session_mode_name( $session, $settings ); // Use the mode helper

			if ( $mode === 'Section Practice' ) {
				// Get source hierarchy for the first question
				$first_question_id = $session_qids[0];
				$source_hierarchy  = Terms_DB::get_source_hierarchy_for_question( $first_question_id ); // Assumes this function exists globally
				$subjects_display  = ! empty( $source_hierarchy ) ? implode( ' / ', $source_hierarchy ) : 'N/A';
			} else {
				$session_subjects = [];
				foreach ( $session_qids as $qid ) {
					$gid      = $question_to_group_map[ $qid ] ?? null;
					$topic_id = $gid ? ( $group_to_topic_map[ $gid ] ?? null ) : null;
					if ( $topic_id && isset( $lineage_cache[ $topic_id ] ) ) {
						$session_subjects[] = $lineage_cache[ $topic_id ];
					}
				}
				$subjects_display = ! empty( $session_subjects ) ? implode( ', ', array_unique( array_filter( $session_subjects, fn( $s ) => $s !== 'N/A' ) ) ) : 'N/A';
				if ( empty( $subjects_display ) ) {
					$subjects_display = 'N/A';
				}
			}
		}
		return $subjects_display;
	}
    
    /**
	 * NEW HELPER: Gets the result display string for a session.
	 */
	public static function get_session_result_display( $session, $settings ) {
		if ( $session->status === 'paused' ) {
			return '-'; // No result for paused
		}

		$is_scored = isset( $settings['marks_correct'] );
		if ( $is_scored ) {
			return number_format( (float) $session->marks_obtained, 1 ) . ' Score';
		} else {
			$total_attempted = (int) $session->correct_count + (int) $session->incorrect_count;
			// Calculate accuracy
			$accuracy = ( $total_attempted > 0 ) ? ( ( (int) $session->correct_count / $total_attempted ) * 100 ) : 0;
			// Format to two decimal places and add '%'
			return number_format( $accuracy, 2 ) . '%';
		}
	}
}