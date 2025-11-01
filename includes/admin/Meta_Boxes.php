<?php
namespace QuestionPress\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles registration, rendering, and saving of custom meta boxes.
 */
class Meta_Boxes {

	// --- qp_plan Meta Box ---

	/**
	 * Adds the meta box container for Plan Details.
	 * Hooked to 'add_meta_boxes_qp_plan'.
	 * Replaces qp_add_plan_details_meta_box().
	 */
	public static function add_plan_details() {
		add_meta_box(
			'qp_plan_details_meta_box',           // Unique ID
			__( 'Plan Details', 'question-press' ), // Box title
			[ self::class, 'render_plan_details' ],    // Callback function (static method in this class)
			'qp_plan',                            // Post type
			'normal',                             // Context
			'high'                                // Priority
		);
	}

	/**
	 * Renders the HTML content for the Plan Details meta box.
	 * Replaces qp_render_plan_details_meta_box().
	 *
	 * @param \WP_Post $post The post object.
	 */
	public static function render_plan_details( $post ) {
		// Add a nonce field for security
		wp_nonce_field( 'qp_save_plan_details_meta', 'qp_plan_details_nonce' );

		// Get existing meta values
		$plan_type = get_post_meta( $post->ID, '_qp_plan_type', true );
		// ... (rest of the variable fetching logic from original function) ...
		$duration_value = get_post_meta($post->ID, '_qp_plan_duration_value', true);
		$duration_unit = get_post_meta($post->ID, '_qp_plan_duration_unit', true);
		$attempts = get_post_meta($post->ID, '_qp_plan_attempts', true);
		$course_access_type = get_post_meta($post->ID, '_qp_plan_course_access_type', true);
		$linked_courses_raw = get_post_meta($post->ID, '_qp_plan_linked_courses', true);
		$linked_courses = is_array($linked_courses_raw) ? $linked_courses_raw : [];
		$description = get_post_meta($post->ID, '_qp_plan_description', true);

		// Get all published courses for selection
		$courses = get_posts([ /* ... get_posts args ... */
			'post_type' => 'qp_course',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby' => 'title',
			'order' => 'ASC',
		]);

		// --- Output the HTML (copied directly from original function) ---
		?>
		<style> /* Keep styles here for now */
			.qp-plan-meta-box table { width: 100%; border-collapse: collapse; }
			/* ... rest of the styles ... */
			.qp-plan-meta-box th, .qp-plan-meta-box td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
			.qp-plan-meta-box th { width: 150px; font-weight: 600; }
			.qp-plan-meta-box select, .qp-plan-meta-box input[type="number"], .qp-plan-meta-box textarea { width: 100%; max-width: 350px; box-sizing: border-box; }
			.qp-plan-meta-box .description { font-size: 0.9em; color: #666; }
			.qp-plan-meta-box .conditional-field { display: none; } /* Hide conditional fields initially */
			.qp-plan-meta-box .course-select-list { max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff; }
			.qp-plan-meta-box .course-select-list label { display: block; margin-bottom: 5px; }
		</style>

		<div class="qp-plan-meta-box">
			<table>
				<tbody>
					<tr>
						<th><label for="qp_plan_type">Plan Type</label></th>
						<td>
							<select name="_qp_plan_type" id="qp_plan_type">
								<option value="">— Select Type —</option>
								<option value="time_limited" <?php selected($plan_type, 'time_limited'); ?>>Time Limited</option>
								<option value="attempt_limited" <?php selected($plan_type, 'attempt_limited'); ?>>Attempt Limited</option>
								<option value="course_access" <?php selected($plan_type, 'course_access'); ?>>Course Access Only</option>
								<option value="unlimited" <?php selected($plan_type, 'unlimited'); ?>>Unlimited (Time & Attempts)</option>
								<option value="combined" <?php selected($plan_type, 'combined'); ?>>Combined (Time, Attempts, Courses)</option>
							</select>
							<p class="description">Select the primary restriction type for this plan.</p>
						</td>
					</tr>

					<tr class="conditional-field" data-depends-on="time_limited combined">
						<th><label for="qp_plan_duration_value">Duration</label></th>
						<td>
							<input type="number" name="_qp_plan_duration_value" id="qp_plan_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" style="width: 80px; margin-right: 10px;">
							<select name="_qp_plan_duration_unit" id="qp_plan_duration_unit">
								<option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
								<option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
								<option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
							</select>
							<p class="description">How long the access lasts after purchase.</p>
						</td>
					</tr>
					<?php // ... rest of the table rows from original function ... ?>
					<tr class="conditional-field" data-depends-on="attempt_limited combined">
						<th><label for="qp_plan_attempts">Number of Attempts</label></th>
						<td>
							<input type="number" name="_qp_plan_attempts" id="qp_plan_attempts" value="<?php echo esc_attr($attempts); ?>" min="1">
							<p class="description">How many attempts the user gets with this plan.</p>
						</td>
					</tr>
					<tr class="conditional-field" data-depends-on="course_access combined">
						<th><label for="qp_plan_course_access_type">Course Access</label></th>
						<td>
							<select name="_qp_plan_course_access_type" id="qp_plan_course_access_type">
								<option value="all" <?php selected($course_access_type, 'all'); ?>>All Courses</option>
								<option value="specific" <?php selected($course_access_type, 'specific'); ?>>Specific Courses</option>
							</select>
						</td>
					</tr>
					<tr class="conditional-field" data-depends-on="course_access combined" data-sub-depends-on="specific">
						<th><label>Select Courses</label></th>
						<td>
							<div class="course-select-list">
								<?php if (!empty($courses)) : ?>
									<?php foreach ($courses as $course) : ?>
										<label>
											<input type="checkbox" name="_qp_plan_linked_courses[]" value="<?php echo esc_attr($course->ID); ?>" <?php checked(in_array($course->ID, $linked_courses)); ?>>
											<?php echo esc_html($course->post_title); ?>
										</label>
									<?php endforeach; ?>
								<?php else: ?>
									<p>No courses found. Please create courses first.</p>
								<?php endif; ?>
							</div>
							 <p class="description">Select the specific courses included in this plan.</p>
						</td>
					</tr>
					 <tr>
						<th><label for="qp_plan_description">Description</label></th>
						<td>
							<textarea name="_qp_plan_description" id="qp_plan_description" rows="3"><?php echo esc_textarea($description); ?></textarea>
							 <p class="description">Optional user-facing description (e.g., for display on product page or user dashboard).</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<script type="text/javascript"> /* Keep JS here for now */
			jQuery(document).ready(function($) {
				// ... (JS code from original function) ...
				const planTypeSelect = $('#qp_plan_type');
				const courseAccessSelect = $('#qp_plan_course_access_type');
				const metaBox = $('.qp-plan-meta-box');

				function toggleFields() {
					const selectedType = planTypeSelect.val();
					const selectedCourseAccess = courseAccessSelect.val();

					metaBox.find('.conditional-field').each(function() {
						const $fieldRow = $(this);
						const dependsOn = $fieldRow.data('depends-on') ? $fieldRow.data('depends-on').split(' ') : [];
						const subDependsOn = $fieldRow.data('sub-depends-on');

						let show = false;
						if (dependsOn.includes(selectedType)) {
							show = true;
							if (subDependsOn === 'specific' && selectedCourseAccess !== 'specific') {
								show = false;
							}
						}

						if (show) { $fieldRow.slideDown(200); }
						else { $fieldRow.slideUp(200); }
					});
				}
				toggleFields(); // Initial call
				planTypeSelect.on('change', toggleFields);
				courseAccessSelect.on('change', toggleFields);
			});
		</script>
		<?php
		// --- End HTML Output ---
	}

	/**
	 * Saves the meta box data when the 'qp_plan' post type is saved.
	 * Replaces qp_save_plan_details_meta().
	 * Hooked to 'save_post_qp_plan'.
	 *
	 * @param int $post_id The ID of the post being saved.
	 */
	public static function save_plan_details( $post_id ) {
		// Check nonce, permissions, autosave, post type (copied from original function)
		if (!isset($_POST['qp_plan_details_nonce']) || !wp_verify_nonce($_POST['qp_plan_details_nonce'], 'qp_save_plan_details_meta')) return $post_id;
		if (!current_user_can('edit_post', $post_id)) return $post_id;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
		if ('qp_plan' !== get_post_type($post_id)) return $post_id;

		// Sanitize and save meta fields (copied from original function)
		$fields_to_save = [ /* ... fields ... */
			'_qp_plan_type' => 'sanitize_key',
			'_qp_plan_duration_value' => 'absint',
			'_qp_plan_duration_unit' => 'sanitize_key',
			'_qp_plan_attempts' => 'absint',
			'_qp_plan_course_access_type' => 'sanitize_key',
			'_qp_plan_description' => 'sanitize_textarea_field',
		];
		foreach ($fields_to_save as $meta_key => $sanitize_func) {
			if (isset($_POST[$meta_key])) {
				$value = call_user_func($sanitize_func, $_POST[$meta_key]);
				if (($sanitize_func === 'absint') && $value === 0 && !isset($_POST[$meta_key])) {
					 delete_post_meta($post_id, $meta_key); continue;
				 }
				update_post_meta($post_id, $meta_key, $value);
			} else {
				delete_post_meta($post_id, $meta_key);
			}
		}
		// Handle linked courses array (copied from original function)
		if (isset($_POST['_qp_plan_linked_courses']) && is_array($_POST['_qp_plan_linked_courses'])) {
			$linked_courses = array_map('absint', $_POST['_qp_plan_linked_courses']);
			update_post_meta($post_id, '_qp_plan_linked_courses', $linked_courses);
		} else {
			update_post_meta($post_id, '_qp_plan_linked_courses', []);
		}
	}

    // --- qp_course Meta Boxes ---

	/**
	 * Adds the meta box container for Course Access Settings.
	 * Hooked to 'add_meta_boxes_qp_course'.
	 * Replaces qp_add_course_access_meta_box().
	 */
	public static function add_course_access() {
		add_meta_box(
			'qp_course_access_meta_box',          // Unique ID
			__( 'Course Access & Monetization', 'question-press' ), // Box title
			[ self::class, 'render_course_access' ],   // Callback function (static method in this class)
			'qp_course',                          // Post type
			'side',                               // Context
			'high'                                // Priority
		);
	}

	/**
	 * Renders the HTML content for the Course Access meta box.
	 * Replaces qp_render_course_access_meta_box().
	 *
	 * @param \WP_Post $post The post object.
	 */
	public static function render_course_access( $post ) {
		// Add a nonce field for security
		wp_nonce_field( 'qp_save_course_access_meta', 'qp_course_access_nonce' );

		// Get existing meta values
		$access_mode = get_post_meta( $post->ID, '_qp_course_access_mode', true ) ?: 'free'; // Default to free
		// ... (rest of the variable fetching logic from original function) ...
		$duration_value = get_post_meta($post->ID, '_qp_course_access_duration_value', true);
		$duration_unit = get_post_meta($post->ID, '_qp_course_access_duration_unit', true) ?: 'day';
		$linked_product_id = get_post_meta($post->ID, '_qp_linked_product_id', true);
		$auto_plan_id = get_post_meta($post->ID, '_qp_course_auto_plan_id', true);

		// Get all published WooCommerce products for selection
		$products = [];
		if ( class_exists( 'WooCommerce' ) ) {
			$products = wc_get_products([ /* ... wc_get_products args ... */
				'status' => 'publish',
				'limit' => -1,
				'orderby' => 'title',
				'order' => 'ASC',
				'return' => 'objects',
			]);
		}

		// --- Output the HTML (copied directly from original function) ---
		?>
		<style>
            #qp_course_access_meta_box p { margin-bottom: 15px; }
            #qp_course_access_meta_box label { font-weight: 600; display: block; margin-bottom: 5px; }
            #qp_course_access_meta_box select,
            #qp_course_access_meta_box input[type="number"] { width: 100%; box-sizing: border-box; margin-bottom: 5px;}
            #qp_course_access_meta_box .duration-group { display: flex; align-items: center; gap: 10px; }
            #qp_course_access_meta_box .duration-group input[type="number"] { width: 80px; flex-shrink: 0; }
            #qp_course_access_meta_box .duration-group select { flex-grow: 1; }
            #qp-purchase-fields { display: <?php echo ($access_mode === 'requires_purchase') ? 'block' : 'none'; ?>; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;}
            #qp_course_access_meta_box small.description { font-size: 0.9em; color: #666; display: block; margin-top: 3px; }
            #qp-auto-plan-info { font-style: italic; color: #666; font-size: 0.9em; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ddd; }
        </style>

		<p>
			<label for="qp_course_access_mode"><?php esc_html_e('Access Mode:', 'question-press'); ?></label>
			<select name="_qp_course_access_mode" id="qp_course_access_mode">
				<option value="free" <?php selected($access_mode, 'free'); ?>><?php esc_html_e('Free (Public Enrollment)', 'question-press'); ?></option>
				<option value="requires_purchase" <?php selected($access_mode, 'requires_purchase'); ?>><?php esc_html_e('Requires Purchase', 'question-press'); ?></option>
			</select>
		</p>

		<div id="qp-purchase-fields">
			<p>
				<label><?php esc_html_e('Access Duration:', 'question-press'); ?></label>
				<div class="duration-group">
					<input type="number" name="_qp_course_access_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" placeholder="e.g., 30">
					<select name="_qp_course_access_duration_unit">
						<option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
						<option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
						<option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
					</select>
				</div>
				 <small class="description"><?php esc_html_e('How long access lasts after purchase. Leave blank for lifetime access.', 'question-press'); ?></small>
			</p>

			<?php // Only show product link if WooCommerce is active ?>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
			<p>
				<label for="qp_linked_product_id"><?php esc_html_e('Linked WooCommerce Product:', 'question-press'); ?></label>
				<select name="_qp_linked_product_id" id="qp_linked_product_id">
					<option value="">— <?php esc_html_e('Select Product', 'question-press'); ?> —</option>
					<?php
					if ($products) {
						foreach ($products as $product) {
							if ($product->is_type('simple') || $product->is_type('variable')) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr($product->get_id()),
									selected($linked_product_id, $product->get_id(), false),
									esc_html($product->get_name()) . ' (#' . $product->get_id() . ')'
								);
							}
						}
					}
					?>
				</select>
				<small class="description"><?php esc_html_e('Product users click "Purchase" for. Ensure this product is linked to the correct auto-generated or manual plan.', 'question-press'); ?></small>
			</p>
			<?php else : ?>
				<p><small class="description"><?php esc_html_e('Install and activate WooCommerce to link products for purchase.', 'question-press'); ?></small></p>
			<?php endif; ?>

