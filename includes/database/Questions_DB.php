<?php
namespace QuestionPress\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles database operations for Questions, Groups, and Options.
 *
 * @package QuestionPress\Database
 */
class Questions_DB extends DB { // Inherits from DB to get $wpdb

    /**
     * Get the name of the questions table.
     * @return string
     */
    public static function get_questions_table_name() {
        return self::$wpdb->prefix . 'qp_questions';
    }

    /**
     * Get the name of the question groups table.
     * @return string
     */
    public static function get_groups_table_name() {
        return self::$wpdb->prefix . 'qp_question_groups';
    }

    /**
     * Get the name of the options table.
     * @return string
     */
    public static function get_options_table_name() {
        return self::$wpdb->prefix . 'qp_options';
    }

    /**
     * Get a single question's basic data by its ID.
     * Includes group data.
     *
     * @param int $question_id
     * @return object|null Question data object or null if not found.
     */
    public static function get_question_by_id( $question_id ) {
        $q_table = self::get_questions_table_name();
        $g_table = self::get_groups_table_name();

        return self::$wpdb->get_row( self::$wpdb->prepare(
            "SELECT q.*, g.direction_text, g.direction_image_id, g.is_pyq, g.pyq_year
             FROM {$q_table} q
             LEFT JOIN {$g_table} g ON q.group_id = g.group_id
             WHERE q.question_id = %d",
            $question_id
        ) );
    }

    /**
     * Get options for a specific question.
     *
     * @param int $question_id
     * @return array Array of option objects.
     */
    public static function get_options_for_question( $question_id ) {
        $o_table = self::get_options_table_name();
        return self::$wpdb->get_results( self::$wpdb->prepare(
            "SELECT option_id, option_text, is_correct
             FROM {$o_table}
             WHERE question_id = %d
             ORDER BY option_id ASC", // Or however options should be ordered by default
            $question_id
        ) );
    }

    /**
     * Get the correct option ID for a question.
     *
     * @param int $question_id
     * @return int|null Correct option ID or null if none is set.
     */
    public static function get_correct_option_id( $question_id ) {
         $o_table = self::get_options_table_name();
         return self::$wpdb->get_var( self::$wpdb->prepare(
            "SELECT option_id FROM {$o_table} WHERE question_id = %d AND is_correct = 1",
            $question_id
        ) );
    }

     /**
     * Get basic group data by ID.
     *
     * @param int $group_id
     * @return object|null
     */
    public static function get_group_by_id( $group_id ) {
        $g_table = self::get_groups_table_name();
        return self::$wpdb->get_row( self::$wpdb->prepare(
            "SELECT * FROM {$g_table} WHERE group_id = %d",
            $group_id
        ) );
    }

    /**
     * Get all questions belonging to a specific group.
     *
     * @param int $group_id
     * @return array Array of question objects.
     */
    public static function get_questions_by_group_id( $group_id ) {
        $q_table = self::get_questions_table_name();
        return self::$wpdb->get_results( self::$wpdb->prepare(
            "SELECT * FROM {$q_table} WHERE group_id = %d ORDER BY question_id ASC",
            $group_id
        ) );
    }

    /**
     * Inserts a new question group into the database.
     *
     * @param array $data Associative array of group data (e.g., 'direction_text', 'is_pyq', 'pyq_year').
     * @return int|false The new group_id on success, false on failure.
     */
    public static function insert_group( $data ) {
        $g_table = self::get_groups_table_name();

        // Define expected columns and their formats for security/correctness
        $allowed_columns = [
            'direction_text'     => '%s',
            'direction_image_id' => '%d',
            'is_pyq'             => '%d',
            'pyq_year'           => '%s',
        ];
        $insert_data = [];
        $formats = [];

        foreach ( $allowed_columns as $col => $format ) {
            if ( array_key_exists( $col, $data ) ) {
                $insert_data[$col] = $data[$col];
                $formats[] = $format;
            }
        }

        if ( empty( $insert_data ) ) {
            return false; // Nothing to insert
        }

        $result = self::$wpdb->insert( $g_table, $insert_data, $formats );

        return $result ? self::$wpdb->insert_id : false;
    }

    /**
     * Updates an existing question group in the database.
     *
     * @param int   $group_id The ID of the group to update.
     * @param array $data     Associative array of group data to update.
     * @return int|false The number of rows updated (usually 1 or 0), or false on failure.
     */
    public static function update_group( $group_id, $data ) {
        $g_table = self::get_groups_table_name();
        $group_id = absint( $group_id );
        if ( $group_id <= 0 ) {
            return false;
        }

        // Define expected columns and their formats
        $allowed_columns = [
            'direction_text'     => '%s',
            'direction_image_id' => '%d',
            'is_pyq'             => '%d',
            'pyq_year'           => '%s',
        ];
        $update_data = [];
        $formats = [];

        foreach ( $allowed_columns as $col => $format ) {
            if ( array_key_exists( $col, $data ) ) {
                $update_data[$col] = $data[$col];
                $formats[] = $format;
            }
        }

        if ( empty( $update_data ) ) {
            return 0; // Nothing to update
        }

        return self::$wpdb->update( $g_table, $update_data, ['group_id' => $group_id], $formats, ['%d'] );
    }

