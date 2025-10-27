<?php
namespace QuestionPress\Admin\Views;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Need access to DB classes
use QuestionPress\Database\Terms_DB;

/**
 * Handles rendering the hidden "Merge Terms" admin page.
 */
class Merge_Terms_Page {

	/**
	 * Renders the "Merge Terms" admin page.
	 * Replaces the old qp_render_merge_terms_page function.
	 */
	public static function render() {
		global $wpdb;

		// Security and data validation (Keep this logic)
		if ( ! isset( $_REQUEST['term_ids'] ) || ! is_array( $_REQUEST['term_ids'] ) || count( $_REQUEST['term_ids'] ) < 2 ) {
			wp_die( 'Please select at least two items to merge.' );
		}
		// TODO: Add nonce check for accessing this page if needed (depends on how it's linked).
		// For now, assume access is controlled by the bulk action nonce on the previous page.

		$term_ids_to_merge = array_map( 'absint', $_REQUEST['term_ids'] );
		$taxonomy_name     = sanitize_key( $_REQUEST['taxonomy'] ?? '' ); // Added default
		$taxonomy_label    = sanitize_text_field( $_REQUEST['taxonomy_label'] ?? 'Items' ); // Added default
		$ids_placeholder   = implode( ',', $term_ids_to_merge );

		if ( empty($taxonomy_name) || empty($ids_placeholder) ) {
			wp_die('Missing required parameters (taxonomy or term IDs).');
		}

		$term_table = Terms_DB::get_terms_table_name(); // Use DB class method
		$terms_to_merge = $wpdb->get_results( "SELECT * FROM {$term_table} WHERE term_id IN ({$ids_placeholder})" );

		if ( empty($terms_to_merge) ) {
			wp_die('Could not find the terms selected for merging.');
		}

		// The first selected term will be the master, its data pre-fills the form
		$master_term        = $terms_to_merge[0];
		$master_description = Terms_DB::get_meta( $master_term->term_id, 'description', true ); // Use DB class method

		// Get all possible parent terms for the dropdown (excluding the ones being merged)
		$parent_terms = $wpdb->get_results( $wpdb->prepare(
			"SELECT term_id, name FROM $term_table WHERE taxonomy_id = %d AND parent = 0 AND term_id NOT IN ({$ids_placeholder}) ORDER BY name ASC",
			$master_term->taxonomy_id
		) );

		// Fetch all children for all terms being merged
		$all_children = $wpdb->get_results( "SELECT term_id, name, parent FROM {$term_table} WHERE parent IN ({$ids_placeholder}) ORDER BY name ASC" );
		$children_by_parent = [];
		foreach ( $all_children as $child ) {
			$children_by_parent[ $child->parent ][] = $child;
		}

		// Prepare arguments for the template
		$args = [
			'taxonomy_label'     => $taxonomy_label,
			'taxonomy_name'      => $taxonomy_name, // Pass taxonomy name for redirection
			'term_ids_to_merge'  => $term_ids_to_merge,
			'terms_to_merge'     => $terms_to_merge,
			'master_term'        => $master_term,
			'master_description' => $master_description,
			'parent_terms'       => $parent_terms,
			'children_by_parent' => $children_by_parent,
		];

		// Load and echo the template (assuming it exists and qp_get_template_html is available)
		// We'll create this template file next if it doesn't exist yet.
		echo \qp_get_template_html( 'merge-terms-page', 'admin', $args );

		// The original function included the JS directly. Let's keep it that way for now.
		?>
		<script>
			jQuery(document).ready(function($) {
				var allTerms = <?php echo json_encode( $terms_to_merge ); ?>;
				var allChildren = <?php echo json_encode( $children_by_parent ); ?>;
				var allParentOptions = <?php echo json_encode( $parent_terms ); ?>; // Get parent options for JS

				function populateMergeTable() {
					var destinationId = $('input[name="destination_term_id"]:checked').val();
					var $tableBody = $('#child-merge-table tbody').empty();
					var destinationChildren = allChildren[destinationId] || [];
					var destinationTerm = allTerms.find(term => term.term_id == destinationId);
					var destinationTermName = destinationTerm ? destinationTerm.name : 'Selected Destination';

					// Update the default parent selection dropdown based on destination term
                    var $parentSelect = $('#parent-term');
                    $parentSelect.empty().append('<option value="0">— None —</option>'); // Reset
                    allParentOptions.forEach(function(parentOpt){
                         // Exclude the NEW destination term itself from being its own parent
                        if (parentOpt.term_id != destinationId) {
                            var $option = $('<option></option>').val(parentOpt.term_id).text(parentOpt.name);
                            // Set selected based on the initially loaded master term's parent
                            if (destinationTerm && parentOpt.term_id == destinationTerm.parent) {
                                $option.prop('selected', true);
                            }
                            $parentSelect.append($option);
                        }
                    });

					allTerms.forEach(function(parentTerm) {
						if (parentTerm.term_id == destinationId) return; // Skip destination parent

						var sourceChildren = allChildren[parentTerm.term_id] || [];
						if (sourceChildren.length === 0) return; // Skip parents with no children

						sourceChildren.forEach(function(child) {
							var row = $('<tr>'); // Create jQuery object
							row.append('<td><strong>' + $('<div/>').text(child.name).html() + '</strong> (ID: ' + child.term_id + ')</td>'); // Sanitize name
							row.append('<td>' + $('<div/>').text(parentTerm.name).html() + '</td>'); // Sanitize parent name

							var $selectCell = $('<td>');
							var $select = $('<select name="child_merges[' + child.term_id + ']" style="width: 100%;">');

							// Option 1: Merge to parent destination
							$select.append('<option value="' + destinationId + '">Merge into: ' + $('<div/>').text($('#term-name').val() || destinationTermName).html() + ' (Parent)</option>'); // Use current name field value

							// Option 2: Merge into existing children of destination
							destinationChildren.forEach(function(destChild) {
								$select.append('<option value="' + destChild.term_id + '">Merge into: ' + $('<div/>').text(destChild.name).html() + '</option>');
							});

							$selectCell.append($select);
							row.append($selectCell);
							$tableBody.append(row);
						});
					});
					if ($tableBody.children().length === 0) {
						$tableBody.append('<tr><td colspan="3">No child items to merge for the selected sources.</td></tr>');
					}
				}

				// Update form defaults and merge table when the destination changes
				$('input[name="destination_term_id"]').on('change', function() {
					var selectedId = $(this).val();
					var selectedTerm = allTerms.find(term => term.term_id == selectedId);
					if (selectedTerm) {
						$('#term-name').val(selectedTerm.name);
						// Fetch and update description via AJAX if needed, or assume preloaded data is sufficient for now
						// For simplicity, let's just clear it or keep the original master description for now.
						// $('#term-description').val(selectedTerm.description || ''); // Assuming description was preloaded
					}
					populateMergeTable(); // Update the child merge options
				});

				// Update child merge options if the final name changes
                $('#term-name').on('input', function() {
                    populateMergeTable();
                });


				// Initial population
				populateMergeTable();
			});
		</script>
		<?php
	}
}