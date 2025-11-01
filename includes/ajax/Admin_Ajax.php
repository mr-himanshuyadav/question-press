<?php
namespace QuestionPress\Ajax; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Database\Terms_DB;
use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\Attempt_Evaluator;
use QuestionPress\Admin\Backup\Backup_Manager;
use WP_Error; // Use statement for WP_Error
use WP_Query; // Use statement for WP_Query
use QP_Questions_List_Table; // Use statement for list table

/**
 * Handles AJAX requests related to the WordPress Admin area.
 */
class Admin_Ajax {

    /**
     * AJAX handler for the admin list table.
     * Gets child topics for a given parent subject term.
     */
    public static function get_topics_for_list_table_filter() {
        check_ajax_referer('qp_admin_filter_nonce', 'nonce');
        $subject_term_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;

        if (!$subject_term_id) {
            wp_send_json_success(['topics' => []]);
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';

        // This query finds terms that are children of the selected subject term.
        $topics = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id as topic_id, name as topic_name
             FROM {$term_table}
             WHERE parent = %d
             ORDER BY name ASC",
            $subject_term_id
        ));

        wp_send_json_success(['topics' => $topics]);
    }

    /**
     * AJAX handler for the admin list table.
     * Gets sources/sections that have questions for a given subject and topic.
     */
    public static function get_sources_for_list_table_filter() {
        check_ajax_referer('qp_admin_filter_nonce', 'nonce');
        $subject_id = isset($_POST['subject_id']) ? absint($_POST['subject_id']) : 0;
        $topic_id = isset($_POST['topic_id']) ? absint($_POST['topic_id']) : 0;

        if (!$subject_id) {
            wp_send_json_success(['sources' => []]);
            return;
        }

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

        // Step 1: Get the relevant group IDs based on subject/topic filter
        $term_ids_to_check = [];
        if ($topic_id > 0) {
            $term_ids_to_check = [$topic_id];
        } else {
            // Fetch all descendants if only subject is selected
            $term_ids_to_check = Terms_DB::get_all_descendant_ids($subject_id);
            // Ensure the subject ID itself is included if needed based on structure
            // $term_ids_to_check[] = $subject_id;
             $term_ids_to_check = array_unique($term_ids_to_check);
        }


        $group_ids = [];
        if (!empty($term_ids_to_check)) {
            $term_ids_placeholder = implode(',', $term_ids_to_check);
            $group_ids = $wpdb->get_col("SELECT object_id FROM $rel_table WHERE term_id IN ($term_ids_placeholder) AND object_type = 'group'");
        }

        if (empty($group_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }
        $group_ids_placeholder = implode(',', array_unique($group_ids)); // Ensure unique group IDs


        // Step 2: Find all source/section terms linked to questions within those groups
        $linked_term_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.term_id
             FROM {$term_table} t
             JOIN {$rel_table} r ON t.term_id = r.term_id
             WHERE r.object_id IN ($group_ids_placeholder) AND r.object_type = 'group' AND t.taxonomy_id = %d",
            $source_tax_id
        ));

        if (empty($linked_term_ids)) {
            wp_send_json_success(['sources' => []]);
            return;
        }

        // Step 3: Fetch the full lineage (parents) for every linked term
        $full_lineage_ids = [];
        foreach ($linked_term_ids as $term_id) {
            $current_id = $term_id;
            for ($i = 0; $i < 10; $i++) { // Safety break
                if (!$current_id || in_array($current_id, $full_lineage_ids)) break;
                $full_lineage_ids[] = $current_id;
                $current_id = $wpdb->get_var($wpdb->prepare("SELECT parent FROM $term_table WHERE term_id = %d", $current_id));
            }
        }
        $all_relevant_term_ids = array_unique($full_lineage_ids);
        $all_relevant_term_ids_placeholder = implode(',', $all_relevant_term_ids);

        // Step 4: Fetch all details for the relevant terms
        $all_terms_data = $wpdb->get_results("SELECT term_id, name, parent FROM $term_table WHERE term_id IN ($all_relevant_term_ids_placeholder)");

        // Step 5: Build a hierarchical tree from the flat list
        $terms_by_id = [];
        foreach ($all_terms_data as $term) {
            $terms_by_id[$term->term_id] = $term;
            $term->children = [];
        }

        $tree = [];
        foreach ($terms_by_id as $term_id => &$term) {
             // Check parent exists before trying to access it
             if ($term->parent != 0 && isset($terms_by_id[$term->parent])) {
                 $terms_by_id[$term->parent]->children[] = &$term;
             } elseif ($term->parent == 0) {
                 $tree[] = &$term;
             }
        }
        unset($term); // Break reference


        // Sort the top-level sources by name
        usort($tree, function ($a, $b) {
            return strcmp($a->name, $b->name);
        });

        // Sort children recursively
         if (!function_exists('qp_sort_term_children_recursive')) {
            function qp_sort_term_children_recursive(&$terms) {
                usort($terms, function ($a, $b) { return strcmp($a->name, $b->name); });
                foreach ($terms as &$term) {
                    if (!empty($term->children)) {
                        qp_sort_term_children_recursive($term->children);
                    }
                }
            }
         }
         qp_sort_term_children_recursive($tree);


        wp_send_json_success(['sources' => $tree]);
    }

    /**
     * AJAX handler to get the HTML for the quick edit form row.
     */
    public static function get_quick_edit_form() {
        // 1. Security & Basic Validation
        check_ajax_referer('qp_get_quick_edit_form_nonce', 'nonce');
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        if (!$question_id) {
            wp_send_json_error(['message' => 'No Question ID provided.']);
        }

        // 2. Fetch ALL Data using the DB Method
        $data = Questions_DB::get_data_for_quick_edit($question_id);

        if (!$data) {
            wp_send_json_error(['message' => 'Could not retrieve data for this question.']);
        }

        // Extract data into variables for easier use in the template
        $question = $data['question']; // This is an object containing group info too
        $options = $data['options'];
        $current_terms = $data['current_terms'];
        $all_terms = $data['all_terms'];
        $links = $data['links'];

        // 3. Generate Form HTML using Output Buffering
        ob_start();
        ?>
        <script>
            // Localize necessary data for the quick-edit.js dropdown logic
            var qp_quick_edit_data = <?php echo wp_json_encode([
                                            // Pass only what the JS needs for dropdowns and current selections
                                            'all_subjects'        => $all_terms['subjects'],        // Array of {subject_id, subject_name}
                                            'all_subject_terms'   => $all_terms['subject_terms'],   // Array of {id, name, parent}
                                            'all_source_terms'    => $all_terms['source_terms'],    // Array of {id, name, parent_id}
                                            'all_exams'           => $all_terms['exams'],           // Array of {exam_id, exam_name}
                                            'all_labels'          => $all_terms['labels'],          // Array of {label_id, label_name}
                                            'exam_subject_links'  => $links['exam_subject_links'],
                                            'source_subject_links' => $links['source_subject_links'],
                                            'current_subject_id'  => $current_terms['subject'],
                                            'current_topic_id'    => $current_terms['topic'],
                                            'current_source_id'   => $current_terms['source'],
                                            'current_section_id'  => $current_terms['section'],
                                            'current_exam_id'     => $current_terms['exam'],
                                            'current_labels'      => $current_terms['labels'],     // Array of label IDs
                                        ]); ?>;
        </script>

        <form class="quick-edit-form-wrapper">
            <?php wp_nonce_field('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field'); ?>

            <div class="quick-edit-display-text">
                <?php // Display Direction and Question Text (already sanitized in DB method) ?>
                <?php if (!empty($question->direction_text)) : ?>
                    <div class="display-group">
                        <strong>Direction:</strong>
                        <p><?php echo $question->direction_text; ?></p>
                    </div>
                <?php endif; ?>
                <div class="display-group">
                    <strong>Question:</strong>
                    <p><?php echo $question->question_text; ?></p>
                </div>
            </div>

            <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr($question->status); // Pass status back ?>">

            <div class="quick-edit-main-container">
                <div class="quick-edit-col-left">
                    <label><strong>Correct Answer</strong></label>
                    <div class="options-group">
                        <?php foreach ($options as $option) : ?>
                            <label class="option-label">
                                <input type="radio" name="correct_option_id" value="<?php echo esc_attr($option->option_id); ?>" <?php checked($option->is_correct, 1); ?>>
                                <?php // Option text is already sanitized in DB method ?>
                                <input type="text" readonly value="<?php echo esc_attr($option->option_text); ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="quick-edit-col-right">
                    <?php // Dropdowns will be populated by JS using qp_quick_edit_data ?>
                    <div class="form-row-flex">
                        <div class="form-group-half qe-right-dropdowns">
                            <label for="qe-subject-<?php echo esc_attr($question_id); ?>"><strong>Subject</strong></label>
                            <select name="subject_id" id="qe-subject-<?php echo esc_attr($question_id); ?>" class="qe-subject-select">
                                 <option value="">— Select Subject —</option>
                                 <?php foreach ($all_terms['subjects'] as $subject) : ?>
                                    <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($subject->subject_id, $current_terms['subject']); ?>>
                                        <?php echo esc_html($subject->subject_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-half qe-right-dropdowns">
                            <label for="qe-topic-<?php echo esc_attr($question_id); ?>"><strong>Topic</strong></label>
                            <select name="topic_id" id="qe-topic-<?php echo esc_attr($question_id); ?>" class="qe-topic-select" disabled>
                                <option value="">— Select subject first —</option>
                                <?php // Options populated by JS ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-flex">
                        <div class="form-group-half qe-right-dropdowns">
                            <label for="qe-source-<?php echo esc_attr($question_id); ?>"><strong>Source</strong></label>
                            <select name="source_id" id="qe-source-<?php echo esc_attr($question_id); ?>" class="qe-source-select" disabled>
                                <option value="">— Select Subject First —</option>
                                 <?php // Options populated by JS ?>
                            </select>
                        </div>
                        <div class="form-group-half qe-right-dropdowns">
                            <label for="qe-section-<?php echo esc_attr($question_id); ?>"><strong>Section</strong></label>
                            <select name="section_id" id="qe-section-<?php echo esc_attr($question_id); ?>" class="qe-section-select" disabled>
                                <option value="">— Select Source First —</option>
                                 <?php // Options populated by JS ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-flex qe-pyq-fields-wrapper" style="align-items: center;">
                        <div class="form-group-shrink">
                            <label class="inline-checkbox">
                                <input type="checkbox" name="is_pyq" value="1" class="qe-is-pyq-checkbox" <?php checked($question->is_pyq, 1); ?>> Is PYQ?
                            </label>
                        </div>
                        <div class="form-group-expand qe-pyq-fields" style="<?php echo $question->is_pyq ? '' : 'display: none;'; ?>">
                            <div class="form-group-half">
                                <select name="exam_id" class="qe-exam-select" <?php echo !$current_terms['subject'] ? 'disabled' : ''; ?>>
                                    <option value="">— Select Exam —</option>
                                    <?php // Options populated by JS ?>
                                </select>
                            </div>
                            <div class="form-group-half">
                                <input type="number" name="pyq_year" value="<?php echo esc_attr($question->pyq_year); ?>" placeholder="Year (e.g., 2023)">
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <label><strong>Labels</strong></label>
                        <div class="labels-group">
                            <?php foreach ($all_terms['labels'] as $label) : ?>
                                <label class="inline-checkbox">
                                    <input type="checkbox" name="labels[]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_terms['labels'])); ?>>
                                    <?php echo esc_html($label->label_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <p class="submit inline-edit-save">
                <button type="button" class="button-secondary cancel">Cancel</button>
                <button type="button" class="button-primary save">Update</button>
            </p>
        </form>

        <style>
            .quick-edit-display-text { background-color: #f6f7f7; border: 1px solid #e0e0e0; padding: 10px 20px; margin: 20px 20px 10px; border-radius: 4px }
            .quick-edit-display-text .display-group { margin-bottom: 10px }
            .options-group label:last-child, .quick-edit-display-text .display-group:last-child, .quick-edit-form-wrapper .form-row:last-child { margin-bottom: 0 }
            .quick-edit-display-text p { margin: 5px 0 0; padding-left: 10px; border-left: 3px solid #ccc; color: #555; font-style: italic }
            .quick-edit-form-wrapper h4 { font-size: 16px; margin-top: 20px; margin-bottom: 10px; padding: 10px 20px }
            .inline-edit-row .submit { padding: 20px }
            .quick-edit-form-wrapper .title { font-size: 15px; font-weight: 500; color: #555 }
            .quick-edit-form-wrapper .form-row, .quick-edit-form-wrapper .form-row-flex { margin-bottom: 1rem }
            .quick-edit-form-wrapper label, .quick-edit-form-wrapper strong { font-weight: 600; display: block; margin-bottom: 0rem }
            .quick-edit-form-wrapper select, .quick-edit-form-wrapper input[type="number"] { width: 100%; box-sizing: border-box; } /* Ensure number input is full width */
            .quick-edit-main-container { display: flex; gap: 20px; margin-bottom: 1rem; padding: 0 20px }
            .form-row-flex .qe-pyq-fields { display: flex; gap: 1rem; flex-grow: 1; } /* Allow PYQ fields to grow */
            .form-row-flex .qe-right-dropdowns { display: flex; flex-direction: column; gap: 0.5rem; flex: 1; }
            .labels-group, .options-group { display: flex; padding: .5rem; border: 1px solid #ddd; background: #fff }
            .quick-edit-col-left { flex: 0 0 40% }
            .form-group-half, .quick-edit-col-right { flex: 1 }
            .form-group-shrink { flex-shrink: 0; margin-right: 10px; } /* Prevent PYQ checkbox from shrinking */
            .form-group-expand { flex-grow: 1; } /* Allow PYQ fields container to expand */
            .options-group { flex-direction: column; justify-content: space-between; height: auto; box-sizing: border-box; gap: 10px; }
            .option-label { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem }
            .option-label input[type=radio] { margin-top: 0; align-self: center }
            .option-label input[type=text] { width: 90%; background-color: #f0f0f1; border: none; padding: 8px; border-radius: 3px;} /* Readonly style */
            .form-row-flex { display: flex; gap: 1rem; align-items: center; } /* Align items vertically center */
            .quick-edit-form-wrapper p.submit button.button-secondary { margin-right: 10px }
            .labels-group { flex-wrap: wrap; gap: .5rem 1rem }
            .inline-checkbox { white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; } /* Better alignment for inline checkboxes */
        </style>
        <?php
        $form_html = ob_get_clean();

        // 4. Send JSON Response
        wp_send_json_success(['form' => $form_html]);
    }

    /**
     * AJAX handler to save the data from the quick edit form.
     */
    public static function save_quick_edit_data() {
        // Step 1: Security check and data validation
        check_ajax_referer('qp_save_quick_edit_nonce', 'qp_save_quick_edit_nonce_field');

        $data = $_POST;
        $question_id = isset($data['question_id']) ? absint($data['question_id']) : 0;
        if (!$question_id) {
            wp_send_json_error(['message' => 'Invalid Question ID provided.']);
        }

        // Step 2: Setup database variables
        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $o_table = $wpdb->prefix . 'qp_options';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';

        // Step 3: Get necessary IDs for processing
        $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM $q_table WHERE question_id = %d", $question_id));

        // Step 4: Update Group-Level Data (PYQ status)
        if ($group_id) {
            $wpdb->update($g_table, [
                'is_pyq' => isset($data['is_pyq']) ? 1 : 0,
                'pyq_year' => (isset($data['is_pyq']) && !empty($data['pyq_year'])) ? sanitize_text_field($data['pyq_year']) : null
            ], ['group_id' => $group_id]);
        }

        // Step 5: CONSOLIDATED Group and Question-Level Term Relationships
        if ($group_id) {
            // --- 5a: Handle ALL Group-Level Relationships ---
            $group_taxonomies = ['subject', 'source', 'exam'];
            $tax_ids_to_clear = [];

            foreach ($group_taxonomies as $tax_name) {
                $tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $tax_name));
                if ($tax_id) $tax_ids_to_clear[] = $tax_id;
            }

            // Delete all existing group relationships for these taxonomies in one query
            if (!empty($tax_ids_to_clear)) {
                $tax_ids_placeholder = implode(',', $tax_ids_to_clear);
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$rel_table}
                     WHERE object_id = %d AND object_type = 'group'
                     AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN ($tax_ids_placeholder))",
                    $group_id
                ));
            }

            // Insert new relationships for the group
            $group_terms_to_apply = [];
            // Subject/Topic: Link the group to the most specific topic selected.
            if (!empty($data['topic_id'])) $group_terms_to_apply[] = absint($data['topic_id']);
            // *** ADDED FALLBACK TO SUBJECT ***
            elseif (!empty($data['subject_id'])) $group_terms_to_apply[] = absint($data['subject_id']);


            // Source/Section: Link the group to the most specific term (section > source).
            if (!empty($data['section_id'])) {
                $group_terms_to_apply[] = absint($data['section_id']);
            } elseif (!empty($data['source_id'])) {
                $group_terms_to_apply[] = absint($data['source_id']);
            }

            // Exam: Link the group if it's a PYQ and an exam is selected
            if (isset($data['is_pyq']) && !empty($data['exam_id'])) {
                $group_terms_to_apply[] = absint($data['exam_id']);
            }

            // Insert all new group relationships
            foreach (array_unique($group_terms_to_apply) as $term_id) {
                if ($term_id > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $term_id, 'object_type' => 'group']);
                }
            }
        }

        // --- 5b: Handle Question-Level Relationships (Labels) ---
        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
        if ($label_tax_id) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rel_table}
                 WHERE object_id = %d AND object_type = 'question'
                 AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)",
                $question_id,
                $label_tax_id
            ));
        }

        if (!empty($data['labels']) && is_array($data['labels'])) {
            foreach ($data['labels'] as $label_id) {
                if (absint($label_id) > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => absint($label_id), 'object_type' => 'question']);
                }
            }
        }

        // Step 6: Update the Correct Answer Option and Re-evaluate
        $new_correct_option_id = isset($data['correct_option_id']) ? absint($data['correct_option_id']) : 0;
        if ($new_correct_option_id > 0) {
            // Get the original correct option ID before making any changes.
            $original_correct_option_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$o_table} WHERE question_id = %d AND is_correct = 1", $question_id));

            // Update the database
            $wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]);
            $wpdb->update($o_table, ['is_correct' => 1], ['option_id' => $new_correct_option_id, 'question_id' => $question_id]);

            // Update question status based on whether a correct answer is now set
            $new_status = 'publish';
            $wpdb->update($q_table, ['status' => $new_status], ['question_id' => $question_id]);


            // If the correct answer has changed, trigger the re-evaluation.
             if ($original_correct_option_id != $new_correct_option_id) {
                    Attempt_Evaluator::re_evaluate_question_attempts($question_id, $new_correct_option_id);
             }

        } else {
             // If no correct option was selected, ensure the status is 'draft'
             $wpdb->update($q_table, ['status' => 'draft'], ['question_id' => $question_id]);
             $wpdb->update($o_table, ['is_correct' => 0], ['question_id' => $question_id]); // Ensure no option is marked correct
        }

        // Step 7: Re-render the updated table row and send it back
         // --- Re-fetch the updated item to pass to single_row ---
         $list_table_args = [
            'status'        => isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : ($question->status ?? 'publish'), // Use updated status
            'subject_id'    => isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : 0,
            'topic_id'      => isset($_REQUEST['filter_by_topic']) ? absint($_REQUEST['filter_by_topic']) : 0,
            'source_filter' => isset($_REQUEST['filter_by_source']) ? sanitize_text_field($_REQUEST['filter_by_source']) : '',
            'label_ids'     => isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [],
            'search'        => isset($_REQUEST['s']) ? sanitize_text_field(stripslashes($_REQUEST['s'])) : '',
            'orderby'       => 'question_id', // Keep simple for single row fetch
            'order'         => 'DESC',
            'per_page'      => 1,             // Only need 1 item
            'current_page'  => 1,
        ];

        // Add specific ID filter
        $where_conditions = [ $wpdb->prepare("q.question_id = %d", $question_id) ];
        $params = [];

         // Construct the query parts similar to get_questions_for_list_table
         $q_table = Questions_DB::get_questions_table_name();
         $g_table = Questions_DB::get_groups_table_name();
         $select_sql = "SELECT DISTINCT q.*, g.group_id, g.direction_text, g.direction_image_id, g.is_pyq, g.pyq_year";
         $query_from = "FROM {$q_table} q";
         $query_joins = " LEFT JOIN {$g_table} g ON q.group_id = g.group_id";
         // Simplified WHERE for single ID fetch, assuming status check happened already or isn't needed here
         $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);

         $item_query = $select_sql . " " . $query_from . " " . $query_joins . " " . $where_clause . " LIMIT 1";
         $updated_item_raw = $wpdb->get_row( $wpdb->prepare($item_query, $params), ARRAY_A );

         if ($updated_item_raw) {
             $items_array = [$updated_item_raw]; // Put in array for enrich function
             Questions_DB::enrich_questions_with_terms($items_array); // Use the enrich function
             $updated_item = $items_array[0];

             // Check if the item still matches the current list table filters
             $list_table = new QP_Questions_List_Table(); // Need an instance
             $current_filters = [ // Reconstruct filters from request
                 'status'        => isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : 'publish',
                 'subject_id'    => isset($_REQUEST['filter_by_subject']) ? absint($_REQUEST['filter_by_subject']) : 0,
                 'topic_id'      => isset($_REQUEST['filter_by_topic']) ? absint($_REQUEST['filter_by_topic']) : 0,
                 'source_filter' => isset($_REQUEST['filter_by_source']) ? sanitize_text_field($_REQUEST['filter_by_source']) : '',
                 'label_ids'     => isset($_REQUEST['filter_by_label']) ? array_filter(array_map('absint', (array)$_REQUEST['filter_by_label'])) : [],
                 'search'        => isset($_REQUEST['s']) ? sanitize_text_field(stripslashes($_REQUEST['s'])) : '',
             ];

             $matches_filters = Questions_DB::check_question_matches_filters($updated_item, $current_filters);


             if ($matches_filters) {
                 ob_start();
                 $list_table->single_row($updated_item); // Pass the enriched item
                 $row_html = ob_get_clean();
                 wp_send_json_success(['row_html' => $row_html]);
             } else {
                 // Item no longer matches filters, send empty row to remove it
                 wp_send_json_success(['row_html' => '']);
             }

         } else {
              // If item couldn't be re-fetched (e.g., status changed to trash and current view is publish)
              wp_send_json_success(['row_html' => '']); // Send empty to remove
         }
    }

    /**
     * AJAX handler to create a new backup.
     */
    public static function create_backup() {
        check_ajax_referer('qp_backup_restore_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $result = Backup_Manager::perform_backup('manual');
        if ($result['success']) {
             $backups_html = Backup_Manager::get_local_backups_html();
             wp_send_json_success(['backups_html' => $backups_html]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX handler to delete a local backup file.
     */
    public static function delete_backup() {
        check_ajax_referer('qp_backup_restore_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';

        if (empty($filename) || (strpos($filename, 'qp-backup-') !== 0 && strpos($filename, 'qp-auto-backup-') !== 0 && strpos($filename, 'uploaded-') !== 0) || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
            wp_send_json_error(['message' => 'Invalid or malicious filename provided.']);
        }

        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
        $file_path = trailingslashit($backup_dir) . $filename;

        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                 $backups_html = Backup_Manager::get_local_backups_html();
                 wp_send_json_success(['backups_html' => $backups_html, 'message' => 'Backup deleted successfully.']);
            } else {
                wp_send_json_error(['message' => 'Could not delete the file. Please check file permissions.']);
            }
        } else {
            wp_send_json_error(['message' => 'File not found. It may have already been deleted.']);
        }
    }

    /**
     * AJAX handler to restore a backup from a local file.
     */
    public static function restore_backup() {
        check_ajax_referer('qp_backup_restore_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
        if (empty($filename)) {
            wp_send_json_error(['message' => 'Invalid filename.']);
        }

        $result = Backup_Manager::perform_restore($filename);
        if ($result['success']) {
            wp_send_json_success(['message' => 'Data has been successfully restored.', 'stats' => $result['stats']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX handler to regenerate the JWT secret key.
     */
    public static function regenerate_api_key() {
        check_ajax_referer('qp_regenerate_api_key_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $new_key = wp_generate_password(64, true, true);
        update_option('qp_jwt_secret_key', $new_key);

        wp_send_json_success(['new_key' => $new_key]);
    }


} // End class Admin_Ajax