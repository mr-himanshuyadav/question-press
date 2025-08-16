<?php
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class QP_Terms_List_Table extends WP_List_Table {
     private $taxonomy;
    private $taxonomy_label;
    private $tab_slug; // Add this new property

    public function __construct($taxonomy, $taxonomy_label, $tab_slug) {
        $this->taxonomy = $taxonomy;
        $this->taxonomy_label = $taxonomy_label;
        $this->tab_slug = $tab_slug;
        parent::__construct([
            'singular' => $this->taxonomy_label,
            'plural'   => 'qp-organization-table', // Add custom class here
            'ajax'     => false
        ]);
    }

    protected function get_table_classes() {
        // Start with the default WordPress classes and add our custom one.
        return array( 'widefat', 'striped', $this->_args['plural'] );
    }

    public function get_columns() {
        return ['cb' => '<input type="checkbox" />', 'name' => 'Name', 'description' => 'Description', 'count' => 'Count'];
    }

    function column_cb($item) {
        return sprintf('<input type="checkbox" name="term_ids[]" value="%s" />', $item->term_id);
    }

    protected function get_bulk_actions() {
        return ['merge' => 'Merge'];
    }

    public function process_bulk_action()
{
    $action = $this->current_action();

    // --- Merge Page Redirect Logic ---
    if ('merge' === $action && !isset($_POST['action2'])) { // action2 is used by the merge page form itself
        check_admin_referer('bulk-' . $this->_args['plural']);
        $term_ids = isset($_REQUEST['term_ids']) ? array_map('absint', $_REQUEST['term_ids']) : [];
        if (count($term_ids) < 2) {
            wp_die('Please select at least two items to merge.');
        }
        $redirect_url = admin_url('admin.php?page=qp-merge-terms');
        $redirect_url = add_query_arg([
            'taxonomy' => $this->taxonomy,
            'taxonomy_label' => $this->taxonomy_label,
            'term_ids' => $term_ids,
        ], $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    // --- NEW: Centralized Merge Processing Logic ---
    if (isset($_POST['action']) && $_POST['action'] === 'perform_merge') {
        check_admin_referer('qp_perform_merge_nonce');

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $meta_table = $wpdb->prefix . 'qp_term_meta';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        $destination_term_id = absint($_POST['destination_term_id']);
        $source_term_ids = array_map('absint', $_POST['source_term_ids']);
        $final_name = sanitize_text_field($_POST['term_name']);
        $final_parent = absint($_POST['parent']);
        $final_description = sanitize_textarea_field($_POST['term_description']);
        $child_merges = isset($_POST['child_merges']) ? (array)$_POST['child_merges'] : [];

        // --- 1. Handle explicit child merges from the form ---
        if (!empty($child_merges)) {
            foreach ($child_merges as $source_child_id => $dest_child_id) {
                // This recursively merges the selected children and all their descendants
                $this->recursively_merge_terms(absint($source_child_id), absint($dest_child_id));
            }
        }

        // --- 2. Handle the top-level parent merge ---
        $source_term_ids_to_merge = array_diff($source_term_ids, [$destination_term_id]);
        foreach($source_term_ids_to_merge as $source_term_id) {
            $this->recursively_merge_terms($source_term_id, $destination_term_id);
        }

        // --- 3. Update the final destination term with the new details ---
        $wpdb->update($term_table,
            ['name' => $final_name, 'slug' => sanitize_title($final_name), 'parent' => $final_parent],
            ['term_id' => $destination_term_id]
        );
        qp_update_term_meta($destination_term_id, 'description', $final_description);

        // Set success message and redirect
        QP_Sources_Page::set_message(count($source_term_ids_to_merge) . ' item(s) were successfully merged into "' . esc_html($final_name) . '".', 'updated');
        QP_Sources_Page::redirect_to_tab($this->tab_slug); // Use the tab_slug property for correct redirection
    }
}

/**
 * NEW: Recursive helper function to merge a source term into a destination term.
 * This handles reassignment of questions, merging/re-parenting of children, and deletion.
 *
 * @param int $source_term_id      The ID of the term to merge and delete.
 * @param int $destination_term_id The ID of the term to merge into.
 */
private function recursively_merge_terms($source_term_id, $destination_term_id) {
    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $meta_table = $wpdb->prefix . 'qp_term_meta';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    if ($source_term_id === $destination_term_id) {
        return; // Cannot merge a term into itself.
    }

    // --- FIX START: Correctly reassign GROUP relationships ---
    // 1. Find all groups linked to the source term.
    $group_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM $rel_table WHERE term_id = %d AND object_type = 'group'",
        $source_term_id
    ));

    if (!empty($group_ids)) {
        // 2. Delete the old relationships for these groups to the source term.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $rel_table WHERE term_id = %d AND object_type = 'group'",
            $source_term_id
        ));

        // 3. Insert new relationships for the destination term, ignoring duplicates.
        foreach ($group_ids as $group_id) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $rel_table (object_id, term_id, object_type) VALUES (%d, %d, 'group')",
                $group_id,
                $destination_term_id
            ));
        }
    }
    // --- FIX END ---

    // *** NEW: Update historical session snapshots ***
    $sessions_to_update = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, settings_snapshot FROM {$sessions_table} WHERE settings_snapshot LIKE %s",
        '%"' . $wpdb->esc_like($source_term_id) . '"%'
    ));

    foreach ($sessions_to_update as $session) {
        $settings = json_decode($session->settings_snapshot, true);
        $was_updated = false;

        // Check and update various keys where the term ID might be stored
        $keys_to_check = ['subjects', 'topics', 'section_id'];
        foreach ($keys_to_check as $key) {
            if (isset($settings[$key])) {
                if (is_array($settings[$key])) {
                    $index = array_search($source_term_id, $settings[$key]);
                    if ($index !== false) {
                        $settings[$key][$index] = $destination_term_id;
                        $settings[$key] = array_unique($settings[$key]); // Prevent duplicates
                        $was_updated = true;
                    }
                } else {
                    if ($settings[$key] == $source_term_id) {
                        $settings[$key] = $destination_term_id;
                        $was_updated = true;
                    }
                }
            }
        }

        if ($was_updated) {
            $wpdb->update(
                $sessions_table,
                ['settings_snapshot' => wp_json_encode($settings)],
                ['session_id' => $session->session_id]
            );
        }
    }
    // *** END NEW ***

    // 2. Get children of both source and destination to compare them.
    $source_children = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE parent = %d", $source_term_id), OBJECT_K);
    $dest_children = $wpdb->get_results($wpdb->prepare("SELECT term_id, name FROM $term_table WHERE parent = %d", $destination_term_id), OBJECT_K);

    $dest_children_names = array_map('strtolower', wp_list_pluck($dest_children, 'name'));

    // 3. Loop through source children to decide their fate.
    foreach ($source_children as $source_child) {
        $found_in_dest = array_search(strtolower($source_child->name), $dest_children_names);

        if ($found_in_dest !== false) {
            // A child with the same name exists in the destination. Merge them recursively.
            $dest_child_key = array_keys($dest_children)[$found_in_dest];
            $dest_child_id = $dest_children[$dest_child_key]->term_id;
            $this->recursively_merge_terms($source_child->term_id, $dest_child_id);
        } else {
            // This child is unique. Re-parent it to the destination term.
            $wpdb->update($term_table, ['parent' => $destination_term_id], ['term_id' => $source_child->term_id]);
        }
    }

    // 4. After re-assigning questions and handling children, delete the now-empty source term.
    $wpdb->delete($term_table, ['term_id' => $source_term_id]);
    $wpdb->delete($meta_table, ['term_id' => $source_term_id]);
}

    public function prepare_items() {
        global $wpdb;
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->process_bulk_action();

        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $term_table = $wpdb->prefix . 'qp_terms';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';
        $meta_table = $wpdb->prefix . 'qp_term_meta';

        
        $taxonomy_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = %s", $this->taxonomy));

        // Get all terms for the taxonomy first
        $all_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, m.meta_value as description
             FROM $term_table t
             LEFT JOIN $meta_table m ON t.term_id = m.term_id AND m.meta_key = 'description'
             WHERE t.taxonomy_id = %d 
             ORDER BY t.name ASC",
            $taxonomy_id
        ));

        if (empty($all_terms)) {
            $this->items = [];
            return;
        }

        // --- NEW HIERARCHICAL COUNT LOGIC ---
        $questions_table = $wpdb->prefix . 'qp_questions';
        $terms_by_id = [];
        $children_map = [];

        foreach ($all_terms as $term) {
            $term->question_ids = []; // Initialize question ID array
            $terms_by_id[$term->term_id] = $term;
            if ($term->parent != 0) {
                $children_map[$term->parent][] = $term->term_id;
            }
        }

        // 1. Get all question-to-group mappings to use as a lookup table
        $question_group_map = $wpdb->get_results("SELECT question_id, group_id FROM $questions_table WHERE group_id > 0", OBJECT_K);

        // 2. Get all relationships for the current taxonomy in one query
        $relationships = $wpdb->get_results($wpdb->prepare("SELECT object_id, term_id, object_type FROM $rel_table WHERE term_id IN (SELECT term_id FROM $term_table WHERE taxonomy_id = %d)", $taxonomy_id));

        // 3. Populate direct question IDs for each term based on relationships
        foreach ($relationships as $rel) {
            if (!isset($terms_by_id[$rel->term_id])) continue;

            if ($rel->object_type === 'question') {
                $terms_by_id[$rel->term_id]->question_ids[] = (int)$rel->object_id;
            } elseif ($rel->object_type === 'group') {
                foreach ($question_group_map as $qid => $q) {
                    if ($q->group_id == $rel->object_id) {
                        $terms_by_id[$rel->term_id]->question_ids[] = (int)$qid;
                    }
                }
            }
        }

        // 4. Create a recursive function to aggregate unique question IDs up the hierarchy
        function aggregate_question_ids(&$term, &$terms_by_id, &$children_map) {
            if (isset($term->count_calculated)) { // Memoization to prevent re-calculating
                return $term->question_ids;
            }

            if (isset($children_map[$term->term_id])) {
                foreach ($children_map[$term->term_id] as $child_id) {
                    $child_term = $terms_by_id[$child_id];
                    // Recursively get the child's complete list of questions
                    $child_question_ids = aggregate_question_ids($child_term, $terms_by_id, $children_map);
                    // Merge the child's questions into the parent's list
                    $term->question_ids = array_merge($term->question_ids, $child_question_ids);
                }
            }
            // Ensure the final list for this term is unique
            $term->question_ids = array_unique($term->question_ids);
            $term->count_calculated = true; // Mark as calculated
            return $term->question_ids;
        }

        // 5. Start the aggregation process from all top-level terms (parents)
        foreach ($all_terms as $term) {
            if ($term->parent == 0) {
                aggregate_question_ids($term, $terms_by_id, $children_map);
            }
        }

        // 6. Set the final count for each term
        foreach ($all_terms as $term) {
            $term->count = count($term->question_ids);
        }
        
        $parents_with_children = [];
        foreach ($all_terms as $term) {
            if ($term->parent != 0) {
                $parents_with_children[$term->parent] = true;
            }
        }
        foreach ($all_terms as $term) {
            $term->has_children = isset($parents_with_children[$term->term_id]);
        }

        // Arrange into a hierarchy
        $this->items = $this->build_hierarchy($all_terms);
    }

    public function single_row($item) {
        $row_classes = [];
        if ($item->parent != 0) {
            // Add classes to identify this as a child and specify its parent
            $row_classes[] = 'child-row child-of-' . $item->parent;
        }
        if ($item->has_children) {
            $row_classes[] = 'parent-row';
        }

        echo '<tr id="term-' . $item->term_id . '" class="' . esc_attr(implode(' ', $row_classes)) . '" data-term-id="' . $item->term_id . '">';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    private function build_hierarchy(array $terms, $parent_id = 0, $level = 0) {
        $result = [];
        foreach ($terms as $term) {
            if ($term->parent == $parent_id) {
                $term->level = $level;
                $result[] = $term;
                $children = $this->build_hierarchy($terms, $term->term_id, $level + 1);
                $result = array_merge($result, $children);
            }
        }
        return $result;
    }

    public function column_name($item) {
        $name_prefix = '';
        $padding_style = 'style="padding-left: ' . ($item->level * 15) . 'px;"';

        if ($item->has_children) {
            $name_prefix = '<span class="toggle-children dashicons dashicons-arrow-right-alt2"></span>';
        }

        // Nonce creation remains the same
        $edit_nonce = wp_create_nonce('qp_edit_' . $this->taxonomy . '_' . $item->term_id);
        $delete_nonce = wp_create_nonce('qp_delete_' . $this->taxonomy . '_' . $item->term_id);

        $actions = [
            'edit' => sprintf('<a href="?page=qp-organization&tab=%s&action=edit&term_id=%s&_wpnonce=%s">Edit</a>', $this->tab_slug, $item->term_id, $edit_nonce)
        ];

        if ($item->name !== 'Uncategorized') {
            $actions['delete'] = sprintf('<a href="?page=qp-organization&tab=%s&action=delete&term_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $this->tab_slug, $item->term_id, $delete_nonce);
        }
        
        // Get the HTML for the actions, but don't echo it.
        $actions_html = $this->row_actions($actions);

        // Wrap the name and actions in a flex container.
        return '<div ' . $padding_style . '>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span>' . $name_prefix . '<strong>' . esc_html($item->name) . '</strong></span>
                        ' . $actions_html . '
                    </div>
                </div>';
    }

    public function column_default($item, $column_name) {
        return esc_html($item->$column_name);
    }
}