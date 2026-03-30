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
     * Standardized to return { success: true, data: { question_ids: [] } }
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

        // Ensure IDs are integers and handle empty results
        $question_ids = ! empty( $question_ids ) ? array_map('intval', $question_ids) : [];

        // 4. Return standardized wrapped response
        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'question_ids' => $question_ids
            ]
        ], 200 );
    }

    /**
     * Callback to get a single question by its ID.
     * Standardized to return { success: true, data: { ...question_details... } }
     */
    public static function get_single_question_by_id( \WP_REST_Request $request ) {
        // Get the question's actual database ID from the URL parameter
        $question_id = (int) $request['id'];

        if ($question_id <= 0) {
             return new WP_Error('rest_invalid_id', 'Invalid Question ID provided.', ['status' => 400]);
        }

        // Call the DB method (updated in previous step to include is_correct and explanation)
        $question_data = Questions_DB::get_question_details_for_api($question_id);

        // Check the result
        if ( $question_data ) {
            // Found and formatted data successfully - wrap in standard format
            return new WP_REST_Response( [
                'success' => true,
                'data'    => $question_data
            ], 200 );
        } else {
            // Question not found or not published
            return new WP_Error( 'rest_question_not_found', 'Question not found or is not published.', ['status' => 404] );
        }
    }

} // End class QuestionController