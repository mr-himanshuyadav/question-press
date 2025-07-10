<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Labels_Page {

    /**
     * Handles all logic and rendering for the Labels admin page.
     */
    public static function render() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_labels';
        $message = '';
        $message_type = ''; // 'updated' or 'error'
        $label_to_edit = null;

        // --- Check if we are in EDIT mode ---
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['label_id'])) {
            $label_id = absint($_GET['label_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_label_' . $label_id)) {
                $label_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE label_id = %d", $label_id));
            }
        }
        
        // --- Handle Update Label Form Submission ---
        if (isset($_POST['update_label']) && isset($_POST['label_id']) && check_admin_referer('qp_update_label_nonce')) {
            $label_id = absint($_POST['label_id']);
            $label_color = sanitize_hex_color($_POST['label_color']);
            $description = sanitize_textarea_field($_POST['label_description']);
            $label_name = sanitize_text_field($_POST['label_name']);
            
            $current_label = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE label_id = %d", $label_id));

            $update_data = [
                'label_color' => $label_color,
                'description' => $description
            ];
            
            // Only update the name if it's not a default label
            if ($current_label && $current_label->is_default == 0) {
                $update_data['label_name'] = $label_name;
            }

            $wpdb->update($table_name, $update_data, ['label_id' => $label_id]);
            $message = 'Label updated successfully.';
            $message_type = 'updated';
            $label_to_edit = null; // Clear edit mode after update
        }

        // --- Handle Add Label Form Submission ---
        if (isset($_POST['add_label']) && check_admin_referer('qp_add_label_nonce')) {
            $label_name = sanitize_text_field($_POST['label_name']);
            $label_color = sanitize_hex_color($_POST['label_color']);
            $description = sanitize_textarea_field($_POST['label_description']);
            
            if (!empty($label_name) && !empty($label_color)) {
                $wpdb->insert($table_name, [
                    'label_name' => $label_name,
                    'label_color' => $label_color,
                    'description' => $description,
                    'is_default' => 0
                ]);
                $message = 'Label added successfully.';
                $message_type = 'updated';
            } else {
                $message = 'Label name and color cannot be empty.';
                $message_type = 'error';
            }
        }
        
        // --- Handle Delete Label Action ---
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['label_id'])) {
            $label_id = absint($_GET['label_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_delete_label_' . $label_id)) {
                $label_to_delete = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE label_id = %d", $label_id));
                if ($label_to_delete && $label_to_delete->is_default == 0) {
                    $wpdb->delete($table_name, ['label_id' => $label_id]);
                    $message = 'Label deleted successfully.';
                    $message_type = 'updated';
                } else {
                    $message = 'Default labels cannot be deleted.';
                    $message_type = 'error';
                }
            }
        }

        // Get all labels from the database
        $labels = $wpdb->get_results("SELECT * FROM $table_name ORDER BY is_default DESC, label_name ASC");
        ?>
                

            <div id="col-container" class="wp-clearfix">
                <div id="col-left">
                    <div class="col-wrap">
                        <div class="form-wrap">
                            <h2><?php echo $label_to_edit ? 'Edit Label' : 'Add New Label'; ?></h2>
                            
                            <form method="post" action="admin.php?page=qp-labels">
                                <?php if ($label_to_edit) : ?>
                                    <?php wp_nonce_field('qp_update_label_nonce'); ?>
                                    <input type="hidden" name="label_id" value="<?php echo esc_attr($label_to_edit->label_id); ?>">
                                <?php else : ?>
                                    <?php wp_nonce_field('qp_add_label_nonce'); ?>
                                <?php endif; ?>
                                
                                <div class="form-field form-required">
                                    <label for="label-name">Name</label>
                                    <input name="label_name" id="label-name" type="text" value="<?php echo $label_to_edit ? esc_attr($label_to_edit->label_name) : ''; ?>" size="40" aria-required="true" required <?php echo ($label_to_edit && $label_to_edit->is_default) ? 'readonly' : ''; ?>>
                                    <p>The name is how it appears on your site. Default label names cannot be changed.</p>
                                </div>

                                <div class="form-field">
                                    <label for="label-description">Description</label>
                                    <textarea name="label_description" id="label-description" rows="3" cols="40"><?php echo $label_to_edit ? esc_textarea($label_to_edit->description) : ''; ?></textarea>
                                    <p>The description is not prominent by default; it is primarily for administrative use.</p>
                                </div>

                                <div class="form-field">
                                    <label for="label-color">Color</label>
                                    <input name="label_color" id="label-color" type="text" value="<?php echo $label_to_edit ? esc_attr($label_to_edit->label_color) : '#cccccc'; ?>" class="qp-color-picker">
                                    <p>The color for the label for easy identification.</p>
                                </div>

                                <p class="submit">
                                    <?php if ($label_to_edit) : ?>
                                        <input type="submit" name="update_label" id="submit" class="button button-primary" value="Update Label">
                                        <a href="admin.php?page=qp-labels" class="button button-secondary">Cancel Edit</a>
                                    <?php else : ?>
                                        <input type="submit" name="add_label" id="submit" class="button button-primary" value="Add New Label">
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
                                <?php if (!empty($labels)) : ?>
                                    <?php foreach ($labels as $label) : ?>
                                        <tr>
                                            <td>
                                                <span style="padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr($label->label_color); ?>;">
                                                    <?php echo esc_html($label->label_name); ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html($label->description); ?></td>
                                            <td>
                                                <?php
                                                    $edit_nonce = wp_create_nonce('qp_edit_label_' . $label->label_id);
                                                    $edit_link = sprintf('<a href="?page=%s&action=edit&label_id=%s&_wpnonce=%s">Edit</a>', esc_attr($_REQUEST['page']), absint($label->label_id), $edit_nonce);
                                                    
                                                    if ($label->is_default == 0) {
                                                        $delete_nonce = wp_create_nonce('qp_delete_label_' . $label->label_id);
                                                        $delete_link = sprintf(
                                                            '<a href="?page=%s&action=delete&label_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this label?\');">Delete</a>',
                                                            esc_attr($_REQUEST['page']),
                                                            absint($label->label_id),
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
                                        <td class="colspanchange" colspan="3">No labels found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div></div></div>
        <?php
    }
}