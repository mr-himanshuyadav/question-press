<?php
namespace QuestionPress\Admin\Views;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use QuestionPress\Database\Terms_DB;
use QuestionPress\Utils\Template_Loader;

class Export_Page
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
        self::handle_export_submission();
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        
        $subjects = [];
        if ($subject_tax_id) {
            $subjects = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0 ORDER BY name ASC",
                $subject_tax_id
            ));
        }

        // Prepare arguments for the template
        $args = [
            'subjects' => $subjects,
        ];

        // Load and echo the template
        echo Template_Loader::get_html( 'tools-export', 'admin', $args );
    }

    private static function generate_zip()
{
    if (empty($_POST['subject_ids'])) {
        wp_die('Please select at least one subject to export.');
    }

    $subject_term_ids = array_map('absint', $_POST['subject_ids']);

    global $wpdb;
    $q_table = $wpdb->prefix . 'qp_questions';
    $g_table = $wpdb->prefix . 'qp_question_groups';
    $o_table = $wpdb->prefix . 'qp_options';
    $term_table = $wpdb->prefix . 'qp_terms';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    
    // **FIX START**: Use the new global helper function to get all topics and subjects to query.
    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
    $all_descendant_ids = [];
    foreach($subject_term_ids as $sid) {
        // This function is now available globally from question-press.php
        $descendants = Terms_DB::get_all_descendant_ids($sid, $wpdb, $term_table);
        $all_descendant_ids = array_merge($all_descendant_ids, $descendants);
    }
    $all_descendant_ids = array_unique($all_descendant_ids);
    $term_ids_placeholder = implode(',', $all_descendant_ids);
    // **FIX END**

    $groups_data = $wpdb->get_results("
        SELECT g.*, exam.name AS exam_name
        FROM {$g_table} g
        JOIN {$rel_table} rel ON g.group_id = rel.object_id AND rel.object_type = 'group'
        LEFT JOIN (
            SELECT rel_exam.object_id, exam_term.name 
            FROM {$rel_table} rel_exam 
            JOIN {$term_table} exam_term ON rel_exam.term_id = exam_term.term_id
            WHERE rel_exam.object_type = 'group'
        ) AS exam ON g.group_id = exam.object_id
        WHERE rel.term_id IN ($term_ids_placeholder)
        GROUP BY g.group_id
    ");

    if (empty($groups_data)) {
        wp_die('No question groups found for the selected subjects.');
    }

    $final_question_groups = [];
    $all_options_raw = $wpdb->get_results("SELECT * FROM $o_table");
    $options_by_question = [];
    foreach ($all_options_raw as $opt) {
        $options_by_question[$opt->question_id][] = $opt;
    }

    foreach ($groups_data as $group) {
        $questions_in_group = $wpdb->get_results($wpdb->prepare("SELECT * FROM $q_table WHERE group_id = %d", $group->group_id));
        if (empty($questions_in_group)) continue;

        // Get Subject/Topic lineage for the group
        $subject_lineage_names = [];
        $group_subject_term_id = $wpdb->get_var($wpdb->prepare("SELECT term_id FROM $rel_table WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM $term_table WHERE taxonomy_id = %d)", $group->group_id, $subject_tax_id));
        if ($group_subject_term_id) {
            // This function is now available globally from question-press.php
            $subject_lineage_names = Terms_DB::get_lineage_names($group_subject_term_id, $wpdb, $term_table);
        }

        // Get Source/Section lineage for the group
        $source_lineage_names = Terms_DB::get_source_hierarchy_for_question($questions_in_group[0]->question_id); // This function was already in the main plugin file
        $direction_image_name = $group->direction_image_id ? basename(get_attached_file($group->direction_image_id)) : null;

        $group_output = [
            'groupId'       => 'db_group_' . $group->group_id,
            'subject'       => $subject_lineage_names,
            'source'        => $source_lineage_names,
            'isPYQ'         => (bool)$group->is_pyq,
            'examName'      => $group->exam_name,
            'pyqYear'       => $group->pyq_year,
            'Direction'     => ['text' => $group->direction_text, 'image' => $direction_image_name],
            'questions'     => []
        ];

        foreach ($questions_in_group as $question) {
            $options_array = [];
            if (isset($options_by_question[$question->question_id])) {
                foreach ($options_by_question[$question->question_id] as $opt) {
                    $options_array[] = ['optionText' => $opt->option_text, 'isCorrect' => (bool)$opt->is_correct];
                }
            }

            $group_output['questions'][] = [
                'questionId'        => 'db_question_' . $question->question_id,
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
        'schemaVersion' => '3.2',
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
