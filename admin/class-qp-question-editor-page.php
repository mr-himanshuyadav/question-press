<?php
if (!defined('ABSPATH')) exit;

class QP_Question_Editor_Page {

    public static function render() {
        global $wpdb;
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        $is_editing = $group_id > 0;
        
        // Data holders
        $direction_text = '';
        $direction_image_id = 0;
        $current_subject_id = 0;
        $questions_in_group = [];
        $is_pyq_group = false;

        if ($is_editing) {
            $g_table = $wpdb->prefix . 'qp_question_groups';
            $q_table = $wpdb->prefix . 'qp_questions';
            $o_table = $wpdb->prefix . 'qp_options';
            $ql_table = $wpdb->prefix . 'qp_question_labels';
            $l_table = $wpdb->prefix . 'qp_labels';
            
            $group_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $g_table WHERE group_id = %d", $group_id));
            if ($group_data) {
                $direction_text = $group_data->direction_text;
                $direction_image_id = $group_data->direction_image_id;
                $current_subject_id = $group_data->subject_id;
                $questions_in_group = $wpdb->get_results($wpdb->prepare("SELECT * FROM $q_table WHERE group_id = %d ORDER BY question_id ASC", $group_id));
                
                if (!empty($questions_in_group)) {
                    $is_pyq_group = (bool)$questions_in_group[0]->is_pyq; 
                    foreach ($questions_in_group as $q) {
                        $q->options = $wpdb->get_results($wpdb->prepare("SELECT * FROM $o_table WHERE question_id = %d ORDER BY option_id ASC", $q->question_id));
                        // Fetch the full label object now, not just the ID
                        $q->labels = $wpdb->get_results($wpdb->prepare("SELECT l.label_id, l.label_name, l.label_color FROM {$ql_table} ql JOIN {$l_table} l ON ql.label_id = l.label_id WHERE ql.question_id = %d", $q->question_id));
                    }
                }
            }

            // NEW: Fetch logs associated with any question in this group
            $question_ids_in_group = wp_list_pluck($questions_in_group, 'question_id');
            if (!empty($question_ids_in_group)) {
                $ids_placeholder = implode(',', array_map('absint', $question_ids_in_group));
                $logs_table = $wpdb->prefix . 'qp_logs';
                $question_logs = $wpdb->get_results("SELECT * FROM $logs_table WHERE log_data LIKE '%\"question_id\"%' AND JSON_EXTRACT(log_data, '$.question_id') IN ($ids_placeholder) ORDER BY log_date DESC");
            }
        }

        if (empty($questions_in_group)) {
            $questions_in_group[] = (object)['question_id' => 0, 'custom_question_id' => 'New', 'question_text' => '', 'is_pyq' => 0, 'options' => [], 'labels' => []];
        }

        $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        $all_labels = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_labels ORDER BY label_name ASC");
        
        if (isset($_GET['message'])) {
            $message = $_GET['message'] === '1' ? 'Question(s) updated successfully.' : 'Question(s) saved successfully.';
            echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo $is_editing ? 'Edit Question' : 'Add New Question'; ?></h1>
            <a href="<?php echo admin_url('admin.php?page=question-press'); ?>" class="page-title-action">Back to All Questions</a>
            <hr class="wp-header-end">

            <?php if (!empty($question_logs)) : ?>
            <div id="qp-question-logs" class="notice notice-warning">
                <h4><span class="dashicons dashicons-flag"></span> This Question Group has open reports:</h4>
                <ul>
                    <?php foreach ($question_logs as $log) : ?>
                        <li><strong><?php echo esc_html($log->log_date); ?>:</strong> <?php echo esc_html($log->log_message); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('qp_save_question_group_nonce'); ?>
                <input type="hidden" name="group_id" value="<?php echo esc_attr($group_id); ?>">

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            
                            <div class="postbox">
                                <h2 class="hndle"><span>Direction (Optional Passage)</span></h2>
                                <div class="inside">
                                    <textarea name="direction_text" style="width: 100%; height: 100px;"><?php echo esc_textarea($direction_text); ?></textarea>
                                    <p class="description">This text will appear above all questions in this group. You can use LaTeX for math by enclosing it in dollar signs, e.g., <code>$E=mc^2$</code>.</p>
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
                                ?>
                                <div class="postbox qp-question-block">
                                    <div class="postbox-header">
                                        <h2 class="hndle"><span>Question (ID: <?php echo esc_html($question->custom_question_id); ?>)</span></h2>
                                        <div class="handle-actions">
                                            <button type="button" class="button-link remove-question-block">Remove</button>
                                        </div>
                                    </div>
                                    <div class="inside">
                                        <?php if(!empty($question->labels)) : ?>
                                            <div class="current-labels-display">
                                                <?php foreach($question->labels as $label): ?>
                                                    <span style="background-color: <?php echo esc_attr($label->label_color); ?>; color: #fff; padding: 2px 6px; font-size: 11px; border-radius: 3px;"><?php echo esc_html($label->label_name); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <input type="hidden" name="questions[<?php echo $q_index; ?>][question_id]" class="question-id-input" value="<?php echo esc_attr($question->question_id); ?>">
                                        <textarea name="questions[<?php echo $q_index; ?>][question_text]" class="question-text-area" style="width: 100%; height: 100px;" placeholder="Enter question text here..." required><?php echo esc_textarea($question->question_text); ?></textarea>
                                        <p class="description">You can use LaTeX for math, e.g., <code>$x^2$</code>.</p>
                                        <hr>
                                        <p><strong>Options (Select the radio button for the correct answer)</strong></p>
                                        <?php
                                        $option_count = 5;
                                        for ($i = 0; $i < $option_count; $i++) : 
                                            $option = isset($question->options[$i]) ? $question->options[$i] : null;
                                            $is_correct = $option ? $option->is_correct : ($i == 0 && !$is_editing);
                                        ?>
                                        <div class="qp-option-row" style="display: flex; align-items: center; margin-bottom: 5px;">
                                            <input type="radio" name="questions[<?php echo $q_index; ?>][is_correct_option]" value="<?php echo $i; ?>" <?php checked($is_correct); ?>>
                                            <input type="text" name="questions[<?php echo $q_index; ?>][options][]" class="option-text-input" value="<?php echo $option ? esc_attr($option->option_text) : ''; ?>" style="flex-grow: 1; margin: 0 5px;" placeholder="Option <?php echo $i + 1; ?>">
                                        </div>
                                        <?php endfor; ?>
                                        <p class="description">At least 4 options are recommended. You can use LaTeX here too.</p>
                                        <hr>
                                        <p><strong>Labels for this Question:</strong></p>
                                        <div class="labels-group">
                                            <?php foreach ($all_labels as $label) : ?>
                                                <label class="inline-checkbox"><input value="<?php echo esc_attr($label->label_id); ?>" type="checkbox" name="questions[<?php echo $q_index; ?>][labels][]" class="label-checkbox" <?php checked(in_array($label->label_id, $current_label_ids)); ?>> <?php echo esc_html($label->label_name); ?></label>
                                            <?php endforeach; ?>
                                        </div>
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
                                    <div class="submitbox" id="submitpost">
                                        <div id="major-publishing-actions">
                                            <div id="publishing-action">
                                                <input name="save_group" type="submit" class="button button-primary button-large" id="publish" value="<?php echo $is_editing ? 'Update Question(s)' : 'Save Question(s)'; ?>">
                                            </div>
                                            <div class="clear"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                             <div class="postbox">
                                <h2 class="hndle"><span>Subject</span></h2>
                                <div class="inside">
                                    <select name="subject_id" style="width: 100%;">
                                        <?php foreach($all_subjects as $subject) : ?>
                                            <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($current_subject_id, $subject->subject_id); ?>>
                                                <?php echo esc_html($subject->subject_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="postbox">
                                <h2 class="hndle"><span>Settings</span></h2>
                                <div class="inside">
                                    <label><input type="checkbox" name="is_pyq" value="1" <?php checked($is_pyq_group, 1); ?>> PYQ (Applies to all questions in this group)</label>
                                </div>
                            </div>
                        </div>
                    </div><br class="clear">
                </div></form>
        </div>
        <style>
            .current-labels-display { margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 5px; }
            .labels-group { display: flex; flex-wrap: wrap; gap: 5px 15px; padding: 5px; border: 1px solid #ddd; background: #fff; max-height: 100px; overflow-y: auto; }
            .inline-checkbox { white-space: nowrap; }
        </style>
        <?php
    }
}