<?php
namespace QuestionPress\Rest_Api; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_REST_Request;
use WP_Error;
use WP_REST_Response;
use QuestionPress\Database\Questions_DB; // Use our DB class
use QuestionPress\Database\Terms_DB;   // Use our DB class

/**
 * Handles REST API requests for retrieving and creating questions.
 */
class QuestionController {

    /**
     * Callback to start a session (get question IDs).
     */
    public static function start_session_and_get_questions( \WP_REST_Request $request ) {
        // 1. Sanitize Parameters
        $subject_param = $request->get_param('subject_id');
        $subject_id = ($subject_param === 'all' || empty($subject_param)) ? 'all' : absint($subject_param);

        $pyq_only_param = $request->get_param('pyq_only');
        // Treat various truthy values (e.g., 'true', '1') as true
        $pyq_only = filter_var($pyq_only_param, FILTER_VALIDATE_BOOLEAN);

        // 2. Prepare Arguments for DB Method
        $args = [
            'subject_id' => $subject_id,
            'pyq_only'   => $pyq_only,
        ];

        // 3. Call DB Method
        $question_ids = Questions_DB::get_question_ids_for_api_session( $args );

        // Ensure IDs are integers
        $question_ids = array_map('intval', $question_ids);

        // 4. Handle Response
        if ( empty( $question_ids ) ) {
            return new WP_REST_Response( ['question_ids' => []], 200 );
        }

        return new WP_REST_Response( ['question_ids' => $question_ids], 200 );
    }

    /**
     * Callback to get a single question by its ID.
     */
    public static function get_single_question_by_id( \WP_REST_Request $request ) {
        // Get the question's actual database ID from the URL parameter
        $question_id = (int) $request['id'];

        if ($question_id <= 0) {
             return new WP_Error('rest_invalid_id', 'Invalid Question ID provided.', ['status' => 400]);
        }

        // Call the new DB method
        $question_data = Questions_DB::get_question_details_for_api($question_id);

        // Check the result
        if ( $question_data ) {
            // Found and formatted data successfully
            return new WP_REST_Response( $question_data, 200 );
        } else {
            // Question not found or not published
            return new WP_Error( 'rest_question_not_found', 'Question not found or is not published.', ['status' => 404] );
        }
    }

    /**
     * Callback to add a new question group.
     */
    public static function add_question_group( \WP_REST_Request $request ) {
        global $wpdb;
        $data = $request->get_json_params();

        if (!isset($data['subject_id']) || !isset($data['questions']) || !is_array($data['questions'])) {
            return new WP_Error('rest_invalid_request', 'Missing required fields: subject_id and questions are required.', ['status' => 400]);
        }

        $g_table = Questions_DB::get_groups_table_name();
        $q_table = Questions_DB::get_questions_table_name();
        $o_table = Questions_DB::get_options_table_name();
        $rel_table = Terms_DB::get_relationships_table_name();

        // --- Create the Question Group ---
        $wpdb->insert($g_table, [
            'direction_text' => isset($data['direction_text']) ? sanitize_textarea_field($data['direction_text']) : null,
            'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
            'pyq_year' => isset($data['pyq_year']) ? sanitize_text_field($data['pyq_year']) : null,
        ]);
        $group_id = $wpdb->insert_id;

        if (!$group_id) {
            return new WP_Error('db_error', 'Could not create the question group.', ['status' => 500]);
        }

        // Link group to subject
        $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['subject_id']), 'object_type' => 'group']);

        // Link group to exam if PYQ
        if (isset($data['is_pyq']) && !empty($data['exam_id'])) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => absint($data['exam_id']), 'object_type' => 'group']);
        }

        // --- Loop Through and Create Each Question ---
        foreach ($data['questions'] as $question_data) {
            $question_text = sanitize_textarea_field($question_data['question_text']);
            $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));

            // Note: 'qp_next_custom_question_id' option logic seems to be missing from the
            // original API callback, but present in the admin save handler.
            // We will add it here for consistency.
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            update_option('qp_next_custom_question_id', $next_custom_id + 1);

            $wpdb->insert($q_table, [
                'group_id' => $group_id,
                'custom_question_id' => $next_custom_id, // Added this
                'question_text' => $question_text,
                'question_text_hash' => $hash,
                'status' => 'publish' // Assuming API-added questions are always published
            ]);
            $question_id = $wpdb->insert_id;

            // Add Options
            if (isset($question_data['options']) && is_array($question_data['options'])) {
                foreach ($question_data['options'] as $option) {
                    $wpdb->insert($o_table, [
                        'question_id' => $question_id,
                        'option_text' => sanitize_text_field($option['optionText']), // Match JSON
                        'is_correct' => (int)$option['isCorrect'] // Match JSON
                    ]);
                }
            }

            // Add Labels by creating relationships
            if (isset($question_data['labels']) && is_array($question_data['labels'])) {
                foreach ($question_data['labels'] as $label_id) {
                    $wpdb->insert($rel_table, [
                        'object_id' => $question_id,
                        'term_id' => absint($label_id),
                        'object_type' => 'question'
                    ]);
                }
            }
        }

        return new WP_REST_Response(['success' => true, 'message' => 'Question group created successfully.', 'group_id' => $group_id], 201);
    }

} // End class QuestionController