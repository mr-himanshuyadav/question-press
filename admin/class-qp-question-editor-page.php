<?php
if (!defined('ABSPATH')) exit;

class QP_Question_Editor_Page {

    public static function render() {
        global $wpdb;
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        $is_editing = $group_id > 0;
        
        $direction_text = '';
        $current_subject_id = 0;
        $questions_in_group = [];

        if ($is_editing) {
            $g_table = $wpdb->prefix . 'qp_question_groups';
            $q_table = $wpdb->prefix . 'qp_questions';
            $o_table = $wpdb->prefix . 'qp_options';
            
            $group_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $g_table WHERE group_id = %d", $group_id));
            if ($group_data) {
                $direction_text = $group_data->direction_text;
                $current_subject_id = $group_data->subject_id;
                $questions_in_group = $wpdb->get_results($wpdb->prepare("SELECT * FROM $q_table WHERE group_id = %d ORDER BY question_id ASC", $group_id));
                
                foreach ($questions_in_group as $q) {
                    $q->options = $wpdb->get_results($wpdb->prepare("SELECT * FROM $o_table WHERE question_id = %d ORDER BY option_id ASC", $q->question_id));
                }
            }
        }

        if (!$is_editing) {
            $questions_in_group[] = (object)['question_id' => 0, 'question_text' => '', 'is_pyq' => 0, 'options' => []];
        }

        $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        
        if (isset($_GET['message'])) {
            $message = $_GET['message'] === '1' ? 'Question group updated successfully.' : 'Question group saved successfully.';
            echo '<div id="message" class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo $is_editing ? 'Edit Question Group' : 'Add New Question Group'; ?></h1>
            <a href="<?php echo admin_url('admin.php?page=question-press'); ?>" class="page-title-action">Back to All Questions</a>
            <hr class="wp-header-end">

            <form method="post" action="">
                <?php wp_nonce_field('qp_save_question_group_nonce'); ?>
                <input type="hidden" name="group_id" value="<?php echo esc_attr($group_id); ?>">

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            
                            <div class="postbox">
                                <h2 class="hndle"><span>Direction & Subject</span></h2>
                                <div class="inside">
                                    <p><strong>Subject:</strong></p>
                                    <select name="subject_id" style="width: 100%; margin-bottom: 15px;">
                                        <?php foreach($all_subjects as $subject) : ?>
                                            <option value="<?php echo esc_attr($subject->subject_id); ?>" <?php selected($current_subject_id, $subject->subject_id); ?>>
                                                <?php echo esc_html($subject->subject_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <p><strong>Direction Text (Optional):</strong></p>
                                    <textarea name="direction_text" style="width: 100%; height: 100px;"><?php echo esc_textarea($direction_text); ?></textarea>
                                    <p class="description">This text will appear above all questions in this group. You can use LaTeX for math by enclosing it in dollar signs, e.g., <code>$E=mc^2$</code>.</p>
                                </div>
                            </div>
                            
                            <div id="qp-question-blocks-container">
                                <?php foreach ($questions_in_group as $q_index => $question) : ?>
                                <div class="postbox qp-question-block">
                                    <div class="postbox-header">
                                        <h2 class="hndle"><span>Question</span></h2>
                                        <div class="handle-actions">
                                            <button type="button" class="button-link remove-question-block">Remove</button>
                                        </div>
                                    </div>
                                    <div class="inside">
                                        <textarea name="questions[<?php echo $q_index; ?>][question_text]" class="question-text-area" style="width: 100%; height: 100px;" placeholder="Enter question text here..."><?php echo esc_textarea($question->question_text); ?></textarea>
                                        <hr>
                                        <p><strong>Options (Select the radio button for the correct answer)</strong></p>
                                        <?php
                                        $option_count = max(4, count($question->options));
                                        for ($i = 0; $i < $option_count; $i++) : 
                                            $option = isset($question->options[$i]) ? $question->options[$i] : null;
                                            $is_correct = $option ? $option->is_correct : ($i == 0);
                                        ?>
                                        <div class="qp-option-row" style="display: flex; align-items: center; margin-bottom: 5px;">
                                            <input type="radio" name="questions[<?php echo $q_index; ?>][is_correct_option]" value="<?php echo $i; ?>" <?php checked($is_correct); ?>>
                                            <input type="text" name="questions[<?php echo $q_index; ?>][options][]" value="<?php echo $option ? esc_attr($option->option_text) : ''; ?>" style="flex-grow: 1; margin: 0 5px;">
                                        </div>
                                        <?php endfor; ?>
                                        <hr>
                                        <label><input type="checkbox" class="is-pyq-checkbox" name="questions[<?php echo $q_index; ?>][is_pyq]" value="1" <?php checked($question->is_pyq, 1); ?>> PYQ (Previous Year Question)</label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-new-question-block" class="button button-secondary">+ Add Another Question to this Group</button>
                        </div>

                        <div id="postbox-container-1" class="postbox-container">
                            <div class="postbox">
                                <h2 class="hndle"><span>Publish</span></h2>
                                <div class="inside">
                                    <div class="submitbox" id="submitpost">
                                        <div id="major-publishing-actions">
                                            <div id="publishing-action">
                                                <input name="save_group" type="submit" class="button button-primary button-large" value="<?php echo $is_editing ? 'Update Group' : 'Save Group'; ?>">
                                            </div>
                                            <div class="clear"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <br class="clear">
                </div>
            </form>
        </div>
        <?php
    }
}