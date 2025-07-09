<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Importer {

    public function handle_import() {
        if (!isset($_POST['qp_import_nonce_field']) || !wp_verify_nonce($_POST['qp_import_nonce_field'], 'qp_import_nonce_action')) {
            wp_die('Security check failed.');
        }

        if (!isset($_FILES['question_zip_file']) || $_FILES['question_zip_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('File upload error. Please try again.');
        }

        $labels_to_apply = isset($_POST['labels_to_apply']) ? array_map('absint', $_POST['labels_to_apply']) : [];
        $file = $_FILES['question_zip_file'];
        $file_path = $file['tmp_name'];

        if (!in_array($file['type'], ['application/zip', 'application/x-zip-compressed'])) {
            wp_die('Invalid file type. Please upload a .zip file.');
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'qp_temp_import_' . time();
        wp_mkdir_p($temp_dir);

        $zip = new ZipArchive;
        if ($zip->open($file_path) === TRUE) {
            $zip->extractTo($temp_dir);
            $zip->close();
        } else {
            $this->cleanup($temp_dir);
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
            wp_die('Invalid JSON format in questions.json file. Error: ' . json_last_error_msg());
        }
        
        // Pass the temporary directory to the processing function
        $result = $this->process_data($data, $labels_to_apply, $temp_dir);
        $this->cleanup($temp_dir);
        $this->display_results($result);
    }

    /**
     * Finds an attachment ID by its filename.
     *
     * @param string $filename The name of the file to search for.
     * @return int|null The attachment ID if found, otherwise null.
     */
    private function find_attachment_id_by_filename($filename) {
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            '%' . $wpdb->esc_like($filename)
        ));

        if ($post_id) {
            return (int) $post_id;
        }
        return null;
    }

    /**
     * Handles the upload of an image or finds an existing one.
     *
     * @param string $image_filename The name of the image file.
     * @param string $temp_dir The temporary directory where the zip was extracted.
     * @return int|null The attachment ID or null on failure.
     */
    private function get_or_upload_image($image_filename, $temp_dir) {
        if (empty($image_filename)) {
            return null;
        }

        // Check if the image already exists in the media library
        $existing_attachment_id = $this->find_attachment_id_by_filename($image_filename);
        if ($existing_attachment_id) {
            return $existing_attachment_id;
        }

        // If not, proceed to upload it
        $image_path = trailingslashit($temp_dir) . 'images/' . $image_filename;

        if (!file_exists($image_path)) {
            return null;
        }
        
        // Need to require these files to use media_handle_sideload()
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Create a temporary file copy for WordPress to handle
        $temp_file = tmpfile();
        fwrite($temp_file, file_get_contents($image_path));
        $meta = stream_get_meta_data($temp_file);
        
        $file_array = [
            'name'     => $image_filename,
            'tmp_name' => $meta['uri']
        ];
        
        // Let WordPress handle the upload
        $attachment_id = media_handle_sideload($file_array, 0);
        
        fclose($temp_file); // Close and delete the temporary file

        if (is_wp_error($attachment_id)) {
            error_log('Question Press Importer Error: ' . $attachment_id->get_error_message());
            return null;
        }

        return $attachment_id;
    }

    private function process_data($data, $labels_to_apply = [], $temp_dir = '') {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $topics_table = $wpdb->prefix . 'qp_topics';
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
            // Find or create subject
            $subject_name = !empty($group['subject']) ? sanitize_text_field($group['subject']) : 'Uncategorized';
            $subject_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM $subjects_table WHERE subject_name = %s", $subject_name));
            if (!$subject_id) {
                $wpdb->insert($subjects_table, ['subject_name' => $subject_name, 'description' => '']);
                $subject_id = $wpdb->insert_id;
            }

            // --- IMAGE HANDLING LOGIC ---
            $direction_image_id = null;
            if (isset($group['Direction']['image'])) {
                $direction_image_id = $this->get_or_upload_image($group['Direction']['image'], $temp_dir);
            }
            // --- END IMAGE HANDLING LOGIC ---

            $direction_text = isset($group['Direction']['text']) ? $group['Direction']['text'] : null;
            $wpdb->insert($groups_table, [
                'direction_text' => $direction_text,
                'direction_image_id' => $direction_image_id, // Save the image ID
                'subject_id' => $subject_id
            ]);
            $group_id = $wpdb->insert_id;

            foreach ($group['questions'] as $question) {
                // Find or create topic
                $topic_id = null;
                $topic_name = !empty($question['topicName']) ? sanitize_text_field($question['topicName']) : null;
                if ($topic_name) {
                    $topic_id = $wpdb->get_var($wpdb->prepare("SELECT topic_id FROM $topics_table WHERE topic_name = %s AND subject_id = %d", $topic_name, $subject_id));
                    if (!$topic_id) {
                        $wpdb->insert($topics_table, ['topic_name' => $topic_name, 'subject_id' => $subject_id]);
                        $topic_id = $wpdb->insert_id;
                    }
                }
                
                $question_text = $question['questionText'];
                $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));

                $existing_question_id = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash));

                $next_custom_id = get_option('qp_next_custom_question_id', 1000);

                $wpdb->insert($questions_table, [
                    'custom_question_id' => $next_custom_id,
                    'group_id' => $group_id,
                    'topic_id' => $topic_id,
                    'question_text' => $question['questionText'],
                    'question_text_hash' => $hash,
                    'is_pyq' => isset($question['isPYQ']) ? (int)$question['isPYQ'] : 0,
                    'source_file' => isset($data['sourceFile']) ? sanitize_text_field($data['sourceFile']) : null,
                    'source_page' => isset($question['source']['page']) ? absint($question['source']['page']) : null,
                    'source_number' => isset($question['source']['number']) ? absint($question['source']['number']) : null,
                    'duplicate_of' => $existing_question_id ? $existing_question_id : null
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

                if (!empty($labels_to_apply)) {
                    foreach ($labels_to_apply as $label_id) {
                        $wpdb->query($wpdb->prepare(
                            "INSERT IGNORE INTO $question_labels_table (question_id, label_id) VALUES (%d, %d)",
                            $question_id, $label_id
                        ));
                    }
                }
            }
        }
        return ['imported' => $imported_count, 'duplicates' => $duplicate_count];
    }

    private function cleanup($dir) {
        if (!is_dir($dir)) return;

        // A more robust cleanup function
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

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