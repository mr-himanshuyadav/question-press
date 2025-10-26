<?php
namespace QuestionPress\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles database operations for custom terms and taxonomies.
 *
 * @package QuestionPress\Database
 */
class Terms_DB extends DB { // Inherits from DB to get $wpdb

    /**
     * Get the name of the terms table.
     * @return string
     */
    public static function get_terms_table_name() {
        return self::$wpdb->prefix . 'qp_terms';
    }

    /**
     * Get the name of the term meta table.
     * @return string
     */
    public static function get_term_meta_table_name() {
        return self::$wpdb->prefix . 'qp_term_meta';
    }

    /**
     * Get the name of the taxonomies table.
     * @return string
     */
    public static function get_taxonomies_table_name() {
        return self::$wpdb->prefix . 'qp_taxonomies';
    }

    /**
     * Get the name of the term relationships table.
     * @return string
     */
    public static function get_relationships_table_name() {
        return self::$wpdb->prefix . 'qp_term_relationships';
    }

    /**
    * Helper method to get taxonomy ID by name.
    * (You might want this in Terms_DB instead)
    * @param string $taxonomy_name
    * @return int|null
    */
   public static function get_taxonomy_id_by_name($taxonomy_name){
         $tax_table = self::get_taxonomies_table_name(); // Use self::
         return self::$wpdb->get_var(self::$wpdb->prepare( // Use self::$wpdb
             "SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s",
             $taxonomy_name
         ));
    }

    /**
     * Retrieve metadata for a term from our custom table.
     * (Moved from global function qp_get_term_meta)
     *
     * @param int    $term_id  ID of the term.
     * @param string $meta_key Metadata key.
     * @param bool   $single   Whether to return a single value. (Currently only supports single)
     * @return mixed Will be null if the meta field does not exist.
     */
    public static function get_meta($term_id, $meta_key, $single = true) {
        $wpdb = self::$wpdb;
        $meta_table = self::get_term_meta_table_name();
        $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $meta_table WHERE term_id = %d AND meta_key = %s", $term_id, $meta_key));
        return $value;
    }