    /**
     * Deletes one or more questions and their associated options and relationships.
     *
     * @param int|array $question_ids A single question ID or an array of question IDs.
     * @return int|false Number of questions deleted, or false on error.
     */
    public static function delete_questions( $question_ids ) {
        if ( empty( $question_ids ) ) {
            return 0;
        }
        $ids = array_map( 'absint', (array) $question_ids );
        $ids_placeholder = implode( ',', $ids );

        $q_table = self::get_questions_table_name();
        $o_table = self::get_options_table_name();
        $rel_table = Terms_DB::get_relationships_table_name(); // Use Terms_DB method

        // Delete options
        self::$wpdb->query( "DELETE FROM {$o_table} WHERE question_id IN ({$ids_placeholder})" );
        // Delete term relationships (e.g., labels)
        self::$wpdb->query( "DELETE FROM {$rel_table} WHERE object_id IN ({$ids_placeholder}) AND object_type = 'question'" );
        // Delete questions
        $deleted_count = self::$wpdb->query( "DELETE FROM {$q_table} WHERE question_id IN ({$ids_placeholder})" );

        return $deleted_count;
    }

    /**
     * Deletes a question group and all its associated questions, options, and relationships.
     *
     * @param int $group_id The ID of the group to delete.
     * @return bool True on success, false otherwise.
     */
    public static function delete_group_and_contents( $group_id ) {
        $group_id = absint( $group_id );
        if ( $group_id <= 0 ) {
            return false;
        }

        $g_table = self::get_groups_table_name();
        $rel_table = Terms_DB::get_relationships_table_name(); // Use Terms_DB method

        // Find questions associated with the group
        $question_ids = self::get_questions_by_group_id( $group_id );
        $qids_to_delete = wp_list_pluck( $question_ids, 'question_id' );

        // Delete associated questions (which also handles options and question relationships)
        if ( ! empty( $qids_to_delete ) ) {
            self::delete_questions( $qids_to_delete );
        }

        // Delete group term relationships
        self::$wpdb->delete( $rel_table, ['object_id' => $group_id, 'object_type' => 'group'], ['%d', '%s'] );

        // Delete the group itself
        $deleted = self::$wpdb->delete( $g_table, ['group_id' => $group_id], ['%d'] );

        return (bool) $deleted;
    }

