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

    private function process_data($data, $labels_to_apply = [], $temp_dir = '')
    {
        global $wpdb;
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $groups_table = $wpdb->prefix . 'qp_question_groups';
        $questions_table = $wpdb->prefix . 'qp_questions';
        $options_table = $wpdb->prefix . 'qp_options';

        // Cache taxonomy IDs
        $tax_ids = [
            'subject' => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'"),
            'exam'    => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'exam'"),
            'source'  => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'"),
            'label'   => $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'"),
        ];

        $imported_count = 0;
        $duplicate_count = 0;
        $new_images_count = 0;
        
        $duplicate_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM $term_table WHERE name = %s AND taxonomy_id = %d", 'Duplicate', $tax_ids['label']));

        if (!isset($data['questionGroups']) || !is_array($data['questionGroups'])) {
            return ['imported' => 0, 'duplicates' => 0, 'new_images' => 0];
        }

        foreach ($data['questionGroups'] as $group) {
            // --- Term Handling ---
            $subject_term_id = qp_get_or_create_term($group['subject'] ?? 'Uncategorized', $tax_ids['subject']);
            $exam_term_id = !empty($group['examName']) ? qp_get_or_create_term($group['examName'], $tax_ids['exam']) : null;
            $source_term_id = !empty($group['sourceName']) ? qp_get_or_create_term($group['sourceName'], $tax_ids['source']) : null;
            $section_term_id = ($source_term_id && !empty($group['sectionName'])) ? qp_get_or_create_term($group['sectionName'], $tax_ids['source'], $source_term_id) : null;
            
            // --- Image Handling ---
            $direction_image_id = null;
            if (isset($group['Direction']['image'])) {
                $image_result = $this->get_or_upload_image($group['Direction']['image'], $temp_dir);
                if ($image_result) {
                    $direction_image_id = $image_result['id'];
                    if ($image_result['is_new']) $new_images_count++;
                }
            }

            // --- Create Question Group ---
            $wpdb->insert($groups_table, [
                'direction_text' => isset($group['Direction']['text']) ? $group['Direction']['text'] : null,
                'direction_image_id' => $direction_image_id,
                'subject_id' => 0, // Legacy column, can be removed later
                'is_pyq' => isset($group['isPYQ']) ? (int)$group['isPYQ'] : 0,
                'exam_id' => 0, // Legacy
                'pyq_year' => isset($group['pyqYear']) ? sanitize_text_field($group['pyqYear']) : null,
            ]);
            $group_id = $wpdb->insert_id;

            // --- Create Group Relationships ---
            if ($subject_term_id) {
                $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $subject_term_id, 'object_type' => 'group']);
            }
            if ($exam_term_id && $group['isPYQ']) {
                $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $exam_term_id, 'object_type' => 'group']);
            }

            foreach ($group['questions'] as $question) {
                $topic_term_id = !empty($question['topicName']) ? qp_get_or_create_term($question['topicName'], $tax_ids['subject'], $subject_term_id) : null;

                $question_text = $question['questionText'];
                $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));
                $existing_question_id = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash));
                $next_custom_id = get_option('qp_next_custom_question_id', 1000);

                // --- Create Question ---
                $wpdb->insert($questions_table, [
                    'custom_question_id' => $next_custom_id,
                    'group_id' => $group_id,
                    'topic_id' => 0, // Legacy
                    'source_id' => $source_term_id, // Store term_id here
                    'section_id' => $section_term_id, // Store term_id here
                    'question_number_in_section' => $question['questionNumber'] ?? null,
                    'question_text' => $question_text,
                    'question_text_hash' => $hash,
                    'duplicate_of' => $existing_question_id ?: null,
                    'status' => 'draft'
                ]);
                $question_id = $wpdb->insert_id;
                update_option('qp_next_custom_question_id', $next_custom_id + 1);

                // --- Create Question Relationships ---
                if ($topic_term_id) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $topic_term_id, 'object_type' => 'question']);
                }
                if ($section_term_id) { // Link question to the most specific source term (the section)
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $section_term_id, 'object_type' => 'question']);
                } elseif ($source_term_id) { // Fallback to parent source if no section
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $source_term_id, 'object_type' => 'question']);
                }

                // --- Add Options and Set Status ---
                $has_correct_answer = false;
                if (!empty($question['options']) && is_array($question['options'])) {
                    foreach ($question['options'] as $option) {
                        $is_correct_val = !empty($option['isCorrect']);
                        $wpdb->insert($options_table, [
                            'question_id' => $question_id,
                            'option_text' => $option['optionText'],
                            'is_correct' => $is_correct_val ? 1 : 0
                        ]);
                        if ($is_correct_val) $has_correct_answer = true;
                    }
                }

                if ($has_correct_answer) {
                    $wpdb->update($questions_table, ['status' => 'publish'], ['question_id' => $question_id]);
                }

                // --- Handle Labels ---
                if ($existing_question_id && $duplicate_term_id) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $duplicate_term_id, 'object_type' => 'question']);
                    $duplicate_count++;
                }
                $imported_count++;

                if (!empty($labels_to_apply)) {
                    foreach ($labels_to_apply as $label_term_id) {
                        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'question')", $question_id, $label_term_id));
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
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=qp-tools&tab=import')); ?>" class="button button-primary">Import Another File</a></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=question-press')); ?>" class="button button-secondary">Go to All Questions</a></p>
        </div>
        <?php
    }
}