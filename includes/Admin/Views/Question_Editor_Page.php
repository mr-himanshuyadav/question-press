<?php

namespace QuestionPress\Admin\Views;


if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\Attempt_Evaluator;
use QuestionPress\Admin\Views\Course_Editor_Helper;
use QuestionPress\Utils\Template_Loader;

class Question_Editor_Page
{
    public static function handle_save_group()
    {
        // wp_send_json_success(['message' => 'DEBUG: Handler was reached']);
        // die();

        // 1. Security Checks
        if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
            wp_send_json_error(['message' => 'Security check failed.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        global $wpdb;
        $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
        $is_editing = $group_id > 0;

        // --- Get group-level data from the form ---
        $direction_text = isset($_POST['direction_text']) ? stripslashes($_POST['direction_text']) : '';
        $direction_image_id = absint($_POST['direction_image_id']);
        $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
        $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';
        $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

        // 2. Validation
        if (empty($_POST['subject_id'])) {
            wp_send_json_error(['message' => 'A subject is required to save a group.'], 400);
        }
        if (empty($questions_from_form)) {
             wp_send_json_error(['message' => 'At least one question is required to save a group.'], 400);
        }

        // --- 3. Save Group Data ---
        $group_data = [
            'direction_text'     => wp_kses_post($direction_text),
            'direction_image_id' => $direction_image_id,
            'is_pyq'             => $is_pyq,
            'pyq_year'           => $is_pyq ? $pyq_year : null,
        ];

        if ($is_editing) {
            Questions_DB::update_group( $group_id, $group_data );
        } else {
            $new_group_id = Questions_DB::insert_group( $group_data );
            if ($new_group_id) {
                $group_id = $new_group_id;
            } else {
                wp_send_json_error(['message' => 'Error creating question group in database.'], 500);
            }
        }

        // --- 4. CONSOLIDATED Group-Level Term Relationship Handling ---
        if ($group_id) {
            $rel_table = "{$wpdb->prefix}qp_term_relationships";
            $term_table = "{$wpdb->prefix}qp_terms";
            $tax_table = "{$wpdb->prefix}qp_taxonomies";

            $group_taxonomies_to_manage = ['subject', 'source', 'exam'];
            $tax_ids_to_clear = [];
            foreach ($group_taxonomies_to_manage as $tax_name) {
                $tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $tax_name));
                if ($tax_id) $tax_ids_to_clear[] = $tax_id;
            }

            if (!empty($tax_ids_to_clear)) {
                $tax_ids_placeholder = implode(',', $tax_ids_to_clear);
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN ($tax_ids_placeholder))",
                    $group_id
                ));
            }

            $terms_to_apply_to_group = [];
            if (!empty($_POST['topic_id'])) {
                $terms_to_apply_to_group[] = absint($_POST['topic_id']);
            } elseif (!empty($_POST['subject_id'])) {
                $terms_to_apply_to_group[] = absint($_POST['subject_id']);
            }
            if (!empty($_POST['section_id'])) {
                $terms_to_apply_to_group[] = absint($_POST['section_id']);
            } elseif (!empty($_POST['source_id'])) {
                $terms_to_apply_to_group[] = absint($_POST['source_id']);
            }
            if ($is_pyq && !empty($_POST['exam_id'])) {
                $terms_to_apply_to_group[] = absint($_POST['exam_id']);
            }

            foreach (array_unique($terms_to_apply_to_group) as $term_id) {
                if ($term_id > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $term_id, 'object_type' => 'group']);
                }
            }
        }

        // --- 5. Process Individual Questions (and their Label relationships) ---
        $q_table = "{$wpdb->prefix}qp_questions";
        $o_table = "{$wpdb->prefix}qp_options";
        $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
        $submitted_q_ids = [];

        foreach ($questions_from_form as $q_data) {
            $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
            if (empty(trim($question_text))) continue;

            // 1. Get and sanitize the explanation text
            $sanitized_explanation = isset($q_data['explanation_text']) ? wp_kses_post(stripslashes($q_data['explanation_text'])) : null;

            // 2. Check if it's meaningfully empty (after stripping tags and trimming whitespace)
            $is_empty = (trim(strip_tags($sanitized_explanation ?? '')) === '');
            $explanation_to_save = $is_empty ? null : $sanitized_explanation;

            $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
            $is_question_complete = !empty($q_data['correct_option_id']);

            $question_db_data = [
                'group_id' => $group_id,
                'question_number_in_section' => isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '',
                'question_text' => wp_kses_post($question_text),
                'explanation_text' => $explanation_to_save,
                'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
                'status' => $is_question_complete ? 'publish' : 'draft',
            ];

            if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
                $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
            } else {
                $wpdb->insert($q_table, $question_db_data);
                $question_id = $wpdb->insert_id;
            }
            $submitted_q_ids[] = $question_id;

            if ($question_id > 0) {
                // Handle Question-Level Relationships (LABELS ONLY)
                $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
                if ($label_tax_id) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $question_id, $label_tax_id));
                }
                $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
                foreach ($labels as $label_id) {
                    if ($label_id > 0) {
                        $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $label_id, 'object_type' => 'question']);
                    }
                }

                $original_correct_option_id = Questions_DB::get_correct_option_id($question_id);
                $correct_option_id_set = Questions_DB::save_options_for_question($question_id, $q_data);

                if ($original_correct_option_id != $correct_option_id_set) {
                    Attempt_Evaluator::re_evaluate_question_attempts($question_id, absint($correct_option_id_set));
                }
            }
        }   

        // --- 6. Clean up removed questions ---
        $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
        if (!empty($questions_to_delete)) {
            $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
            $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
            $wpdb->query("DELETE FROM $rel_table WHERE object_id IN ($ids_placeholder) AND object_type = 'question'");
            $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
        }

        // --- 7. Final JSON Response ---
        if ($is_editing && empty($submitted_q_ids)) {
            Questions_DB::delete_group_and_contents($group_id);
            // Send a success response with a redirect URL to the main page
            wp_send_json_success(['redirect_url' => admin_url('admin.php?page=question-press&message=1')]);
        }

        $redirect_url = $is_editing
            ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1')
            : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');

        // Always send a JSON success response
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    public static function render()
    {
        global $wpdb;
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        $is_editing = $group_id > 0;

        $open_reports = [];
        if ($is_editing) {
            $reports_table = $wpdb->prefix . 'qp_question_reports';
            $term_table = $wpdb->prefix . 'qp_terms';
            $questions_in_group_ids = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d", $group_id));

            if (!empty($questions_in_group_ids)) {
                $ids_placeholder = implode(',', $questions_in_group_ids);
                $reports_raw = $wpdb->get_results("
                SELECT question_id, GROUP_CONCAT(DISTINCT reason_term_ids SEPARATOR ',') as all_reason_ids, GROUP_CONCAT(comment SEPARATOR '|||') as all_comments
                FROM {$reports_table}
                WHERE status = 'open' AND question_id IN ($ids_placeholder)
                GROUP BY question_id
            ");

                foreach ($reports_raw as $report) {
                    $reason_ids = array_unique(array_filter(explode(',', $report->all_reason_ids)));
                    $reason_names = [];
                    if (!empty($reason_ids)) {
                        $reason_ids_placeholder = implode(',', array_map('absint', $reason_ids));
                        $reason_names = $wpdb->get_col("SELECT name FROM {$term_table} WHERE term_id IN ($reason_ids_placeholder)");
                    }

                    $open_reports[$report->question_id] = [
                        'reasons' => $reason_names,
                        'comments' => array_filter(explode('|||', $report->all_comments))
                    ];
                }
            }
        }

        // Data holders
        $direction_text = '';
        $direction_image_id = 0;
        $current_subject_id = 0;
        $current_topic_id = 0;
        $questions_in_group = [];
        $group_status = 'draft';

        // --- NEW data holders ---
        $is_pyq_group = false;
        $current_exam_id = 0;
        $current_pyq_year = '';
        $current_source_id = 0;
        $current_section_id = 0;
        $has_draft_question = false;


        if ($is_editing) {
            // --- NEW: Fetch all data using the DB class method ---
            $editor_data = Questions_DB::get_group_details_for_editor($group_id);

            if ($editor_data && $editor_data['group']) {
                $group_data = $editor_data['group']; // The group object
                $group_terms = $editor_data['terms']; // The processed term IDs array

                $direction_text = $group_data->direction_text;
                $direction_image_id = $group_data->direction_image_id;
                $is_pyq_group = !empty($group_data->is_pyq);
                $current_pyq_year = $group_data->pyq_year ?? '';

                // Assign term IDs directly from the processed array
                $current_subject_id = $group_terms['subject'];
                $current_topic_id = $group_terms['topic'];
                $current_source_id = $group_terms['source'];
                $current_section_id = $group_terms['section'];
                $current_exam_id = $group_terms['exam'];

                // Questions array is already populated with options and labels
                $questions_in_group = $editor_data['questions'];

                // Determine group status and check for draft questions
                if (!empty($questions_in_group)) {
                    $group_status = $questions_in_group[0]->status; // Get status from first question
                    foreach($questions_in_group as $q) {
                        if ($q->status === 'draft') {
                            $has_draft_question = true;
                            break;
                        }
                    }
                }

            } else {
                // Handle case where group wasn't found - maybe show an error and return?
                echo '<div class="notice notice-error"><p>Error: Could not load question group data.</p></div>';
                return;
            }
            // --- END NEW DATA FETCHING ---
        }

        // --- Keep the logic for initializing an empty question if $questions_in_group is still empty ---
        if (empty($questions_in_group)) {
            $questions_in_group[] = (object)['question_id' => 0, 'question_text' => '', 'options' => [], 'labels' => []];
            // Set default status if adding new
            $group_status = 'draft';
            $has_draft_question = true;
        }

        // --- Fetch ALL data needed for the form dropdowns from the new taxonomy system ---
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // --- Fetch ALL data needed for the form dropdowns from our new helper class ---
        $form_data = Course_Editor_Helper::get_question_editor_dropdown_data();

        $all_subjects         = $form_data['all_subjects'];
        $all_subject_terms    = $form_data['all_subject_terms'];
        $all_labels           = $form_data['all_labels'];
        $all_exams            = $form_data['all_exams'];
        $all_source_terms     = $form_data['all_source_terms'];
        $source_subject_links = $form_data['source_subject_links'];
        $exam_subject_links   = $form_data['exam_subject_links'];
        $all_parent_sources   = $form_data['all_parent_sources'];

        // Pass all necessary data to our JavaScript file
        wp_localize_script('qp-editor-script', 'qp_editor_data', [
            'all_subject_terms'   => $all_subject_terms,
            'all_source_terms'    => $all_source_terms,
            'source_subject_links' => $source_subject_links,
            'exam_subject_links'  => $exam_subject_links,
            'all_exams'           => $all_exams,
            'current_topic_id'    => $current_topic_id,
            'current_source_id'   => $current_source_id,
            'current_section_id'  => $current_section_id,
            'current_pyq_year'    => $current_pyq_year,
            'current_exam_id'     => $current_exam_id,
        ]);

        // --- CAPTURE MESSAGE HTML ---
        ob_start();
        if (isset($_GET['message'])) {
            $message = $_GET['message'] === '1' ? 'Question(s) updated successfully.' : 'Question(s) saved successfully.';
            echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        $message_html = ob_get_clean();
        // --- END CAPTURE ---
        
        // --- PREPARE BACK BUTTON ---
        $referer = wp_get_referer();
        $show_back_button = false;
        if ($referer) {
            $referer_query = parse_url($referer, PHP_URL_QUERY);
            if ($referer_query) {
                parse_str($referer_query, $query_vars);
                if (isset($query_vars['page']) && $query_vars['page'] !== 'question-press') {
                    $show_back_button = true;
                }
            }
        }
        // --- END PREPARE ---
        
        // --- PREPARE ARGS FOR TEMPLATE ---
        $args = [
            'message_html'         => $message_html,
            'open_reports'         => $open_reports,
            'group_id'             => $group_id,
            'is_editing'           => $is_editing,
            'show_back_button'     => $show_back_button,
            'referer'              => $referer,
            'has_draft_question'   => $has_draft_question,
            'direction_text'       => $direction_text,
            'direction_image_id'   => $direction_image_id,
            'questions_in_group'   => $questions_in_group,
            'all_labels'           => $all_labels,
            'all_subjects'         => $all_subjects,
            'current_subject_id'   => $current_subject_id,
            'is_pyq_group'         => $is_pyq_group,
            'all_exams'            => $all_exams,
            'current_exam_id'      => $current_exam_id,
            'current_pyq_year'     => $current_pyq_year,
            'all_parent_sources'   => $all_parent_sources,
            'current_source_id'    => $current_source_id,
        ];

        // --- CALL THE TEMPLATE ---
        echo Template_Loader::get_html( 'question-editor', 'admin', $args );
    }
}
