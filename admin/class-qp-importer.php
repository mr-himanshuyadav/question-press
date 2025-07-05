<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Importer {

    /**
     * Handles the entire import process.
     */
    public function handle_import() {
        if (!isset($_POST['qp_import_nonce_field']) || !wp_verify_nonce($_POST['qp_import_nonce_field'], 'qp_import_nonce_action')) {
            wp_die('Security check failed.');
        }

        if (!isset($_FILES['question_zip_file']) || $_FILES['question_zip_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('File upload error. Please try again.');
        }

        $file = $_FILES['question_zip_file'];
        $file_path = $file['tmp_name'];

        if ($file['type'] !== 'application/zip' && $file['type'] !== 'application/x-zip-compressed') {
            wp_die('Invalid file type. Please upload a .zip file.');
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'qp_temp_import';
        wp_mkdir_p($temp_dir);

        $zip = new ZipArchive;
        if ($zip->open($file_path) === TRUE) {
            $zip->extractTo($temp_dir);
            $zip->close();
        } else {
            wp_die('Failed to unzip the file.');
        }

        $json_file = trailingslashit($temp_dir) . 'questions.json';
        if (!file_exists($json_file)) {
            $this->cleanup($temp_dir);
            wp_die('The zip file does not contain a questions.json file.');
        }

        $json_content = file_get_contents($json_file);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->cleanup($temp_dir);
            wp_die('Invalid JSON format in questions.json file.');
        }

        $result = $this->process_data($data);
        $this->cleanup($temp_dir);
        $this->display_results($result);
    }

    /**
     * Processes the parsed JSON data and inserts it into the database.
     */
    // In admin/class-qp-importer.php

private function process_data($data) {
    global $wpdb;
    $subjects_table = $wpdb->prefix . 'qp_subjects';
    $groups_table = $wpdb->prefix . 'qp_question_groups';
    $questions_table = $wpdb->prefix . 'qp_questions';
    $options_table = $wpdb->prefix . 'qp_options';
    $labels_table = $wpdb->prefix . 'qp_labels';
    $question_labels_table = $wpdb->prefix . 'qp_question_labels';

    $imported_count = 0;
    $duplicate_count = 0;
    $duplicate_label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM $labels_table WHERE label_name = %s", 'Duplicate'));

    if (!isset($data['questionGroups']) || !is_array($data['questionGroups'])) {
        return ['imported' => 0, 'duplicates' => 0];
    }

    foreach ($data['questionGroups'] as $group) {
        $subject_name = !empty($group['subject']) ? sanitize_text_field($group['subject']) : 'Uncategorized';
        $subject_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM $subjects_table WHERE subject_name = %s", $subject_name));
        if (!$subject_id) {
            $wpdb->insert($subjects_table, ['subject_name' => $subject_name, 'description' => '']);
            $subject_id = $wpdb->insert_id;
        }

        $direction_text = isset($group['Direction']['text']) ? $group['Direction']['text'] : null;
        $wpdb->insert($groups_table, ['direction_text' => $direction_text, 'subject_id' => $subject_id]);
        $group_id = $wpdb->insert_id;

        foreach ($group['questions'] as $question) {
            $question_text = $question['questionText'];
            $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));
            $is_duplicate = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash)) ? true : false;
            // UPDATED: Get the ID of the existing question, not just true/false
            $existing_question_id = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash));
            $next_custom_id = get_option('qp_next_custom_question_id', 1000);
            
            // CORRECTED: Added source_page and source_number to the insert data
            $wpdb->insert($questions_table, [
                'custom_question_id' => $next_custom_id,
                'group_id' => $group_id,
                'question_text' => $question['questionText'],
                'question_text_hash' => $hash,
                'is_pyq' => isset($question['isPYQ']) ? (int)$question['isPYQ'] : 0,
                'source_file' => isset($data['sourceFile']) ? sanitize_text_field($data['sourceFile']) : null,
                'source_page' => isset($question['source']['page']) ? absint($question['source']['page']) : null,
                'source_number' => isset($question['source']['number']) ? absint($question['source']['number']) : null,
                'duplicate_of' => $existing_question_id ? $existing_question_id : null // NEW: Save the original ID
            ]);
            $question_id = $wpdb->insert_id;
            update_option('qp_next_custom_question_id', $next_custom_id + 1);

            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as $option) {
                    $wpdb->insert($options_table, ['question_id' => $question_id, 'option_text' => $option['optionText'], 'is_correct' => (int)$option['isCorrect']]);
                }
            }

            if ($existing_question_id) {
                if ($duplicate_label_id) {
                    $wpdb->insert($question_labels_table, ['question_id' => $question_id, 'label_id' => $duplicate_label_id]);
                }
                $duplicate_count++;
            }
            $imported_count++;
        }
    }
    return ['imported' => $imported_count, 'duplicates' => $duplicate_count];
}

    private function cleanup($dir) {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->cleanup("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * Displays the results of the import process.
     */
    private function display_results($result) {
        ?>
        <div class="wrap">
            <h1>Import Results</h1>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>Import Complete!</strong><br>
                    Successfully imported: <?php echo esc_html($result['imported']); ?> questions.<br>
                    Marked as duplicate: <?php echo esc_html($result['duplicates']); ?> questions.
                </p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=qp-import')); ?>" class="button button-primary">Import Another File</a></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=question-press')); ?>" class="button button-secondary">Go to All Questions</a></p>
        </div>
        <?php
    }
}