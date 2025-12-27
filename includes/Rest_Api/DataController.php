<?php

namespace QuestionPress\Rest_Api; // PSR-4 Namespace

use QuestionPress\Utils\Dashboard_Manager;
use QuestionPress\Frontend\Dashboard;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;
use QuestionPress\Database\Terms_DB; // Use our DB class
use QuestionPress\Utils\User_Access; // For course access checks

/**
 * Handles REST API requests for retrieving data (subjects, topics, etc.).
 */
class DataController
{

    /**
     * Callback to get all subjects.
     */
    public static function get_subjects()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $subject_tax_id = Terms_DB::get_taxonomy_id_by_name('subject');

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all topics.
     */
    public static function get_topics()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $subject_tax_id = Terms_DB::get_taxonomy_id_by_name('subject');

        if (!$subject_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0 ORDER BY name ASC",
            $subject_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all exams.
     */
    public static function get_exams()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $exam_tax_id = Terms_DB::get_taxonomy_id_by_name('exam');

        if (!$exam_tax_id) {
            return new WP_REST_Response([], 200); // Return empty if taxonomy doesn't exist
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id as exam_id, name as exam_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $exam_tax_id
        ));

        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all sources and sections.
     */
    public static function get_sources()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        $source_tax_id = Terms_DB::get_taxonomy_id_by_name('source');