			<?php // Auto-plan info logic remains the same ?>
			<?php if ($auto_plan_id && get_post($auto_plan_id)) : ?>
				 <p id="qp-auto-plan-info">
					 <?php esc_html_e( 'This course automatically manages Plan ID #', 'question-press' ); echo esc_html($auto_plan_id); ?>.
					 <a href="<?php echo esc_url(get_edit_post_link($auto_plan_id)); ?>" target="_blank"><?php esc_html_e( 'View Plan', 'question-press' ); ?></a><br>
					 <?php esc_html_e( 'Ensure your Linked Product above uses this Plan ID.', 'question-press' ); ?>
				 </p>
			<?php elseif ($access_mode === 'requires_purchase') : ?>
				 <p id="qp-auto-plan-info">
					 <?php esc_html_e( 'A Plan will be automatically created/updated when you save this course. Link your WC Product to that Plan ID.', 'question-press' ); ?>
				 </p>
			<?php endif; ?>

		</div>

		<script type="text/javascript"> /* Keep JS here for now */
			jQuery(document).ready(function($) {
				// ... (JS code from original function) ...
				$('#qp_course_access_mode').on('change', function() {
					if ($(this).val() === 'requires_purchase') { $('#qp-purchase-fields').slideDown(200); }
					else { $('#qp-purchase-fields').slideUp(200); }
				}).trigger('change');
			});
		</script>
		<?php
		// --- End HTML Output ---
	}

	/**
	 * Saves the meta box data when the 'qp_course' post type is saved.
	 * Replaces qp_save_course_access_meta().
	 * Hooked to 'save_post_qp_course'.
	 *
	 * @param int $post_id The ID of the post being saved.
	 */
	public static function save_course_access( $post_id ) {
		// Check nonce, permissions, autosave, post type (copied from original function)
		if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) return $post_id;
		if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id)) return $post_id;

		// Save Access Mode (copied from original function)
		$access_mode = isset($_POST['_qp_course_access_mode']) ? sanitize_key($_POST['_qp_course_access_mode']) : 'free';
		update_post_meta($post_id, '_qp_course_access_mode', $access_mode);

		// Save fields only if requires_purchase (copied from original function)
		if ($access_mode === 'requires_purchase') {
			$duration_value = isset($_POST['_qp_course_access_duration_value']) ? absint($_POST['_qp_course_access_duration_value']) : '';
			update_post_meta($post_id, '_qp_course_access_duration_value', $duration_value);
			$duration_unit = isset($_POST['_qp_course_access_duration_unit']) ? sanitize_key($_POST['_qp_course_access_duration_unit']) : 'day';
			update_post_meta($post_id, '_qp_course_access_duration_unit', $duration_unit);
			$product_id = isset($_POST['_qp_linked_product_id']) ? absint($_POST['_qp_linked_product_id']) : '';
			update_post_meta($post_id, '_qp_linked_product_id', $product_id);
		} else {
			delete_post_meta($post_id, '_qp_course_access_duration_value');
			delete_post_meta($post_id, '_qp_course_access_duration_unit');
			delete_post_meta($post_id, '_qp_linked_product_id');
		}
	}

    /**
	 * Adds the meta box container for Course Structure.
	 * Hooked to 'add_meta_boxes'. Note: This hooks on ALL post types.
	 * Replaces qp_add_course_structure_meta_box().
	 */
	public static function add_course_structure() {
		// Only add the box on the 'qp_course' post type screen
		$screen = get_current_screen();
		if ( $screen && $screen->id === 'qp_course' ) {
			add_meta_box(
				'qp_course_structure_meta_box', // Unique ID
				__( 'Course Structure', 'question-press' ), // Box title
				[ self::class, 'render_course_structure' ], // Callback function
				'qp_course',                         // Post type
				'normal',                            // Context
				'high'                               // Priority
			);
		}
	}

	/**
	 * Renders the HTML content for the Course Structure meta box.
	 * Replaces qp_render_course_structure_meta_box().
	 *
	 * @param \WP_Post $post The post object.
	 */
	public static function render_course_structure( $post ) {
		// Add a nonce field for security
		wp_nonce_field( 'qp_save_course_structure_meta', 'qp_course_structure_nonce' );

		// --- Output the HTML (copied directly from original function) ---
		?>
		<div id="qp-course-structure-container">
			<p><?php esc_html_e( 'Define the sections and content items for this course below. Drag and drop to reorder.', 'question-press' ); ?></p>

			<div id="qp-sections-list">
				<?php
				// Placeholder for loading existing sections/items later via JS
				?>
			</div>

			<p>
				<button type="button" id="qp-add-section-btn" class="button button-secondary">
					<span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> <?php esc_html_e( 'Add Section', 'question-press' ); ?>
				</button>
			</p>
		</div>

		<?php // Keep styles here for now ?>
		<style>
			#qp-sections-list .qp-section { /* ... styles ... */ }
            /* ... (Copy ALL styles from the original qp_render_course_structure_meta_box function here) ... */
            border: 1px solid #ccd0d4; margin-bottom: 15px; background: #fff; border-radius: 4px;
            .qp-section-header { padding: 10px 15px; background: #f6f7f7; border-bottom: 1px solid #ccd0d4; cursor: move; display: flex; justify-content: space-between; align-items: center; }
            .qp-section-header h3 { margin: 0; font-size: 1.1em; display: inline-block; }
            .qp-section-title-input { font-size: 1.1em; font-weight: bold; border: none; box-shadow: none; padding: 2px 5px; margin-left: 5px; background: transparent; }
            .qp-section-controls button, .qp-item-controls button { margin-left: 5px; }
            .qp-section-content { padding: 15px; }
            .qp-items-list { margin-left: 10px; border-left: 3px solid #eef2f5; padding-left: 15px; min-height: 30px; }
            .qp-course-item { border: 1px dashed #dcdcde; padding: 10px; margin-bottom: 10px; background: #fdfdfd; border-radius: 3px; }
            .qp-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; cursor: move; padding-bottom: 5px; border-bottom: 1px solid #eee; }
            .qp-item-title-input { font-weight: bold; border: none; box-shadow: none; padding: 2px 5px; background: transparent; flex-grow: 1; }
            .qp-item-config { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
            .qp-config-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px; }
            .qp-config-row label { display: block; font-weight: 500; margin-bottom: 3px; font-size: 0.9em; }
            .qp-config-row select, .qp-config-row input { width: 100%; box-sizing: border-box; }
            .qp-item-config .qp-marks-group { display: flex; gap: 10px; }
            .qp-item-config .qp-marks-group > div { flex: 1; }
		</style>
		<?php
		// --- End HTML Output ---
	}

	/**
	 * Saves the course structure data when the 'qp_course' post type is saved.
	 * Replaces qp_save_course_structure_meta().
	 * Hooked into 'save_post_qp_course'.
	 *
	 * @param int $post_id The ID of the post being saved.
	 */
	public static function save_course_structure( $post_id ) {
		// Check nonce, permissions, autosave, post type (copied from original function)
		if (!isset($_POST['qp_course_structure_nonce']) || !wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) return $post_id;
		if (!current_user_can('edit_post', $post_id)) return $post_id;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
		if ('qp_course' !== get_post_type($post_id)) return $post_id;

		// --- Data processing logic (copied directly from original function) ---
		global $wpdb;
		$sections_table = $wpdb->prefix . 'qp_course_sections';
		$items_table = $wpdb->prefix . 'qp_course_items';
		$progress_table = $wpdb->prefix . 'qp_user_items_progress';

		// 1. Fetch Existing Structure IDs
		$existing_section_ids = $wpdb->get_col( $wpdb->prepare( "SELECT section_id FROM $sections_table WHERE course_id = %d", $post_id ) );
		$existing_item_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT item_id FROM $items_table WHERE course_id = %d", $post_id ) );

		$submitted_section_ids = [];
		$submitted_item_ids = [];
		$processed_item_ids = [];

		// 2. Loop through submitted sections and items: Update or Insert
		if ( isset( $_POST['course_sections'] ) && is_array( $_POST['course_sections'] ) ) {
			foreach ( $_POST['course_sections'] as $section_order => $section_data ) {
				$section_id    = isset( $section_data['section_id'] ) ? absint( $section_data['section_id'] ) : 0;
				$section_title = sanitize_text_field( $section_data['title'] ?? 'Untitled Section' );
				$section_db_data = [ /* ... section data ... */
					'course_id' => $post_id,
					'title' => $section_title,
					'section_order' => $section_order + 1
				];

				if ( $section_id > 0 && in_array( $section_id, $existing_section_ids ) ) {
					// UPDATE
					$wpdb->update( $sections_table, $section_db_data, ['section_id' => $section_id] );
					$submitted_section_ids[] = $section_id;
				} else {
					// INSERT
					$wpdb->insert( $sections_table, $section_db_data );
					$section_id = $wpdb->insert_id;
					if (!$section_id) continue; // Skip items on insert failure
					$submitted_section_ids[] = $section_id;
				}

				// Process Items within this section
				if ( $section_id && isset( $section_data['items'] ) && is_array( $section_data['items'] ) ) {
					foreach ( $section_data['items'] as $item_order => $item_data ) {
						$item_id      = isset( $item_data['item_id'] ) ? absint( $item_data['item_id'] ) : 0;
						$item_title   = sanitize_text_field( $item_data['title'] ?? 'Untitled Item' );
						$content_type = sanitize_key( $item_data['content_type'] ?? 'test_series' );
						$config = [];
						if ($content_type === 'test_series' && isset($item_data['config'])) {
                            // ... (config processing logic from original function) ...
							$raw_config = $item_data['config'];
							$config = [
								'time_limit'      => isset($raw_config['time_limit']) ? absint($raw_config['time_limit']) : 0,
								'scoring_enabled' => isset($raw_config['scoring_enabled']) ? 1 : 0,
								'marks_correct'   => isset($raw_config['marks_correct']) ? floatval($raw_config['marks_correct']) : 1,
								'marks_incorrect' => isset($raw_config['marks_incorrect']) ? floatval($raw_config['marks_incorrect']) : 0,
							];
							if (isset($raw_config['selected_questions']) && !empty($raw_config['selected_questions'])) {
								$question_ids_str = sanitize_text_field($raw_config['selected_questions']);
								$question_ids = array_filter(array_map('absint', explode(',', $question_ids_str)));
								if (!empty($question_ids)) $config['selected_questions'] = $question_ids;
							}
						}
						$item_db_data = [ /* ... item data ... */
							'section_id' => $section_id,
							'course_id' => $post_id,
							'title' => $item_title,
							'item_order' => $item_order + 1,
							'content_type' => $content_type,
							'content_config' => wp_json_encode($config)
						];

						if ( $item_id > 0 && in_array( $item_id, $existing_item_ids ) ) {
							// UPDATE
							$wpdb->update( $items_table, $item_db_data, ['item_id' => $item_id] );
							$submitted_item_ids[] = $item_id;
							$processed_item_ids[] = $item_id;
						} else {
							// INSERT
							$wpdb->insert( $items_table, $item_db_data );
							$new_item_id = $wpdb->insert_id;
							if ($new_item_id) {
								$submitted_item_ids[] = $new_item_id;
								$processed_item_ids[] = $new_item_id;
							}
						}
					} // end foreach item
				} // end if section_id and items exist
			} // end foreach section
		} // end if course_sections exist

		// 3. Identify Sections and Items to Delete
		$section_ids_to_delete = array_diff( $existing_section_ids, $submitted_section_ids );
		$item_ids_to_delete    = array_diff( $existing_item_ids, $processed_item_ids );

		// 4. Clean up User Progress for Deleted Items
		if ( ! empty( $item_ids_to_delete ) ) {
			$ids_placeholder = implode( ',', array_map( 'absint', $item_ids_to_delete ) );
			$wpdb->query( "DELETE FROM $progress_table WHERE item_id IN ($ids_placeholder)" );
		}

		// 5. Delete Orphaned Items
		if ( ! empty( $item_ids_to_delete ) ) {
			$ids_placeholder = implode( ',', array_map( 'absint', $item_ids_to_delete ) );
			$wpdb->query( "DELETE FROM $items_table WHERE item_id IN ($ids_placeholder)" );
		}

		// 6. Delete Orphaned Sections
		if ( ! empty( $section_ids_to_delete ) ) {
			$ids_placeholder = implode( ',', array_map( 'absint', $section_ids_to_delete ) );
			$wpdb->query( "DELETE FROM $sections_table WHERE section_id IN ($ids_placeholder)" );
		}
		// --- End data processing logic ---
	}

	/**
     * Automatically creates or updates a qp_plan post based on course settings.
     * Triggered after the course meta is saved.
     *
     * @param int $post_id The ID of the qp_course post being saved.
     */
    public static function sync_course_plan( $post_id ) {
        // Basic checks (already done in qp_save_course_access_meta, but good practice)
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'qp_course' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // Verify nonce again, just to be safe, using the nonce from the access meta save
        if ( ! isset( $_POST['qp_course_access_nonce'] ) || ! wp_verify_nonce( $_POST['qp_course_access_nonce'], 'qp_save_course_access_meta' ) ) {
            return;
        }

        $access_mode = get_post_meta( $post_id, '_qp_course_access_mode', true );

        // Only proceed if the course requires purchase
        if ( $access_mode !== 'requires_purchase' ) {
            // Optional: If switched from paid to free, we could potentially update the linked plan's status,
            // but for now, we'll just leave the plan as is to preserve access for past purchasers.
            return;
        }

        // Get the course details needed for the plan
        $course_title     = get_the_title( $post_id );
        $duration_value   = get_post_meta( $post_id, '_qp_course_access_duration_value', true );
        $duration_unit    = get_post_meta( $post_id, '_qp_course_access_duration_unit', true );
        $existing_plan_id = get_post_meta( $post_id, '_qp_course_auto_plan_id', true );

        // Determine plan type based on duration
        $plan_type = ! empty( $duration_value ) ? 'time_limited' : 'unlimited'; // Course access implies unlimited attempts

        // Prepare plan post data
        $plan_post_args = [
            'post_title'   => 'Auto: Access Plan for Course "' . $course_title . '"',
            'post_content' => '', // Content not needed
            'post_status'  => 'publish', // Auto-publish the plan
            'post_type'    => 'qp_plan',
            'meta_input'   => [ // Use meta_input for direct meta saving/updating
                '_qp_is_auto_generated'       => 'true', // Flag this as auto-managed
                '_qp_plan_type'               => $plan_type,
                '_qp_plan_duration_value'     => ! empty( $duration_value ) ? absint( $duration_value ) : null,
                '_qp_plan_duration_unit'      => ! empty( $duration_value ) ? sanitize_key( $duration_unit ) : null,
                '_qp_plan_attempts'           => null, // Course access plans grant unlimited attempts within duration
                '_qp_plan_course_access_type' => 'specific',
                '_qp_plan_linked_courses'     => [ $post_id ], // Link specifically to this course ID
                // '_qp_plan_description' => 'Automatically generated plan for ' . $course_title, // Optional description
            ],
        ];

        $plan_id_to_save = 0;

        // Check if a plan already exists and is valid
        if ( ! empty( $existing_plan_id ) ) {
            $existing_plan_post = get_post( $existing_plan_id );
            // Check if the post exists and is indeed a qp_plan
            if ( $existing_plan_post && $existing_plan_post->post_type === 'qp_plan' ) {
                // Update existing plan
                $plan_post_args['ID'] = $existing_plan_id; // Add ID for update
                $updated_plan_id      = wp_update_post( $plan_post_args, true ); // true returns WP_Error on failure
                if ( ! is_wp_error( $updated_plan_id ) ) {
                    $plan_id_to_save = $updated_plan_id;
                    error_log( "QP Auto Plan: Updated Plan ID #{$plan_id_to_save} for Course ID #{$post_id}" );
                } else {
                    error_log( "QP Auto Plan: FAILED to update Plan ID #{$existing_plan_id} for Course ID #{$post_id}. Error: " . $updated_plan_id->get_error_message() );
                }
            } else {
                // The linked ID was invalid, clear it and create a new one
                delete_post_meta( $post_id, '_qp_course_auto_plan_id' );
                $existing_plan_id = 0; // Force creation below
            }
        }

        // Create new plan if no valid existing one was found/updated
        if ( empty( $plan_id_to_save ) && empty( $existing_plan_id ) ) {
            $new_plan_id = wp_insert_post( $plan_post_args, true ); // true returns WP_Error on failure
            if ( ! is_wp_error( $new_plan_id ) ) {
                $plan_id_to_save = $new_plan_id;
                // Save the new plan ID back to the course meta
                update_post_meta( $post_id, '_qp_course_auto_plan_id', $plan_id_to_save );
                error_log( "QP Auto Plan: CREATED Plan ID #{$plan_id_to_save} for Course ID #{$post_id}" );
            } else {
                error_log( "QP Auto Plan: FAILED to create new Plan for Course ID #{$post_id}. Error: " . $new_plan_id->get_error_message() );
            }
        }

    }

} // End class Meta_Boxes