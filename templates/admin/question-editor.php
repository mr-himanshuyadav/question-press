<?php
/**
 * Template for the Admin Question Editor page.
 *
 * @package QuestionPress/Templates/Admin
 *
 * @var string $message_html         HTML for displaying notices.
 * @var array  $open_reports         Array of open reports for this group.
 * @var bool   $is_editing           True if editing an existing group.
 * @var int    $group_id             The ID of the group being edited.
 * @var bool   $show_back_button     Whether to show the "Back" button.
 * @var string $referer              The referring URL.
 * @var bool   $has_draft_question   True if any question in the group is a draft.
 * @var string $direction_text       The text for the group's direction.
 * @var int    $direction_image_id   The attachment ID for the direction image.
 * @var array  $questions_in_group   Array of question objects.
 * @var array  $all_labels           List of all available label terms.
 * @var array  $all_subjects         List of all parent subject terms.
 * @var int    $current_subject_id   The ID of the currently linked subject.
 * @var array  $all_exams            List of all available exam terms.
 * @var bool   $is_pyq_group         True if the group is marked as PYQ.
 * @var int    $current_exam_id      The ID of the currently linked exam.
 * @var string $current_pyq_year     The PYQ year value.
 * @var array  $all_parent_sources   List of all parent source terms.
 * @var int    $current_source_id    The ID of the currently linked source.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">
    <?php echo $message_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

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
    <?php if ($show_back_button) : ?>
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

        <?php // *** MODIFICATION 1: Removed the "Step 1 of 2" / "Step 2 of 2" notices *** ?>

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                <div id="post-body-content">
                    <?php // *** MODIFICATION 2: Changed to show for new groups as well *** ?>
                    <?php if ($has_draft_question) : ?>
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
                                    <?php // *** MODIFICATION 3: Removed the "if ($is_editing)" wrapper *** ?>
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
                                    <?php // *** End of removed "if ($is_editing)" wrapper *** ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="add-new-question-block" class="button button-secondary">+ Add Another Question</button>
                </div>

                <div id="postbox-container-1" class="postbox-container">
                    <div class="postbox">
                        <div id="major-publishing-actions" style="text-align: center;">
                            <?php // *** MODIFICATION 4: Changed button text for new groups *** ?>
                            <button type="button" name="save_group" class="button button-primary button-large" id="qp-save-group-btn"><?php echo $is_editing ? 'Update Group' : 'Save Group'; ?></button>
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