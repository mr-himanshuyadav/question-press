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
        
        $result = $this->process_data($data, $labels_to_apply, $temp_dir);
        $this->cleanup($temp_dir);
        $this->display_results($result);
    }

    private function find_attachment_id_by_filename($filename) {
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            '%' . $wpdb->esc_like($filename)
        ));
        return $post_id ? (int) $post_id : null;
    }

    private function get_or_upload_image($image_filename, $temp_dir) {
        if (empty($image_filename)) return null;
        if ($existing_id = $this->find_attachment_id_by_filename($image_filename)) {
            return ['id' => $existing_id, 'is_new' => false];
        }

        $image_path = trailingslashit($temp_dir) . 'images/' . $image_filename;
        if (!file_exists($image_path)) return null;

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $file_array = ['name' => $image_filename, 'tmp_name' => $image_path];
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            error_log('Question Press Importer Error: ' . $attachment_id->get_error_message());
            return null;
        }
        return ['id' => $attachment_id, 'is_new' => true];
    }

    private function process_data($data, $labels_to_apply = [], $temp_dir = '') {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $topics_table = $wpdb->prefix . 'qp_topics';
        $sources_table = $wpdb->prefix . 'qp_sources';
        $sections_table = $wpdb->prefix . 'qp_source_sections';
        $exams_table = $wpdb->prefix . 'qp_exams';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $options_table = $wpdb->prefix . 'qp_options';
        $labels_table = $wpdb->prefix . 'qp_labels';
        $question_labels_table = $wpdb->prefix . 'qp_question_labels';

        $imported_count = 0;
        $duplicate_count = 0;
        $new_images_count = 0;
        $duplicate_label_id = $wpdb->get_var($wpdb->prepare("SELECT label_id FROM $labels_table WHERE label_name = %s", 'Duplicate'));

        if (!isset($data['questionGroups']) || !is_array($data['questionGroups'])) {
            return ['imported' => 0, 'duplicates' => 0, 'new_images' => 0];
        }

        foreach ($data['questionGroups'] as $group) {
            $subject_name = !empty($group['subject']) ? sanitize_text_field($group['subject']) : 'Uncategorized';
            $subject_id = $wpdb->get_var($wpdb->prepare("SELECT subject_id FROM $subjects_table WHERE subject_name = %s", $subject_name));
            if (!$subject_id) {
                $wpdb->insert($subjects_table, ['subject_name' => $subject_name]);
                $subject_id = $wpdb->insert_id;
            }

            $source_id = null;
            if (!empty($group['sourceName'])) {
                $source_name = sanitize_text_field($group['sourceName']);
                $source_id = $wpdb->get_var($wpdb->prepare("SELECT source_id FROM $sources_table WHERE source_name = %s AND subject_id = %d", $source_name, $subject_id));
                if (!$source_id) {
                    $wpdb->insert($sources_table, ['source_name' => $source_name, 'subject_id' => $subject_id]);
                    $source_id = $wpdb->insert_id;
                }
            }
            
            $section_id = null;
            if ($source_id && !empty($group['sectionName'])) {
                $section_name = sanitize_text_field($group['sectionName']);
                $section_id = $wpdb->get_var($wpdb->prepare("SELECT section_id FROM $sections_table WHERE section_name = %s AND source_id = %d", $section_name, $source_id));
                if (!$section_id) {
                    $wpdb->insert($sections_table, ['section_name' => $section_name, 'source_id' => $source_id]);
                    $section_id = $wpdb->insert_id;
                }
            }

            $exam_id = null;
            if (!empty($group['examName'])) {
                $exam_name = sanitize_text_field($group['examName']);
                $exam_id = $wpdb->get_var($wpdb->prepare("SELECT exam_id FROM $exams_table WHERE exam_name = %s", $exam_name));
                if (!$exam_id) {
                    $wpdb->insert($exams_table, ['exam_name' => $exam_name]);
                    $exam_id = $wpdb->insert_id;
                }
            }

            $direction_image_id = null;
            if (isset($group['Direction']['image'])) {
                $image_result = $this->get_or_upload_image($group['Direction']['image'], $temp_dir);
                if ($image_result) {
                    $direction_image_id = $image_result['id'];
                    if ($image_result['is_new']) $new_images_count++;
                }
            }

            // --- CORRECTED: Insert group data, including new PYQ fields ---
            $wpdb->insert($groups_table, [
                'direction_text' => isset($group['Direction']['text']) ? $group['Direction']['text'] : null,
                'direction_image_id' => $direction_image_id,
                'subject_id' => $subject_id,
                'is_pyq' => isset($group['isPYQ']) ? (int)$group['isPYQ'] : 0,
                'exam_id' => $exam_id,
                'pyq_year' => isset($group['pyqYear']) ? sanitize_text_field($group['pyqYear']) : null,
            ]);
            $group_id = $wpdb->insert_id;

            foreach ($group['questions'] as $question) {
                $topic_id = null;
                if (!empty($question['topicName'])) {
                    $topic_name = sanitize_text_field($question['topicName']);
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

                // --- CORRECTED: Insert question data without PYQ fields ---
                $wpdb->insert($questions_table, [
                    'custom_question_id' => $next_custom_id,
                    'group_id' => $group_id,
                    'topic_id' => $topic_id,
                    'source_id' => $source_id,
                    'section_id' => $section_id,
                    'question_number_in_section' => isset($question['questionNumber']) ? sanitize_text_field($question['questionNumber']) : null,
                    'question_text' => $question['questionText'],
                    'question_text_hash' => $hash,
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
                        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $question_labels_table (question_id, label_id) VALUES (%d, %d)", $question_id, $label_id));
                    }
                }
            }
        }
        return ['imported' => $imported_count, 'duplicates' => $duplicate_count, 'new_images' => $new_images_count];
    }

    private function cleanup($dir) {
        if (!is_dir($dir)) return;
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){ rmdir($file->getRealPath()); } else { unlink($file->getRealPath()); }
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
                    New images uploaded: <?php echo esc_html($result['new_images']); ?>.<br>
                    Marked as duplicate: <?php echo esc_html($result['duplicates']); ?> questions.
                </p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=qp-import')); ?>" class="button button-primary">Import Another File</a></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=question-press')); ?>" class="button button-secondary">Go to All Questions</a></p>
        </div>
        <?php
    }
}