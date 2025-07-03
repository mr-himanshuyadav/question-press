<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Subjects_Page {

    /**
     * Handles all logic and rendering for the Subjects admin page.
     */
    public static function render() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_subjects';
        $message = '';
        $message_type = ''; // 'updated' or 'error'
        $subject_to_edit = null;

        // --- Check if we are in EDIT mode ---
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['subject_id'])) {
            $subject_id = absint($_GET['subject_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_subject_' . $subject_id)) {
                $subject_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE subject_id = %d", $subject_id));
            }
        }
        
        // --- Handle Update Subject Form Submission ---
        if (isset($_POST['update_subject']) && isset($_POST['subject_id']) && check_admin_referer('qp_update_subject_nonce')) {
            $subject_id = absint($_POST['subject_id']);
            $description = sanitize_textarea_field($_POST['subject_description']);
            $subject_name = sanitize_text_field($_POST['subject_name']);
            
            $current_subject = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE subject_id = %d", $subject_id));

            $update_data = ['description' => $description];
            
            if ($current_subject && strtolower($current_subject->subject_name) !== 'uncategorized') {
                $update_data['subject_name'] = $subject_name;
            }

            $result = $wpdb->update($table_name, $update_data, ['subject_id' => $subject_id]);

            if ($result !== false) {
                 $message = 'Subject updated successfully.';
                 $message_type = 'updated';
            } else {
                $message = 'An error occurred while updating the subject.';
                $message_type = 'error';
            }
            $subject_to_edit = null; // Clear edit mode
        }

        // --- Handle Add Subject Form Submission ---
        if (isset($_POST['add_subject']) && check_admin_referer('qp_add_subject_nonce')) {
            $subject_name = sanitize_text_field($_POST['subject_name']);
            $description = sanitize_textarea_field($_POST['subject_description']);
            if (!empty($subject_name)) {
                $result = $wpdb->insert($table_name, [
                    'subject_name' => $subject_name,
                    'description' => $description
                ]);
                if ($result) {
                    $message = 'Subject added successfully.';
                    $message_type = 'updated';
                } else {
                    $message = 'An error occurred while adding the subject.';
                    $message_type = 'error';
                }
            } else {
                $message = 'Subject name cannot be empty.';
                $message_type = 'error';
            }
        }
        
        // --- Handle Delete Subject Action ---
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['subject_id'])) {
            $subject_id = absint($_GET['subject_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_delete_subject_' . $subject_id)) {
                $subject_to_delete = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE subject_id = %d", $subject_id));
                if ($subject_to_delete && strtolower($subject_to_delete->subject_name) !== 'uncategorized') {
                    $wpdb->delete($table_name, ['subject_id' => $subject_id]);
                    $message = 'Subject deleted successfully.';
                    $message_type = 'updated';
                } else {
                    $message = 'The "Uncategorized" subject cannot be deleted.';
                    $message_type = 'error';
                }
            }
        }

        // Get all subjects from the database
        $subjects = $wpdb->get_results("SELECT * FROM $table_name ORDER BY subject_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Subjects</h1>

            <?php if (!empty($message)) : ?>
                <div id="message" class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <hr class="wp-header-end">

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $subject_to_edit ? 'Edit Subject' : 'Add New Subject'; ?></h2>
                            
                            <form method="post" action="admin.php?page=qp-subjects">
                                <?php if ($subject_to_edit) : ?>
                                    <?php wp_nonce_field('qp_update_subject_nonce'); ?>
                                    <input type="hidden" name="subject_id" value="<?php echo esc_attr($subject_to_edit->subject_id); ?>">
                                <?php else : ?>
                                    <?php wp_nonce_field('qp_add_subject_nonce'); ?>
                                <?php endif; ?>
                                
                                <div class="form-field form-required">
                                    <label for="subject-name">Name</label>
                                    <input name="subject_name" id="subject-name" type="text" value="<?php echo $subject_to_edit ? esc_attr($subject_to_edit->subject_name) : ''; ?>" size="40" aria-required="true" required <?php echo ($subject_to_edit && strtolower($subject_to_edit->subject_name) === 'uncategorized') ? 'readonly' : ''; ?>>
                                    <p>The name is how it appears on your site. The "Uncategorized" name cannot be changed.</p>
                                </div>

                                <div class="form-field">
                                    <label for="subject-description">Description</label>
                                    <textarea name="subject_description" id="subject-description" rows="3" cols="40"><?php echo $subject_to_edit && isset($subject_to_edit->description) ? esc_textarea($subject_to_edit->description) : ''; ?></textarea>
                                    <p>The description is not prominent by default; it is primarily for administrative use.</p>
                                </div>

                                <p class="submit">
                                    <?php if ($subject_to_edit) : ?>
                                        <input type="submit" name="update_subject" id="submit" class="button button-primary" value="Update Subject">
                                        <a href="admin.php?page=qp-subjects" class="button button-secondary">Cancel Edit</a>
                                    <?php else : ?>
                                        <input type="submit" name="add_subject" id="submit" class="button button-primary" value="Add New Subject">
                                    <?php endif; ?>
                                </p>
                            </form>
                        </div>
                    </div>
                </div><div id="col-right">
                    <div class="col-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col" class="manage-column">Name</th>
                                    <th scope="col" class="manage-column">Description</th>
                                    <th scope="col" class="manage-column">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="the-list">
                                <?php if (!empty($subjects)) : ?>
                                    <?php foreach ($subjects as $subject) : ?>
                                        <tr>
                                            <td><?php echo esc_html($subject->subject_name); ?></td>
                                            <td><?php echo isset($subject->description) ? esc_html($subject->description) : ''; ?></td>
                                            <td>
                                                <?php
                                                    $edit_nonce = wp_create_nonce('qp_edit_subject_' . $subject->subject_id);
                                                    $edit_link = sprintf('<a href="?page=%s&action=edit&subject_id=%s&_wpnonce=%s">Edit</a>', esc_attr($_REQUEST['page']), absint($subject->subject_id), $edit_nonce);

                                                    if (strtolower($subject->subject_name) !== 'uncategorized') {
                                                        $delete_nonce = wp_create_nonce('qp_delete_subject_' . $subject->subject_id);
                                                        $delete_link = sprintf(
                                                            '<a href="?page=%s&action=delete&subject_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this subject?\');">Delete</a>',
                                                            esc_attr($_REQUEST['page']),
                                                            absint($subject->subject_id),
                                                            $delete_nonce
                                                        );
                                                        echo $edit_link . ' | ' . $delete_link;
                                                    } else {
                                                        echo $edit_link . ' (Default)';
                                                    }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="no-items">
                                        <td class="colspanchange" colspan="3">No subjects found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div></div></div>
        <?php
    }
}