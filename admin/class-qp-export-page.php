<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Export_Page {

    public static function handle_export_submission() {
        if (isset($_GET['page']) && $_GET['page'] === 'qp-export' && isset($_POST['export_questions'])) {
            if (check_admin_referer('qp_export_nonce_action', 'qp_export_nonce_field')) {
                self::generate_zip();
            }
        }
    }

    public static function render() {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Export Questions</h1>
            <hr class="wp-header-end">

            <p>Select the subjects you wish to export. All questions within the selected subjects will be exported into a single <code>.zip</code> file conforming to schema v2.2.</p>

            <form method="post" action="admin.php?page=qp-export">
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

    private static function generate_zip() {
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
        $t_table = $wpdb->prefix . 'qp_topics';
        $src_table = $wpdb->prefix . 'qp_sources';
        $sec_table = $wpdb->prefix . 'qp_source_sections';
        $e_table = $wpdb->prefix . 'qp_exams';

        // Get all groups within the selected subjects
        $groups_data = $wpdb->get_results($wpdb->prepare(
            "SELECT g.group_id, g.direction_text, g.direction_image_id, g.is_pyq, g.pyq_year,
                    s.subject_name, e.exam_name
             FROM {$g_table} g
             LEFT JOIN {$s_table} s ON g.subject_id = s.subject_id
             LEFT JOIN {$e_table} e ON g.exam_id = e.exam_id
             WHERE g.subject_id IN ($subject_ids_placeholder)",
            $subject_ids
        ));

        if (empty($groups_data)) {
            wp_die('No question groups found for the selected subjects.');
        }

        $final_question_groups = [];

        foreach ($groups_data as $group) {
            // Get all questions for the current group
            $questions_in_group = $wpdb->get_results($wpdb->prepare(
                "SELECT q.question_id, q.custom_question_id, q.question_text, q.question_number_in_section,
                        t.topic_name, src.source_name, sec.section_name
                 FROM {$q_table} q
                 LEFT JOIN {$t_table} t ON q.topic_id = t.topic_id
                 LEFT JOIN {$src_table} src ON q.source_id = src.source_id
                 LEFT JOIN {$sec_table} sec ON q.section_id = sec.section_id
                 WHERE q.group_id = %d",
                $group->group_id
            ));
            
            if(empty($questions_in_group)) continue; // Skip groups with no questions

            $direction_image_name = $group->direction_image_id ? basename(get_attached_file($group->direction_image_id)) : null;

            $group_output = [
                'groupId'       => 'db_group_' . $group->group_id,
                'subject'       => $group->subject_name,
                // These source fields are taken from the first question, assuming they are consistent for the group
                'sourceName'    => $questions_in_group[0]->source_name,
                'sectionName'   => $questions_in_group[0]->section_name,
                'isPYQ'         => (bool)$group->is_pyq,
                'examName'      => $group->exam_name,
                'pyqYear'       => $group->pyq_year,
                'Direction'     => [
                    'text'  => $group->direction_text,
                    'image' => $direction_image_name
                ],
                'questions'     => []
            ];

            foreach ($questions_in_group as $question) {
                $options = $wpdb->get_results($wpdb->prepare("SELECT option_text, is_correct FROM {$o_table} WHERE question_id = %d", $question->question_id));
                $options_array = [];
                foreach ($options as $opt) {
                    $options_array[] = ['optionText' => $opt->option_text, 'isCorrect' => (bool)$opt->is_correct];
                }

                $group_output['questions'][] = [
                    'questionId'        => 'db_question_' . $question->question_id,
                    'topicName'         => $question->topic_name,
                    'questionText'      => $question->question_text,
                    'questionNumber'    => $question->question_number_in_section,
                    'options'           => $options_array,
                ];
            }
            $final_question_groups[] = $group_output;
        }


        $filename = 'qp-export-' . date('Y-m-d') . '.zip';
        $json_filename = 'questions.json';

        $final_json = [
            'schemaVersion' => '2.2',
            'exportTimestamp' => date('c'),
            'sourceFile' => 'database_export',
            'questionGroups' => $final_question_groups
        ];
        
        $json_data = json_encode($final_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $zip_path = tempnam(sys_get_temp_dir(), 'qp_export');
        $zip = new ZipArchive();

        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            wp_die("Cannot create ZIP archive.");
        }

        $zip->addFromString($json_filename, $json_data);
        $zip->close();
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Connection: close');
        readfile($zip_path);
        unlink($zip_path);
        exit;
    }
}