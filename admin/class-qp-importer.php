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

        // Check if it's a zip file
        if ($file['type'] !== 'application/zip') {
            wp_die('Invalid file type. Please upload a .zip file.');
        }

        // Unzip the file to a temporary directory
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

        // Find the questions.json file
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

        // Process the data
        $result = $this->process_data($data);

        // Clean up the temporary directory
        $this->cleanup($temp_dir);

        // Display results
        $this->display_results($result);
    }

    /**
     * Processes the parsed JSON data and inserts it into the database.
     */
    private function process_data($data) {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $options_table = $wpdb->prefix . 'qp_options';

        $imported_count = 0;
        $skipped_count = 0;

        if (!isset($data['questionGroups']) || !is_array($data['questionGroups'])) {
            return ['imported' => 0, 'skipped' => 0];
        }

        foreach ($data['questionGroups'] as $group) {
            // 1. Get or create the subject ID
            $subject_name = !empty($group['subject']) ? sanitize_text_field($group['subject']) : 'Uncategorized';
            $subject_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM $subjects_table WHERE subject_name = %s", $subject_name));
            if (!$subject_id) {
                $wpdb->insert($subjects_table, ['subject_name' => $subject_name]);
                $subject_id = $wpdb->insert_id;
            }

            // 2. Insert the question group (direction)
            $direction_text = isset($group['Direction']['text']) ? $group['Direction']['text'] : null;
            $wpdb->insert($groups_table, [
                'direction_text' => $direction_text,
                'subject_id' => $subject_id
            ]);
            $group_id = $wpdb->insert_id;

            // 3. Loop through and insert questions
            foreach ($group['questions'] as $question) {
                $question_text = $question['questionText'];
                
                // Check for duplicates
                $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));
                $existing_question = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash));

                if ($existing_question) {
                    $skipped_count++;
                    continue;
                }

                $wpdb->insert($questions_table, [
                    'group_id' => $group_id,
                    'question_text' => $question_text,
                    'question_text_hash' => $hash,
                    'is_pyq' => isset($question['isPYQ']) ? (int)$question['isPYQ'] : 0,
                    'source_file' => isset($data['sourceFile']) ? sanitize_text_field($data['sourceFile']) : null,
                ]);
                $question_id = $wpdb->insert_id;

                // 4. Insert options for the question
                if (isset($question['options']) && is_array($question['options'])) {
                    foreach ($question['options'] as $option) {
                        $wpdb->insert($options_table, [
                            'question_id' => $question_id,
                            'option_text' => $option['optionText'],
                            'is_correct' => (int)$option['isCorrect']
                        ]);
                    }
                }
                $imported_count++;
            }
        }

        return ['imported' => $imported_count, 'skipped' => $skipped_count];
    }

    /**
     * Deletes the temporary import directory.
     */
    private function cleanup($dir) {
        // A basic recursive directory removal
        if (!is_dir($dir)) {
            return;
        }
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
                    Skipped (duplicates): <?php echo esc_html($result['skipped']); ?> questions.
                </p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=qp-import')); ?>" class="button button-primary">Import Another File</a></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=question-press')); ?>" class="button button-secondary">Go to All Questions</a></p>
        </div>
        <?php
    }
}