    /**
     * Saves/Updates options for a given question based on submitted data.
     * Deletes options not present in the submitted data.
     * Sets the correct answer.
     * (Moved from global function process_question_options)
     *
     * @param int $question_id The ID of the question.
     * @param array $q_data    The submitted data array for this question (containing options, option_ids, correct_option_id).
     * @return int|null        The ID of the correct option that was set, or null.
     */
    public static function save_options_for_question($question_id, $q_data) {
        $o_table = self::get_options_table_name();
        $submitted_option_ids = [];
        $options_text = isset($q_data['options']) ? (array)$q_data['options'] : [];
        $option_ids = isset($q_data['option_ids']) ? (array)$q_data['option_ids'] : [];
        $correct_option_id_from_form = isset($q_data['correct_option_id']) ? $q_data['correct_option_id'] : null;
        $final_correct_option_id = null; // Variable to store the actual correct ID

        // Get original correct option ID before changes
        // $original_correct_option_id = self::get_correct_option_id($question_id); // Fetch original correct ID

        foreach ($options_text as $index => $option_text) {
            $option_id = isset($option_ids[$index]) ? absint($option_ids[$index]) : 0;
            // --- FIX: Use wp_kses_post for option text ---
            $trimmed_option_text = trim(stripslashes($option_text));
            if (empty($trimmed_option_text)) continue;

            $option_data = ['option_text' => wp_kses_post($trimmed_option_text)]; // Use wp_kses_post
            // --- END FIX ---

            $current_option_actual_id = 0; // Track the ID for this iteration

            if ($option_id > 0) {
                // Update existing option
                self::$wpdb->update($o_table, $option_data, ['option_id' => $option_id]);
                $submitted_option_ids[] = $option_id;
                $current_option_actual_id = $option_id;
            } else {
                // Insert new option
                $option_data['question_id'] = $question_id;
                self::$wpdb->insert($o_table, $option_data);
                $new_option_id = self::$wpdb->insert_id;
                $submitted_option_ids[] = $new_option_id;
                $current_option_actual_id = $new_option_id;

                // Update correct_option_id_from_form if it referred to a new option
                if ($correct_option_id_from_form === 'new_' . $index) {
                    $correct_option_id_from_form = $new_option_id;
                }
            }

             // Check if this option is the one marked as correct from the form
             if ($correct_option_id_from_form == $current_option_actual_id) { // Use == for loose comparison
                 $final_correct_option_id = $current_option_actual_id;
             }

        } // End foreach option

        // Delete options that were not submitted
        $existing_db_option_ids = self::$wpdb->get_col(self::$wpdb->prepare(
            "SELECT option_id FROM $o_table WHERE question_id = %d", $question_id
        ));
        $options_to_delete = array_diff($existing_db_option_ids, $submitted_option_ids);
        if (!empty($options_to_delete)) {
            $ids_placeholder = implode(',', array_map('absint', $options_to_delete));
            self::$wpdb->query("DELETE FROM $o_table WHERE option_id IN ($ids_placeholder)"); // Use query() for multiple deletions
        }

        // Set the correct answer flag
        self::$wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]); // Reset all first
        if ($final_correct_option_id !== null) {
            self::$wpdb->update(
                $o_table,
                ['is_correct' => 1],
                ['option_id' => absint($final_correct_option_id), 'question_id' => $question_id]
            );
        }

        // --- Return the correct ID that was actually set ---
        return $final_correct_option_id;
        // --- Removed the call to qp_re_evaluate_question_attempts ---

    } // End save_options_for_question method

    /**
     * Re-evaluates all attempts for a specific question after its correct answer has changed.
     * Also recalculates and updates stats for affected sessions.
     * (Moved from global function qp_re_evaluate_question_attempts)
     *
     * @param int $question_id The ID of the question that was updated.
     * @param int $new_correct_option_id The ID of the new correct option.
     */
    private static function re_evaluate_attempts($question_id, $new_correct_option_id) {
        // Use self::$wpdb and hardcode table names for now
        $attempts_table = self::$wpdb->prefix . 'qp_user_attempts';
        $sessions_table = self::$wpdb->prefix . 'qp_user_sessions';
        $options_table = self::get_options_table_name(); // Use existing helper

        // 1. Find all session IDs that have an attempt for this question.
        $affected_session_ids = self::$wpdb->get_col(self::$wpdb->prepare(
            "SELECT DISTINCT session_id FROM {$attempts_table} WHERE question_id = %d",
            $question_id
        ));

        if (empty($affected_session_ids)) {
            return; // No attempts to update.
        }

        // Ensure new_correct_option_id is an integer
        $new_correct_option_id = absint($new_correct_option_id);

        // 2. Update the is_correct status for all attempts of this question.
        // Set is_correct = 1 where the selected option matches the new correct option.
        self::$wpdb->query(self::$wpdb->prepare(
            "UPDATE {$attempts_table} SET is_correct = 1 WHERE question_id = %d AND selected_option_id = %d",
            $question_id,
            $new_correct_option_id
        ));
        // Set is_correct = 0 for all other attempts of this question (including those where selected_option_id might be NULL or different).
        self::$wpdb->query(self::$wpdb->prepare(
            "UPDATE {$attempts_table} SET is_correct = 0 WHERE question_id = %d AND (selected_option_id IS NULL OR selected_option_id != %d)",
            $question_id,
            $new_correct_option_id
        ));


        // 3. Loop through each affected session and recalculate its score.
        foreach ($affected_session_ids as $session_id) {
            $session = self::$wpdb->get_row(self::$wpdb->prepare(
                "SELECT settings_snapshot FROM {$sessions_table} WHERE session_id = %d", $session_id
            ));
            if (!$session || empty($session->settings_snapshot)) continue;

            $settings = json_decode($session->settings_snapshot, true);
            // Provide defaults if settings are missing or not numbers
            $marks_correct = isset($settings['marks_correct']) && is_numeric($settings['marks_correct']) ? floatval($settings['marks_correct']) : 0;
            $marks_incorrect = isset($settings['marks_incorrect']) && is_numeric($settings['marks_incorrect']) ? floatval($settings['marks_incorrect']) : 0; // Already negative usually

            // Recalculate counts directly from the attempts table for this session
            $correct_count = (int) self::$wpdb->get_var(self::$wpdb->prepare(
                "SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 1", $session_id
            ));
            $incorrect_count = (int) self::$wpdb->get_var(self::$wpdb->prepare(
                "SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 0", $session_id
            ));
            // Recalculate total attempted based ONLY on correct/incorrect, exclude skipped/expired etc.
            $total_attempted = $correct_count + $incorrect_count;

            $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

            // Update the session record with the new, accurate counts and score.
            self::$wpdb->update(
                $sessions_table,
                [
                    'correct_count'   => $correct_count,
                    'incorrect_count' => $incorrect_count,
                    'total_attempted' => $total_attempted, // Update total attempted as well
                    'marks_obtained'  => $final_score
                ],
                ['session_id' => $session_id]
            );
        }
    } // End re_evaluate_attempts method

    // --- More methods for saving, updating, deleting will be added below ---

} // End class Questions_DB