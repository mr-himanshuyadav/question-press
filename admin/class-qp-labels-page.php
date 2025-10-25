<?php
if (!defined('ABSPATH')) exit;

use QuestionPress\Database\Terms_DB;

class QP_Labels_Page
{
    /**
     * Handles form submissions for the Labels tab using the new taxonomy system.
     */
    public static function handle_forms()
    {
        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'labels') {
            return;
        }
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $meta_table = $wpdb->prefix . 'qp_term_meta';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");
        if (!$label_tax_id) return;

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_label' || $_POST['action'] === 'update_label') && check_admin_referer('qp_add_edit_label_nonce')) {
            $label_name = sanitize_text_field($_POST['label_name']);
            $label_color = sanitize_hex_color($_POST['label_color']);
            $description = sanitize_textarea_field($_POST['label_description']);
            
            if (empty($label_name) || empty($label_color)) {
                QP_Sources_Page::set_message('Label name and color are required.', 'error');
            } else {
                $term_data = [
                    'taxonomy_id' => $label_tax_id,
                    'name' => $label_name,
                    'slug' => sanitize_title($label_name)
                ];
                $term_id = 0;

                if ($_POST['action'] === 'update_label') {
                    $term_id = absint($_POST['term_id']);
                    // **THE FIX**: Check the default status from the DB, not the form.
                    $is_default = Terms_DB::get_meta($term_id, 'is_default', true);

                    if (!$is_default) {
                        $wpdb->update($term_table, $term_data, ['term_id' => $term_id]);
                    }
                    QP_Sources_Page::set_message('Label updated.', 'updated');
                } else {
                    $wpdb->insert($term_table, $term_data);
                    $term_id = $wpdb->insert_id;
                    QP_Sources_Page::set_message('Label added.', 'updated');
                }

                if ($term_id > 0) {
                    Terms_DB::update_meta($term_id, 'color', $label_color);
                    Terms_DB::update_meta($term_id, 'description', $description);
                }
            }
            QP_Sources_Page::redirect_to_tab('labels');
        }

        // Delete Handler (Code remains the same, it was already correct)
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['term_id']) && check_admin_referer('qp_delete_label_' . absint($_GET['term_id']))) {
            $term_id = absint($_GET['term_id']);
            $is_default = Terms_DB::get_meta($term_id, 'is_default', true);

            if ($is_default) {
                QP_Sources_Page::set_message('Default labels cannot be deleted.', 'error');
            } else {
                $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel_table WHERE term_id = %d AND object_type = 'question'", $term_id));
                if ($usage_count > 0) {
                    $formatted_count = "<strong><span style='color:red;'>{$usage_count} question(s).</span></strong>";
                    QP_Sources_Page::set_message("This label cannot be deleted because it is in use by {$formatted_count}", 'error');
                } else {
                    $wpdb->delete($term_table, ['term_id' => $term_id]);
                    $wpdb->delete($meta_table, ['term_id' => $term_id]);
                    QP_Sources_Page::set_message('Label deleted successfully.', 'updated');
                }
            }
            QP_Sources_Page::redirect_to_tab('labels');
        }
    }

    /**
     * Renders the HTML for the Labels tab using the new taxonomy system.
     */
    public static function render()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $meta_table = $wpdb->prefix . 'qp_term_meta';
        
        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");
        
        $label_to_edit = null;
        $edit_meta = ['color' => '#cccccc', 'description' => '', 'is_default' => 0];

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['term_id'])) {
            $term_id = absint($_GET['term_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_label_' . $term_id)) {
                $label_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $term_table WHERE term_id = %d AND taxonomy_id = %d", $term_id, $label_tax_id));
                if ($label_to_edit) {
                    $edit_meta['color'] = Terms_DB::get_meta($term_id, 'color', true) ?: '#cccccc';
                    $edit_meta['description'] = Terms_DB::get_meta($term_id, 'description', true);
                    $edit_meta['is_default'] = Terms_DB::get_meta($term_id, 'is_default', true);
                }
            }
        }

        $labels_query = $wpdb->prepare("
            SELECT t.term_id, t.name, 
                   MAX(CASE WHEN m.meta_key = 'color' THEN m.meta_value END) as color,
                   MAX(CASE WHEN m.meta_key = 'description' THEN m.meta_value END) as description,
                   MAX(CASE WHEN m.meta_key = 'is_default' THEN m.meta_value END) as is_default
            FROM $term_table t
            LEFT JOIN $meta_table m ON t.term_id = m.term_id
            WHERE t.taxonomy_id = %d
            GROUP BY t.term_id
            ORDER BY is_default DESC, name ASC", 
            $label_tax_id
        );
        
        $labels = $wpdb->get_results($labels_query);

        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }
?>
        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php echo $label_to_edit ? 'Edit Label' : 'Add New Label'; ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=labels">
                            <?php wp_nonce_field('qp_add_edit_label_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $label_to_edit ? 'update_label' : 'add_label'; ?>">
                            <?php if ($label_to_edit) : ?>
                                <input type="hidden" name="term_id" value="<?php echo esc_attr($label_to_edit->term_id); ?>">
                            <?php endif; ?>

                            <div class="form-field form-required">
                                <label for="label-name">Name</label>
                                <input name="label_name" id="label-name" type="text" value="<?php echo $label_to_edit ? esc_attr($label_to_edit->name) : ''; ?>" size="40" required <?php echo ($label_to_edit && $edit_meta['is_default']) ? 'readonly' : ''; ?>>
                                <?php if ($label_to_edit && $edit_meta['is_default']): ?>
                                    <p>Default label names cannot be changed.</p>
                                <?php endif; ?>
                            </div>

                            <div class="form-field">
                                <label for="label-description">Description</label>
                                <textarea name="label_description" id="label-description" rows="3" cols="40"><?php echo esc_textarea($edit_meta['description']); ?></textarea>
                            </div>

                            <div class="form-field">
                                <label for="label-color">Color</label>
                                <input name="label_color" id="label-color" type="text" value="<?php echo esc_attr($edit_meta['color']); ?>" class="qp-color-picker">
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
                                        <td><span style="padding: 2px 8px; border-radius: 3px; color: #fff; background-color: <?php echo esc_attr($label->color); ?>;"><?php echo esc_html($label->name); ?></span></td>
                                        <td><?php echo esc_html($label->description); ?></td>
                                        <td>
                                            <?php
                                            $edit_nonce = wp_create_nonce('qp_edit_label_' . $label->term_id);
                                            $edit_link = sprintf('<a href="?page=qp-organization&tab=labels&action=edit&term_id=%s&_wpnonce=%s">Edit</a>', $label->term_id, $edit_nonce);

                                            if (!$label->is_default) {
                                                $delete_nonce = wp_create_nonce('qp_delete_label_' . $label->term_id);
                                                $delete_link = sprintf(
                                                    '<a href="?page=qp-organization&tab=labels&action=delete&term_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>',
                                                    $label->term_id,
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