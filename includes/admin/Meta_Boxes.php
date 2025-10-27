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

	// --- We will add Course Meta Box methods here later ---

} // End class Meta_Boxes