        if (!$source_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id AS source_id, name AS source_name, parent AS parent_id FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
            $source_tax_id
        ));
        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all labels.
     */
    public static function get_labels()
    {
        global $wpdb;
        $tax_table = Terms_DB::get_taxonomies_table_name();
        $term_table = Terms_DB::get_terms_table_name();
        $meta_table = Terms_DB::get_term_meta_table_name();

        $label_tax_id = Terms_DB::get_taxonomy_id_by_name('label');

        if (!$label_tax_id) {
            return new WP_REST_Response([], 200);
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id as label_id, t.name as label_name, m.meta_value as label_color
             FROM {$term_table} t
             LEFT JOIN {$meta_table} m ON t.term_id = m.term_id AND m.meta_key = 'color'
             WHERE t.taxonomy_id = %d 
             ORDER BY t.name ASC",
            $label_tax_id
        ));

        return new WP_REST_Response($results, 200);
    }

    /**
     * Callback to get all published qp_course posts.
     */
    public static function get_courses()
    {
        $courses = get_posts([
            'post_type' => 'qp_course',
            'post_status' => 'publish',
            'numberposts' => -1, // Get all courses
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if (empty($courses)) {
            return new \WP_REST_Response([], 200);
        }

        // Format the data for the app
        $formatted_courses = [];
        foreach ($courses as $course_post) {
            $formatted_courses[] = [
                'id' => $course_post->ID,
                'title' => $course_post->post_title,
                // You can add more data here if you need it in the app
                // 'thumbnail_url' => get_the_post_thumbnail_url($course_post->ID, 'medium'),
            ];
        }

        return new \WP_REST_Response($formatted_courses, 200);
    }
    
    /**
     * Callback to get the details for a single qp_course.
     * (REVISED to match the app's detailed course/[id].tsx interfaces)
     */
    public static function get_course_details(\WP_REST_Request $request)
    {
        $course_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if ( ! $course_id ) {
            return new \WP_Error('rest_invalid_id', 'Invalid course ID.', ['status' => 400]);
        }

        // 1. Call the centralized data function. It has all the data we need.
        $data = Dashboard_Manager::get_course_structure_data( $course_id, $user_id );

        // 2. Handle errors
        if ( is_null( $data ) ) {
            return new \WP_Error('rest_forbidden', 'Course not found or you do not have access.', ['status' => 403]);
        }

        // 3. Process data to match the app's interfaces (CourseDetails, CourseSection, CourseItem)
        $processed_sections = [];
        $is_previous_item_complete = true; // For progression logic

        if ( ! empty( $data['sections'] ) ) {
            foreach ( $data['sections'] as $section ) {
                $processed_items = [];
                
                // Get the items for this section
                $items_in_section = $data['items_by_section'][ $section->section_id ] ?? [];
                
                if ( ! empty( $items_in_section ) ) {
                    foreach ( $items_in_section as $item ) {
                        $item_id = $item->item_id;
                        
                        // Get progress for this item
                        $progress = $data['progress_data'][ $item_id ] ?? [
                            'status'        => 'not_started',
                            'session_id'    => null,
                            'attempt_count' => 0,
                        ];
                        
                        // Progression logic
                        $is_locked = false;
                        if ( $data['is_progressive'] && ! $is_previous_item_complete ) {
                            $is_locked = true;
                        }

                        // Retake logic
                        $retake_limit = $data['retake_limit'];
                        $attempt_count = (int) $progress['attempt_count'];
                        $can_retake = false;
                        $retakes_left = null;

                        if ( $data['allow_retakes'] ) {
                            if ( $retake_limit === 0 ) {
                                $can_retake = true; // Unlimited retakes
                                $retakes_left = null; // App should interpret null as '∞'
                            } else {
                                $retakes_left = $retake_limit - $attempt_count;
                                if ( $retakes_left > 0 ) {
                                    $can_retake = true;
                                }
                            }
                        }

                        // Build the final CourseItem object
                        $processed_items[] = [
                            'item_id'       => $item_id,
                            'title'         => $item->title,
                            'content_type'  => $item->content_type,
                            'status'        => $progress['status'],
                            'session_id'    => $progress['session_id'],
                            'attempt_count' => $attempt_count,
                            'is_locked'     => $is_locked,
                            'retake_limit'  => $retake_limit,
                            'can_retake'    => $can_retake,
                            'retakes_left'  => $retakes_left,
                        ];
                        
                        // Update progression for next loop iteration
                        if ( $data['is_progressive'] ) {
                            $is_previous_item_complete = ($progress['status'] === 'completed');
                        }
                    } // end foreach item
                } // end if items

                // Build the final CourseSection object
                $processed_sections[] = [
                    'section_id'  => $section->section_id,
                    'title'       => $section->title,
                    'description' => $section->description,
                    'items'       => $processed_items,
                ];
            } // end foreach section
        } // end if sections

        // 4. Determine enrollment status
        // We check if the 'back_url' (which we already calculated) goes to 'my-courses'
        $is_enrolled = ( strpos( $data['back_url'], 'my-courses' ) !== false );

        // 5. Assemble final response for the app
        $api_response_data = [
            'course_id'        => $data['course_post']->ID,
            'title'            => $data['course_post']->post_title,
            'description'      => $data['course_post']->post_content, // Map 'content' to 'description'
            'sections'         => $processed_sections, // Use 'sections' key
            'is_enrolled'      => $is_enrolled,
            'allow_retakes'    => $data['allow_retakes'],
            'progression_mode' => $data['is_progressive'] ? 'progressive' : 'free',
        ];

        // Wrap the response in our standard { success: true, data: ... } structure
        return new \WP_REST_Response( [ 'success' => true, 'data' => $api_response_data ], 200 );
    }

    /**
     * Gets the data manifest for a specific session (active or completed).
     * - If 'active' or 'paused', it returns the practice manifest.
     * - If 'completed' or 'abandoned', it returns the final results summary.
     */
    public static function get_session_results(\WP_REST_Request $request)
    {
        $session_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if (! $session_id) {
            return new \WP_Error('rest_invalid_id', 'Invalid session ID.', ['status' => 400]);
        }

        // 1. Check the session status
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'qp_user_sessions';
        $session_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$sessions_table} WHERE session_id = %d AND user_id = %d",
            $session_id, $user_id
        ));

        if (! $session_status) {
             return new \WP_Error('rest_not_found', 'Session not found or permission denied.', ['status' => 404]);
        }

        // 2. Branch the logic
        if ($session_status === 'active' || $session_status === 'paused') {
            // --- SESSION IS ACTIVE ---
            // Call our centralized function from Practice_Manager to get the manifest
            $session_manifest = \QuestionPress\Utils\Practice_Manager::get_active_session_data($session_id, $user_id);
            
            if (is_wp_error($session_manifest)) {
                return $session_manifest;
            }
            
            // The app's interface expects a 'questions' key, but it will be empty.
            // We will add it here to prevent 'undefined' errors in the app.
            $session_manifest['questions'] = []; // App will need to fetch these

            return new \WP_REST_Response(['success' => true, 'data' => $session_manifest], 200);

        } else {
            // --- SESSION IS COMPLETED ---
            // Get final results (the original purpose of this function)
            $results = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    session_id, status, total_attempted, correct_count, 
                    incorrect_count, skipped_count, marks_obtained 
                 FROM {$sessions_table} 
                 WHERE session_id = %d",
                $session_id
            ), ARRAY_A); 

            if (! $results) {
                return new \WP_Error('rest_no_data', 'Could not retrieve session results.', ['status' => 500]);
            }

            return new \WP_REST_Response(['success' => true, 'data' => $results], 200);
        }
    }

    /**
     * Callback to get the data for the main overview dashboard.
     * (REVISED TO FIX DATA MISMATCH)
     */
    public static function get_dashboard_overview( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // 1. Call the correct data-fetching function from Dashboard.php
        $data = Dashboard_Manager::get_overview_data($user_id); //

        if (is_wp_error($data)) {
            return $data; // Pass the WP_Error object on failure
        }

        // 2. Process Active Sessions into the format the app expects
        $processed_active_sessions = [];
        foreach($data['active_sessions'] as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            $processed_active_sessions[] = [
                'session_id' => (int) $session->session_id,
                'start_time' => $session->start_time,
                'status' => $session->status,
                'mode_name' => Dashboard_Manager::get_session_mode_name($session, $settings), //
                'subjects_display' => Dashboard_Manager::get_session_subjects_display($session, $settings, $data['lineage_cache_active'], $data['group_to_topic_map_active'], $data['question_to_group_map_active']), //
                'result_display' => '-', // Active sessions don't have a result
            ];
        }

        // 3. Process Recent History into the format the app expects
        // THIS IS THE CRITICAL FIX FOR YOUR RECENT HISTORY
        $processed_recent_history = [];
        foreach($data['recent_history'] as $session) { //
            $settings = json_decode($session->settings_snapshot, true);
            $processed_recent_history[] = [ //
                'session_id' => (int) $session->session_id,
                'start_time' => $session->start_time,
                'status' => $session->status,
                'mode_name' => Dashboard_Manager::get_session_mode_name($session, $settings), //
                'subjects_display' => Dashboard_Manager::get_session_subjects_display($session, $settings, $data['lineage_cache_recent'], $data['group_to_topic_map_recent'], $data['question_to_group_map_recent']), //
                'result_display' => $data['accuracy_stats'][$session->session_id] ?? Dashboard_Manager::get_session_result_display($session, $settings), //
            ];
        }

        // 4. Create the final clean data object for the app
        // THIS IS THE CRITICAL FIX FOR YOUR STATS
        $api_response_data = [
            'total_attempted'     => (int) $data['stats']->total_attempted,   // <-- FIX: Accessing inside 'stats' and casting to (int)
            'total_correct'       => (int) $data['stats']->total_correct,     // <-- FIX: Accessing inside 'stats' and casting to (int)
            'overall_accuracy'    => (float) $data['overall_accuracy'],
            'review_count'        => (int) $data['review_count'],
            'never_correct_count' => (int) $data['never_correct_count'],
            'active_sessions'     => $processed_active_sessions,  // <-- FIX
            'recent_history'      => $processed_recent_history, // <-- FIX: Using processed array
        ];

        // 5. Return the *wrapped* response that the app expects
        return new \WP_REST_Response(['success' => true, 'data' => $api_response_data], 200);
    }
    /**
     * Callback to get the data for the main profile dashboard.
     */
    public static function get_dashboard_profile( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // Call the public helper function from Dashboard.php
        $profile_data = Dashboard_Manager::get_profile_data( $user_id );

        if ( empty( $profile_data ) ) {
             return new \WP_Error('rest_no_data', 'Could not retrieve profile data.', ['status' => 500]);
        }
        
        // Return the *wrapped* response that the app expects
        return new \WP_REST_Response(['success' => true, 'data' => $profile_data], 200);
    }

    /**
     * Callback to get the review details for a specific session.
     * (This endpoint is used by the mobile app)
     */
    public static function get_session_review_details( \WP_REST_Request $request ) {
        $session_id = (int) $request['id'];
        $user_id = get_current_user_id();

        if ( ! $session_id ) {
            return new \WP_Error( 'rest_invalid_id', 'Invalid session ID.', [ 'status' => 400 ] );
        }

        // 1. Call your "correct" data-gathering function
        $data = \QuestionPress\Utils\Dashboard_Manager::get_session_review_data( $session_id, $user_id );

        if ( is_null( $data ) ) {
            return new \WP_Error( 'rest_not_found', 'Session not found or permission denied.', [ 'status' => 404 ] );
        }

        // 2. Transform the '$data['questions']' array for the mobile app
        $app_questions = [];
        foreach ( $data['questions'] as $q_obj ) {
            
            // Transform options: The app wants {key, value}, but your DB has {id, text}.
            // We will map 'option_id' -> 'option_key' and 'option_text' -> 'option_value'.
            $transformed_options = [];
            $correct_answer_keys = []; // We'll find this while we loop
            foreach( $q_obj->options as $option_row ) {
                $transformed_options[] = [
                    'option_key' => (string) $option_row->option_id, // Mapping ID to Key (as string)
                    'option_value' => $option_row->option_text, // Mapping Text to Value
                ];
                
                if ( $option_row->is_correct ) {
                    $correct_answer_keys[] = (string) $option_row->option_id; // Store all correct keys (as strings)
                }
            }
            
            // Your query doesn't select 'question_type'.
            // We must infer it based on the number of correct answers.
            $question_type = ( count($correct_answer_keys) > 1 ) ? 'multiple_choice' : 'single_choice';
            
            // Format the correct_answer for the app
            $app_correct_answer = null;
            if ($question_type === 'multiple_choice') {
                $app_correct_answer = $correct_answer_keys; // e.g., ["123", "124"]
            } else {
                $app_correct_answer = $correct_answer_keys[0] ?? null; // e.g., "123"
            }
            
            // Format the user_answer for the app.
            // Your PHP function provides 'selected_option_id' from the DB.
            $app_user_answer = $q_obj->selected_option_id ? (string) $q_obj->selected_option_id : null;
            
            // This logic assumes 'selected_option_id' is for single-choice answers.
            // If your app supports multiple-choice answers, your 'qp_user_attempts'
            // table would need to store a JSON array, and the 'get_session_review_data'
            // function would need to be updated to pass 'user_answer' instead of 'selected_option_id'.
            // Based on your provided function, this is the correct transformation.

            $app_questions[] = [
                'question_id' => $q_obj->question_id,
                'question_text' => $q_obj->question_text,
                'question_type' => $question_type, // Our best guess
                'options' => $transformed_options,
                'explanation' => $q_obj->explanation, // This is 'explanation_text'
                'user_answer' => $app_user_answer, // This is 'selected_option_id'
                'correct_answer' => $app_correct_answer, // This is derived from $q_obj->options
                'is_correct' => $q_obj->is_correct,
                'is_marked_for_review' => $q_obj->is_marked_for_review,
            ];
        }

        // 3. Use the 'app_summary' directly from your function
        // We just need to flatten it to match the app's 'SessionReviewDetails' interface
        $final_app_data = [
            'session_id' => $data['app_summary']['session_id'],
            'status' => $data['app_summary']['status'],
            'start_time' => $data['app_summary']['start_time'],
            'end_time' => $data['app_summary']['end_time'],
            'total_questions' => $data['app_summary']['total_questions'],
            'correct_count' => $data['app_summary']['correct_count'],
            'incorrect_count' => $data['app_summary']['incorrect_count'],
            'skipped_count' => $data['app_summary']['skipped_count'],
            'marks_obtained' => $data['app_summary']['marks_obtained'],
            'total_marks' => $data['app_summary']['total_marks'],
            'overall_accuracy' => $data['app_summary']['overall_accuracy'],
            'questions' => $app_questions,
        ];

        // 4. Wrap and return
        return new \WP_REST_Response( [ 'success' => true, 'data' => $final_app_data ], 200 );
    }

    /**
     * Callback to get the data for the dashboard history tab.
     * (REVISED to match app's 'Session' interface)
     */
    public static function get_dashboard_history( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // 1. Call the centralized data function
        $data = Dashboard_Manager::get_history_data( $user_id );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // 2. Process Completed Sessions for the app
        $processed_completed = [];
        foreach ( $data['completed_sessions'] as $session ) {
            $settings = json_decode( $session->settings_snapshot, true );
            $is_scored = isset( $settings['marks_correct'] );

            $mode_details = Dashboard_Manager::qp_get_history_mode_details( $session, $settings );
            
            if ( isset( $data['accuracy_stats'][ $session->session_id ] ) && ! $is_scored ) {
                $result_display = $data['accuracy_stats'][ $session->session_id ];
            } else {
                $result_display = Dashboard_Manager::get_session_result_display( $session, $settings );
            }

            $context_display = Dashboard_Manager::get_session_context_display(
                $session,
                $settings,
                $data['lineage_cache_completed'],
                $data['group_to_topic_map_completed'],
                $data['question_to_group_map_completed'],
                $data['existing_course_item_ids']
            );

            $processed_completed[] = [
                'session_id'        => (int) $session->session_id,
                'status'            => $session->status, // 'completed' or 'abandoned'
                'mode_name'         => $mode_details['label'], // Renamed from mode_label
                'subjects_display'  => wp_strip_all_tags( $context_display ), // Renamed from context_display
                'result_display'    => $result_display,
                'start_time'        => $session->start_time,
                'review_url'        => add_query_arg( 'session_id', $session->session_id, $data['review_page_url'] ),
                
                // Add fields from interface (even if null) to maintain shape
                'end_reason'        => $session->end_reason ?? null,
                'last_activity'     => $session->last_activity, 
            ];
        }

        // 3. Process Paused Sessions for the app
        $processed_paused = [];
        foreach ( $data['paused_sessions'] as $session ) {
            $settings = json_decode( $session->settings_snapshot, true );
            $mode_details = Dashboard_Manager::qp_get_history_mode_details( $session, $settings );

            $processed_paused[] = [
                'session_id'        => (int) $session->session_id,
                'status'            => $session->status, // 'paused'
                'mode_name'         => $mode_details['label'], // Renamed from mode_label
                'subjects_display'  => 'Paused Session', // Paused sessions don't have this context
                'result_display'    => '-', // No result for paused
                'start_time'        => $session->start_time,
                'resume_url'        => add_query_arg( 'session_id', $session->session_id, $data['session_page_url'] ),

                // Add fields from interface (even if null) to maintain shape
                'end_reason'        => null,
                'last_activity'     => $session->last_activity,
            ];
        }

        // 4. Create the final clean data object
        $api_response_data = [
            'completed' => $processed_completed,
            'paused'    => $processed_paused,
        ];

        return new \WP_REST_Response( [ 'success' => true, 'data' => $api_response_data ], 200 );
    }

    /**
     * Callback to get the data for the "My Courses" dashboard tab.
     * (REVISED to match app's 'Course' interface and expected keys)
     */
    public static function get_dashboard_my_courses( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // 1. Call the centralized data function
        $data = Dashboard_Manager::get_my_courses_data( $user_id );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // 2. Process Enrolled Courses (from WP_Query) for the app
        $processed_enrolled = [];
        // Get the global opt-out setting
        $global_opt_out = $data['allow_global_opt_out'] ?? false;

        if ( $data['enrolled_courses_query'] instanceof \WP_Query && $data['enrolled_courses_query']->have_posts() ) {
            foreach ( $data['enrolled_courses_query']->posts as $course_post ) {
                $course_id = $course_post->ID;
                $progress_data = $data['enrolled_courses_data'][ $course_id ] ?? [ 'progress' => 0, 'is_complete' => false ];
                
                // Check per-course opt-out meta
                $course_opt_out = get_post_meta($course_id, '_qp_course_allow_opt_out', true);
                
                // Logic: Allow if global is on AND per-course is not '0'
                // (empty or '1' means allow, '0' means disallow)
                $allow_opt_out = $global_opt_out && ($course_opt_out !== '0');

                $processed_enrolled[] = [
                    'id'            => $course_id,
                    'title'         => $course_post->post_title,
                    'status'        => $course_post->post_status,
                    'thumbnail'     => get_the_post_thumbnail_url( $course_id, 'medium' ),
                    'progress'      => $progress_data['progress'],
                    'is_complete'   => $progress_data['is_complete'],
                    'allow_opt_out' => $allow_opt_out,
                    'access_mode'   => get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free', // Get access mode, default to 'free'
                ];
            }
        }

        // 3. Process Purchased-but-Not-Enrolled Courses for the app
        $processed_purchased = [];
        if ( ! empty( $data['purchased_not_enrolled_posts'] ) ) {
            foreach ( $data['purchased_not_enrolled_posts'] as $course_post ) {
                $course_id = $course_post->ID;
                $processed_purchased[] = [
                    'id'            => $course_id,
                    'title'         => $course_post->post_title,
                    'status'        => $course_post->post_status,
                    'thumbnail'     => get_the_post_thumbnail_url( $course_id, 'medium' ),
                    'progress'      => 0,
                    'is_complete'   => false,
                    'allow_opt_out' => false, // Can't opt-out if not enrolled
                    'access_mode'   => get_post_meta($course_id, '_qp_course_access_mode', true) ?: 'free',
                ];
            }
        }

        // 4. Create the final clean data object
        // --- THIS IS THE FIX ---
        $api_response_data = [
            'enrolled_courses'             => $processed_enrolled,
            'purchased_not_enrolled_courses' => $processed_purchased,
        ];
        // --- END FIX ---

        return new \WP_REST_Response( [ 'success' => true, 'data' => $api_response_data ], 200 );
    }
    /**
     * Callback to get the data for the "Available Courses" dashboard tab.
     * (Designed to match app's 'available-courses.tsx' screen)
     */
    public static function get_dashboard_available_courses( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // 1. Call the centralized data function
        $data = Dashboard_Manager::get_available_courses_data( $user_id );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // 2. Process the WP_Query results into a clean array for the app
        $processed_courses = [];
        if ( $data['available_courses_query'] instanceof \WP_Query && $data['available_courses_query']->have_posts() ) {
            foreach ( $data['available_courses_query']->posts as $course_post ) {
                $course_id = $course_post->ID;
                $access_mode = get_post_meta( $course_id, '_qp_course_access_mode', true ) ?: 'free';
                $price_html = null;

                // Get price if it's a paid course
                if ( $access_mode === 'paid' && function_exists('wc_get_product') ) {
                    $product_id = get_post_meta( $course_id, '_qp_linked_product_id', true );
                    if ( $product_id ) {
                        $product = wc_get_product( $product_id );
                        if ( $product ) {
                            // Get the raw price string (e.g., "$10.00")
                            $price_html = $product->get_price_html() ? wp_strip_all_tags($product->get_price_html()) : 'N/A';
                        }
                    }
                }

                // Build the object matching the app's interface
                $processed_courses[] = [
                    'id'          => $course_id,
                    'title'       => $course_post->post_title,
                    'status'      => $course_post->post_status, // 'publish' or 'expired'
                    'access_mode' => $access_mode, // 'free' or 'paid'
                    'price'       => $price_html, // e.g., "$10.00" or null
                ];
            }
        }

        // 3. Create the final clean data object
        // --- THIS IS THE FIX ---
        // The key 'available_courses' MUST match the app screen's expectation
        $api_response_data = [
            'available_courses' => $processed_courses,
        ];
        // --- END FIX ---

        return new \WP_REST_Response( [ 'success' => true, 'data' => $api_response_data ], 200 );
    }

    /**
     * Callback to get the data for the "Review" dashboard tab.
     * (REVISED with robust sanitization to prevent JSON encoding errors)
     */
    public static function get_dashboard_review( \WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        
        // 1. Call the centralized data function
        $data = Dashboard_Manager::get_review_data( $user_id );

        if ( is_wp_error( $data ) ) {
            return $data;
        }

        // 2. Process data for the app
        $processed_questions = [];
        if ( is_array( $data['review_questions'] ) ) { // Check if it's an array first
            foreach ( $data['review_questions'] as $q ) {
                
                // --- THIS IS THE FIX ---
                // We must aggressively sanitize text to prevent JSON errors
                
                // 1. Ensure text is a string, handle nulls
                $text = (string) ( $q->question_text ?? '' );
                // 2. Remove all HTML/PHP tags
                $text = strip_tags( $text );
                // 3. Convert HTML entities (like &amp;) to characters
                $text = html_entity_decode( $text );
                // 4. Trim it
                $text = wp_trim_words( $text, 25, '...' );
                // 5. Final check to remove any lingering non-UTF8 characters
                $text = wp_check_invalid_utf8( $text, true );

                // Also sanitize the subject name
                $subject = (string) ( $q->subject_name ?? 'N/A' );
                if ( empty( $subject ) || $subject === 'Uncategorized' ) {
                    $subject = 'N/A';
                }
                // --- END FIX ---

                $processed_questions[] = [
                    'question_id'   => (int) $q->question_id,
                    'question_text' => $text,
                    'subject_name'  => esc_html( $subject ),
                ];
            }
        }

        // 3. Create the final clean data object
        $api_response_data = [
            'review_questions'      => $processed_questions,
            'never_correct_count'   => (int) $data['never_correct_count'],
            'total_incorrect_count' => (int) $data['total_incorrect_count'],
        ];

        return new \WP_REST_Response( [ 'success' => true, 'data' => $api_response_data ], 200 );
    }

    /**
     * Callback to get basic user analytics directly from user meta.
     * Implementation of Streak Decay Logic (Step 1.4).
     */
    public static function get_basic_analytics(WP_REST_Request $request)
    {
        $user_id = get_current_user_id();

        // 1. Streak Decay Logic
        $today = current_time('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));
        
        $last_act_date  = get_user_meta($user_id, '_qp_last_activity_date', true);
        $current_streak = (int) get_user_meta($user_id, '_qp_current_streak', true);

        // If activity was not today AND not yesterday, the streak has decayed
        if (!empty($last_act_date) && $last_act_date !== $today && $last_act_date !== $yesterday) {
            $current_streak = 0;
            update_user_meta($user_id, '_qp_current_streak', 0);
            // Note: We keep _qp_last_activity_date as is; it will be updated on the next session finalize.
        }

        // 2. Assemble pre-calculated stats (Architect's Path)
        $data = [
            'total_time'       => (int) get_user_meta($user_id, '_qp_total_time_spent', true),
            'total_attempts'   => (int) get_user_meta($user_id, '_qp_total_attempts', true),
            'correct_count'    => (int) get_user_meta($user_id, '_qp_correct_count', true),
            'accuracy'         => (float) get_user_meta($user_id, '_qp_overall_accuracy', true),
            'streak'           => $current_streak,
            'advanced_enabled' => false, // Hook for Analytics Addon
        ];

        return new WP_REST_Response(['success' => true, 'data' => $data], 200);
    }
} // End class DataController