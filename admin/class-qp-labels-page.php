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
                self::set_message('Label name and color are required.', 'error');
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
                    self::set_message('Label updated.', 'updated');
                } else {
                    $wpdb->insert($term_table, $term_data);
                    $term_id = $wpdb->insert_id;
                    self::set_message('Label added.', 'updated');
                }

                if ($term_id > 0) {
                    Terms_DB::update_meta($term_id, 'color', $label_color);
                    Terms_DB::update_meta($term_id, 'description', $description);
                }
            }
            self::redirect_to_tab('labels');
        }

        // Delete Handler (Code remains the same, it was already correct)
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['term_id']) && check_admin_referer('qp_delete_label_' . absint($_GET['term_id']))) {
            $term_id = absint($_GET['term_id']);
            $is_default = Terms_DB::get_meta($term_id, 'is_default', true);

            if ($is_default) {
                self::set_message('Default labels cannot be deleted.', 'error');
            } else {
                $usage_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel_table WHERE term_id = %d AND object_type = 'question'", $term_id));
                if ($usage_count > 0) {
                    $formatted_count = "<strong><span style='color:red;'>{$usage_count} question(s).</span></strong>";
                    self::set_message("This label cannot be deleted because it is in use by {$formatted_count}", 'error');
                } else {
                    $wpdb->delete($term_table, ['term_id' => $term_id]);
                    $wpdb->delete($meta_table, ['term_id' => $term_id]);
                    self::set_message('Label deleted successfully.', 'updated');
                }
            }
            self::redirect_to_tab('labels');
        }
    }

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

        // Display session messages before loading the template
        if (isset($_SESSION['qp_admin_message'])) {
            $message = html_entity_decode($_SESSION['qp_admin_message']);
            echo '<div id="message" class="notice notice-' . esc_attr($_SESSION['qp_admin_message_type']) . ' is-dismissible"><p>' . $message . '</p></div>';
            unset($_SESSION['qp_admin_message'], $_SESSION['qp_admin_message_type']);
        }

        // Prepare arguments for the template
        $args = [
            'label_to_edit' => $label_to_edit,
            'edit_meta'     => $edit_meta,
            'labels'        => $labels,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'organization-tab-labels', 'admin', $args );
    }

    // ADD THESE TWO HELPER FUNCTIONS
    public static function set_message($message, $type) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['qp_admin_message'] = $message;
            $_SESSION['qp_admin_message_type'] = $type;
        }
    }

    public static function redirect_to_tab($tab) {
        wp_safe_redirect(admin_url('admin.php?page=qp-organization&tab=' . $tab));
        exit;
    }
}