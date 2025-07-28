<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Export_Page
{

    public static function handle_export_submission()
    {
        if (isset($_GET['page']) && $_GET['page'] === 'qp-tools' && isset($_POST['export_questions'])) {
            if (check_admin_referer('qp_export_nonce_action', 'qp_export_nonce_field')) {
                self::generate_zip();
            }
        }
    }

    public static function render()
    {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        $subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Export Questions</h1>
            <hr class="wp-header-end">

            <p>Select the subjects you wish to export. All questions within the selected subjects will be exported into a single <code>.zip</code> file conforming to schema v2.2.</p>

            <form method="post" action="admin.php?page=qp-tools&tab=export">
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

    private static function generate_zip()
    {
        if (empty($_POST['subject_ids'])) {
            wp_die('Please select at least one subject to export.');
        }

        $subject_term_ids = array_map('absint', $_POST['subject_ids']);
        $ids_placeholder = implode(',', $subject_term_ids);

        global $wpdb;
        $q_table = $wpdb->prefix . 'qp_questions';
        $g_table = $wpdb->prefix . 'qp_question_groups';
        $o_table = $wpdb->prefix . 'qp_options';
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Get all groups linked to the selected subjects
        $groups_data = $wpdb->get_results("
            SELECT g.*, subj.name AS subject_name, exam.name AS exam_name
            FROM {$g_table} g
            JOIN {$rel_table} rel ON g.group_id = rel.object_id AND rel.object_type = 'group'
            JOIN {$term_table} subj ON rel.term_id = subj.term_id
            LEFT JOIN (
                SELECT rel_exam.object_id, exam_term.name 
                FROM {$rel_table} rel_exam 
                JOIN {$term_table} exam_term ON rel_exam.term_id = exam_term.term_id
                WHERE rel_exam.object_type = 'group'
            ) AS exam ON g.group_id = exam.object_id
            WHERE rel.term_id IN ($ids_placeholder)
        ");

        if (empty($groups_data)) {
            wp_die('No question groups found for the selected subjects.');
        }

        $final_question_groups = [];
        $all_question_ids = wp_list_pluck($wpdb->get_results("SELECT question_id, group_id FROM $q_table"), 'question_id', 'group_id');
        $all_options_raw = $wpdb->get_results("SELECT * FROM $o_table");
        $options_by_question = [];
        foreach($all_options_raw as $opt) {
            $options_by_question[$opt->question_id][] = $opt;
        }

        foreach ($groups_data as $group) {
            $questions_in_group = $wpdb->get_results($wpdb->prepare("SELECT * FROM $q_table WHERE group_id = %d", $group->group_id));

            if (empty($questions_in_group)) continue;

            $direction_image_name = $group->direction_image_id ? basename(get_attached_file($group->direction_image_id)) : null;

            $group_output = [
                'groupId'       => 'db_group_' . $group->group_id,
                'subject'       => $group->subject_name,
                'sourceName'    => null, // Will be populated by the first question
                'sectionName'   => null, // Will be populated by the first question
                'isPYQ'         => (bool)$group->is_pyq,
                'examName'      => $group->exam_name,
                'pyqYear'       => $group->pyq_year,
                'Direction'     => ['text' => $group->direction_text, 'image' => $direction_image_name],
                'questions'     => []
            ];

            $first_question_processed = false;

            foreach ($questions_in_group as $question) {
                // Get Topic
                $topic_name = $wpdb->get_var($wpdb->prepare("SELECT t.name FROM $term_table t JOIN $rel_table r ON t.term_id = r.term_id WHERE r.object_id = %d AND r.object_type = 'question' AND t.parent != 0", $question->question_id));
                
                // Get Source & Section
                $source_section_names = qp_get_source_hierarchy_for_question($question->question_id);

                // Populate group-level source/section from the first question
                if (!$first_question_processed) {
                    $group_output['sourceName'] = $source_section_names['source'];
                    $group_output['sectionName'] = $source_section_names['section'];
                    $first_question_processed = true;
                }
                
                $options_array = [];
                if(isset($options_by_question[$question->question_id])) {
                    foreach ($options_by_question[$question->question_id] as $opt) {
                        $options_array[] = ['optionText' => $opt->option_text, 'isCorrect' => (bool)$opt->is_correct];
                    }
                }

                $group_output['questions'][] = [
                    'questionId'        => 'db_question_' . $question->question_id,
                    'topicName'         => $topic_name,
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
