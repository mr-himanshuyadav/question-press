<?php
if (!defined('ABSPATH')) exit;

// This class was already included in the Subjects page, but it's good practice to ensure it's here.
require_once QP_PLUGIN_PATH . 'admin/class-qp-terms-list-table.php';

class QP_Sources_Page {

    public static function handle_forms() {
        // Instantiate and process bulk actions immediately if on the correct tab.
        if (isset($_GET['page']) && $_GET['page'] === 'qp-organization' && isset($_GET['tab']) && $_GET['tab'] === 'sources') {
            $list_table = new QP_Terms_List_Table('source', 'Source/Section', 'sources');
            $list_table->process_bulk_action();
        }

        if ((!isset($_POST['action']) && !isset($_GET['action'])) || !isset($_GET['tab']) || $_GET['tab'] !== 'sources') {
            return;
        }
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");
        if (!$source_tax_id) return;

        // Add/Update Handler
        if (isset($_POST['action']) && ($_POST['action'] === 'add_term' || $_POST['action'] === 'update_term') && check_admin_referer('qp_add_edit_source_nonce')) {
            $term_name = sanitize_text_field($_POST['term_name']);
            $description = sanitize_textarea_field($_POST['term_description']);
            $parent = absint($_POST['parent']);

            if (empty($term_name)) {
                self::set_message('Name cannot be empty.', 'error');
            } else {
                $data = ['name' => $term_name, 'slug' => sanitize_title($term_name), 'parent' => $parent, 'taxonomy_id' => $source_tax_id];
                $term_id = 0;

                if ($_POST['action'] === 'update_term') {
                    $term_id = absint($_POST['term_id']);
                    $wpdb->update($term_table, $data, ['term_id' => $term_id]);
                    self::set_message('Source/Section updated.', 'updated');
                } else {
                    $wpdb->insert($term_table, $data);
                    $term_id = $wpdb->insert_id;
                    self::set_message('Source/Section added.', 'updated');
                }
                if ($term_id > 0) {
                    qp_update_term_meta($term_id, 'description', $description);

                    if ($parent == 0) {
                        $linked_subject_ids = isset($_POST['linked_subjects']) ? array_map('absint', $_POST['linked_subjects']) : [];
                        
                        // First, delete all existing subject links for this source to prevent duplicates
                        $wpdb->delete($rel_table, ['object_id' => $term_id, 'object_type' => 'source_subject_link']);

                        // Then, insert the new links
                        if (!empty($linked_subject_ids)) {
                            foreach ($linked_subject_ids as $subject_term_id) {
                                $wpdb->insert($rel_table, [
                                    'object_id'   => $term_id, 
                                    'term_id'     => $subject_term_id, 
                                    'object_type' => 'source_subject_link'
                                ]);
                            }
                        }
                    }
                }
            }
            self::redirect_to_tab('sources');
        }

        // Merge Handler
        if (isset($_POST['action']) && $_POST['action'] === 'perform_merge' && check_admin_referer('qp_perform_merge_nonce')) {
            $destination_term_id = absint($_POST['destination_term_id']);
            $source_term_ids = array_map('absint', $_POST['source_term_ids']);
            $final_name = sanitize_text_field($_POST['term_name']);
            $final_parent = absint($_POST['parent']);
            $final_description = sanitize_textarea_field($_POST['term_description']);

            // Remove the destination from the list of sources to avoid deleting it
            $source_term_ids = array_diff($source_term_ids, [$destination_term_id]);
            $ids_placeholder = implode(',', $source_term_ids);

            // Re-assign relationships from source terms to the destination term
            $wpdb->query($wpdb->prepare(
                "UPDATE $rel_table SET term_id = %d WHERE term_id IN ($ids_placeholder)",
                $destination_term_id
            ));
            
            // Update the destination term with the new details
            $wpdb->update($term_table, 
                ['name' => $final_name, 'slug' => sanitize_title($final_name), 'parent' => $final_parent], 
                ['term_id' => $destination_term_id]
            );
            qp_update_term_meta($destination_term_id, 'description', $final_description);

            // Delete the now-empty source terms and their meta
            $wpdb->query("DELETE FROM $term_table WHERE term_id IN ($ids_placeholder)");
            $wpdb->query("DELETE FROM $meta_table WHERE term_id IN ($ids_placeholder)");
            
            self::set_message(count($source_term_ids) . ' item(s) were successfully merged into "' . esc_html($final_name) . '".', 'updated');
            self::redirect_to_tab('sources');
        }

        // Delete Handler
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['term_id']) && check_admin_referer('qp_delete_source_' . absint($_GET['term_id']))) {
            $term_id = absint($_GET['term_id']);
            $error_messages = [];

            // Check 1: Prevent deletion if it has child terms (sections/sub-sections)
            $child_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $term_table WHERE parent = %d", $term_id));
            if ($child_count > 0) {
                $formatted_count = "<strong><span style='color:red;'>{$child_count} sub-section(s)</span></strong>";
                $error_messages[] = "{$formatted_count} are associated with it.";
            }

                        // Check 2: Prevent deletion if it or any of its children are linked to questions
            $descendant_ids = [$term_id];
            $current_parent_ids = [$term_id];
            for ($i = 0; $i < 5; $i++) { // Safety break after 5 levels
                if (empty($current_parent_ids)) break;
                $ids_placeholder = implode(',', $current_parent_ids);
                $child_ids = $wpdb->get_col("SELECT term_id FROM $term_table WHERE parent IN ($ids_placeholder)");
                if (!empty($child_ids)) {
                    $descendant_ids = array_merge($descendant_ids, $child_ids);
                    $current_parent_ids = $child_ids;
                } else {
                    break;
                }
            }
            $descendant_ids_placeholder = implode(',', array_unique($descendant_ids));
            $usage_count = $wpdb->get_var("SELECT COUNT(DISTINCT object_id) FROM $rel_table WHERE object_type = 'question' AND term_id IN ($descendant_ids_placeholder)");

            if ($usage_count > 0) {
                $formatted_count = "<strong><span style='color:red;'>{$usage_count} question(s)</span></strong>";
                $error_messages[] = "{$formatted_count} are linked to it (or its sections).";
            }

            if (!empty($error_messages)) {
                $message = "This item cannot be deleted for the following reasons:<br>" . implode('<br>', $error_messages);
                self::set_message($message, 'error');
            } else {
                $wpdb->delete($term_table, ['term_id' => $term_id]);
                $wpdb->delete($meta_table, ['term_id' => $term_id]);
                self::set_message('Source/Section deleted successfully.', 'updated');
            }
            self::redirect_to_tab('sources');
        }
    }

    public static function render() {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");
        
        $term_to_edit = null;
        $edit_description = '';

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['term_id'])) {
            $term_id = absint($_GET['term_id']);
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qp_edit_source_' . $term_id)) {
                $term_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $term_table WHERE term_id = %d", $term_id));
                if ($term_to_edit) {
                    $edit_description = qp_get_term_meta($term_id, 'description', true);
                }
            }
        }
        
        $all_source_terms = $wpdb->get_results($wpdb->prepare("SELECT term_id, name, parent FROM $term_table WHERE taxonomy_id = %d ORDER BY name ASC", $source_tax_id));
        
        $list_table = new QP_Terms_List_Table('source', 'Source/Section', 'sources');
        $list_table->prepare_items();

        // --- NEW: Fetch data needed for subject linking ---
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
        $all_subjects = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND name != 'Uncategorized' ORDER BY name ASC",
            $subject_tax_id
        ));

        $linked_subject_ids = [];
        if ($term_to_edit) {
            $linked_subject_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->prefix}qp_term_relationships WHERE object_id = %d AND object_type = 'source_subject_link'",
                $term_to_edit->term_id
            ));
        }
        // --- END NEW ---
        
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
                        <h2><?php echo $term_to_edit ? 'Edit Source/Section' : 'Add New Source/Section'; ?></h2>
                        <form method="post" action="admin.php?page=qp-organization&tab=sources">
                            <?php wp_nonce_field('qp_add_edit_source_nonce'); ?>
                            <input type="hidden" name="action" value="<?php echo $term_to_edit ? 'update_term' : 'add_term'; ?>">
                            <?php if ($term_to_edit) : ?>
                                <input type="hidden" name="term_id" value="<?php echo esc_attr($term_to_edit->term_id); ?>">
                            <?php endif; ?>
                            
                            <div class="form-field form-required">
                                <label for="term-name">Name</label>
                                <input name="term_name" id="term-name" type="text" value="<?php echo $term_to_edit ? esc_attr($term_to_edit->name) : ''; ?>" size="40" required>
                                <p>The name of the source (e.g., a book title) or a section within it (e.g., Chapter 5).</p>
                            </div>

                            <div class="form-field">
                                <label for="parent-source">Parent Item</label>
                                <select name="parent" id="parent-source">
                                    <option value="0">— None (Top-Level Source) —</option>
                                    <?php
                                        // A simple function to recursively display options
                                        function qp_source_dropdown_options($terms, $parent_id = 0, $level = 0, $selected = 0) {
                                            // Use a more visually distinct prefix for indentation
                                            $prefix = str_repeat('— ', $level);
                                            foreach ($terms as $term) {
                                                if ($term->parent == $parent_id) {
                                                    printf(
                                                        '<option value="%s" %s>%s%s</option>',
                                                        esc_attr($term->term_id),
                                                        selected($selected, $term->term_id, false),
                                                        $prefix, // Use the new prefix here
                                                        esc_html($term->name)
                                                    );
                                                    // The recursive call remains the same
                                                    qp_source_dropdown_options($terms, $term->term_id, $level + 1, $selected);
                                                }
                                            }
                                        }
                                        qp_source_dropdown_options($all_source_terms, 0, 0, $term_to_edit ? $term_to_edit->parent : 0);
                                    ?>
                                </select>
                                <p>Assign a parent to create a hierarchy. A "Chapter" should have a "Book" as its parent.</p>
                            </div>

                            <div class="form-field" id="linked-subjects-field" style="<?php echo ($term_to_edit && $term_to_edit->parent != 0) ? 'display:none;' : ''; ?>">
                                <label for="linked-subjects">Linked Subjects</label>
                                <div class="subjects-checkbox-group" style="padding: 10px; border: 1px solid #ddd; background: #fff; max-height: 150px; overflow-y: auto;">
                                    <?php foreach ($all_subjects as $subject) : ?>
                                        <label style="display: block; margin-bottom: 5px;">
                                            <input type="checkbox" name="linked_subjects[]" value="<?php echo esc_attr($subject->term_id); ?>" <?php checked(in_array($subject->term_id, $linked_subject_ids)); ?>>
                                            <?php echo esc_html($subject->name); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p>Select the subjects this source should be available under. This only applies to top-level sources.</p>
                            </div>

                            <script>
                                jQuery(document).ready(function($) {
                                    $('#parent-source').on('change', function() {
                                        if ($(this).val() == '0') {
                                            $('#linked-subjects-field').slideDown();
                                        } else {
                                            $('#linked-subjects-field').slideUp();
                                        }
                                    });
                                });
                            </script>

                            <div class="form-field">
                                <label for="term-description">Description</label>
                                <textarea name="term_description" id="term-description" rows="3" cols="40"><?php echo esc_textarea($edit_description); ?></textarea>
                            </div>

                            <p class="submit">
                                <input type="submit" class="button button-primary" value="<?php echo $term_to_edit ? 'Update Item' : 'Add New Item'; ?>">
                                <?php if ($term_to_edit) : ?>
                                    <a href="admin.php?page=qp-organization&tab=sources" class="button button-secondary">Cancel Edit</a>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                        <input type="hidden" name="tab" value="sources" />
                        <?php $list_table->display(); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // Helper functions can be kept for consistency or moved to a central file later
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