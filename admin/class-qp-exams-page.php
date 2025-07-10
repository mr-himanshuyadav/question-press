<?php
if (!defined('ABSPATH')) exit;

class QP_Exams_Page {

    /**
     * Handles all form submissions for the Exams tab before any HTML is rendered.
     */
    public static function handle_forms() {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'exams') {
            return;
        }
        global $wpdb;
        $exams_table = $wpdb->prefix . 'qp_exams';
        $exam_subjects_table = $wpdb->prefix . 'qp_exam_subjects';

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_exam' || $_POST['action'] === 'update_exam') && check_admin_referer('qp_add_edit_exam_nonce')) {
            $exam_name = sanitize_text_field($_POST['exam_name']);
            $linked_subjects = isset($_POST['linked_subjects']) ? array_map('absint', $_POST['linked_subjects']) : [];

            if (empty($exam_name)) {
                QP_Sources_Page::set_message('Exam name cannot be empty.', 'error');
            } else {
                $data = ['exam_name' => $exam_name];
                if ($_POST['action'] === 'update_exam') {
                    $exam_id = absint($_POST['exam_id']);
                    $wpdb->update($exams_table, $data, ['exam_id' => $exam_id]);
                    QP_Sources_Page::set_message('Exam updated successfully.', 'updated');
                } else {
                    $wpdb->insert($exams_table, $data);
                    $exam_id = $wpdb->insert_id;
                    QP_Sources_Page::set_message('Exam added successfully.', 'updated');
                }

                // Handle the subject linking
                $wpdb->delete($exam_subjects_table, ['exam_id' => $exam_id]);
                if (!empty($linked_subjects)) {
                    foreach ($linked_subjects as $subject_id) {
                        $wpdb->insert($exam_subjects_table, ['exam_id' => $exam_id, 'subject_id' => $subject_id]);
                    }
                }
            }
            QP_Sources_Page::redirect_to_tab('exams');
        }

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['exam_id']) && check_admin_referer('qp_delete_exam_' . absint($_GET['exam_id']))) {
            $exam_id = absint($_GET['exam_id']);
            $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_questions WHERE exam_id = %d", $exam_id));
            if ($usage_count > 0) {
                QP_Sources_Page::set_message("This exam cannot be deleted because it is in use by {$usage_count} PYQ question(s).", 'error');
            } else {
                $wpdb->delete($exams_table, ['exam_id' => $exam_id]);
                $wpdb->delete($exam_subjects_table, ['exam_id' => $exam_id]); // Also remove subject links
                QP_Sources_Page::set_message('Exam deleted successfully.', 'updated');
            }
            QP_Sources_Page::redirect_to_tab('exams');
        }
    }

    /**
     * Renders the HTML for the Exams tab.
     */
    public static function render() {
        global $wpdb;
        $exams_table = $wpdb->prefix . 'qp_exams';
        $exam_subjects_table = $wpdb->prefix . 'qp_exam_subjects';
        $subjects_table = $wpdb->prefix . 'qp_subjects';
        
        $exam_to_edit = null;
        $linked_subjects_for_edit = [];

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['exam_id'])) {
            $exam_id = absint($_GET['exam_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_exam_' . $exam_id)) {
                $exam_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $exams_table WHERE exam_id = %d", $exam_id));
                if ($exam_to_edit) {
                    $linked_subjects_for_edit = $wpdb->get_col($wpdb->prepare("SELECT subject_id FROM $exam_subjects_table WHERE exam_id = %d", $exam_id));
                }
            }
        }
        
        $all_subjects = $wpdb->get_results("SELECT * FROM $subjects_table ORDER BY subject_name ASC");
        
        // Fetch all exams and their linked subjects for the display table
        $exams = $wpdb->get_results("SELECT * FROM $exams_table ORDER BY exam_year DESC, exam_name ASC");
        $all_linked_subjects = $wpdb->get_results("
            SELECT es.exam_id, s.subject_name 
            FROM $exam_subjects_table es 
            JOIN $subjects_table s ON es.subject_id = s.subject_id
        ");

        $subjects_by_exam = [];
        foreach ($all_linked_subjects as $link) {
            $subjects_by_exam[$link->exam_id][] = $link->subject_name;
        }

        if (isset($_SESSION['qp_admin_message'])) {
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php echo $exam_to_edit ? 'Edit Exam' : 'Add New Exam'; ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=exams">
                            <?php wp_nonce_field('qp_add_edit_exam_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $exam_to_edit ? 'update_exam' : 'add_exam'; ?>">
                            <?php if ($exam_to_edit) : ?>
                                <input type="hidden" name="exam_id" value="<?php echo esc_attr($exam_to_edit->exam_id); ?>">
                            <?php endif; ?>
                            
                            <div class="form-field form-required">
                                <label for="exam-name">Exam Name</label>
                                <input name="exam_name" id="exam-name" type="text" value="<?php echo $exam_to_edit ? esc_attr($exam_to_edit->exam_name) : ''; ?>" size="40" required>
                                <p>e.g., UPSC Prelims, NEET, GATE Civil</p>
                            </div>
                            
                            <div class="form-field">
                                <label>Linked Subjects</label>
                                <div class="subjects-checkbox-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 200px; overflow-y: auto;">
                                <?php foreach ($all_subjects as $subject): 
                                    if (strtolower($subject->subject_name) === 'uncategorized') continue; // Don't allow linking 'Uncategorized'
                                    $checked = in_array($subject->subject_id, $linked_subjects_for_edit);
                                ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="linked_subjects[]" value="<?php echo esc_attr($subject->subject_id); ?>" <?php checked($checked); ?>>
                                        <?php echo esc_html($subject->subject_name); ?>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                                <p>Select all subjects that are part of this exam.</p>
                            </div>

                            <p class="submit">
                                <input type="submit" class="button button-primary" value="<?php echo $exam_to_edit ? 'Update Exam' : 'Add New Exam'; ?>">
                                <?php if ($exam_to_edit) : ?>
                                    <a href="admin.php?page=qp-organization&tab=exams" class="button button-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Exam</th>
                                <th>Linked Subjects</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($exams)) : foreach ($exams as $exam) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($exam->exam_name); ?></strong>
                                        <?php if ($exam->exam_year) echo ' (' . esc_html($exam->exam_year) . ')'; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            if (!empty($subjects_by_exam[$exam->exam_id])) {
                                                echo implode(', ', array_map('esc_html', $subjects_by_exam[$exam->exam_id]));
                                            } else {
                                                echo '<em>None</em>';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $edit_nonce = wp_create_nonce('qp_edit_exam_' . $exam->exam_id);
                                            $delete_nonce = wp_create_nonce('qp_delete_exam_' . $exam->exam_id);
                                            $edit_link = sprintf('<a href="?page=qp-organization&tab=exams&action=edit&exam_id=%s&_wpnonce=%s">Edit</a>', $exam->exam_id, $edit_nonce);
                                            $delete_link = sprintf('<a href="?page=qp-organization&tab=exams&action=delete&exam_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $exam->exam_id, $delete_nonce);
                                            echo $edit_link . ' | ' . $delete_link;
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr class="no-items"><td colspan="3">No exams found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}