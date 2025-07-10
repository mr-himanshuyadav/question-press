<?php
if (!defined('ABSPATH')) exit;

class QP_Subjects_Page {

    public static function handle_forms() {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'subjects') {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_subjects';

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_subject' || $_POST['action'] === 'update_subject') && check_admin_referer('qp_add_edit_subject_nonce')) {
            $subject_name = sanitize_text_field($_POST['subject_name']);
            $description = sanitize_textarea_field($_POST['subject_description']);

            if (empty($subject_name)) {
                QP_Sources_Page::set_message('Subject name cannot be empty.', 'error');
            } else {
                $data = ['subject_name' => $subject_name, 'description' => $description];
                if ($_POST['action'] === 'update_subject') {
                    $subject_id = absint($_POST['subject_id']);
                    if (strtolower($wpdb->get_var($wpdb->prepare("SELECT subject_name FROM $table_name WHERE subject_id = %d", $subject_id))) === 'uncategorized') {
                        unset($data['subject_name']); // Don't allow changing the 'Uncategorized' name
                    }
                    $wpdb->update($table_name, $data, ['subject_id' => $subject_id]);
                    QP_Sources_Page::set_message('Subject updated.', 'updated');
                } else {
                    $wpdb->insert($table_name, $data);
                    QP_Sources_Page::set_message('Subject added.', 'updated');
                }
            }
            QP_Sources_Page::redirect_to_tab('subjects');
        }

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['subject_id']) && check_admin_referer('qp_delete_subject_' . absint($_GET['subject_id']))) {
            $subject_id = absint($_GET['subject_id']);
            if (strtolower($wpdb->get_var($wpdb->prepare("SELECT subject_name FROM $table_name WHERE subject_id = %d", $subject_id))) === 'uncategorized') {
                QP_Sources_Page::set_message('The "Uncategorized" subject cannot be deleted.', 'error');
            } else {
                $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_question_groups WHERE subject_id = %d", $subject_id));
                if ($usage_count > 0) {
                    QP_Sources_Page::set_message("This subject cannot be deleted because it is in use by {$usage_count} question group(s).", 'error');
                } else {
                    $wpdb->delete($table_name, ['subject_id' => $subject_id]);
                    QP_Sources_Page::set_message('Subject deleted successfully.', 'updated');
                }
            }
            QP_Sources_Page::redirect_to_tab('subjects');
        }
    }

    public static function render() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_subjects';
        $subject_to_edit = null;

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['subject_id'])) {
            $subject_id = absint($_GET['subject_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_subject_' . $subject_id)) {
                $subject_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE subject_id = %d", $subject_id));
            }
        }
        
        $subjects = $wpdb->get_results("SELECT * FROM $table_name ORDER BY subject_name ASC");
        
        if (isset($_SESSION['qp_admin_message'])) {
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
        ?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php echo $subject_to_edit ? 'Edit Subject' : 'Add New Subject'; ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=subjects">
                            <?php wp_nonce_field('qp_add_edit_subject_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $subject_to_edit ? 'update_subject' : 'add_subject'; ?>">
                            <?php if ($subject_to_edit) : ?>
                                <input type="hidden" name="subject_id" value="<?php echo esc_attr($subject_to_edit->subject_id); ?>">
                            <?php endif; ?>
                            
                            <div class="form-field form-required">
                                <label for="subject-name">Name</label>
                                <input name="subject_name" id="subject-name" type="text" value="<?php echo $subject_to_edit ? esc_attr($subject_to_edit->subject_name) : ''; ?>" size="40" required <?php echo ($subject_to_edit && strtolower($subject_to_edit->subject_name) === 'uncategorized') ? 'readonly' : ''; ?>>
                                <?php if ($subject_to_edit && strtolower($subject_to_edit->subject_name) === 'uncategorized'): ?>
                                <p>The "Uncategorized" name cannot be changed.</p>
                                <?php endif; ?>
                            </div>

                            <div class="form-field">
                                <label for="subject-description">Description</label>
                                <textarea name="subject_description" id="subject-description" rows="3" cols="40"><?php echo $subject_to_edit && isset($subject_to_edit->description) ? esc_textarea($subject_to_edit->description) : ''; ?></textarea>
                            </div>

                            <p class="submit">
                                <input type="submit" class="button button-primary" value="<?php echo $subject_to_edit ? 'Update Subject' : 'Add New Subject'; ?>">
                                <?php if ($subject_to_edit) : ?>
                                    <a href="admin.php?page=qp-organization&tab=subjects" class="button button-secondary">Cancel Edit</a>
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
                                <th>Name</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($subjects)) : foreach ($subjects as $subject) : ?>
                                <tr>
                                    <td><?php echo esc_html($subject->subject_name); ?></td>
                                    <td><?php echo isset($subject->description) ? esc_html($subject->description) : ''; ?></td>
                                    <td>
                                        <?php
                                            $edit_nonce = wp_create_nonce('qp_edit_subject_' . $subject->subject_id);
                                            $edit_link = sprintf('<a href="?page=qp-organization&tab=subjects&action=edit&subject_id=%s&_wpnonce=%s">Edit</a>', $subject->subject_id, $edit_nonce);

                                            if (strtolower($subject->subject_name) !== 'uncategorized') {
                                                $delete_nonce = wp_create_nonce('qp_delete_subject_' . $subject->subject_id);
                                                $delete_link = sprintf('<a href="?page=qp-organization&tab=subjects&action=delete&subject_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $subject->subject_id, $delete_nonce);
                                                echo $edit_link . ' | ' . $delete_link;
                                            } else {
                                                echo $edit_link;
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr class="no-items"><td colspan="3">No subjects found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}