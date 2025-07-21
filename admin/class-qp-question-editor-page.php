<?php
if (!defined('ABSPATH')) exit;

class QP_Question_Editor_Page
{

    public static function render()
    {
        global $wpdb;
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        $is_editing = $group_id > 0;

        $open_reports = [];
        if ($is_editing) {
            $reports_table = $wpdb->prefix . 'qp_question_reports';
            $reasons_table = $wpdb->prefix . 'qp_report_reasons';
            $open_reports = $wpdb->get_results($wpdb->prepare(
                "SELECT r.question_id, rr.reason_text 
         FROM {$reports_table} r 
         JOIN {$reasons_table} rr ON r.reason_id = rr.reason_id
         WHERE r.status = 'open' AND r.question_id IN (SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d)",
                $group_id
            ));
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


        if ($is_editing) {
            $g_table = $wpdb->prefix . 'qp_question_groups';
            $q_table = $wpdb->prefix . 'qp_questions';
            $o_table = $wpdb->prefix . 'qp_options';
            $ql_table = $wpdb->prefix . 'qp_question_labels';
            $l_table = $wpdb->prefix . 'qp_labels';

            $group_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $g_table WHERE group_id = %d", $group_id));
            if ($group_data) {
                // --- UPDATED: Load all group-level data, including new PYQ fields ---
                $direction_text = $group_data->direction_text;
                $direction_image_id = $group_data->direction_image_id;
                $current_subject_id = $group_data->subject_id;
                $is_pyq_group = !empty($group_data->is_pyq);
                $current_exam_id = $group_data->exam_id ?? 0;
                $current_pyq_year = $group_data->pyq_year ?? '';


                $questions_in_group = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$q_table} WHERE group_id = %d ORDER BY question_id ASC",
                    $group_id
                ));

                if (!empty($questions_in_group)) {
                    // Get the status from the first question to determine if the group is a draft.
                    $group_status = $questions_in_group[0]->status;
                    // --- Get details from the FIRST question to populate metaboxes that are shared ---
                    $first_q = $questions_in_group[0];
                    $current_topic_id = $first_q->topic_id ?? 0;
                    $current_source_id = $first_q->source_id ?? 0;
                    $current_section_id = $first_q->section_id ?? 0;


                    foreach ($questions_in_group as $q) {
                        $q->options = $wpdb->get_results($wpdb->prepare("SELECT * FROM $o_table WHERE question_id = %d ORDER BY option_id ASC", $q->question_id));
                        $q->labels = $wpdb->get_results($wpdb->prepare("SELECT l.label_id, l.label_name FROM {$ql_table} ql JOIN {$l_table} l ON ql.label_id = l.label_id WHERE ql.question_id = %d", $q->question_id));
                    }
                }
            }
        }

        if (empty($questions_in_group)) {
            // Default for a new, empty form
            $questions_in_group[] = (object)['question_id' => 0, 'custom_question_id' => 'New', 'question_text' => '', 'options' => [], 'labels' => []];
        }

        // --- Fetch ALL data needed for the form dropdowns ---
        $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        $all_topics = $wpdb->get_results("SELECT topic_id, topic_name, subject_id FROM {$wpdb->prefix}qp_topics ORDER BY topic_name ASC");
        $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
        $all_exams = $wpdb->get_results("SELECT exam_id, exam_name FROM {$wpdb->prefix}qp_exams ORDER BY exam_name ASC");
        $all_sources = $wpdb->get_results("SELECT source_id, source_name FROM {$wpdb->prefix}qp_sources ORDER BY source_name ASC");
        $all_sections = $wpdb->get_results("SELECT section_id, section_name, source_id FROM {$wpdb->prefix}qp_source_sections ORDER BY section_name ASC");


        // Prepare topics and sections data for JavaScript
        $topics_by_subject = [];
        foreach ($all_topics as $topic) {
            $topics_by_subject[$topic->subject_id][] = ['id' => $topic->topic_id, 'name' => $topic->topic_name];
        }
        $sections_by_source = [];
        foreach ($all_sections as $section) {
            $sections_by_source[$section->source_id][] = ['id' => $section->section_id, 'name' => $section->section_name];
        }

        // Pass all necessary data to our JavaScript file
        wp_localize_script('qp-editor-script', 'qp_editor_data', [
            'topics_by_subject'   => $topics_by_subject,
            'sections_by_source'  => $sections_by_source,
            'current_topic_id'    => $current_topic_id,
            'current_section_id'  => $current_section_id,
        ]);

        if (isset($_GET['message'])) {
            $message = $_GET['message'] === '1' ? 'Question(s) updated successfully.' : 'Question(s) saved successfully.';
            echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
?>
        <div class="wrap">
            <?php if (!empty($open_reports)):
                $reports_by_question = [];
                foreach ($open_reports as $report) {
                    $reports_by_question[$report->question_id][] = $report->reason_text;
                }
            ?>
                <div class="notice notice-error" style="padding: 1rem; border-left-width: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0 0 0.5rem 0;">&#9888; Open Reports for this Group</h3>
                            <p style="margin-top: 0;">The following questions have open reports. Resolving them will remove them from the "Needs Review" queue.</p>
                            <ul style="list-style: disc; padding-left: 20px; margin-bottom: 0;">
                                <?php foreach ($reports_by_question as $qid => $reasons): ?>
                                    <li><strong>Question #<?php echo esc_html(get_question_custom_id($qid)); ?>:</strong> <?php echo esc_html(implode(', ', array_unique($reasons))); ?></li>
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
            <a href="<?php echo admin_url('admin.php?page=question-press'); ?>" class="page-title-action">Back to All Questions</a>
            <hr class="wp-header-end">

            <form method="post" action="">
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
                            <div class="postbox">
                                <h2 class="hndle"><span>PYQ Details</span></h2>
                                <div class="inside">
                                    <div class="qp-horizontal-flex-group">

                                        <div class="qp-flex-item qp-flex-item-shrink">
                                            <label for="is_pyq_checkbox"><strong>Is a PYQ?</strong></label>
                                            <input type="checkbox" name="is_pyq" id="is_pyq_checkbox" value="1" <?php checked($is_pyq_group, 1); ?>>
                                        </div>

                                        <div id="pyq_fields_wrapper" class="qp-flex-item-grow" style="<?php echo $is_pyq_group ? '' : 'display: none;'; ?>">
                                            <div class="qp-horizontal-flex-group">
                                                <div class="qp-flex-item qp-flex-item-grow">
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
                                                <div class="qp-flex-item">
                                                    <label for="pyq_year"><strong>Year</strong></label>
                                                    <input type="number" name="pyq_year" value="<?php echo esc_attr($current_pyq_year); ?>" style="width: 100%;" placeholder="e.g., 2023">
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </div>
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

                            <div id="qp-question-blocks-container">
                                <?php foreach ($questions_in_group as $q_index => $question) :
                                    $current_label_ids = wp_list_pluck($question->labels, 'label_id');
                                    $status_class = 'status-' . ($question->status ?? 'draft');
                                ?>
                                    <div class="postbox qp-question-block <?php echo esc_attr($status_class); ?>">
                                        <div class="postbox-header">
                                            <h2 class="hndle">
                                                <span>
                                                    Q<?php echo ($q_index + 1); ?>: Question (ID: <?php echo esc_html($question->custom_question_id); ?>)
                                                    <?php if ($question->question_id > 0) : ?>
                                                        <small style="font-weight: normal; font-size: 12px; color: #777;"> | DB ID: <?php echo esc_html($question->question_id); ?></small>
                                                        <?php
                                                        $status = $question->status ?? 'draft';
                                                        $status_color = $status === 'publish' ? '#4CAF50' : '#FFC107';
                                                        $status_text = ucfirst($status);
                                                        ?>
                                                        <span style="background-color: <?php echo $status_color; ?>; color: #fff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: bold; vertical-align: middle; margin-left: 10px;"><?php echo esc_html($status_text); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </h2>
                                            <div class="handle-actions">
                                                <button type="button" class="button-link remove-question-block">Remove</button>
                                            </div>
                                        </div>
                                        <div class="inside">
                                            <input type="hidden" name="questions[<?php echo $q_index; ?>][question_id]" class="question-id-input" value="<?php echo esc_attr($question->question_id); ?>">
                                            <p>
                                                <label for="question_number_in_section_<?php echo $q_index; ?>"><strong>Question Number in Source</strong></label>
                                                <input type="text" name="questions[<?php echo $q_index; ?>][question_number_in_section]" id="question_number_in_section_<?php echo $q_index; ?>" value="<?php echo esc_attr($question->question_number_in_section ?? ''); ?>" style="width: 50%;">
                                            </p>
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
                                                    <?php for ($i = 0; $i < 5; $i++) :
                                                        $option = isset($question->options[$i]) ? $question->options[$i] : null;
                                                    $option_id_value = $option ? esc_attr($option->option_id) : 'new_' . $i;
                                                $is_correct = $option ? $option->is_correct : false;
                                            ?>
                                                <div class="qp-option-row" style="display: flex; align-items: center; margin-bottom: 5px; gap: 5px;">
                                                    <input type="radio" name="questions[<?php echo $q_index; ?>][correct_option_id]" value="<?php echo $option_id_value; ?>" <?php checked($is_correct); ?>>
                                                        <input type="hidden" name="questions[<?php echo $q_index; ?>][option_ids][]" value="<?php echo $option ? esc_attr($option->option_id) : '0'; ?>">
                                                        <input type="text" name="questions[<?php echo $q_index; ?>][options][]" class="option-text-input" value="<?php echo $option ? esc_attr($option->option_text) : ''; ?>" style="flex-grow: 1;" placeholder="Option <?php echo $i + 1; ?>">
                                                        <?php if ($option && $option->option_id): ?>
                                                            <small style="color: #777; white-space: nowrap;">ID: <?php echo esc_html($option->option_id); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endfor; ?>
                                                <hr>
                                                <p><strong>Labels for this Question:</strong></p>
                                                <div class="labels-group">
                                                        <?php foreach ($all_labels as $label) : ?>
                                                            <label class="inline-checkbox"><input value="<?php echo esc_attr($label->label_id); ?>" type="checkbox" name="questions[<?php echo $q_index; ?>][labels][]" class="label-checkbox" <?php checked(in_array($label->label_id, $current_label_ids)); ?>> <?php echo esc_html($label->label_name); ?></label>
                                                        <?php endforeach; ?>
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
                                <h2 class="hndle"><span>Publish</span></h2>
                                <div class="inside">
                                    <div id="major-publishing-actions">
                                        <input name="save_group" type="submit" class="button button-primary button-large" id="publish" value="<?php echo $is_editing ? 'Update Group' : 'Save Draft & Add Options'; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="postbox">
                                <h2 class="hndle"><span>Categorization</span></h2>
                                <div class="inside">
                                    <p>
                                        <label for="subject_id"><strong>Subject</strong></label>
                                        <select name="subject_id" id="subject_id" style="width: 100%;">
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
                                <h2 class="hndle"><span>Source Details</span></h2>
                                <div class="inside">
                                    <p>
                                        <label for="source_id"><strong>Source</strong></label>
                                        <select name="source_id" id="source_id" style="width: 100%;">
                                            <option value="">— Select a Source —</option>
                                            <?php foreach ($all_sources as $source) : ?>
                                                <option value="<?php echo esc_attr($source->source_id); ?>" <?php selected($current_source_id, $source->source_id); ?>>
                                                    <?php echo esc_html($source->source_name); ?>
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
                border-left: 4px solid #4CAF50; /* Green for Published */
            }
            .qp-question-block.status-draft {
                border-left: 4px solid #FFC107; /* Yellow for Draft */
            }
        </style>
<?php
    }
}
