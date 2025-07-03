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

        // --- Handle Add Subject Form Submission ---
        if (isset($_POST['add_subject']) && check_admin_referer('qp_add_subject_nonce')) {
            $subject_name = sanitize_text_field($_POST['subject_name']);
            if (!empty($subject_name)) {
                $wpdb->insert($table_name, ['subject_name' => $subject_name]);
                $message = 'Subject added successfully.';
                $message_type = 'updated';
            } else {
                $message = 'Subject name cannot be empty.';
                $message_type = 'error';
            }
        }
        
        // --- Handle Delete Subject Action ---
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['subject_id'])) {
            $subject_id = absint($_GET['subject_id']);
            // Verify nonce for security
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_delete_subject_' . $subject_id)) {
                // We should not delete the "Uncategorized" subject
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

        // Get all subjects from the database to display in the table
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
                            <h2>Add New Subject</h2>
                            <form method="post" action="admin.php?page=qp-subjects">
                                <?php wp_nonce_field('qp_add_subject_nonce'); ?>
                                
                                <div class="form-field form-required">
                                    <label for="subject-name">Name</label>
                                    <input name="subject_name" id="subject-name" type="text" value="" size="40" aria-required="true" required>
                                    <p>The name is how it appears on your site.</p>
                                </div>

                                <p class="submit">
                                    <input type="submit" name="add_subject" id="submit" class="button button-primary" value="Add New Subject">
                                </p>
                            </form>
                        </div>
                    </div>
                </div><div id="col-right">
                    <div class.col-wrap">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th scope="col" class="manage-column">Name</th>
                                    <th scope="col" class="manage-column">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="the-list">
                                <?php if (!empty($subjects)) : ?>
                                    <?php foreach ($subjects as $subject) : ?>
                                        <tr>
                                            <td><?php echo esc_html($subject->subject_name); ?></td>
                                            <td>
                                                <?php if (strtolower($subject->subject_name) !== 'uncategorized') : 
                                                    // Create a security nonce for the delete link
                                                    $delete_nonce = wp_create_nonce('qp_delete_subject_' . $subject->subject_id);
                                                    $delete_link = sprintf(
                                                        '<a href="?page=%s&action=delete&subject_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this subject?\');">Delete</a>',
                                                        esc_attr($_REQUEST['page']),
                                                        absint($subject->subject_id),
                                                        $delete_nonce
                                                    );
                                                    echo $delete_link;
                                                else:
                                                    echo 'Cannot be deleted';
                                                endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr class="no-items">
                                        <td class="colspanchange" colspan="2">No subjects found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div></div></div>
        <?php
    }
}