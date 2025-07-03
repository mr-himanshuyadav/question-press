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

        // --- Handle Add Label Form Submission ---
        if (isset($_POST['add_label']) && check_admin_referer('qp_add_label_nonce')) {
            $label_name = sanitize_text_field($_POST['label_name']);
            $label_color = sanitize_hex_color($_POST['label_color']);
            
            if (!empty($label_name) && !empty($label_color)) {
                $wpdb->insert($table_name, [
                    'label_name' => $label_name,
                    'label_color' => $label_color,
                    'is_default' => 0 // Custom labels are not default
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
        $labels = $wpdb->get_results("SELECT * FROM $table_name ORDER BY label_name ASC");
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Labels</h1>

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
                            <h2>Add New Label</h2>
                            <form method="post" action="admin.php?page=qp-labels">
                                <?php wp_nonce_field('qp_add_label_nonce'); ?>
                                
                                <div class="form-field form-required">
                                    <label for="label-name">Name</label>
                                    <input name="label_name" id="label-name" type="text" value="" size="40" aria-required="true" required>
                                    <p>The name is how it appears on your site.</p>
                                </div>

                                <div class="form-field">
                                    <label for="label-color">Color</label>
                                    <input name="label_color" id="label-color" type="text" value="#cccccc" class="qp-color-picker">
                                    <p>The color for the label for easy identification.</p>
                                </div>

                                <p class="submit">
                                    <input type="submit" name="add_label" id="submit" class="button button-primary" value="Add New Label">
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
                                                <?php if ($label->is_default == 0) : 
                                                    $delete_nonce = wp_create_nonce('qp_delete_label_' . $label->label_id);
                                                    $delete_link = sprintf(
                                                        '<a href="?page=%s&action=delete&label_id=%s&_wpnonce=%s" style="color:#a00;" onclick="return confirm(\'Are you sure you want to delete this label?\');">Delete</a>',
                                                        esc_attr($_REQUEST['page']),
                                                        absint($label->label_id),
                                                        $delete_nonce
                                                    );
                                                    echo $delete_link;
                                                else:
                                                    echo 'Default';
                                                endif; ?>
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