    /**
     * Update metadata for a term in our custom table. Creates if not exists.
     * (Moved from global function qp_update_term_meta)
     *
     * @param int    $term_id     ID of the term.
     * @param string $meta_key    Metadata key.
     * @param mixed  $meta_value  Metadata value. Must be serializable if non-scalar.
     */
    public static function update_meta($term_id, $meta_key, $meta_value) {
        $meta_table = self::get_term_meta_table_name();
        $existing_meta_id = self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT meta_id FROM $meta_table WHERE term_id = %d AND meta_key = %s",
            $term_id,
            $meta_key
        ));

        if ($existing_meta_id) {
            self::$wpdb->update(
                $meta_table,
                ['meta_value' => maybe_serialize($meta_value)], // Serialize if needed
                ['meta_id' => $existing_meta_id]
            );
        } else {
            self::$wpdb->insert(
                $meta_table,
                [
                    'term_id' => $term_id,
                    'meta_key' => $meta_key,
                    'meta_value' => maybe_serialize($meta_value) // Serialize if needed
                ]
            );
        }
    }

    /**
     * Get or create a term in the custom taxonomy system.
     * (Moved from global function qp_get_or_create_term)
     *
     * @param string $name         The name of the term.
     * @param int    $taxonomy_id  The ID of the taxonomy.
     * @param int    $parent_id    Optional. The term_id of the parent. Default 0.
     * @return int                 The term_id of the existing or newly created term, or 0 on failure.
     */
    public static function get_or_create($name, $taxonomy_id, $parent_id = 0) {
        $term_table = self::get_terms_table_name();

        // Sanitize the input
        $name = sanitize_text_field($name);
        $taxonomy_id = absint($taxonomy_id);
        $parent_id = absint($parent_id);
        if (empty($name) || empty($taxonomy_id)) {
            return 0;
        }

        // Check if the term already exists
        $existing_term_id = self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT term_id FROM {$term_table} WHERE name = %s AND taxonomy_id = %d AND parent = %d",
            $name,
            $taxonomy_id,
            $parent_id
        ));

        if ($existing_term_id) {
            return (int) $existing_term_id;
        }

        // If it doesn't exist, create it
        $result = self::$wpdb->insert(
            $term_table,
            [
                'name'        => $name,
                'slug'        => sanitize_title($name),
                'taxonomy_id' => $taxonomy_id,
                'parent'      => $parent_id,
            ]
        );

        return $result ? (int) self::$wpdb->insert_id : 0;
    }

    /**
     * Trace a term's lineage back to the root and return an array of names.
     * (Moved from global function qp_get_term_lineage_names)
     *
     * @param int    $term_id      The starting term_id.
     * @return array An ordered array of names from parent to child.
     */
    public static function get_lineage_names($term_id) {
        $term_table = self::get_terms_table_name();
        $lineage = [];
        $current_id = absint($term_id);
        for ($i = 0; $i < 10; $i++) { // Safety break
            if (!$current_id) break;
            $term = self::$wpdb->get_row(self::$wpdb->prepare(
                "SELECT name, parent FROM {$term_table} WHERE term_id = %d",
                $current_id
            ));
            if ($term) {
                array_unshift($lineage, $term->name);
                $current_id = absint($term->parent);
            } else {
                break;
            }
        }
        return $lineage;
    }

    /**
     * Get all descendant term IDs for a given parent, including the parent itself.
     * (Moved from global function get_all_descendant_ids)
     *
     * @param int    $parent_id The starting term_id.
     * @return array An array of term IDs.
     */
    public static function get_all_descendant_ids($parent_id) {
        $term_table = self::get_terms_table_name();
        $parent_id = absint($parent_id);
        $descendant_ids = [$parent_id];
        $current_parent_ids = [$parent_id];

        for ($i = 0; $i < 10; $i++) { // Safety break
            if (empty($current_parent_ids)) break;

            // Create a placeholder string like '%d, %d, %d'
            $placeholders = implode(',', array_fill(0, count($current_parent_ids), '%d'));

            // Prepare the query safely
            $child_ids = self::$wpdb->get_col( self::$wpdb->prepare(
                "SELECT term_id FROM $term_table WHERE parent IN ($placeholders)",
                $current_parent_ids
            ) );

            if (!empty($child_ids)) {
                // Ensure child IDs are integers before merging
                $child_ids = array_map('absint', $child_ids);
                $descendant_ids = array_merge($descendant_ids, $child_ids);
                $current_parent_ids = $child_ids;
            } else {
                break;
            }
        }
        return array_unique($descendant_ids);
    }

    /**
     * Helper function to get the full source hierarchy for a given question.
     * (Moved from global function qp_get_source_hierarchy_for_question)
     *
     * @param int $question_id The ID of the question.
     * @return array An array containing the names of the source, section, etc., in order.
     */
    public static function get_source_hierarchy_for_question($question_id) {
        $rel_table = self::get_relationships_table_name();
        $term_table = self::get_terms_table_name();
        $tax_table = self::get_taxonomies_table_name();
        $questions_table = self::$wpdb->prefix . 'qp_questions'; // Hardcode for now

        // Step 1: Get the group_id for the given question.
        $group_id = self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT group_id FROM {$questions_table} WHERE question_id = %d",
            $question_id
        ));

        if (!$group_id) {
            return [];
        }

        // Step 2: Find the most specific source term linked to the GROUP.
        $term_id = self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT r.term_id
             FROM {$rel_table} r
             JOIN {$term_table} t ON r.term_id = t.term_id
             WHERE r.object_id = %d AND r.object_type = 'group'
             AND t.taxonomy_id = (SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source')
             LIMIT 1",
            $group_id
        ));

        if (!$term_id) {
            return [];
        }

        // Step 3: Use the moved lineage function
        return self::get_lineage_names($term_id);
    }

    /**
     * Gets terms linked to a specific object ID and type for given taxonomy names.
     * Optionally includes term meta.
     *
     * @param int          $object_id    The ID of the object (e.g., group_id, question_id).
     * @param string       $object_type  The type of the object ('group', 'question').
     * @param string|array $taxonomy_names The name(s) of the taxonomy(ies) to look for.
     * @param array        $include_meta Optional array of meta keys to include.
     * @return array       Array of term objects (stdClass), each potentially containing a 'meta' property. Returns empty array if taxonomy doesn't exist or no terms found.
     */
    public static function get_linked_terms( $object_id, $object_type, $taxonomy_names, $include_meta = [] ) {
        $object_id = absint($object_id);
        if ( $object_id <= 0 ) {
            return [];
        }
        $taxonomy_names = (array) $taxonomy_names;
        $tax_placeholders = implode( ',', array_fill( 0, count($taxonomy_names), '%s' ) );

        $tax_table = self::get_taxonomies_table_name();
        $term_table = self::get_terms_table_name();
        $rel_table = self::get_relationships_table_name();

        // Get relevant taxonomy IDs
        $taxonomy_ids_query = self::$wpdb->prepare(
            "SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name IN ({$tax_placeholders})",
            $taxonomy_names
        );
        $taxonomy_ids = self::$wpdb->get_col( $taxonomy_ids_query );

        if ( empty($taxonomy_ids) ) {
            return []; // Taxonomies not found
        }
        $tax_ids_placeholder = implode( ',', $taxonomy_ids );

        // Base query to get linked terms
        $sql = self::$wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, t.parent, tax.taxonomy_name
             FROM {$term_table} t
             JOIN {$rel_table} r ON t.term_id = r.term_id
             JOIN {$tax_table} tax ON t.taxonomy_id = tax.taxonomy_id
             WHERE r.object_id = %d AND r.object_type = %s AND t.taxonomy_id IN ({$tax_ids_placeholder})",
            $object_id,
            $object_type
        );

        $terms = self::$wpdb->get_results( $sql );

        // Include meta if requested
        if ( !empty($terms) && !empty($include_meta) ) {
            $term_ids = wp_list_pluck( $terms, 'term_id' );
            $term_ids_placeholder = implode( ',', array_map('absint', $term_ids) );
            $meta_keys_placeholder = implode( ',', array_fill(0, count($include_meta), '%s') );
            $meta_table = self::get_term_meta_table_name();

            $meta_query = self::$wpdb->prepare(
                "SELECT term_id, meta_key, meta_value FROM {$meta_table} WHERE term_id IN ({$term_ids_placeholder}) AND meta_key IN ({$meta_keys_placeholder})",
                array_merge( [$term_ids_placeholder], $include_meta ) // Note: Placeholder %d is handled directly in SQL string
            );
             // Fetch meta and organize it
            $meta_results = self::$wpdb->get_results( $meta_query );
            $meta_by_term = [];
            foreach ( $meta_results as $meta ) {
                $meta_by_term[ $meta->term_id ][ $meta->meta_key ] = maybe_unserialize($meta->meta_value);
            }

            // Attach meta to terms
            foreach ( $terms as $term ) {
                $term->meta = $meta_by_term[ $term->term_id ] ?? [];
            }
        }

        return $terms;
    }


    // --- Methods will be moved/added below ---


} // End class Terms_DB