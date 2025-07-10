<?php
if (!defined('ABSPATH')) exit;

class QP_Labels_Page
{

    public static function handle_forms()
    {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'labels') {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_labels';

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_label' || $_POST['action'] === 'update_label') && check_admin_referer('qp_add_edit_label_nonce')) {
            $label_name = sanitize_text_field($_POST['label_name']);
            $label_color = sanitize_hex_color($_POST['label_color']);
            $description = sanitize_textarea_field($_POST['label_description']);

            if (empty($label_name) || empty($label_color)) {
                QP_Sources_Page::set_message('Label name and color are required.', 'error');
            } else {
                $data = ['label_name' => $label_name, 'label_color' => $label_color, 'description' => $description];
                if ($_POST['action'] === 'update_label') {
                    $label_id = absint($_POST['label_id']);
                    if ($wpdb->get_var($wpdb->prepare("SELECT is_default FROM $table_name WHERE label_id = %d", $label_id))) {
                        unset($data['label_name']); // Don't allow changing default label names
                    }
                    $wpdb->update($table_name, $data, ['label_id' => $label_id]);
                    QP_Sources_Page::set_message('Label updated.', 'updated');
                } else {
                    $data['is_default'] = 0;
                    $wpdb->insert($table_name, $data);
                    QP_Sources_Page::set_message('Label added.', 'updated');
                }
            }
            QP_Sources_Page::redirect_to_tab('labels');
        }

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['label_id']) && check_admin_referer('qp_delete_label_' . absint($_GET['label_id']))) {
            $label_id = absint($_GET['label_id']);
            if ($wpdb->get_var($wpdb->prepare("SELECT is_default FROM $table_name WHERE label_id = %d", $label_id))) {
                QP_Sources_Page::set_message('Default labels cannot be deleted.', 'error');
            } else {
                $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}qp_question_labels WHERE label_id = %d", $label_id));
                if ($usage_count > 0) {
                    QP_Sources_Page::set_message("This label cannot be deleted because it is in use by {$usage_count} question(s).", 'error');
                } else {
                    $wpdb->delete($table_name, ['label_id' => $label_id]);
                    QP_Sources_Page::set_message('Label deleted successfully.', 'updated');
                }
            }
            QP_Sources_Page::redirect_to_tab('labels');
        }
    }

    public static function render()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'qp_labels';
        $label_to_edit = null;

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['label_id'])) {
            $label_id = absint($_GET['label_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_label_' . $label_id)) {
                $label_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE label_id = %d", $label_id));
            }
        }

        $labels = $wpdb->get_results("SELECT * FROM $table_name ORDER BY is_default DESC, label_name ASC");

        if (isset($_SESSION['qp_admin_message'])) {
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . esc_html($_SESSION['qp_admin_message']) . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php wp_nonce_field('qp_add_edit_label_nonce'); ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=labels">
                            <?php wp_nonce_field('qp_add_edit_label_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $label_to_edit ? 'update_label' : 'add_label'; ?>">
                            <?php if ($label_to_edit) : ?>
                                <input type="hidden" name="label_id" value="<?php echo esc_attr($label_to_edit->label_id); ?>">
                            <?php endif; ?>

                            <div class="form-field form-required">
                                <label for="label-name">Name</label>
                                <input name="label_name" id="label-name" type="text" value="<?php echo $label_to_edit ? esc_attr($label_to_edit->label_name) : ''; ?>" size="40" required <?php echo ($label_to_edit && $label_to_edit->is_default) ? 'readonly' : ''; ?>>
                                <?php if ($label_to_edit && $label_to_edit->is_default): ?>
                                    <p>Default label names cannot be changed.</p>
                                <?php endif; ?>
                            </div>

                            <div class="form-field">
                                <label for="label-description">Description</label>
                                <textarea name="label_description" id="label-description" rows="3" cols="40"><?php echo $label_to_edit ? esc_textarea($label_to_edit->description) : ''; ?></textarea>
                            </div>

                            <div class="form-field">
                                <label for="label-color">Color</label>
                                <input name="label_color" id="label-color" type="text" value="<?php echo $label_to_edit ? esc_attr($label_to_edit->label_color) : '#cccccc'; ?>" class="qp-color-picker">
                            </div>

                            <p class="submit">
                                <input type="submit" class="button button-primary" value="<?php echo $label_to_edit ? 'Update Label' : 'Add New Label'; ?>">
                                <?php if ($label_to_edit) : ?>
                                    <a href="admin.php?page=qp-organization&tab=labels" class="button button-secondary">Cancel Edit</a>
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
                            <?php if (!empty($labels)) : foreach ($labels as $label) : ?>
                                    <tr>
                                        <td><span style="padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr($label->label_color); ?>;"><?php echo esc_html($label->label_name); ?></span></td>
                                        <td><?php echo esc_html($label->description); ?></td>
                                        <td>
                                            <?php
                                            $edit_nonce = wp_create_nonce('qp_edit_label_' . $label->label_id);
                                            $edit_link = sprintf('<a href="?page=qp-organization&tab=labels&action=edit&label_id=%s&_wpnonce=%s">Edit</a>', $label->label_id, $edit_nonce);

                                            if ($label->is_default == 0) {
                                                $delete_nonce = wp_create_nonce('qp_delete_label_' . $label->label_id);
                                                $delete_link = sprintf(
                                                    '<a href="?page=qp-organization&tab=labels&action=delete&label_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>',
                                                    $label->label_id,
                                                    $delete_nonce
                                                );
                                                echo $edit_link . ' | ' . $delete_link;
                                            } else {
                                                echo $edit_link;
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else : ?>
                                <tr class="no-items">
                                    <td colspan="3">No labels found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
<?php
    }
}
