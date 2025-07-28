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
            'plural'   => $this->taxonomy, // THE FIX IS HERE
            'ajax'     => false
        ]);
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

    public function process_bulk_action() {
        $action = $this->current_action();

        if ('merge' === $action) {
            check_admin_referer('bulk-' . $this->_args['plural']);

            $term_ids = isset($_REQUEST['term_ids']) ? array_map('absint', $_REQUEST['term_ids']) : [];

            if (count($term_ids) < 2) {
                // Not a real redirect, but stops execution and shows a message
                wp_die('Please select at least two items to merge.');
            }

            // Build the URL for our hidden merge page
            $redirect_url = admin_url('admin.php?page=qp-merge-terms');

            // Add the necessary parameters for the merge page to use
            $redirect_url = add_query_arg(
                [
                    'taxonomy' => $this->taxonomy,
                    'taxonomy_label' => $this->taxonomy_label,
                    'term_ids' => $term_ids,
                ],
                $redirect_url
            );

            wp_safe_redirect($redirect_url);
            exit;
        }
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

        // --- NEW: Recursive Count Logic ---
        $term_ids = wp_list_pluck($all_terms, 'term_id');
        $ids_placeholder = implode(',', $term_ids);

        // Get direct counts for all terms at once
        $direct_counts = $wpdb->get_results(
            "SELECT term_id, COUNT(object_id) as count 
             FROM $rel_table 
             WHERE term_id IN ($ids_placeholder) 
             GROUP BY term_id",
            OBJECT_K // Index the results by term_id
        );

        $terms_by_id = [];
        foreach ($all_terms as $term) {
            $term->direct_count = isset($direct_counts[$term->term_id]) ? (int)$direct_counts[$term->term_id]->count : 0;
            $terms_by_id[$term->term_id] = $term;
        }

        // Recursively add child counts to their parents
        foreach ($terms_by_id as $term) {
            $parent_id = $term->parent;
            if ($parent_id != 0 && isset($terms_by_id[$parent_id])) {
                if (!isset($terms_by_id[$parent_id]->child_count)) {
                    $terms_by_id[$parent_id]->child_count = 0;
                }
                // Add its own direct count plus any counts from its own children
                $terms_by_id[$parent_id]->child_count += $term->direct_count + ($term->child_count ?? 0);
            }
        }
        
        // Finalize total count for each term
        foreach ($all_terms as $term) {
            $term->count = $term->direct_count + ($term->child_count ?? 0);
        }
        // --- End of New Logic ---

        // Arrange into a hierarchy
        $this->items = $this->build_hierarchy($all_terms);
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
    $padding = str_repeat('— ', $item->level);
    // Use a more specific nonce name based on the taxonomy
    $edit_nonce = wp_create_nonce('qp_edit_' . $this->taxonomy . '_' . $item->term_id);
    $delete_nonce = wp_create_nonce('qp_delete_' . $this->taxonomy . '_' . $item->term_id);

    $actions = [
        'edit' => sprintf('<a href="?page=qp-organization&tab=%s&action=edit&term_id=%s&_wpnonce=%s">Edit</a>', $this->tab_slug, $item->term_id, $edit_nonce)
    ];

    if ($item->name !== 'Uncategorized') {
        $actions['delete'] = sprintf('<a href="?page=qp-organization&tab=%s&action=delete&term_id=%s&_wpnonce=%s" style="color:#a00;">Delete</a>', $this->tab_slug, $item->term_id, $delete_nonce);
    }

    return $padding . '<strong>' . esc_html($item->name) . '</strong>' . $this->row_actions($actions);
}

    public function column_default($item, $column_name) {
        return esc_html($item->$column_name);
    }
}