<?php

namespace QuestionPress\Admin\Views;


if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Questions_DB;
use QuestionPress\Utils\Attempt_Evaluator;
use QuestionPress\Admin\Views\Course_Editor_Helper;

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

            $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
            $is_question_complete = !empty($q_data['correct_option_id']);

            $question_db_data = [
                'group_id' => $group_id,
                'question_number_in_section' => isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '',
                'question_text' => wp_kses_post($question_text),
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

        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'");
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

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

        if (isset($_GET['message'])) {
            $message = $_GET['message'] === '1' ? 'Question(s) updated successfully.' : 'Question(s) saved successfully.';
            echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
?>
        <div class="wrap">
            <?php if (!empty($open_reports)): ?>
                <div class="notice notice-error" style="padding: 1rem; border-left-width: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0 0 0.5rem 0;">&#9888; Open Reports for this Group</h3>
                            <p style="margin-top: 0;">The following questions have open reports. Resolving them will remove them from the "Needs Review" queue.</p>
                            <ul style="list-style: disc; padding-left: 20px; margin-bottom: 0;">
                                <?php foreach ($open_reports as $qid => $report_data): ?>
                                    <li><strong>Question #<?php echo esc_html($qid); ?>:</strong> <?php echo esc_html(implode(', ', $report_data['reasons'])); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div>
                            <?php
                            $resolve_nonce = wp_create_nonce('qp_resolve_group_reports_' . $group_id);
                            $resolve_url = esc_url(admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&action=resolve_group_reports&_wpnonce=' . $resolve_nonce));
                            ?>
                            <a href="<?php echo $resolve_url; ?>" class="button button-primary">Resolve All Reports</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <h1 class="wp-heading-inline"><?php echo $is_editing ? 'Edit Question Group' : 'Add New Question Group'; ?></h1>
            <?php
            $referer = wp_get_referer();
            $show_back_button = false;
            if ($referer) {
                $referer_query = parse_url($referer, PHP_URL_QUERY);
                if ($referer_query) {
                    parse_str($referer_query, $query_vars);
                    // Show the button if the user came from a WP admin page that is NOT the main question list
                    if (isset($query_vars['page']) && $query_vars['page'] !== 'question-press') {
                        $show_back_button = true;
                    }
                }
            }

            if ($show_back_button) : ?>
                <a href="<?php echo esc_url($referer); ?>" class="page-title-action qp-back-button">&larr; Back to Previous Page</a>
            <?php endif; ?>
            <a href="<?php echo admin_url('admin.php?page=question-press'); ?>" class="page-title-action">All Questions</a>
            <?php if ($is_editing) : ?>
                <a href="<?php echo admin_url('admin.php?page=qp-question-editor'); ?>" class="page-title-action">Add New Question</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <form method="post" action="" class='qp-question-editor-form-wrapper'>
                <input type="hidden" name="action" value="qp_save_question_group">
                <?php wp_nonce_field('qp_save_question_group_nonce'); ?>
                <input type="hidden" name="group_id" value="<?php echo esc_attr($group_id); ?>">

                <?php if (!$is_editing) : ?>
                    <div class="notice notice-info inline">
                        <p><b>Step 1 of 2:</b> Enter the question text and categorization details. You will add options and labels in the next step.</p>
                    </div>
                <?php elseif ($is_editing && $group_status === 'draft') : ?>
                    <div class="notice notice-warning inline">
                        <p><b>Step 2 of 2:</b> This question is a draft. Please add options for each question and select a correct answer to publish it.</p>
                    </div>
                <?php endif; ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <?php if ($is_editing && $has_draft_question) : ?>
                                <div class="notice notice-warning inline" style="margin: 0; margin-bottom: 5px;">
                                    <p><strong>Draft Status:</strong> This group contains one or more questions that are still drafts (missing a correct answer). Draft questions will not appear on the frontend until they are completed and published.</p>
                                </div>
                            <?php endif; ?>
                            <div class="postbox">
                                <h2 class="hndle">
                                    <span>
                                        Direction (Optional Passage)
                                        <?php if ($is_editing) : ?>
                                            <small style="font-weight: normal; font-size: 12px; color: #777;"> | Group ID: <?php echo esc_html($group_id); ?></small>
                                        <?php endif; ?>
                                    </span>
                                </h2>
                                <div class="inside">
                                    <?php
                                    wp_editor(
                                        $direction_text, // The content
                                        'direction_text_editor', // A unique ID
                                        [
                                            'textarea_name' => 'direction_text',
                                            'textarea_rows' => 5,
                                            'media_buttons' => false, // Optional: hide the "Add Media" button
                                            'tinymce'       => ['toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist'],
                                        ]
                                    );
                                    ?>
                                    <hr>
                                    <div>
                                        <input type="hidden" name="direction_image_id" id="direction-image-id" value="<?php echo esc_attr($direction_image_id); ?>">
                                        <div id="qp-direction-image-preview" style="margin-top: 10px; margin-bottom: 10px; max-width: 400px;">
                                            <?php if ($direction_image_id) : ?>
                                                <img src="<?php echo esc_url(wp_get_attachment_url($direction_image_id)); ?>" style="max-width:100%; height:auto;">
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button" id="qp-upload-image-button">Upload/Select Image</button>
                                        <button type="button" class="button" id="qp-remove-image-button" style="<?php echo $direction_image_id ? '' : 'display:none;'; ?>">Remove Image</button>
                                    </div>
                                </div>
                            </div>

                            <div class="notice notice-info inline" style="margin-top: 1rem;">
                                <p>
                                    <strong>Tip:</strong> You can use LaTeX for mathematical notations in the Direction, Question, and Option fields. For example, use <code>$ E = mc^2 $</code> for inline and <code>$$ \frac{a}{b} $$</code> for block equations.
                                </p>
                            </div>

                            <div id="qp-question-blocks-container">
                                <?php foreach ($questions_in_group as $q_index => $question) :
                                    $current_label_ids = wp_list_pluck($question->labels, 'label_id');

                                    // Set the correct initial status class
                                    if ($question->question_id > 0) {
                                        $status_class = 'status-' . ($question->status ?? 'draft');
                                    } else {
                                        $status_class = 'status-new';
                                    }

                                    // Prioritize 'reported' status for highlighting
                                    if (isset($open_reports[$question->question_id])) {
                                        $status_class = 'status-reported';
                                    }
                                ?>
                                    <div class="postbox qp-question-block <?php echo esc_attr($status_class); ?>">
                                        <div class="postbox-header">
                                            <button type="button" class="qp-toggle-question-block" title="Toggle visibility" style="padding: 0;">
                                                <span class="dashicons dashicons-arrow-down-alt2" style="margin-left: 5px;"></span>
                                            </button>
                                            <h2 class="hndle">
                                                <span>
                                                    <span class="qp-question-title">Q<?php echo ($q_index + 1); ?>: Question (ID: <?php echo $question->question_id > 0 ? esc_html($question->question_id) : 'New'; ?>)</span>
                                                    <small style="font-weight: normal; font-size: 12px; color: #777; margin-left: 15px;">
                                                        <label for="question_number_in_section_<?php echo $q_index; ?>" style="vertical-align: middle;"><strong>Q. No:</strong></label>
                                                        <input type="text" name="questions[<?php echo $q_index; ?>][question_number_in_section]" id="question_number_in_section_<?php echo $q_index; ?>" value="<?php echo esc_attr($question->question_number_in_section ?? ''); ?>" style="width: 80px; vertical-align: middle; margin-left: 5px; font-weight: normal;">
                                                    </small>
                                                    <div class="qp-header-label-dropdown-container qp-custom-dropdown">
                                                        <button type="button" class="button qp-dropdown-toggle">
                                                            <span>
                                                                <?php
                                                                $count = count($current_label_ids);
                                                                echo $count > 0 ? esc_html($count) . ' Label(s)' : 'Select Labels';
                                                                ?>
                                                            </span>
                                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                                        </button>
                                                        <div class="qp-dropdown-panel">
                                                            <?php foreach ($all_labels as $label) : ?>
                                                                <label>
                                                                    <input type="checkbox" name="questions[<?php echo $q_index; ?>][labels][]" value="<?php echo esc_attr($label->label_id); ?>" <?php checked(in_array($label->label_id, $current_label_ids)); ?>>
                                                                    <?php echo esc_html($label->label_name); ?>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($question->question_id > 0) : ?>
                                                        <?php if (isset($open_reports[$question->question_id])) : ?>
                                                            <span class="qp-status-indicator qp-reported-indicator" title="This question has open reports. Reason(s): <?php echo esc_attr(implode(', ', $open_reports[$question->question_id]['reasons'])); ?>">
                                                                <span class="dashicons dashicons-warning"></span> Reported
                                                            </span>
                                                        <?php else: ?>
                                                            <?php
                                                            $status = $question->status ?? 'draft';
                                                            $status_color = $status === 'publish' ? '#4CAF50' : '#FFC107';
                                                            $status_text = $status === 'publish' ? 'Published' : 'In Draft';
                                                            ?>
                                                            <span class="qp-status-indicator" style="background-color: <?php echo $status_color; ?>;"><?php echo esc_html($status_text); ?></span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </span>
                                            </h2>
                                            <div class="handle-actions qp-editor-quesiton-remove-btn">
                                                <button type="button" class="button-link-delete remove-question-block" title="Remove this question" style="cursor: pointer;">
                                                    <span class="dashicons dashicons-no-alt"></span> Remove
                                                </button>
                                            </div>
                                        </div>
                                        <div class="inside">
                                            <?php
                                            // Check if this specific question has any open reports with comments
                                            if (isset($open_reports[$question->question_id]) && !empty($open_reports[$question->question_id]['comments'])):
                                                foreach ($open_reports[$question->question_id]['comments'] as $comment):
                                            ?>
                                                    <div class="notice notice-alt notice-warning qp-reporter-comment-notice" style="margin: 0 0 15px; padding: 10px; border-left-width: 4px;">
                                                        <p style="margin: 0;"><strong>Reporter's Comment:</strong> <?php echo esc_html($comment); ?></p>
                                                    </div>
                                            <?php
                                                endforeach;
                                            endif;
                                            ?>
                                            <input type="hidden" name="questions[<?php echo $q_index; ?>][question_id]" class="question-id-input" value="<?php echo esc_attr($question->question_id); ?>">
                                            <?php if (!empty($question->labels)) : ?>
                                                <div id="qp-labels-container-<?php echo $q_index; ?>" class="qp-editor-labels-container" data-editor-id="question_text_editor_<?php echo $q_index; ?>">
                                                    <?php foreach ($question->labels as $label) : ?>
                                                        <span class="qp-label" style="background-color: <?php echo esc_attr($label->label_color); ?>; color: #fff; padding: 2px 6px; font-size: 11px; border-radius: 3px; font-weight: 600;">
                                                            <?php echo esc_html($label->label_name); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php
                                            wp_editor(
                                                $question->question_text, // The content
                                                'question_text_editor_' . $q_index, // A unique ID for each editor
                                                [
                                                    'textarea_name' => 'questions[' . $q_index . '][question_text]',
                                                    'textarea_rows' => 5,
                                                    'media_buttons' => false,
                                                    'tinymce'       => ['toolbar1' => 'bold,italic,underline,link,unlink,bullist,numlist'],
                                                ]
                                            );
                                            ?>
                                            <?php if ($is_editing) : ?>
                                                <div class="qp-options-and-labels-wrapper">
                                                    <hr>
                                                    <p><strong>Options (Select the radio button for the correct answer)</strong></p>
                                                    <div class="qp-options-grid-container">
                                                        <?php
                                                        // Always show at least 4 options
                                                        $options_to_show = 4;
                                                        // If a 5th option exists and has text, make sure we show it
                                                        if (isset($question->options[4]) && !empty($question->options[4]->option_text)) {
                                                            $options_to_show = 5;
                                                        }
                                                        // If a 6th option exists and has text, make sure we show it
                                                        if (isset($question->options[5]) && !empty($question->options[5]->option_text)) {
                                                            $options_to_show = 6;
                                                        }

                                                        for ($i = 0; $i < $options_to_show; $i++) :
                                                            $option = isset($question->options[$i]) ? $question->options[$i] : null;
                                                            $option_id_value = $option ? esc_attr($option->option_id) : 'new_' . $i;
                                                            $is_correct = $option ? $option->is_correct : false;
                                                        ?>
                                                            <div class="qp-option-row">
                                                                <input type="radio" name="questions[<?php echo $q_index; ?>][correct_option_id]" value="<?php echo $option_id_value; ?>" <?php checked($is_correct); ?>>
                                                                <input type="hidden" name="questions[<?php echo $q_index; ?>][option_ids][]" value="<?php echo $option ? esc_attr($option->option_id) : '0'; ?>">
                                                                <input type="text" name="questions[<?php echo $q_index; ?>][options][]" class="option-text-input" value="<?php echo $option ? esc_attr($option->option_text) : ''; ?>" placeholder="Option <?php echo $i + 1; ?>">
                                                                <?php if ($option && $option->option_id): ?>
                                                                    <small class="option-id-display">ID: <?php echo esc_html($option->option_id); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <div class="qp-options-actions">
                                                        <button type="button" class="button button-secondary add-new-option-btn" <?php if ($options_to_show >= 6) echo 'style="display:none;"'; ?>>+ Add Option</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-new-question-block" class="button button-secondary">+ Add Another Question</button>
                        </div>

                        <div id="postbox-container-1" class="postbox-container">
                            <div class="postbox">
                                <div id="major-publishing-actions" style="text-align: center;">
                                    <button type="button" name="save_group" class="button button-primary button-large" id="qp-save-group-btn"><?php echo $is_editing ? 'Update Group' : 'Save Draft & Add Options'; ?></button>
                                </div>

                            </div>
                            <div class="postbox">
                                <h2 class="hndle"><span>Organize</span></h2>
                                <div class="inside">
                                    <p>
                                        <label for="subject_id"><strong>Subject </strong><span style="color: red">*</span></label>
                                        <select name="subject_id" id="subject_id" style="width: 100%;">
                                            <option value="">— Select a Subject —</option>
                                            <?php foreach ($all_subjects as $subject) : ?>
                                                <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($current_subject_id, $subject->subject_id); ?>>
                                                    <?php echo esc_html($subject->subject_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                    <p>
                                        <label for="topic_id"><strong>Topic</strong></label>
                                        <select name="topic_id" id="topic_id" style="width: 100%;" disabled>
                                            <option value="">— Select a subject first —</option>
                                        </select>
                                    </p>
                                </div>
                            </div>
                            <div class="postbox">
                                <h2 class="hndle"><span>PYQ Details</span></h2>
                                <div class="inside">
                                    <div class="qp-pyq-container">
                                        <div class="qp-pyq-toggle-row">
                                            <label for="is_pyq_checkbox"><strong>Is a PYQ?</strong></label>
                                            <input type="checkbox" name="is_pyq" id="is_pyq_checkbox" value="1" <?php checked($is_pyq_group, 1); ?>>
                                        </div>

                                        <div id="pyq_fields_wrapper" style="<?php echo $is_pyq_group ? '' : 'display: none;'; ?>">
                                            <div class="qp-pyq-fields-row">
                                                <div class="qp-pyq-field-group">
                                                    <label for="exam_id"><strong>Exam</strong></label>
                                                    <select name="exam_id" id="exam_id" style="width: 100%;">
                                                        <option value="">— Select an Exam —</option>
                                                        <?php foreach ($all_exams as $exam) : ?>
                                                            <option value="<?php echo esc_attr($exam->exam_id); ?>" <?php selected($current_exam_id, $exam->exam_id); ?>>
                                                                <?php echo esc_html($exam->exam_name); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="qp-pyq-field-group">
                                                    <label for="pyq_year"><strong>Year</strong></label>
                                                    <input type="number" name="pyq_year" value="<?php echo esc_attr($current_pyq_year); ?>" style="width: 100%;" placeholder="e.g., 2023">
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <div class="postbox">
                                <h2 class="hndle"><span>Source Details</span></h2>
                                <div class="inside">
                                    <p>
                                        <label for="source_id"><strong>Source</strong></label>
                                        <select name="source_id" id="source_id" style="width: 100%;">
                                            <option value="">— Select a Source —</option>
                                            <?php foreach ($all_parent_sources as $source) : ?>
                                                <option value="<?php echo esc_attr($source->id); ?>" <?php selected($current_source_id, $source->id); ?>>
                                                    <?php echo esc_html($source->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                    <p>
                                        <label for="section_id"><strong>Section</strong></label>
                                        <select name="section_id" id="section_id" style="width: 100%;" <?php echo $current_source_id ? '' : 'disabled'; ?>>
                                            <option value="">— Select a source first —</option>
                                        </select>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

        </div>
        <style>
            .qp-horizontal-flex-group {
                display: flex;
                align-items: flex-end;
                /* Aligns items to the bottom, looks better with labels above */
                gap: 20px;
                /* Space between items */
                width: 100%;
            }

            .qp-flex-item {
                display: flex;
                flex-direction: column;
                /* Stack label on top of input */
            }

            .qp-flex-item-grow {
                flex-grow: 1;
                /* Allows this item to take up remaining space */
            }

            .qp-flex-item-shrink {
                flex-shrink: 0;
                /* Prevents this item from shrinking */
            }

            .qp-flex-item label {
                margin-bottom: 3px;
                font-weight: bold;
            }

            /* Specific alignment for the checkbox */
            .qp-flex-item-shrink label {
                display: block;
            }

            .qp-flex-item-shrink input[type="checkbox"] {
                transform: scale(1.5);
                /* Makes checkbox bigger */
                margin-top: 5px;
            }

            /* Style for the question block borders based on status */
            .qp-question-block.status-publish {
                border-left: 4px solid #4CAF50;
                /* Green for Published */
            }

            .qp-question-block.status-draft {
                border-left: 4px solid #FFC107;
                /* Yellow for Draft */
            }

            .qp-question-block.status-new {
                border-left: 4px solid #2196F3;
                /* Blue for New/Unsaved */
            }

            /* --- Style for reported question block highlight --- */
            .qp-question-block.status-reported {
                border-left: 4px solid #d63638;
                /* Red for Reported */
            }

            .postbox-header .qp-editor-quesiton-remove-btn {
                margin-right: 10px;
            }

            .qp-question-block .button-link-delete {
                color: #a0a5aa;
                text-decoration: none;
                transition: color 0.1s ease-in-out;
            }

            .qp-question-block .button-link-delete:hover {
                color: #d63638;
                /* Red color on hover */
            }

            .qp-question-block .button-link-delete .dashicons {
                font-size: 22px;
                line-height: 1;
            }

            .qp-options-grid-container {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                /* Create two equal columns */
                gap: 10px 15px;
                /* Add space between rows and columns */
            }

            .qp-option-row {
                display: flex;
                align-items: center;
                gap: 5px;
            }

            #post-body-content .postbox-header {
                cursor: none;
            }

            .qp-option-row .option-text-input {
                flex-grow: 1;
                /* Allow text input to fill available space */
                width: 100%;
                /* Ensure it takes up the column width */
            }

            .qp-option-row .option-id-display {
                color: #777;
                white-space: nowrap;
            }

            .wp-editor-tools {
                display: flex;
                /* Use flexbox for alignment */
                align-items: center;
                flex-wrap: wrap;
                /* Allow wrapping on small screens */
            }

            .qp-editor-labels-container {
                display: flex;
                gap: 5px;
                flex-wrap: wrap;
                margin-right: auto;
                /* This is the key change: pushes other elements to the right */
                padding-top: 5px;
                /* Align vertically with the tabs */
            }

            /* Force the parent container to use a flexbox layout instead of floats */
            #post-body.columns-2 {
                display: flex;
                align-items: flex-start;
                /* Align columns to the top */
            }

            /* Set the main content to be flexible */
            #post-body-content {
                flex: 1;
                min-width: 0;
                /* Prevents overflow issues with flex items */
            }

            /* Apply sticky positioning to the sidebar */
            #postbox-container-1 {
                position: -webkit-sticky;
                position: sticky;
                top: 48px;
                /* Offset for admin bar (32px) + some margin (16px) */
                margin-left: 20px;
            }

            @media (max-width: 1000px) {

                /* Force the parent container to use a flexbox layout instead of floats */
                #post-body.columns-2 {
                    display: block;
                }
            }

            .qp-options-actions {
                margin-top: 10px;
            }

            /* --- Styles for collapsible question block button --- */
            .qp-toggle-question-block {
                background: none;
                border: none;
                cursor: pointer;
                padding: 0 8px 0 0;
                color: #787c82;
            }

            .qp-toggle-question-block .dashicons {
                transition: transform 0.2s ease-in-out;
            }

            .qp-toggle-question-block.is-closed .dashicons {
                transform: rotate(-90deg);
            }

            /* --- Style for the contextual back button --- */
            .page-title-action.qp-back-button {
                background: #f6f7f7;
                border-color: #dcdcde;
                color: #50575e;
            }

            .page-title-action.qp-back-button:hover,
            .page-title-action.qp-back-button:focus {
                background: #eef0f2;
                border-color: #c6c9cc;
                color: #2c3338;
            }

            .qp-header-label-dropdown-container {
                position: relative;
                margin-left: 15px;
                vertical-align: middle;
                display: inline-block;
            }

            .qp-status-indicator {
                color: #fff;
                padding: 7px 10px;
                font-size: .8em;
                border-radius: 3px;
                font-weight: bold;
                vertical-align: middle;
                margin-left: 10px;
            }

            .hndle>span {
                display: flex;
                align-items: center;
            }

            /* --- Styles for custom label dropdown --- */
            .qp-custom-dropdown .qp-dropdown-toggle {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .qp-custom-dropdown .qp-dropdown-toggle .dashicons {
                font-size: 16px;
                line-height: 1;
                margin-top: 6px;
            }

            .qp-dropdown-panel {
                display: none;
                position: absolute;
                z-index: 10;
                background-color: #fff;
                border: 1px solid #c3c4c7;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                border-radius: 4px;
                padding: 8px;
                min-width: 200px;
                max-height: 250px;
                overflow-y: auto;
            }

            .qp-dropdown-panel label {
                display: block;
                padding: 5px;
                white-space: nowrap;
                cursor: pointer;
                border-radius: 3px;
            }

            .qp-dropdown-panel label:hover {
                background-color: #f0f0f1;
            }

            /* --- Style for reported question indicator --- */
            .qp-reported-indicator {
                background-color: #d63638;
                display: inline-flex;
                align-items: center;
                gap: 3px;

            }

            .qp-reported-indicator .dashicons {
                font-size: 14px;
                line-height: 1;
                height: auto;
                width: auto;
            }

            .qp-question-block .hndle,
            .postbox .hndle {
                cursor: default;
            }
        </style>
<?php
    }
}
