<?php
namespace QuestionPress\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Database\Terms_DB;

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
     * @param int $question_id The ID of the question.
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
     * @param int $question_id The ID of the question.
     * @return array Array of option objects.
     */
    public static function get_options_for_question( $question_id ) {
        $o_table = self::get_options_table_name();
        return self::$wpdb->get_results( self::$wpdb->prepare(
            "SELECT option_id, option_text, is_correct
             FROM {$o_table}
             WHERE question_id = %d
             ORDER BY option_id ASC",
            $question_id
        ) );
    }

    /**
     * Get the correct option ID for a question.
     *
     * @param int $question_id The ID of the question.
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
        * @param int $group_id The ID of the group.
        * @return object|null The group data object or null if not found.
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
     * @param int $group_id The ID of the group.
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
        $rel_table = Terms_DB::get_relationships_table_name();

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
        $rel_table = Terms_DB::get_relationships_table_name();

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
     * Retrieves questions for the admin list table with filtering, sorting, and pagination.
     *
     * @param array $args {
     * Optional. Array of arguments.
     *
     * @type string $status           Question status ('publish', 'draft', 'trash'). Default 'publish'.
     * @type int    $subject_id       Filter by parent subject term ID. Default 0.
     * @type int    $topic_id         Filter by specific topic term ID. Default 0.
     * @type string $source_filter    Filter by source/section value ('source_X' or 'section_Y'). Default ''.
     * @type array  $label_ids        Array of label term IDs to filter by (AND logic). Default [].
     * @type string $search           Search term. Default ''.
     * @type string $orderby          Column to order by. Default 'question_id'.
     * @type string $order            Order direction ('ASC' or 'DESC'). Default 'DESC'.
     * @type int    $per_page         Items per page. Default 20.
     * @type int    $current_page     Current page number. Default 1.
     * @type bool   $count_only       If true, returns only the total count. Default false.
     * }
     * @return array|int Returns an array ['items' => (array), 'total_items' => (int)] or just the total count if $count_only is true.
     */
    public static function get_questions_for_list_table( $args = [] ) {
        $defaults = [
            'status'        => 'publish',
            'subject_id'    => 0,
            'topic_id'      => 0,
            'source_filter' => '',
            'label_ids'     => [],
            'search'        => '',
            'orderby'       => 'question_id',
            'order'         => 'DESC',
            'per_page'      => 20,
            'current_page'  => 1,
            'count_only'    => false,
        ];
        $args = wp_parse_args( $args, $defaults );

        // Table names
        $q_table = self::get_questions_table_name();
        $g_table = self::get_groups_table_name();
        $rel_table = Terms_DB::get_relationships_table_name();
        $term_table = Terms_DB::get_terms_table_name();
        $tax_table = Terms_DB::get_taxonomies_table_name();

        // Base query structure
        $select_sql = $args['count_only'] ? "SELECT COUNT(DISTINCT q.question_id)" : "SELECT DISTINCT q.*, g.group_id, g.direction_text, g.direction_image_id, g.is_pyq, g.pyq_year";
        $query_from = "FROM {$q_table} q";
        $query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";
        $where_conditions = [];
        $params = [];
        $joins_added = []; // Helper

        // Status filter
        $where_conditions[] = self::$wpdb->prepare("q.status = %s", $args['status']);

        // Subject/Topic Filter
        if ( $args['topic_id'] > 0 ) {
            if (!in_array('topic_rel', $joins_added)) {
                $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
                $joins_added[] = 'topic_rel';
            }
            $where_conditions[] = self::$wpdb->prepare("topic_rel.term_id = %d", $args['topic_id']);
        } elseif ( $args['subject_id'] > 0 ) {
            $child_topic_ids = Terms_DB::get_all_descendant_ids($args['subject_id']);
            if (!empty($child_topic_ids)) {
                 $ids_placeholder = implode(',', $child_topic_ids);
                 if (!in_array('topic_rel', $joins_added)) {
                     $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
                     $joins_added[] = 'topic_rel';
                 }
                 $where_conditions[] = "topic_rel.term_id IN ($ids_placeholder)";
            } else {
                 $where_conditions[] = "1=0"; // Subject has no topics
            }
        }

        // Source/Section Filter
        if ( !empty($args['source_filter']) ) {
            $term_id_to_filter = 0;
            if (strpos($args['source_filter'], 'source_') === 0) {
                $term_id_to_filter = absint(str_replace('source_', '', $args['source_filter']));
            } elseif (strpos($args['source_filter'], 'section_') === 0) {
                $term_id_to_filter = absint(str_replace('section_', '', $args['source_filter']));
            }

            if ($term_id_to_filter > 0) {
                $descendant_ids = Terms_DB::get_all_descendant_ids($term_id_to_filter);
                if (!empty($descendant_ids)) {
                    $term_ids_placeholder = implode(',', $descendant_ids);
                    if (!in_array('source_rel', $joins_added)) {
                        $query_joins .= " JOIN {$rel_table} source_rel ON g.group_id = source_rel.object_id AND source_rel.object_type = 'group'";
                        $joins_added[] = 'source_rel';
                    }
                    $where_conditions[] = "source_rel.term_id IN ($term_ids_placeholder)";
                } else {
                    $where_conditions[] = "1=0";
                }
            }
        }

        // Search Filter
        if ( !empty($args['search']) ) {
            $search_term = $args['search'];
            if (is_numeric($search_term)) {
                $where_conditions[] = self::$wpdb->prepare("q.question_id = %d", absint($search_term));
            } else {
                $like_term = '%' . self::$wpdb->esc_like($search_term) . '%';
                $where_conditions[] = self::$wpdb->prepare("q.question_text LIKE %s", $like_term);
            }
        }

        // Label Filter (AND logic)
        if ( !empty($args['label_ids']) ) {
            $label_tax_id = Terms_DB::get_taxonomy_id_by_name('label');
            if ($label_tax_id) {
                 $label_ids_placeholder = implode(',', array_map('absint', $args['label_ids']));
                 // Use a subquery to find questions having ALL specified labels
                 $where_conditions[] = "q.question_id IN (
                     SELECT r.object_id
                     FROM {$rel_table} r
                     JOIN {$term_table} t ON r.term_id = t.term_id
                     WHERE r.object_type = 'question' AND t.taxonomy_id = {$label_tax_id} AND r.term_id IN ({$label_ids_placeholder})
                     GROUP BY r.object_id
                     HAVING COUNT(DISTINCT r.term_id) = " . count($args['label_ids']) . "
                 )";
            }
        }

        // WHERE Clause
        $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

        // --- Handle Count Query ---
        if ( $args['count_only'] ) {
            $count_query = $select_sql . " " . $query_from . " " . $query_joins . " " . $where_clause;
            // Use prepare if params exist (e.g., from search)
            $query_to_run = empty($params) ? $count_query : self::$wpdb->prepare($count_query, $params);
            return (int) self::$wpdb->get_var( $query_to_run );
        }

        // --- Handle Data Query ---
        // Add joins needed for sorting/display *after* filtering is built
         if ( 'subject_name' === $args['orderby'] && !in_array('topic_rel', $joins_added) ) {
             $query_joins .= " LEFT JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group' AND topic_rel.term_id IN (SELECT term_id FROM {$term_table} WHERE parent != 0 AND taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'))";
             $joins_added[] = 'topic_rel';
         }
         if ( 'subject_name' === $args['orderby'] && !in_array('topic_term', $joins_added) ) {
            $query_joins .= " LEFT JOIN {$term_table} topic_term ON topic_rel.term_id = topic_term.term_id";
            $joins_added[] = 'topic_term';
         }
         if ( 'subject_name' === $args['orderby'] && !in_array('subject_term', $joins_added) ) {
            $query_joins .= " LEFT JOIN {$term_table} subject_term ON topic_term.parent = subject_term.term_id";
            $joins_added[] = 'subject_term';
         }

        // Sorting
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = 'q.question_id'; // Default safe value
         $allowed_orderby = ['question_id', 'subject_name'];
         if ( in_array($args['orderby'], $allowed_orderby) ) {
             if ( $args['orderby'] === 'subject_name' ) {
                 $orderby = 'subject_term.name';
             } else {
                 $orderby = 'q.' . sanitize_key($args['orderby']);
             }
         }

        // Pagination
        $offset = ($args['current_page'] - 1) * $args['per_page'];

        // Final Data Query Assembly
        $data_query = $select_sql . " " . $query_from . " " . $query_joins . " " . $where_clause
                    . " ORDER BY {$orderby} {$order}"
                    . " LIMIT %d OFFSET %d";

        // Add pagination params to the end
        $params[] = $args['per_page'];
        $params[] = $offset;

        $items = self::$wpdb->get_results( self::$wpdb->prepare(
            $data_query,
            $params
        ), ARRAY_A );

         // Fetch total items count using the same filters (without pagination params)
         array_pop($params); // remove offset
         array_pop($params); // remove per_page
         $count_query = "SELECT COUNT(DISTINCT q.question_id) " . $query_from . " " . $query_joins . " " . $where_clause;
         $query_to_run = empty($params) ? $count_query : self::$wpdb->prepare($count_query, $params);
         $total_items = (int) self::$wpdb->get_var( $query_to_run );


        // Add term data needed for display
         if(!empty($items)){
             self::enrich_questions_with_terms($items);
         }


        return [
            'items'       => $items,
            'total_items' => $total_items,
        ];
    }

    /**
     * Helper to enrich question items array with term names (Subject, Topic, Exam, Source Lineage, Labels).
     * Modifies the input array by reference.
     *
     * @param array &$items Array of question items (as associative arrays) fetched from DB.
     */
    public static function enrich_questions_with_terms(array &$items) {
        if (empty($items)) return;

        $group_ids = array_unique(wp_list_pluck($items, 'group_id'));
        $question_ids = array_unique(wp_list_pluck($items, 'question_id'));

        // Clean up empty/invalid IDs
        $group_ids = array_filter($group_ids, 'absint');
        $question_ids = array_filter($question_ids, 'absint');

        if (empty($group_ids) && empty($question_ids)) return;

        // --- Get All Relevant Relationships in One Go ---
        $terms_by_item = [];
        
        // Initialize for all items to prevent notices
        foreach ($items as $item) {
            $terms_by_item[$item['group_id']] = ['subject' => null, 'topic' => null, 'exam' => null, 'source_term_id' => null];
            $terms_by_item[$item['question_id']] = ['labels' => []];
        }

        if (!empty($group_ids)) {
            $group_rels = Terms_DB::get_linked_terms($group_ids, 'group', ['subject', 'source', 'exam']);
            foreach ($group_rels as $gid => $terms) {
                foreach ($terms as $term) {
                    switch ($term->taxonomy_name) {
                        case 'subject':
                            if ($term->parent != 0) $terms_by_item[$gid]['topic'] = $term;
                            else $terms_by_item[$gid]['subject'] = $term;
                            break;
                        case 'source':
                            $terms_by_item[$gid]['source_term_id'] = $term->term_id;
                            break;
                        case 'exam':
                            $terms_by_item[$gid]['exam'] = $term;
                            break;
                    }
                }
            }
        }
        
        if (!empty($question_ids)) {
            $label_rels = Terms_DB::get_linked_terms($question_ids, 'question', 'label', ['color']);
            foreach ($label_rels as $qid => $terms) {
                foreach ($terms as $term) {
                    $terms_by_item[$qid]['labels'][] = (object)[
                        'label_id' => $term->term_id, // Add ID
                        'label_name' => $term->name, 
                        'label_color' => $term->meta['color'] ?? '#cccccc'
                    ];
                }
            }
        }

        // --- Fill in missing Subject if only Topic exists ---
        $all_subject_terms = [];
        $subject_tax_id = Terms_DB::get_taxonomy_id_by_name('subject');
        if ($subject_tax_id) {
            $all_subject_terms_raw = self::$wpdb->get_results(self::$wpdb->prepare("SELECT term_id, name, parent FROM " . Terms_DB::get_terms_table_name() . " WHERE taxonomy_id = %d", $subject_tax_id), OBJECT_K);
            if ($all_subject_terms_raw) $all_subject_terms = $all_subject_terms_raw;
        }

        // Enrich the original $items array
        foreach ($items as &$item) {
            $gid = $item['group_id'];
            $qid = $item['question_id'];
            
            $group_terms = $terms_by_item[$gid] ?? ['subject' => null, 'topic' => null, 'exam' => null, 'source_term_id' => null];
            $question_terms = $terms_by_item[$qid] ?? ['labels' => []];

            $item['subject_name'] = $group_terms['subject']->name ?? null;
            $item['topic_name'] = $group_terms['topic']->name ?? null;
            $item['exam_name'] = $group_terms['exam']->name ?? null;
            $item['linked_source_term_id'] = $group_terms['source_term_id'] ?? null;
            $item['labels'] = $question_terms['labels'] ?? [];

            // If subject is missing but topic exists, fill subject from topic's parent
            if (empty($item['subject_name']) && $group_terms['topic'] && isset($all_subject_terms[$group_terms['topic']->parent])) {
                $item['subject_name'] = $all_subject_terms[$group_terms['topic']->parent]->name;
            }
        }
        unset($item); // Unset reference
    }

    /**
     * Saves/Updates options for a given question based on submitted data.
     * Deletes options not present in the submitted data.
     * Sets the correct answer.
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

        foreach ($options_text as $index => $option_text) {
            $option_id = isset($option_ids[$index]) ? absint($option_ids[$index]) : 0;
            $trimmed_option_text = trim(stripslashes($option_text));
            if (empty($trimmed_option_text)) continue;

            $option_data = ['option_text' => wp_kses_post($trimmed_option_text)];
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
             if ($correct_option_id_from_form == $current_option_actual_id) {
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
            self::$wpdb->query("DELETE FROM $o_table WHERE option_id IN ($ids_placeholder)");
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

        return $final_correct_option_id;
    }

    /**
     * Retrieves all necessary data for rendering the question group editor page.
     *
     * @param int $group_id The ID of the question group.
     * @return array|null An associative array containing all group details, or null if group not found.
     */
    public static function get_group_details_for_editor( int $group_id ) {
        if ( $group_id <= 0 ) {
            return null;
        }

        // 1. Get Group Data
        $group_data = self::get_group_by_id( $group_id );
        if ( ! $group_data ) {
            return null; // Group not found
        }

        // 2. Get Questions in Group
        $questions_in_group = self::get_questions_by_group_id( $group_id );
        if ( empty( $questions_in_group ) ) {
             return [
                 'group' => $group_data,
                 'questions' => [],
                 'terms' => [
                    'subject' => null, 'topic' => null, 'source' => null, 'section' => null, 'exam' => null
                 ]
             ];
        }

        $question_ids = wp_list_pluck( $questions_in_group, 'question_id' );

        // 3. Get All Options for these Questions
        $all_options_raw = self::get_options_for_question($question_ids); // This needs to handle array
        // Re-fetch options properly for multiple questions
        $ids_placeholder = implode( ',', array_map( 'absint', $question_ids ) );
        $o_table = self::get_options_table_name();
        $all_options_raw = self::$wpdb->get_results( "SELECT * FROM {$o_table} WHERE question_id IN ($ids_placeholder) ORDER BY question_id, option_id ASC" );
        
        $options_by_question = [];
        foreach ( $all_options_raw as $option ) {
            $options_by_question[ $option->question_id ][] = $option;
        }

        // 4. Get All Labels for these Questions (including color)
        $labels_by_question_raw = Terms_DB::get_linked_terms($question_ids, 'question', 'label', ['color']);
        $labels_by_question = [];
        foreach($labels_by_question_raw as $qid => $terms) {
            foreach ($terms as $term) {
                 $labels_by_question[$qid][] = (object) [
                    'label_id' => $term->term_id,
                    'label_name' => $term->name,
                    'label_color' => $term->meta['color'] ?? '#cccccc'
                ];
            }
        }


        // 5. Populate Questions with Options and Labels
        foreach ( $questions_in_group as $question ) {
            $question->options = $options_by_question[ $question->question_id ] ?? [];
            $question->labels = $labels_by_question[ $question->question_id ] ?? [];
        }

        // 6. Get Group Term Relationships (Subject/Topic, Source/Section, Exam)
        $group_terms_raw = Terms_DB::get_linked_terms( $group_id, 'group', ['subject', 'source', 'exam'] );
        $group_terms_processed = [
             'subject' => null, 'topic' => null, 'source' => null, 'section' => null, 'exam' => null
        ];

        $term_lineage_cache = []; 

        foreach ($group_terms_raw as $term) {
             if (!isset($term_lineage_cache[$term->term_id])) {
                $lineage_ids = [];
                $current_id = $term->term_id;
                for ($i=0; $i < 10; $i++){
                    if (!$current_id) break;
                    array_unshift($lineage_ids, $current_id);
                    $current_id = self::$wpdb->get_var( self::$wpdb->prepare("SELECT parent FROM ".Terms_DB::get_terms_table_name()." WHERE term_id = %d", $current_id) );
                }
                $term_lineage_cache[$term->term_id] = $lineage_ids;
             }
             $lineage_ids = $term_lineage_cache[$term->term_id];

             if ($term->taxonomy_name === 'subject') {
                 $group_terms_processed['subject'] = $lineage_ids[0] ?? null;
                 $group_terms_processed['topic'] = ($term->parent != 0) ? $term->term_id : null;
             } elseif ($term->taxonomy_name === 'source') {
                 $group_terms_processed['source'] = $lineage_ids[0] ?? null;
                 $group_terms_processed['section'] = ($term->parent != 0) ? $term->term_id : null;
             } elseif ($term->taxonomy_name === 'exam') {
                 $group_terms_processed['exam'] = $term->term_id;
             }
        }


        // 7. Return structured data
        return [
            'group'     => $group_data,
            'questions' => $questions_in_group,
            'terms'     => $group_terms_processed
        ];
    }

    /**
     * Retrieves detailed data for a single question for the frontend practice UI.
     *
     * @param int $question_id The ID of the question.
     * @param int $user_id     The ID of the current user.
     * @param int $session_id  The ID of the current practice session (optional, used for context).
     * @return array|null An associative array containing question details, or null if not found.
     */
    public static function get_question_details_for_practice( int $question_id, int $user_id, int $session_id = 0 ) {
        if ($question_id <= 0 || $user_id <= 0) {
            return null;
        }

        // 1. Fetch Basic Question & Group Data
        $question_base = self::get_question_by_id($question_id);
        if (!$question_base) {
            return null; // Question not found
        }

        $question_data = (array) $question_base;
        $group_id = $question_data['group_id'];

        // Add direction image URL
        $question_data['direction_image_url'] = $question_data['direction_image_id']
            ? wp_get_attachment_url($question_data['direction_image_id'])
            : null;
        unset($question_data['direction_image_id']);

        // 2. Get Subject/Topic & Source/Section Lineage Names
        $question_data['subject_lineage'] = [];
        $question_data['source_lineage'] = [];

        if ($group_id) {
            $group_terms = Terms_DB::get_linked_terms($group_id, 'group', ['subject', 'source']);
            foreach ($group_terms as $term) {
                $lineage = Terms_DB::get_lineage_names($term->term_id);
                if ($term->taxonomy_name === 'subject') {
                    $question_data['subject_lineage'] = $lineage;
                } elseif ($term->taxonomy_name === 'source') {
                    $question_data['source_lineage'] = $lineage;
                }
            }
        }

        // 3. Get Options (Text and ID only for practice)
        $o_table = self::get_options_table_name();
        $options_raw = self::$wpdb->get_results( self::$wpdb->prepare(
            "SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY option_id ASC",
            $question_id
        ), ARRAY_A);
        
        $question_data['options'] = array_map(function($opt) {
            $opt['option_text'] = wp_kses_post(nl2br($opt['option_text']));
            return $opt;
        }, $options_raw);


        // Get Correct Option ID separately
        $correct_option_id = self::get_correct_option_id($question_id);

        // 4. Apply nl2br/kses_post to question/direction text
        if (!empty($question_data['question_text'])) {
            $question_data['question_text'] = wp_kses_post(nl2br($question_data['question_text']));
        }
        if (!empty($question_data['direction_text'])) {
            $question_data['direction_text'] = wp_kses_post(nl2br($question_data['direction_text']));
        }


        // 5. Get User-Specific Statuses
        $a_table = self::$wpdb->prefix . 'qp_user_attempts';
        $review_table = self::$wpdb->prefix . 'qp_review_later';
        $reports_table = self::$wpdb->prefix . 'qp_question_reports';
        $meta_table = Terms_DB::get_term_meta_table_name();

        // Check attempt in *this* session
        $attempt_in_session = null;
        if ($session_id > 0) {
            $attempt_in_session = self::$wpdb->get_row( self::$wpdb->prepare(
                "SELECT attempt_id FROM {$a_table} WHERE user_id = %d AND question_id = %d AND session_id = %d",
                $user_id, $question_id, $session_id
            ));
        }

        // Count previous attempts in *other* sessions
        $previous_attempt_count = (int) self::$wpdb->get_var( self::$wpdb->prepare(
            "SELECT COUNT(*) FROM {$a_table} WHERE user_id = %d AND question_id = %d AND session_id != %d AND status = 'answered'",
            $user_id, $question_id, $session_id
        ));

        // Check if marked for review
        $is_marked = (bool) self::$wpdb->get_var( self::$wpdb->prepare(
            "SELECT COUNT(*) FROM {$review_table} WHERE user_id = %d AND question_id = %d",
            $user_id, $question_id
        ));

        // Check report status
        $report_info = ['has_report' => false, 'has_suggestion' => false];
        $open_reports = self::$wpdb->get_results( self::$wpdb->prepare(
             "SELECT report_id, reason_term_ids FROM {$reports_table} WHERE user_id = %d AND question_id = %d AND status = 'open'",
             $user_id, $question_id
        ));

        if (!empty($open_reports)) {
             $all_reason_ids = [];
             foreach ($open_reports as $report) {
                 $ids = array_filter(array_map('absint', explode(',', $report->reason_term_ids)));
                 if (!empty($ids)) {
                    $all_reason_ids = array_merge($all_reason_ids, $ids);
                 }
             }
             $all_reason_ids = array_unique($all_reason_ids);

             if (!empty($all_reason_ids)) {
                 $ids_placeholder = implode(',', $all_reason_ids);
                 $reason_types = self::$wpdb->get_col(
                    "SELECT meta_value FROM {$meta_table} WHERE meta_key = 'type' AND term_id IN ($ids_placeholder)"
                 );
                 if (in_array('report', $reason_types)) $report_info['has_report'] = true;
                 if (in_array('suggestion', $reason_types)) $report_info['has_suggestion'] = true;
             }
        }

        // Check role permission
        $user = get_userdata($user_id);
        $options = get_option('qp_settings');
        $allowed_roles = isset($options['show_source_meta_roles']) ? $options['show_source_meta_roles'] : [];
        $user_can_view_source = !empty(array_intersect((array)($user->roles ?? []), (array)$allowed_roles));

        // Remove source info if user lacks permission
        if (!$user_can_view_source) {
            $question_data['source_lineage'] = []; // Clear lineage
            $question_data['question_number_in_section'] = null; // Clear number
        }

        // 6. Assemble Final Result Array
        return [
            'question'             => $question_data,
            'correct_option_id'    => $correct_option_id,
            'attempt_id'           => $attempt_in_session ? (int) $attempt_in_session->attempt_id : null,
            'previous_attempt_count' => $previous_attempt_count,
            'is_revision'          => ($previous_attempt_count > 0),
            'is_admin'             => $user_can_view_source,
            'is_marked_for_review' => $is_marked,
            'reported_info'        => $report_info
        ];
    }

    /**
     * Retrieves all necessary data for rendering the quick edit form row for a question.
     *
     * @param int $question_id The ID of the question to get data for.
     * @return array|null An associative array containing all necessary data, or null if question not found.
     */
    public static function get_data_for_quick_edit( int $question_id ) {
        if ( $question_id <= 0 ) {
            return null;
        }

        // 1. Get Core Question and Group Data
        $question = self::get_question_by_id( $question_id );
        if ( ! $question ) {
            return null; // Question not found
        }
        $group_id = $question->group_id;

        // 2. Get Options for the Question
        $options = self::get_options_for_question( $question_id );
        // Sanitize option text for display in readonly input
        foreach ($options as $option) {
            $option->option_text = esc_attr($option->option_text);
        }
        // Sanitize question/direction text
        $question->question_text = wp_kses_post(nl2br($question->question_text));
        $question->direction_text = wp_kses_post(nl2br($question->direction_text));


        // 3. Get Current Term Relationships
        $current_terms = [
            'subject' => null, 'topic' => null, 'source' => null, 'section' => null, 'exam' => null, 'labels' => []
        ];
        if ($group_id) {
            $group_terms_raw = Terms_DB::get_linked_terms( $group_id, 'group', ['subject', 'source', 'exam'] );
            $term_lineage_cache = [];
            foreach ($group_terms_raw as $term) {
                 if (!isset($term_lineage_cache[$term->term_id])) {
                    $lineage_ids = [];
                    $current_id = $term->term_id;
                    for ($i=0; $i < 10; $i++){
                        if (!$current_id) break;
                        array_unshift($lineage_ids, $current_id);
                        $current_id = self::$wpdb->get_var( self::$wpdb->prepare("SELECT parent FROM ".Terms_DB::get_terms_table_name()." WHERE term_id = %d", $current_id) );
                    }
                    $term_lineage_cache[$term->term_id] = $lineage_ids;
                 }
                 $lineage_ids = $term_lineage_cache[$term->term_id];

                 if ($term->taxonomy_name === 'subject') {
                     $current_terms['subject'] = $lineage_ids[0] ?? null;
                     $current_terms['topic'] = ($term->parent != 0) ? $term->term_id : null;
                 } elseif ($term->taxonomy_name === 'source') {
                     $current_terms['source'] = $lineage_ids[0] ?? null;
                     $current_terms['section'] = ($term->parent != 0) ? $term->term_id : null;
                 } elseif ($term->taxonomy_name === 'exam') {
                     $current_terms['exam'] = $term->term_id;
                 }
            }
        }
        
        $labels_raw = Terms_DB::get_linked_terms($question_id, 'question', 'label');
        $current_terms['labels'] = wp_list_pluck($labels_raw, 'term_id');

        // 4. Get All Terms needed for Dropdowns
        $all_terms_data = [
            'subjects' => [], 'subject_terms' => [], 'source_terms' => [], 'exams' => [], 'labels' => []
        ];
        $tax_ids = [
            'subject' => Terms_DB::get_taxonomy_id_by_name('subject'),
            'source'  => Terms_DB::get_taxonomy_id_by_name('source'),
            'exam'    => Terms_DB::get_taxonomy_id_by_name('exam'),
            'label'   => Terms_DB::get_taxonomy_id_by_name('label'),
        ];
        $term_table = Terms_DB::get_terms_table_name();

        if ($tax_ids['subject']) {
            $all_terms_data['subjects'] = self::$wpdb->get_results(self::$wpdb->prepare(
                "SELECT term_id AS subject_id, name AS subject_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC", $tax_ids['subject']
            ));
            $all_terms_data['subject_terms'] = self::$wpdb->get_results(self::$wpdb->prepare(
                "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $tax_ids['subject']
            ));
        }
        if ($tax_ids['source']) {
            $all_terms_data['source_terms'] = self::$wpdb->get_results(self::$wpdb->prepare(
                "SELECT term_id as id, name, parent as parent_id FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $tax_ids['source']
            ));
        }
        if ($tax_ids['exam']) {
            $all_terms_data['exams'] = self::$wpdb->get_results(self::$wpdb->prepare(
                "SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $tax_ids['exam']
            ));
        }
        if ($tax_ids['label']) {
            $all_terms_data['labels'] = self::$wpdb->get_results(self::$wpdb->prepare(
                "SELECT term_id as label_id, name as label_name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC", $tax_ids['label']
            ));
        }

        // 5. Get Relationship Links for JS Filtering
        $rel_table = Terms_DB::get_relationships_table_name();
        $link_data = [
             'exam_subject_links'  => self::$wpdb->get_results("SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'", ARRAY_A),
             'source_subject_links' => self::$wpdb->get_results("SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'", ARRAY_A)
        ];

        // 6. Assemble Result
        return [
            'question'      => $question,
            'options'       => $options,
            'current_terms' => $current_terms,
            'all_terms'     => $all_terms_data,
            'links'         => $link_data
        ];
    }

    /**
     * Searches for published questions based on various criteria.
     *
     * @param array $args {
     * Optional. Array of search arguments.
     *
     * @type string $search       Search term (for ID or text). Default ''.
     * @type int    $subject_id   Filter by parent subject term ID. Default 0.
     * @type int    $topic_id     Filter by specific topic term ID. Default 0.
     * @type int    $source_id    Filter by top-level source term ID (includes descendants). Default 0.
     * @type int    $limit        Maximum number of results to return. Default 100.
     * }
     * @return array Array of matching question objects, each containing 'question_id' and 'question_text'.
     */
    public static function search_questions( $args = [] ) {
        $defaults = [
            'search'     => '',
            'subject_id' => 0,
            'topic_id'   => 0,
            'source_id'  => 0,
            'limit'      => 100,
        ];
        $args = wp_parse_args( $args, $defaults );

        // Table names
        $q_table = self::get_questions_table_name();
        $g_table = self::get_groups_table_name();
        $rel_table = Terms_DB::get_relationships_table_name();
        $term_table = Terms_DB::get_terms_table_name();

        // Base query structure
        $select_sql = "SELECT DISTINCT q.question_id, q.question_text";
        $query_from = "FROM {$q_table} q";
        $query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";
        $where_conditions = ["q.status = 'publish'"];
        $params = [];
        $joins_added = []; // Helper to avoid duplicate joins

        // Search term filter (ID or text)
        if ( ! empty( $args['search'] ) ) {
            $search_term = $args['search'];
            if ( is_numeric( $search_term ) ) {
                $where_conditions[] = self::$wpdb->prepare( "q.question_id = %d", absint( $search_term ) );
            } else {
                $like_term = '%' . self::$wpdb->esc_like( $search_term ) . '%';
                $where_conditions[] = self::$wpdb->prepare( "q.question_text LIKE %s", $like_term );
            }
        }

        // Subject/Topic filter
        if ( $args['topic_id'] > 0 ) {
            // Filter by specific topic
            if ( ! in_array( 'topic_rel', $joins_added ) ) {
                $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
                $joins_added[] = 'topic_rel';
            }
            $where_conditions[] = self::$wpdb->prepare( "topic_rel.term_id = %d", $args['topic_id'] );
        } elseif ( $args['subject_id'] > 0 ) {
            // Filter by subject (find all child topics)
            $child_topic_ids = Terms_DB::get_all_descendant_ids( $args['subject_id'] );
             $child_topic_ids = array_filter($child_topic_ids, function($tid) use ($args) {
                return $tid != $args['subject_id'];
            });

            if ( ! empty( $child_topic_ids ) ) {
                $ids_placeholder = implode( ',', $child_topic_ids );
                if ( ! in_array( 'topic_rel', $joins_added ) ) {
                    $query_joins .= " JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'";
                    $joins_added[] = 'topic_rel';
                }
                $where_conditions[] = "topic_rel.term_id IN ($ids_placeholder)";
            } else {
                $where_conditions[] = "1=0";
            }
        }

        // Source filter (includes descendants)
        if ( $args['source_id'] > 0 ) {
            $descendant_ids = Terms_DB::get_all_descendant_ids( $args['source_id'] );
            if ( ! empty( $descendant_ids ) ) {
                $ids_placeholder = implode( ',', $descendant_ids );
                if ( ! in_array( 'source_rel', $joins_added ) ) {
                    $query_joins .= " JOIN {$rel_table} source_rel ON g.group_id = source_rel.object_id AND source_rel.object_type = 'group'";
                    $joins_added[] = 'source_rel';
                }
                 $where_conditions[] = "source_rel.term_id IN ($ids_placeholder)";
            } else {
                $where_conditions[] = "1=0";
            }
        }

        // Construct final query
        $where_clause = ' WHERE ' . implode( ' AND ', $where_conditions );
        $limit_clause = self::$wpdb->prepare( " LIMIT %d", absint( $args['limit'] ) );
        $sql = $select_sql . " " . $query_from . " " . $query_joins . " " . $where_clause . " ORDER BY q.question_id DESC" . $limit_clause;

        // Execute query
        $query_to_run = empty($params) ? $sql : self::$wpdb->prepare($sql, $params);
        return self::$wpdb->get_results( $query_to_run, OBJECT );
    }

    /**
     * Retrieves detailed data for a single question for the REST API.
     *
     * @param int $question_id The ID of the question.
     * @return array|null An associative array containing question details, or null if not found/published.
     */
    public static function get_question_details_for_api( int $question_id ) {
        if ( $question_id <= 0 ) {
            return null;
        }

        // 1. Fetch Basic Question & Group Data (only if published)
        $q_table = self::get_questions_table_name();
        $g_table = self::get_groups_table_name();
        $question_base = self::$wpdb->get_row( self::$wpdb->prepare(
            "SELECT q.question_id, q.question_text, q.status, g.group_id, g.direction_text, g.direction_image_id
             FROM {$q_table} q
             LEFT JOIN {$g_table} g ON q.group_id = g.group_id
             WHERE q.question_id = %d",
            $question_id
        ) );

        if ( ! $question_base || $question_base->status !== 'publish' ) {
            return null;
        }

        $question_data = (array) $question_base;
        $group_id = $question_data['group_id'];

        // Add direction image URL
        $question_data['direction_image_url'] = $question_data['direction_image_id']
            ? wp_get_attachment_url( $question_data['direction_image_id'] )
            : null;
        unset( $question_data['direction_image_id'], $question_data['group_id'], $question_data['status'] );

        // 2. Get Subject/Topic & Source/Section Lineage Names
        $question_data['subject_lineage'] = [];
        $question_data['source_lineage'] = [];
        if ( $group_id ) {
            $group_terms = Terms_DB::get_linked_terms( $group_id, 'group', ['subject', 'source'] );
            foreach ( $group_terms as $term ) {
                $lineage = Terms_DB::get_lineage_names( $term->term_id );
                if ( $term->taxonomy_name === 'subject' ) {
                    $question_data['subject_lineage'] = $lineage;
                } elseif ( $term->taxonomy_name === 'source' ) {
                    $question_data['source_lineage'] = $lineage;
                }
            }
        }

        // 3. Get Options (ID and Text only)
        $o_table = self::get_options_table_name();
        $options_raw = self::$wpdb->get_results( self::$wpdb->prepare(
            "SELECT option_id, option_text FROM {$o_table} WHERE question_id = %d ORDER BY option_id ASC",
            $question_id
        ), ARRAY_A );
        
        $question_data['options'] = array_map(function($opt) {
            $opt['option_text'] = wp_kses_post(nl2br(stripslashes($opt['option_text'])));
            return $opt;
        }, $options_raw);


        // 4. Apply formatting to question/direction text
        if (!empty($question_data['question_text'])) {
            $question_data['question_text'] = wp_kses_post(nl2br(stripslashes($question_data['question_text'])));
        }
        if (!empty($question_data['direction_text'])) {
            $question_data['direction_text'] = wp_kses_post(nl2br(stripslashes($question_data['direction_text'])));
        }

        $question_data['question_id'] = (int) $question_data['question_id'];

        return $question_data;
    }

    /**
     * Retrieves an array of published question IDs for starting a session via the API.
     *
     * @param array $args {
     * Optional. Array of arguments.
     *
     * @type int|string $subject_id Filter by parent subject term ID, or 'all'. Default 'all'.
     * @type bool       $pyq_only   Whether to only include PYQ questions. Default false.
     * }
     * @return array An array of question IDs.
     */
    public static function get_question_ids_for_api_session( $args = [] ) {
        $defaults = [
            'subject_id' => 'all',
            'pyq_only'   => false,
        ];
        $args = wp_parse_args( $args, $defaults );

        // Table names
        $q_table = self::get_questions_table_name();
        $g_table = self::get_groups_table_name();
        $rel_table = Terms_DB::get_relationships_table_name();

        // Base query
        $select_sql = "SELECT q.question_id";
        $from_sql = " FROM {$q_table} q";
        $joins_sql = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";
        $where_conditions = ["q.status = 'publish'"];
        $params = [];
        $joins_added = [];

        // Subject filter (includes descendants)
        if ( $args['subject_id'] !== 'all' ) {
            $subject_id = absint( $args['subject_id'] );
            if ($subject_id > 0) {
                $term_ids_to_filter = Terms_DB::get_all_descendant_ids($subject_id);

                if (!empty($term_ids_to_filter)) {
                    $ids_placeholder = implode(',', $term_ids_to_filter);
                    if ( ! in_array( 'subject_rel', $joins_added ) ) {
                        $joins_sql .= " JOIN {$rel_table} subject_rel ON g.group_id = subject_rel.object_id AND subject_rel.object_type = 'group'";
                        $joins_added[] = 'subject_rel';
                    }
                    $where_conditions[] = "subject_rel.term_id IN ($ids_placeholder)";
                } else {
                    $where_conditions[] = "1=0";
                }
            } else {
                 $where_conditions[] = "1=0";
            }
        }

        // PYQ filter
        if ( $args['pyq_only'] ) {
            $where_conditions[] = "g.is_pyq = 1";
        }

        // Construct final query
        $where_sql = ' WHERE ' . implode( ' AND ', $where_conditions );
        $query = str_replace("SELECT q.question_id", "SELECT DISTINCT q.question_id", $select_sql)
               . $from_sql . $joins_sql . $where_sql;

        $query_to_run = empty($params) ? $query : self::$wpdb->prepare($query, $params);
        
        $question_ids = self::$wpdb->get_col( $query_to_run );
        shuffle($question_ids);

        return $question_ids;
    }

    /**
     * Checks if a single, enriched question item matches a set of list table filters.
     *
     * @param array $item    The enriched question item.
     * @param array $filters The array of filter values from the list table (e.g., $_REQUEST).
     * @return bool True if the item matches all active filters, false otherwise.
     */
    public static function check_question_matches_filters($item, $filters) {
        // 1. Check Status
        if (!empty($filters['status']) && $item['status'] !== $filters['status']) {
            return false;
        }

        // 2. Check Search
        if (!empty($filters['search'])) {
            $search_term = $filters['search'];
            if (is_numeric($search_term)) {
                if ($item['question_id'] != $search_term) {
                    return false;
                }
            } else {
                if (stripos($item['question_text'], $search_term) === false) {
                    return false;
                }
            }
        }
        
        // 3. Get item's actual subject/topic IDs
        $item_subject_id = null;
        $item_topic_id = $item['topic_id'] ?? null; // Use topic_id if it was enriched
        if ($item['subject_name'] && !$item_topic_id) {
             // This is less reliable, but a fallback
             $item_subject_id = Terms_DB::get_taxonomy_id_by_name($item['subject_name']); 
        } else if ($item_topic_id) {
            // Get parent subject from topic
            $item_subject_id = self::$wpdb->get_var(self::$wpdb->prepare("SELECT parent FROM ".Terms_DB::get_terms_table_name()." WHERE term_id = %d", $item_topic_id));
        }

        // 4. Check Subject Filter
        if (!empty($filters['subject_id'])) {
            if ($item_subject_id != $filters['subject_id']) {
                 return false;
            }
        }

        // 5. Check Topic Filter
        if (!empty($filters['topic_id'])) {
            if ($item_topic_id != $filters['topic_id']) {
                return false;
            }
        }

        // 6. Check Source/Section
        if (!empty($filters['source_filter'])) {
            $term_id_to_filter = 0;
            if (strpos($filters['source_filter'], 'source_') === 0) {
                $term_id_to_filter = absint(str_replace('source_', '', $filters['source_filter']));
            } elseif (strpos($filters['source_filter'], 'section_') === 0) {
                $term_id_to_filter = absint(str_replace('section_', '', $filters['source_filter']));
            }

            if ($term_id_to_filter > 0) {
                $source_and_section_ids = Terms_DB::get_all_descendant_ids($term_id_to_filter);
                if (empty($item['linked_source_term_id']) || !in_array($item['linked_source_term_id'], $source_and_section_ids)) {
                    return false;
                }
            }
        }

        // 7. Check Labels
        if (!empty($filters['label_ids'])) {
            // 'labels' on the item is an array of objects: [ (object)['label_id', 'label_name', 'label_color'] ]
            $item_label_ids = wp_list_pluck($item['labels'], 'label_id');
            $item_label_ids = array_map('intval', $item_label_ids);
            
            $missing_labels = array_diff($filters['label_ids'], $item_label_ids);
            if (!empty($missing_labels)) {
                return false;
            }
        }

        return true;
    }

} // End class Questions_DB