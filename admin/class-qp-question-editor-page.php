<?php
if (!defined('ABSPATH')) exit;

class QP_Question_Editor_Page {

    public static function render() {
        global $wpdb;
        $question_id = isset($_GET['question_id']) ? absint($_GET['question_id']) : 0;
        $is_editing = $question_id > 0;
        
        $question_data = null;
        $direction_text = '';
        $options_data = [];
        $current_subject_id = 0;

        if ($is_editing) {
            $q_table = $wpdb->prefix . 'qp_questions';
            $g_table = $wpdb->prefix . 'qp_question_groups';
            $o_table = $wpdb->prefix . 'qp_options';

            $question_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $q_table WHERE question_id = %d", $question_id));
            if ($question_data) {
                $group_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $g_table WHERE group_id = %d", $question_data->group_id));
                if ($group_data) {
                    $direction_text = $group_data->direction_text;
                    $current_subject_id = $group_data->subject_id;
                }
                $options_data = $wpdb->get_results($wpdb->prepare("SELECT * FROM $o_table WHERE question_id = %d ORDER BY option_id ASC", $question_id));
            }
        }

        $all_subjects = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}qp_subjects ORDER BY subject_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo $is_editing ? 'Edit Question' : 'Add New Question'; ?></h1>
            <?php if (!$is_editing) : ?>
                <a href="<?php echo admin_url('admin.php?page=question-press'); ?>" class="page-title-action">Back to All Questions</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <form method="post" action="">
                <?php wp_nonce_field('qp_save_question_nonce'); ?>
                <input type="hidden" name="question_id" value="<?php echo esc_attr($question_id); ?>">

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <div id="titlediv">
                                <div id="titlewrap">
                                    <label class="screen-reader-text" id="title-prompt-text" for="title">Enter question text here</label>
                                    <textarea name="question_text" id="title" spellcheck="true" autocomplete="off" style="width: 100%; height: 150px;" placeholder="Enter question text here"><?php echo $is_editing ? esc_textarea($question_data->question_text) : ''; ?></textarea>
                                </div>
                            </div>

                            <div class="postbox">
                                <h2 class="hndle"><span>Direction (Optional)</span></h2>
                                <div class="inside">
                                    <textarea name="direction_text" style="width: 100%; height: 100px;"><?php echo esc_textarea($direction_text); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="postbox">
                                <h2 class="hndle"><span>Options</span></h2>
                                <div class="inside" id="qp-options-wrapper">
                                    <?php 
                                    $option_count = max(5, count($options_data));
                                    for ($i = 0; $i < $option_count; $i++) : 
                                        $option = isset($options_data[$i]) ? $options_data[$i] : null;
                                        $is_correct = $option ? $option->is_correct : ($i == 0);
                                    ?>
                                    <div class="qp-option-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                        <input type="radio" name="is_correct_option" value="<?php echo $i; ?>" <?php checked($is_correct); ?>>
                                        <input type="text" name="options[]" value="<?php echo $option ? esc_attr($option->option_text) : ''; ?>" style="flex-grow: 1; margin-left: 10px;">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div><div id="postbox-container-1" class="postbox-container">
                            <div class="postbox">
                                <h2 class="hndle"><span>Publish</span></h2>
                                <div class="inside">
                                    <div class="submitbox" id="submitpost">
                                        <div id="major-publishing-actions">
                                            <div id="publishing-action">
                                                <input name="save" type="submit" class="button button-primary button-large" id="publish" value="<?php echo $is_editing ? 'Update Question' : 'Save Question'; ?>">
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
                                    <label>
                                        <input type="checkbox" name="is_pyq" value="1" <?php if($is_editing) checked($question_data->is_pyq, 1); ?>>
                                        PYQ (Previous Year Question)
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div><br class="clear">
                </div></form>
        </div>
        <?php
    }
}