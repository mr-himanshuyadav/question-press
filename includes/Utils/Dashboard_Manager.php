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
     * NEW: Gathers all data for a specific session review (for Web and API).
     *
     * @param int $session_id The ID of the session.
     * @param int $user_id    The ID of the current user.
     * @return array|null An associative array of all review data, or null if session not found.
     */
    public static function get_session_review_data( $session_id, $user_id ) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $attempts_table = $wpdb->prefix . 'qp_user_attempts';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $options_table = $wpdb->prefix . 'qp_options';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $review_table = $wpdb->prefix . 'qp_review_later';
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // 1. Fetch Session
        $session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions_table} WHERE session_id = %d AND user_id = %d", $session_id, $user_id ) );
        if ( ! $session ) {
            return null; // Not found or no permission
        }

        $settings = json_decode( $session->settings_snapshot, true );

        // 2. Get all attempts and question data
        $attempts_raw = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                a.attempt_id, a.question_id, a.selected_option_id, a.is_correct, a.mock_status, a.status as attempt_status,
                q.question_text,q.explanation_text, q.question_number_in_section,
                g.group_id, g.direction_text
            FROM {$attempts_table} a
            JOIN {$questions_table} q ON a.question_id = q.question_id
            LEFT JOIN {$groups_table} g ON q.group_id = g.group_id
            WHERE a.session_id = %d
            ORDER BY a.attempt_id ASC",
                $session_id
            )
        );

        $attempted_question_ids = wp_list_pluck( $attempts_raw, 'question_id' );
        if ( empty( $attempted_question_ids ) ) {
            $attempted_question_ids = [0]; // Prevent empty IN clause
        }
        $ids_placeholder = implode( ',', array_map( 'absint', $attempted_question_ids ) );

        // 3. Get all options for these questions
        $all_options_raw = $wpdb->get_results( "SELECT question_id, option_id, option_text, is_correct FROM {$options_table} WHERE question_id IN ($ids_placeholder)" );
        $all_options = [];
        foreach ( $all_options_raw as $option ) {
            $all_options[ $option->question_id ][] = $option;
        }

        // 4. Get lineage helper function
        $lineage_cache = [];
        if ( ! function_exists( __NAMESPACE__ . '\get_term_lineage' ) ) {
            function get_term_lineage( $term_id, &$lineage_cache, $wpdb ) {
                if ( isset( $lineage_cache[ $term_id ] ) ) {
                    return $lineage_cache[ $term_id ];
                }
                $lineage    = [];
                $current_id = $term_id;
                for ( $i = 0; $i < 10; $i++ ) {
                    if ( ! $current_id ) {
                        break;
                    }
                    $term = $wpdb->get_row( $wpdb->prepare( "SELECT name, parent FROM {$wpdb->prefix}qp_terms WHERE term_id = %d", $current_id ) );
                    if ( $term ) {
                        array_unshift( $lineage, $term->name );
                        $current_id = $term->parent;
                    } else {
                        break;
                    }
                }
                $lineage_cache[ $term_id ] = $lineage;
                return $lineage;
            }
        }

        // 5. Get taxonomy IDs
        $subject_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'" );
        $source_tax_id = $wpdb->get_var( "SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'" );

        // 6. Pre-fetch term relationships for all groups
        $all_group_ids = array_unique( wp_list_pluck( $attempts_raw, 'group_id' ) );
        $term_rels = [];
        if ( ! empty( $all_group_ids ) ) {
            $group_ids_placeholder = implode( ',', array_map( 'absint', $all_group_ids ) );
            $rel_results = $wpdb->get_results( "SELECT object_id, term_id FROM {$rel_table} WHERE object_type = 'group' AND object_id IN ($group_ids_placeholder)" );
            $all_term_ids = wp_list_pluck( $rel_results, 'term_id' );
            
            // Pre-fetch term taxonomies
            $term_taxonomies = [];
            if (!empty($all_term_ids)) {
                $term_ids_placeholder = implode(',', array_map('absint', $all_term_ids));
                $term_tax_results = $wpdb->get_results("SELECT term_id, taxonomy_id FROM {$term_table} WHERE term_id IN ($term_ids_placeholder)", OBJECT_K);
                $term_taxonomies = wp_list_pluck($term_tax_results, 'taxonomy_id', 'term_id');
            }

            foreach ( $rel_results as $rel ) {
                $term_id = $rel->term_id;
                $tax_id = $term_taxonomies[$term_id] ?? 0;
                if ( $tax_id == $subject_tax_id ) {
                    $term_rels[ $rel->object_id ]['subject'] = $term_id;
                } elseif ( $tax_id == $source_tax_id ) {
                    $term_rels[ $rel->object_id ]['source'] = $term_id;
                }
            }
        }

        // 7. Get other metadata
        $reported_qids_for_user = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$reports_table} WHERE user_id = %d AND status = 'open'", $user_id ) );
        $review_later_qids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$review_table} WHERE user_id = %d AND question_id IN ($ids_placeholder)", $user_id ) );
        $review_lookup = array_flip( $review_later_qids );

        // 8. Process all attempts into the final "questions" array
        $questions = [];
        foreach ( $attempts_raw as $attempt ) {
            $question_id = $attempt->question_id;
            $group_id = $attempt->group_id;
            $options = $all_options[ $question_id ] ?? [];
            
            $selected_answer_text = '';
            $correct_answer_text = '';
            $correct_answer_keys = [];
            $app_options = [];

            foreach ( $options as $option ) {
                if ( $option->is_correct ) {
                    $correct_answer_text = $option->option_text; // For web
                }
                if ( $option->option_id == $attempt->selected_option_id ) {
                    $selected_answer_text = $option->option_text; // For web
                }
            }

            // Get lineage
            $subject_term_id = $term_rels[ $group_id ]['subject'] ?? null;
            $source_term_id = $term_rels[ $group_id ]['source'] ?? null;
            $subject_lineage = $subject_term_id ? get_term_lineage( $subject_term_id, $lineage_cache, $wpdb ) : [];
            $source_lineage = $source_term_id ? get_term_lineage( $source_term_id, $lineage_cache, $wpdb ) : [];

            $questions[] = (object) [ // Use (object) to match the web template's $attempt->
                // Web-specific fields
                'attempt_id' => $attempt->attempt_id,
                'selected_option_id' => $attempt->selected_option_id, // For $is_skipped check
                'selected_answer' => $selected_answer_text,
                'correct_answer' => $correct_answer_text,
                'mock_status' => $attempt->mock_status,
                'direction_text' => $attempt->direction_text,
                'question_number_in_section' => $attempt->question_number_in_section,
                'subject_lineage' => $subject_lineage,
                'source_lineage' => $source_lineage,
                'options' => $options, // Pass raw options for web template

                // App-specific fields
                'question_id' => $question_id,
                'question_text' => $attempt->question_text,
                'explanation' => $attempt->explanation_text,
                'app_options' => $app_options,
                
                // Shared fields
                'is_correct' => ( $attempt->attempt_status === 'answered' ) ? (bool) $attempt->is_correct : null,
                'is_marked_for_review' => isset( $review_lookup[ $question_id ] ),
            ];
        }

        // 9. Calculate final summary stats
        $total_questions = count( $questions );
        $accuracy = ( $session->total_attempted > 0 ) ? ( (int) $session->correct_count / (int) $session->total_attempted ) * 100 : 0;
        $avg_time_per_question = 'N/A';
        if ( $session->total_attempted > 0 && isset( $session->total_active_seconds ) ) {
            $avg_seconds = round( $session->total_active_seconds / $session->total_attempted );
            $avg_time_per_question = sprintf( '%02d:%02d', floor( $avg_seconds / 60 ), $avg_seconds % 60 );
        }
        $marks_correct = $settings['marks_correct'] ?? 1;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;
        $total_marks = 0;
        if ( isset( $settings['marks_correct'] ) ) {
             $total_marks = $total_questions * (float) $marks_correct;
        }

        // 10. Check if course item was deleted
        $is_course_item_deleted = false;
        if ( isset( $settings['course_id'] ) && isset( $settings['item_id'] ) ) {
            $items_table = $wpdb->prefix . 'qp_course_items';
            $item_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$items_table} WHERE item_id = %d AND course_id = %d", absint( $settings['item_id'] ), absint( $settings['course_id'] ) ) );
            if ( ! $item_exists ) {
                $is_course_item_deleted = true;
            }
        }
        
        // 11. Get Mode and Topic List
        // (Copied from your original function)
        $topics_in_session = [];
        if (! empty($all_group_ids)) {
             $group_ids_placeholder = implode(',', $all_group_ids);
             $topics_in_session = $wpdb->get_col("
                SELECT DISTINCT t.name
                FROM {$term_table} t
                JOIN {$rel_table} r ON t.term_id = r.term_id
                WHERE r.object_id IN ($group_ids_placeholder)
                  AND r.object_type = 'group'
                  AND t.taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject')
                ORDER BY t.name ASC
            ");
        }
        
        // (Copied from your original function)
        $is_mock_test = isset( $settings['practice_mode'] ) && $settings['practice_mode'] === 'mock_test';
        $is_section_wise_practice = isset( $settings['practice_mode'] ) && $settings['practice_mode'] === 'Section Wise Practice';
        $mode_class = 'mode-normal';
        $mode = 'Practice';

        if ( $is_mock_test ) {
            if ( isset( $settings['course_id'] ) && $settings['course_id'] > 0 && isset( $settings['item_id'] ) && $settings['item_id'] > 0 ) {
                $course_id = absint( $settings['course_id'] );
                $item_id = absint( $settings['item_id'] );
                $course_title = get_the_title( $course_id );
                $items_table = $wpdb->prefix . 'qp_course_items';
                $sections_table = $wpdb->prefix . 'qp_course_sections';
                $item_info = $wpdb->get_row( $wpdb->prepare( "SELECT i.title AS item_title, s.title AS section_title FROM {$items_table} i LEFT JOIN {$sections_table} s ON i.section_id = s.section_id WHERE i.item_id = %d", $item_id ) );
                $item_title = $item_info ? $item_info->item_title : null;
                $section_title = $item_info ? $item_info->section_title : null;
                $mode = 'Course Test';
                $name_parts = [];
                if ( $course_title ) $name_parts[] = esc_html( $course_title );
                if ( $section_title ) $name_parts[] = esc_html( $section_title );
                if ( $item_title ) $name_parts[] = esc_html( $item_title );
                if ( ! empty( $name_parts ) ) {
                    $mode = implode( ' / ', $name_parts );
                } elseif ( $item_title ) {
                    $mode = esc_html( $item_title );
                }
                $mode_class = 'mode-course-test';
            } else {
                $mode_class = 'mode-mock-test';
                $mode = 'Mock Test';
            }
        } elseif ( isset( $settings['practice_mode'] ) ) {
            switch ( $settings['practice_mode'] ) {
                case 'revision':
                    $mode_class = 'mode-revision';
                    $mode = 'Revision Mode';
                    break;
                case 'Incorrect Que. Practice':
                    $mode_class = 'mode-incorrect';
                    $mode = 'Incorrect Practice';
                    break;
                case 'Section Wise Practice':
                    $mode_class = 'mode-section-wise';
                    $mode = 'Section Wise Practice';
                    break;
            }
        } elseif ( isset( $settings['subject_id'] ) && $settings['subject_id'] === 'review' ) {
            $mode_class = 'mode-review';
            $mode = 'Review Mode';
        }

        // 12. Assemble the final package
        return [
            // Summary Stats
            'session' => $session, // Pass the raw session object for the template
            'accuracy' => $accuracy,
            'avg_time_per_question' => $avg_time_per_question,
            'marks_correct' => $marks_correct,
            'marks_incorrect' => $marks_incorrect,
            'topics_in_session' => $topics_in_session,
            
            // Mode and Context
            'mode' => $mode,
            'mode_class' => $mode_class,
            'is_course_item_deleted' => $is_course_item_deleted,
            'is_section_wise_practice' => $is_section_wise_practice,
            'reported_qids_for_user' => $reported_qids_for_user,
            
            // Session Content
            'questions' => $questions, // This is the new "attempts" array
            'settings' => $settings,

            // App-specific Summary (built from above)
            'app_summary' => [
                'session_id' => $session->session_id,
                'status' => $session->status,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'total_questions' => $total_questions,
                'correct_count' => (int) $session->correct_count,
                'incorrect_count' => (int) $session->incorrect_count,
                'skipped_count' => (int) $session->skipped_count + (int) $session->not_viewed_count,
                'marks_obtained' => (float) $session->marks_obtained,
                'total_marks' => (float) $total_marks,
                'overall_accuracy' => (float) $accuracy,
            ]
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

	/**
	 * HELPER: Gets mode-specific details for a history session.
     * (Moved from history.php template)
	 */
    public static function qp_get_history_mode_details( $session, $settings ) {
        $details = [
            'icon'  => 'dashicons-edit',
            'class' => 'mode-normal',
            'label' => 'Practice',
        ];

        if ( isset( $settings['practice_mode'] ) && $settings['practice_mode'] === 'mock_test' ) {
            if ( isset( $settings['course_id'] ) && $settings['course_id'] > 0 ) {
                $details = [
                    'icon'  => 'dashicons-welcome-learn-more',
                    'class' => 'mode-course-test',
                    'label' => 'Course Test', // Will show "Course / Section / Item"
                ];
            } else {
                $details = [
                    'icon'  => 'dashicons-analytics',
                    'class' => 'mode-mock-test',
                    'label' => self::get_session_mode_name( $session, $settings ), // Use existing helper
                ];
            }
        } elseif ( isset( $settings['practice_mode'] ) ) {
            $mode_map = [
                'revision'                => [ 'icon' => 'dashicons-backup', 'class' => 'mode-revision', 'label' => 'Revision' ],
                'Incorrect Que. Practice' => [ 'icon' => 'dashicons-warning', 'class' => 'mode-incorrect', 'label' => 'Incorrect Practice' ],
                'Section Wise Practice'   => [ 'icon' => 'dashicons-layout', 'class' => 'mode-section-wise', 'label' => 'Section Practice' ],
            ];
            if ( isset( $mode_map[ $settings['practice_mode'] ] ) ) {
                $details = $mode_map[ $settings['practice_mode'] ];
            } else {
                 if ( $settings['practice_mode'] !== 'normal' ) {
                             $details['label'] = $settings['practice_mode'];
                        }
            }
        } elseif ( isset( $settings['subject_id'] ) && $settings['subject_id'] === 'review' ) {
            $details = [
                'icon'  => 'dashicons-book-alt',
                'class' => 'mode-review',
                'label' => 'Review Session',
            ];
        }

        return $details;
    }

    /**
	 * HELPER: Gets the context display (subjects or course name) for a session.
     * (Moved from history.php template)
	 */
    public static function get_session_context_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map, $existing_course_item_ids = [] ) {
        
        $mode_details = self::qp_get_history_mode_details($session, $settings);
        $context_display = '';
        
        if ( $mode_details['class'] === 'mode-course-test' ) {
            global $wpdb; // Make sure $wpdb is available
            $course_id = absint($settings['course_id']);
            $item_id = absint($settings['item_id']);
            
            $course_title = get_the_title($course_id);
            
            // Fetch Item and Section Title
            $items_table = $wpdb->prefix . 'qp_course_items';
            $sections_table = $wpdb->prefix . 'qp_course_sections';
            $item_info = $wpdb->get_row($wpdb->prepare(
                "SELECT i.title AS item_title, s.title AS section_title
                 FROM {$items_table} i
                 LEFT JOIN {$sections_table} s ON i.section_id = s.section_id
                 WHERE i.item_id = %d",
                $item_id
            ));

            $item_title = $item_info ? $item_info->item_title : null;
            $section_title = $item_info ? $item_info->section_title : null;

            // Build the new name string
            $name_parts = [];
            if ($course_title) $name_parts[] = esc_html($course_title);
            if ($section_title) $name_parts[] = esc_html($section_title);
            if ($item_title) $name_parts[] = esc_html($item_title);

            if (!empty($name_parts)) {
                $context_display = implode(' / ', $name_parts);
            }
        } else {
            // --- Original logic for non-course tests ---
            $context_display = self::get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map );
        }
        
        // Check for deleted course item
        if ( isset( $settings['course_id'] ) && isset( $settings['item_id'] ) ) {
            if ( ! empty($existing_course_item_ids) && ! isset( $existing_course_item_ids[ absint( $settings['item_id'] ) ] ) ) {
                $context_display .= ' <em style="color:#777; font-size:0.9em;">(Item removed)</em>';
            }
        }

        return $context_display;
    }

	/**
	 * Gathers all data required for the dashboard history tab.
	 *
	 * @param int $user_id The ID of the current user.
	 * @return array An associative array containing all history data.
	 */
	public static function get_history_data( $user_id ): array {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';
		$attempts_table = $wpdb->prefix . 'qp_user_attempts';
		$items_table    = $wpdb->prefix . 'qp_course_items';

		$options           = get_option( 'qp_settings' );
		$session_page_url  = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
		$review_page_url   = isset( $options['review_page'] ) ? get_permalink( $options['review_page'] ) : home_url( '/' );
		$practice_page_url = isset( $options['practice_page'] ) ? get_permalink( $options['practice_page'] ) : home_url( '/' );

		$user          = wp_get_current_user();
		$user_roles    = (array) $user->roles;
		$allowed_roles = isset( $options['can_delete_history_roles'] ) ? $options['can_delete_history_roles'] : [ 'administrator' ];
		$can_delete    = ! empty( array_intersect( $user_roles, $allowed_roles ) );
		$can_terminate = isset($options['allow_session_termination']) && $options['allow_session_termination'] === '1';

		// Fetch Paused Sessions
		$paused_sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $sessions_table WHERE user_id = %d AND status = 'paused' ORDER BY start_time DESC",
				$user_id
			)
		);

		// Fetch Completed/Abandoned Sessions
		$session_history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC",
				$user_id
			)
		);

		$all_sessions_for_history = array_merge( $paused_sessions, $session_history );

		// Pre-fetch lineage data for BOTH lists
		list($lineage_cache_paused, $group_to_topic_map_paused, $question_to_group_map_paused) = self::prefetch_lineage_data( $paused_sessions );
		list($lineage_cache_completed, $group_to_topic_map_completed, $question_to_group_map_completed) = self::prefetch_lineage_data( $session_history );

		// Fetch accuracy stats
		$session_ids_history = wp_list_pluck( $all_sessions_for_history, 'session_id' );
		$accuracy_stats      = [];
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

		// Pre-fetch existing course item IDs
		$existing_course_item_ids = $wpdb->get_col( "SELECT item_id FROM $items_table" );
		$existing_course_item_ids = array_flip( $existing_course_item_ids ); // Convert to hash map

		// Prepare arguments for the template
		return [
			'practice_page_url'     => $practice_page_url,
			'can_delete'            => $can_delete,
			'can_terminate'         => $can_terminate,
			'session_page_url'      => $session_page_url,
			'review_page_url'       => $review_page_url,
			'existing_course_item_ids' => $existing_course_item_ids,
			'accuracy_stats'        => $accuracy_stats,

			// Pass Paused Sessions and their data
			'paused_sessions'       => $paused_sessions,
			'lineage_cache_paused'  => $lineage_cache_paused,
			'group_to_topic_map_paused' => $group_to_topic_map_paused,
			'question_to_group_map_paused' => $question_to_group_map_paused,

			// Pass Completed Sessions and their data
			'completed_sessions'    => $session_history,
			'lineage_cache_completed' => $lineage_cache_completed,
			'group_to_topic_map_completed' => $group_to_topic_map_completed,
			'question_to_group_map_completed' => $question_to_group_map_completed,
		];
	}
}