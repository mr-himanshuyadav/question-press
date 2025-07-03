<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Export_Page {

    /**
     * Renders the Export admin page.
     */
    public static function render() {
        // Handle the form submission if the export button was clicked
        if (isset($_POST['export_questions']) && check_admin_referer('qp_export_nonce_action', 'qp_export_nonce_field')) {
            self::handle_export();
            return; // Stop rendering the rest of the page
        }
        
        // Display the form
        self::render_export_form();
    }

    /**
     * Displays the export options form.
     */
    private static function render_export_form() {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Export Questions</h1>
            <hr class="wp-header-end">

            <p>Select the subjects you wish to export. All questions within the selected subjects will be exported into a single <code>.zip</code> file.</p>

            <form method="post" action="">
                <?php wp_nonce_field('qp_export_nonce_action', 'qp_export_nonce_field'); ?>
                
                <h2>Select Subjects</h2>
                <fieldset>
                    <?php if (!empty($subjects)) : ?>
                        <?php foreach ($subjects as $subject) : ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="subject_ids[]" value="<?php echo esc_attr($subject->subject_id); ?>">
                                <?php echo esc_html($subject->subject_name); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No subjects found.</p>
                    <?php endif; ?>
                </fieldset>

                <p class="submit">
                    <input type="submit" name="export_questions" class="button button-primary" value="Export Questions">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handles the data fetching and ZIP file generation.
     */
    private static function handle_export() {
        if (empty($_POST['subject_ids'])) {
            wp_die('Please select at least one subject to export.');
        }

        $subject_ids = array_map('absint', $_POST['subject_ids']);
        $subject_ids_placeholder = implode(',', array_fill(0, count($subject_ids), '%d'));

        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $o_table = $wpdb->prefix . 'qp_options';
        $s_table = $wpdb->prefix . 'qp_subjects';

        // Fetch all questions for the selected subjects
        $questions_data = $wpdb->get_results($wpdb->prepare(
            "SELECT q.question_id, q.question_text, q.is_pyq, g.group_id, g.direction_text, s.subject_name
             FROM {$q_table} q
             JOIN {$g_table} g ON q.group_id = g.group_id
             JOIN {$s_table} s ON g.subject_id = s.subject_id
             WHERE g.subject_id IN ($subject_ids_placeholder)",
            $subject_ids
        ));

        if (empty($questions_data)) {
            wp_die('No questions found for the selected subjects.');
        }

        // Process data into the correct JSON structure
        $grouped_by_group = [];
        foreach ($questions_data as $q) {
            if (!isset($grouped_by_group[$q->group_id])) {
                $grouped_by_group[$q->group_id] = [
                    'groupId' => 'db_group_' . $q->group_id,
                    'subject' => $q->subject_name,
                    'Direction' => ['text' => $q->direction_text, 'image' => null],
                    'questions' => []
                ];
            }

            // Fetch options for this question
            $options = $wpdb->get_results($wpdb->prepare("SELECT option_text, is_correct FROM {$o_table} WHERE question_id = %d", $q->question_id));
            $options_array = [];
            foreach ($options as $opt) {
                $options_array[] = ['optionText' => $opt->option_text, 'isCorrect' => (bool)$opt->is_correct];
            }

            $grouped_by_group[$q->group_id]['questions'][] = [
                'questionId' => 'db_question_' . $q->question_id,
                'questionText' => $q->question_text,
                'isPYQ' => (bool)$q->is_pyq,
                'options' => $options_array,
                'source' => null
            ];
        }

        $filename = 'qp-export-' . date('Y-m-d') . '.zip';
        $json_filename = 'questions.json';

        $final_json = [
            'schemaVersion' => '1.2',
            'exportTimestamp' => date('c'),
            'sourceFile' => 'database_export',
            'questionGroups' => array_values($grouped_by_group)
        ];
        
        $json_data = json_encode($final_json, JSON_PRETTY_PRINT);
        
        $zip = new ZipArchive();
        $zip_path = wp_upload_dir()['basedir'] . '/' . $filename;

        if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
            wp_die("Cannot create ZIP archive.");
        }

        $zip->addFromString($json_filename, $json_data);
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        unlink($zip_path); // Delete the temp file
        exit;
    }
}