<?php

namespace QuestionPress\Admin\Views;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use QuestionPress\Database\Terms_DB;

class Importer {

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

        $zip = new \ZipArchive;
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
        // **NEW**: Process hierarchical subject array
        $subject_hierarchy_ids = [];
        $parent_subject_id = 0;
        if (isset($group['subject']) && is_array($group['subject'])) {
            foreach ($group['subject'] as $subject_name) {
                $parent_subject_id = Terms_DB::get_or_create($subject_name, $tax_ids['subject'], $parent_subject_id);
                $subject_hierarchy_ids[] = $parent_subject_id;
            }
        }
        $most_specific_subject_id = end($subject_hierarchy_ids) ?: null;
        $top_level_subject_id = $subject_hierarchy_ids[0] ?? null;

        $exam_term_id = !empty($group['examName']) ? Terms_DB::get_or_create($group['examName'], $tax_ids['exam']) : null;

        // Process hierarchical source array
        $source_hierarchy_ids = [];
        $parent_source_id = 0;
        if (isset($group['source']) && is_array($group['source'])) {
            foreach ($group['source'] as $source_name) {
                $parent_source_id = Terms_DB::get_or_create($source_name, $tax_ids['source'], $parent_source_id);
                $source_hierarchy_ids[] = $parent_source_id;
            }
        }
        $most_specific_source_id = end($source_hierarchy_ids) ?: null;
        $top_level_source_id = $source_hierarchy_ids[0] ?? null;

        // Automatically link the Source to the top-level Subject
        if ($top_level_subject_id && $top_level_source_id) {
            $wpdb->insert($rel_table, [
                'object_id'   => $top_level_source_id,
                'term_id'     => $top_level_subject_id,
                'object_type' => 'source_subject_link'
            ]);
        }
        
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
            'is_pyq' => isset($group['isPYQ']) ? (int)$group['isPYQ'] : 0,
            'pyq_year' => isset($group['pyqYear']) ? sanitize_text_field($group['pyqYear']) : null,
        ]);
        $group_id = $wpdb->insert_id;

        // --- Create Group Relationships ---
        if ($most_specific_subject_id) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $most_specific_subject_id, 'object_type' => 'group']);
        }
        if ($most_specific_source_id) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $most_specific_source_id, 'object_type' => 'group']);
        }
        if ($exam_term_id && $group['isPYQ']) {
            $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $exam_term_id, 'object_type' => 'group']);
            
            // Automatically link the Exam to the top-level Subject
            if ($top_level_subject_id) {
                $wpdb->insert($rel_table, [
                    'object_id'   => $exam_term_id, 
                    'term_id'     => $top_level_subject_id, 
                    'object_type' => 'exam_subject_link'
                ]);
            }
        }

        foreach ($group['questions'] as $question) {
            $question_text = $question['questionText'];
            $hash = md5(strtolower(trim(preg_replace('/\s+/', '', $question_text))));
            $existing_question_id = $wpdb->get_var($wpdb->prepare("SELECT question_id FROM $questions_table WHERE question_text_hash = %s", $hash));

            // --- Create Question ---
            $wpdb->insert($questions_table, [
                'group_id' => $group_id,
                'question_number_in_section' => $question['questionNumber'] ?? null,
                'question_text' => $question_text,
                'question_text_hash' => $hash,
                'duplicate_of' => $existing_question_id ?: null,
                'status' => 'draft'
            ]);
            $question_id = $wpdb->insert_id;

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
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
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