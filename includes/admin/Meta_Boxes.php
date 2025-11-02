<?php

namespace QuestionPress\Admin;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Handles registration, rendering, and saving of custom meta boxes.
 */
class Meta_Boxes
{

	// --- qp_plan Meta Box ---

	/**
	 * Adds the meta box container for Plan Details.
	 * Hooked to 'add_meta_boxes_qp_plan'.
	 * Replaces qp_add_plan_details_meta_box().
	 */
	public static function add_plan_details()
	{
		add_meta_box(
			'qp_plan_details_meta_box',           // Unique ID
			__('Plan Details', 'question-press'), // Box title
			[self::class, 'render_plan_details'],    // Callback function (static method in this class)
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
	public static function render_plan_details($post)
	{
		// Add a nonce field for security
		wp_nonce_field('qp_save_plan_details_meta', 'qp_plan_details_nonce');

		// Get existing meta values
		$plan_type = get_post_meta($post->ID, '_qp_plan_type', true);
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
		<style>
			/* Keep styles here for now */
			.qp-plan-meta-box table {
				width: 100%;
				border-collapse: collapse;
			}

			/* ... rest of the styles ... */
			.qp-plan-meta-box th,
			.qp-plan-meta-box td {
				text-align: left;
				padding: 10px;
				border-bottom: 1px solid #eee;
				vertical-align: top;
			}

			.qp-plan-meta-box th {
				width: 150px;
				font-weight: 600;
			}

			.qp-plan-meta-box select,
			.qp-plan-meta-box input[type="number"],
			.qp-plan-meta-box textarea {
				width: 100%;
				max-width: 350px;
				box-sizing: border-box;
			}

			.qp-plan-meta-box .description {
				font-size: 0.9em;
				color: #666;
			}

			.qp-plan-meta-box .conditional-field {
				display: none;
			}

			/* Hide conditional fields initially */
			.qp-plan-meta-box .course-select-list {
				max-height: 200px;
				overflow-y: auto;
				border: 1px solid #ddd;
				padding: 10px;
				background: #fff;
			}

			.qp-plan-meta-box .course-select-list label {
				display: block;
				margin-bottom: 5px;
			}
		</style>

		<div class="qp-plan-meta-box">
			<table>
				<tbody>
					<?php // --- ADDED: Check if this is an auto-generated plan ---
					$is_auto_plan = get_post_meta($post->ID, '_qp_is_auto_generated', true) === 'true';
					if ($is_auto_plan) :
						$course_id_arr = get_post_meta($post->ID, '_qp_plan_linked_courses', true);
						$course_id     = (is_array($course_id_arr) && ! empty($course_id_arr)) ? $course_id_arr[0] : 0;
					?>
						<tr>
							<td colspan="2">
								<div class="notice notice-info inline" style="margin: 0;">
									<p><strong><?php esc_html_e('Auto-Generated Plan', 'question-press'); ?></strong><br>
										<?php esc_html_e('This plan is automatically managed by its associated course and cannot be edited here.', 'question-press'); ?>
										<?php if ($course_id && get_post($course_id)) : ?>
											<br><a href="<?php echo esc_url(get_edit_post_link($course_id)); ?>" class="button button-small" style="margin-top: 10px;"><?php esc_html_e('Edit Linked Course', 'question-press'); ?></a>
										<?php endif; ?>
									</p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
					<tr <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
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

					<tr class="conditional-field" data-depends-on="time_limited combined" <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
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
					<?php // ... rest of the table rows from original function ... 
					?>
					<tr class="conditional-field" data-depends-on="attempt_limited combined" <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
						<th><label for="qp_plan_attempts">Number of Attempts</label></th>
						<td>
							<input type="number" name="_qp_plan_attempts" id="qp_plan_attempts" value="<?php echo esc_attr($attempts); ?>" min="1">
							<p class="description">How many attempts the user gets with this plan.</p>
						</td>
					</tr>
					<tr class="conditional-field" data-depends-on="course_access combined" <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
						<th><label for="qp_plan_course_access_type">Course Access</label></th>
						<td>
							<select name="_qp_plan_course_access_type" id="qp_plan_course_access_type">
								<option value="all" <?php selected($course_access_type, 'all'); ?>>All Courses</option>
								<option value="specific" <?php selected($course_access_type, 'specific'); ?>>Specific Courses</option>
							</select>
						</td>
					</tr>
					<tr class="conditional-field" data-depends-on="course_access combined" data-sub-depends-on="specific" <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
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
					<tr <?php if ($is_auto_plan) echo 'style="display:none;"'; ?>>
						<th><label for="qp_plan_description">Description</label></th>
						<td>
							<textarea name="_qp_plan_description" id="qp_plan_description" rows="3"><?php echo esc_textarea($description); ?></textarea>
							<p class="description">Optional user-facing description (e.g., for display on product page or user dashboard).</p>
						</td>
					</tr>
				</tbody>
			</table>
		<?php if ( ! $is_auto_plan && class_exists( 'WooCommerce' ) ) : ?>
            <?php
            $auto_product_id = get_post_meta( $post->ID, '_qp_auto_product_id', true );
            $product_post = $auto_product_id ? get_post( $auto_product_id ) : null;
            $product_regular_price = '';
            $product_sale_price = '';
            if ( $auto_product_id && $product_post ) {
                $product = wc_get_product( $auto_product_id );
                if ( $product ) {
                    $product_regular_price = $product->get_regular_price();
                    $product_sale_price = $product->get_sale_price();
                }
            }
            ?>
            <div id="qp-auto-product-box" style="margin-top: 20px; padding: 12px; border: 1px solid #ccd0d4; background: #fdfdfd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px; padding: 0 0 10px; border-bottom: 1px solid #eee;"><?php esc_html_e( 'Auto-Linked Product', 'question-press' ); ?></h3>

                <?php if ( $auto_product_id && $product_post ) : ?>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <div>
                            <strong style="font-size: 1.1em;"><?php echo esc_html( $product_post->post_title ); ?></strong>
                            (ID: <?php echo esc_html( $auto_product_id ); ?>)
                        </div>
                        <a href="<?php echo esc_url( get_edit_post_link( $auto_product_id ) ); ?>" class="button button-secondary button-small" target="_blank"><?php esc_html_e('Edit Product', 'question-press'); ?></a>
                    </div>

                    <div class="qp-price-fields-group" style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <div style="flex: 1;">
                            <label for="_qp_product_regular_price" style="display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 3px;"><?php esc_html_e( 'Regular Price', 'question-press' ); ?></label>
                            <input type="text" id="_qp_product_regular_price" name="_qp_product_regular_price" value="<?php echo esc_attr( $product_regular_price ); ?>" class="wc_input_price" placeholder="e.g., 20.00" style="width: 100%;">
                        </div>
                        <div style="flex: 1;">
                            <label for="_qp_product_sale_price" style="display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 3px;"><?php esc_html_e( 'Sale Price', 'question-press' ); ?></label>
                            <input type="text" id="_qp_product_sale_price" name="_qp_product_sale_price" value="<?php echo esc_attr( $product_sale_price ); ?>" class="wc_input_price" placeholder="Optional" style="width: 100%;">
                        </div>
                    </div>
                    <div>
                        <button type="button" class="button button-primary" id="qp-save-product-price-btn" 
                                data-product-id="<?php echo esc_attr($auto_product_id); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('qp_save_product_price_nonce')); ?>">
                            <?php esc_html_e( 'Save Price', 'question-press' ); ?>
                        </button>
                        <span id="qp-price-save-success" style="color: #2e7d32; font-weight: 600; margin-left: 10px;"></span>
                    </div>
                <?php elseif ( $plan_type !== '' ) : ?>
                    <p style="margin: 0; font-style: italic; color: #666;">
                        <?php esc_html_e( 'A new WooCommerce product will be automatically created and linked when you save this plan.', 'question-press' ); ?>
                    </p>
                <?php else: ?>
                     <p style="margin: 0; font-style: italic; color: #666;">
                        <?php esc_html_e( 'Please select a "Plan Type" and save to generate a product.', 'question-press' ); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>

		<script type="text/javascript">
			/* Keep JS here for now */
			jQuery(document).ready(function($) {
				// Hide conditional fields if it's an auto-plan
				if (<?php echo $is_auto_plan ? 'true' : 'false'; ?>) {
					$('.qp-plan-meta-box .conditional-field').hide();
				}

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

						if (show) {
							$fieldRow.slideDown(200);
						} else {
							$fieldRow.slideUp(200);
						}
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
	public static function save_plan_details($post_id)
	{
		// Check nonce, permissions, autosave, post type (copied from original function)
		if (!isset($_POST['qp_plan_details_nonce']) || !wp_verify_nonce($_POST['qp_plan_details_nonce'], 'qp_save_plan_details_meta')) return $post_id;
		if (!current_user_can('edit_post', $post_id)) return $post_id;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
		if ('qp_plan' !== get_post_type($post_id)) return $post_id;

		// --- ADDED: Prevent editing auto-generated plans ---
		if (get_post_meta($post_id, '_qp_is_auto_generated', true) === 'true') {
			return $post_id; // Do not save any changes
		}
		// --- END ADDED ---

		// Sanitize and save meta fields (copied from original function)
		$fields_to_save = [ /* ... fields ... */
			'_qp_plan_type' => 'sanitize_key',
			'_qp_plan_duration_value' => 'absint',
			'_qp_plan_duration_unit' => 'sanitize_key',
			'_qp_plan_course_access_type' => 'sanitize_key',
			'_qp_plan_description' => 'sanitize_textarea_field',
		];
		// Manual Handling for Plan Attempts 
		$plan_type = isset($_POST['_qp_plan_type']) ? sanitize_key($_POST['_qp_plan_type']) : '';
		if ( $plan_type === 'unlimited' ) {
			// Unlimited plan = NULL attempts
			update_post_meta($post_id, '_qp_plan_attempts', null);
		} elseif ( $plan_type === 'course_access' ) {
			// Course Access plan = 0 attempts
			update_post_meta($post_id, '_qp_plan_attempts', 0);
		} elseif ( $plan_type === 'attempt_limited' || $plan_type === 'combined' ) {
			// Attempt/Combined plan = value from form
			$attempts = isset($_POST['_qp_plan_attempts']) ? absint($_POST['_qp_plan_attempts']) : 0;
			update_post_meta($post_id, '_qp_plan_attempts', $attempts);
		} else {
			// No type or other type, save as 0
			update_post_meta($post_id, '_qp_plan_attempts', 0);
		}
		foreach ($fields_to_save as $meta_key => $sanitize_func) {
			if (isset($_POST[$meta_key])) {
				$value = call_user_func($sanitize_func, $_POST[$meta_key]);
				if (($sanitize_func === 'absint') && $value === 0 && !isset($_POST[$meta_key])) {
					delete_post_meta($post_id, $meta_key);
					continue;
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
	public static function add_course_access()
	{
		add_meta_box(
			'qp_course_access_meta_box',          // Unique ID
			__('Course Access & Monetization', 'question-press'), // Box title
			[self::class, 'render_course_access'],   // Callback function (static method in this class)
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
	public static function render_course_access($post)
	{
		// Add a nonce field for security
		wp_nonce_field('qp_save_course_access_meta', 'qp_course_access_nonce');

		// Get existing meta values
		$access_mode = get_post_meta($post->ID, '_qp_course_access_mode', true) ?: 'free'; // Default to free
		$duration_value = get_post_meta($post->ID, '_qp_course_access_duration_value', true);
		$duration_unit = get_post_meta($post->ID, '_qp_course_access_duration_unit', true) ?: 'day';
		$linked_product_id = get_post_meta($post->ID, '_qp_linked_product_id', true);
		$auto_plan_id = get_post_meta($post->ID, '_qp_course_auto_plan_id', true);
		$expiry_date = get_post_meta($post->ID, '_qp_course_expiry_date', true);

		$product_regular_price = '';
		$product_sale_price = '';
		if ($linked_product_id && class_exists('WooCommerce')) {
			$product = wc_get_product($linked_product_id);
			if ($product) {
				$product_regular_price = $product->get_regular_price();
				$product_sale_price = $product->get_sale_price();
			}
		}

		// --- NEW: Check if the linked product is auto-generated ---
		$is_auto_product_linked = false;
		if (! empty($linked_product_id) && get_post_meta($linked_product_id, '_qp_is_auto_generated', true) === 'true') {
			$is_auto_product_linked = true;
		}
		// --- END NEW ---

		// Get all published WooCommerce products for selection
		$products = [];
		if (class_exists('WooCommerce') && ! $is_auto_product_linked) { // Only query if we need the dropdown
			$products = wc_get_products([
				'status' => 'publish',
				'limit' => -1,
				'orderby' => 'title',
				'order' => 'ASC',
				'return' => 'objects',
			]);
		}

		// --- Output the HTML ---
	?>
		<style>
			#qp_course_access_meta_box p {
				margin-bottom: 15px;
			}

			#qp_course_access_meta_box label {
				font-weight: 600;
				display: block;
				margin-bottom: 5px;
			}

			#qp_course_access_meta_box select,
			#qp_course_access_meta_box input[type="number"] {
				width: 100%;
				box-sizing: border-box;
				margin-bottom: 5px;
			}

			#qp_course_access_meta_box .duration-group {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			#qp_course_access_meta_box .duration-group input[type="number"] {
				width: 80px;
				flex-shrink: 0;
			}

			#qp_course_access_meta_box .duration-group select {
				flex-grow: 1;
			}

			#qp-purchase-fields {
				display: <?php echo ($access_mode === 'requires_purchase') ? 'block' : 'none'; ?>;
				margin-top: 15px;
				border-top: 1px solid #eee;
				padding-top: 0px;
			}

			#qp_course_access_meta_box small.description {
				font-size: 0.9em;
				color: #666;
				display: block;
				margin-top: 3px;
			}

			#qp-auto-plan-info {
				font-style: italic;
				color: #666;
				font-size: 0.9em;
				margin-top: 10px;
				padding-top: 10px;
				border-top: 1px dashed #ddd;
			}

			#qp-auto-product-info {
				font-style: italic;
				color: #666;
				font-size: 0.9em;
				margin-top: 10px;
				padding: 10px;
				border-top: 1px dashed #ddd;
				background: #fdfdfd;
			}
		</style>

		<p style="margin-bottom: 0;">
			<label for="qp_course_access_mode"><?php esc_html_e('Access Mode:', 'question-press'); ?></label>
			<select name="_qp_course_access_mode" id="qp_course_access_mode">
				<option value="free" <?php selected($access_mode, 'free'); ?>><?php esc_html_e('Free (Public Enrollment)', 'question-press'); ?></option>
				<option value="requires_purchase" <?php selected($access_mode, 'requires_purchase'); ?>><?php esc_html_e('Requires Purchase', 'question-press'); ?></option>
			</select>
		</p>

		<p class="qp-expiry-date-field" style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">
			<label for="qp_course_expiry_date"><?php esc_html_e('Expiry Date (Optional):', 'question-press'); ?></label>
			<input type="text" name="_qp_course_expiry_date" id="qp_course_expiry_date" class="qp-datepicker" value="<?php echo esc_attr($expiry_date); ?>" placeholder="YYYY-MM-DD" autocomplete="off">
			<small class="description"><?php esc_html_e('After this date, the course and its product will be unpublished, and all active entitlements will expire.', 'question-press'); ?></small>
		</p>

		<div id="qp-purchase-fields" style="display: <?php echo ($access_mode === 'requires_purchase') ? 'block' : 'none'; ?>;">
			<p>
				<label><?php esc_html_e('Access Duration:', 'question-press'); ?></label>
			<div class="duration-group">
				<input type="number" name="_qp_course_access_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="0" placeholder="e.g., 30">
				<select name="_qp_course_access_duration_unit">
					<option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
					<option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
					<option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
				</select>
			</div>
			<small class="description"><?php esc_html_e('How long access lasts after purchase. Leave blank for lifetime access.', 'question-press'); ?></small>
			</p>

			<?php // Only show product link if WooCommerce is active 
			?>
			<?php if (class_exists('WooCommerce')) : ?>

				<?php // --- REFINED: Show auto-product info or auto-creation text ---
				if ($is_auto_product_linked && $linked_product_id) :
					$product_post = get_post($linked_product_id);
				?>
					<div id="qp-auto-product-info" style="padding: 10px; background: #fdfdfd; border: 1px solid #ddd; border-radius: 4px;">

						<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
							<div>
								<strong style="font-size: 1.1em;"><?php esc_html_e('Product (Auto-Linked)', 'question-press'); ?></strong><br>
								<?php if ($product_post) : ?>
									<span><?php echo esc_html($product_post->post_title); ?> (ID: <?php echo esc_html($linked_product_id); ?>)</span>
								<?php endif; ?>
							</div>
							<a href="<?php echo esc_url(get_edit_post_link($linked_product_id)); ?>" class="button button-secondary button-small" target="_blank"><?php esc_html_e('Edit Product', 'question-press'); ?></a>
						</div>

						<?php if ($product_post) : ?>
							<div class="qp-price-fields-group" style="display: flex; gap: 10px; margin-bottom: 10px;">
								<div style="flex: 1;">
									<label for="_qp_product_regular_price" style="display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 3px;"><?php esc_html_e('Regular Price', 'question-press'); ?></label>
									<input type="text" id="_qp_product_regular_price" name="_qp_product_regular_price" value="<?php echo esc_attr($product_regular_price); ?>" class="wc_input_price" placeholder="e.g., 20.00" style="width: 100%;">
								</div>
								<div style="flex: 1;">
									<label for="_qp_product_sale_price" style="display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 3px;"><?php esc_html_e('Sale Price', 'question-press'); ?></label>
									<input type="text" id="_qp_product_sale_price" name="_qp_product_sale_price" value="<?php echo esc_attr($product_sale_price); ?>" class="wc_input_price" placeholder="Optional" style="width: 100%;">
								</div>
							</div>
							<div>
								<button type="button" class="button button-primary" id="qp-save-product-price-btn"
									data-product-id="<?php echo esc_attr($linked_product_id); ?>"
									data-nonce="<?php echo esc_attr(wp_create_nonce('qp_save_product_price_nonce')); ?>">
									<?php esc_html_e('Save Price', 'question-press'); ?>
								</button>
								<span id="qp-price-save-success" style="color: #2e7d32; font-weight: 600; margin-left: 10px;"></span>
							</div>

						<?php else : ?>
							<span style="color: red;"><?php esc_html_e('Linked product (ID: ', 'question-press');
														echo esc_html($linked_product_id);
														esc_html_e(') not found.', 'question-press'); ?></span>
						<?php endif; ?>
						<input type="hidden" name="_qp_linked_product_id" value="<?php echo esc_attr($linked_product_id); ?>">
					</div>

				<?php // NEW: Show auto-generation text if no product is linked
				else : ?>
					<div id="qp-auto-product-info-new" style="padding: 10px; background: #fdfdfd; border: 1px solid #ddd; border-radius: 4px;">
						<p style="margin: 0; font-style: italic; color: #666;">
							<?php esc_html_e('A new WooCommerce product will be automatically created and linked when you save this course.', 'question-press'); ?>
						</p>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<p><small class="description"><?php esc_html_e('Install and activate WooCommerce to link products for purchase.', 'question-press'); ?></small></p>
			<?php endif; ?>

			<?php // Auto-plan info logic - REFINED 
			?>
			<?php if ($auto_plan_id && get_post($auto_plan_id)) : ?>
				<div id="qp-auto-plan-info" style="padding: 10px; background: #fdfdfd; border: 1px solid #ddd; border-radius: 4px; margin-top: 10px;">
					<strong><?php esc_html_e('Plan (Auto-Linked)', 'question-press'); ?></strong><br>
					<?php echo esc_html(get_the_title($auto_plan_id)); ?> (ID: <?php echo esc_html($auto_plan_id); ?>)
					<br>
					<a href="<?php echo esc_url(get_edit_post_link($auto_plan_id)); ?>" class="button button-secondary button-small" target="_blank" style="margin-top: 5px;"><?php esc_html_e('View Plan', 'question-press'); ?></a>
				</div>
			<?php elseif ($access_mode === 'requires_purchase') : ?>
				<div id="qp-auto-plan-info" style="padding: 10px; background: #fdfdfd; border: 1px solid #ddd; border-radius: 4px; margin-top: 10px;">
					<p style="margin: 0; font-style: italic; color: #666;">
						<?php esc_html_e('An Access Plan will be automatically created/updated when you save this course.', 'question-press'); ?>
					</p>
				</div>
			<?php endif; ?>

		</div>

		<script type="text/javascript">
			/* Keep JS here for now */
			jQuery(document).ready(function($) {
				// ... (JS code from original function) ...
				$('#qp_course_access_mode').on('change', function() {
					if ($(this).val() === 'requires_purchase') {
						$('#qp-purchase-fields').slideDown(200);
					} else {
						$('#qp-purchase-fields').slideUp(200);
					}
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
	public static function save_course_access($post_id)
	{
		// Check nonce, permissions, autosave, post type (copied from original function)
		if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) return $post_id;
		if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id)) return $post_id;

		// Save Access Mode (copied from original function)
		$access_mode = isset($_POST['_qp_course_access_mode']) ? sanitize_key($_POST['_qp_course_access_mode']) : 'free';
		update_post_meta($post_id, '_qp_course_access_mode', $access_mode);

		// Save Expiry Date
		if (isset($_POST['_qp_course_expiry_date'])) {
			// Validate that it's a real date or an empty string
			$expiry_date_input = sanitize_text_field($_POST['_qp_course_expiry_date']);
			if (! empty($expiry_date_input) && \DateTime::createFromFormat('Y-m-d', $expiry_date_input) !== false) {
				update_post_meta($post_id, '_qp_course_expiry_date', $expiry_date_input);
			} else {
				// If it's empty or invalid, delete the meta
				delete_post_meta($post_id, '_qp_course_expiry_date');
			}
		}

		// Save fields only if requires_purchase (copied from original function)
		if ($access_mode === 'requires_purchase') {
			$duration_value = isset($_POST['_qp_course_access_duration_value']) ? absint($_POST['_qp_course_access_duration_value']) : '';
			update_post_meta($post_id, '_qp_course_access_duration_value', $duration_value);
			$duration_unit = isset($_POST['_qp_course_access_duration_unit']) ? sanitize_key($_POST['_qp_course_access_duration_unit']) : 'day';
			update_post_meta($post_id, '_qp_course_access_duration_unit', $duration_unit);

			// --- UPDATED: Only save product ID if it's NOT an auto-product ---
			// (The auto-product ID is hidden and saved automatically, or set by sync_course_plan)
			if (isset($_POST['_qp_linked_product_id'])) {
				$product_id = absint($_POST['_qp_linked_product_id']);
				// Check if this ID is for an auto-product. If it is, trust the value.
				// If it's NOT (i.e., it's from the dropdown), then save it.
				$is_auto_product = get_post_meta($product_id, '_qp_is_auto_generated', true) === 'true';
				if (! $is_auto_product) {
					// This came from the manual dropdown, so save it
					update_post_meta($post_id, '_qp_linked_product_id', $product_id);
				}
				// If it *was* an auto-product, its hidden field value will be saved, which is correct.
			}
			// --- END UPDATED ---

		} else {
			delete_post_meta($post_id, '_qp_course_access_duration_value');
			delete_post_meta($post_id, '_qp_course_access_duration_unit');

			// --- UPDATED: Only delete the link if it's NOT an auto-product ---
			// (We want to keep the link to the auto-product so we can re-publish it later)
			$linked_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
			if (! empty($linked_product_id) && get_post_meta($linked_product_id, '_qp_is_auto_generated', true) !== 'true') {
				// It's a manually linked product, so remove the link
				delete_post_meta($post_id, '_qp_linked_product_id');
			}
			// --- END UPDATED ---
		}
	}

	/**
	 * Adds the meta box container for Course Structure.
	 * Hooked to 'add_meta_boxes'. Note: This hooks on ALL post types.
	 * Replaces qp_add_course_structure_meta_box().
	 */
	public static function add_course_structure()
	{
		// Only add the box on the 'qp_course' post type screen
		$screen = get_current_screen();
		if ($screen && $screen->id === 'qp_course') {
			add_meta_box(
				'qp_course_structure_meta_box', // Unique ID
				__('Course Structure', 'question-press'), // Box title
				[self::class, 'render_course_structure'], // Callback function
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
	public static function render_course_structure($post)
	{
		// Add a nonce field for security
		wp_nonce_field('qp_save_course_structure_meta', 'qp_course_structure_nonce');

		// --- Output the HTML (copied directly from original function) ---
	?>
		<div id="qp-course-structure-container">
			<p><?php esc_html_e('Define the sections and content items for this course below. Drag and drop to reorder.', 'question-press'); ?></p>

			<div id="qp-sections-list">
				<?php
				// Placeholder for loading existing sections/items later via JS
				?>
			</div>

			<p>
				<button type="button" id="qp-add-section-btn" class="button button-secondary">
					<span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> <?php esc_html_e('Add Section', 'question-press'); ?>
				</button>
			</p>
		</div>

		<?php // Keep styles here for now 
		?>
		<style>
			#qp-sections-list .qp-section {
				border: 1px solid #ccd0d4;
				margin-bottom: 15px;
				background: #fff;
				border-radius: 4px;
				/* ... styles ... */
			}

			/* ... (Copy ALL styles from the original qp_render_course_structure_meta_box function here) ... */

			.qp-section-header {
				padding: 10px 15px;
				background: #f6f7f7;
				border-bottom: 1px solid #ccd0d4;
				cursor: move;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}

			.qp-section-header h3 {
				margin: 0;
				font-size: 1.1em;
				display: inline-block;
			}

			.qp-section-title-input {
				font-size: 1.1em;
				font-weight: bold;
				border: none;
				box-shadow: none;
				padding: 2px 5px;
				margin-left: 5px;
				background: transparent;
			}

			.qp-section-controls button,
			.qp-item-controls button {
				margin-left: 5px;
			}

			.qp-section-content {
				padding: 15px;
			}

			.qp-items-list {
				margin-left: 10px;
				border-left: 3px solid #eef2f5;
				padding-left: 15px;
				min-height: 30px;
			}

			.qp-course-item {
				border: 1px dashed #dcdcde;
				padding: 10px;
				margin-bottom: 10px;
				background: #fdfdfd;
				border-radius: 3px;
			}

			.qp-item-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 10px;
				cursor: move;
				padding-bottom: 5px;
				border-bottom: 1px solid #eee;
			}

			.qp-item-title-input {
				font-weight: bold;
				border: none;
				box-shadow: none;
				padding: 2px 5px;
				background: transparent;
				flex-grow: 1;
			}

			.qp-item-config {
				margin-top: 10px;
				padding-top: 10px;
				border-top: 1px solid #eee;
			}

			.qp-config-row {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 10px;
				margin-bottom: 10px;
			}

			.qp-config-row label {
				display: block;
				font-weight: 500;
				margin-bottom: 3px;
				font-size: 0.9em;
			}

			.qp-config-row select,
			.qp-config-row input {
				width: 100%;
				box-sizing: border-box;
			}

			.qp-item-config .qp-marks-group {
				display: flex;
				gap: 10px;
			}

			.qp-item-config .qp-marks-group>div {
				flex: 1;
			}
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
	public static function save_course_structure($post_id)
	{
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
		$existing_section_ids = $wpdb->get_col($wpdb->prepare("SELECT section_id FROM $sections_table WHERE course_id = %d", $post_id));
		$existing_item_ids    = $wpdb->get_col($wpdb->prepare("SELECT item_id FROM $items_table WHERE course_id = %d", $post_id));

		$submitted_section_ids = [];
		$submitted_item_ids = [];
		$processed_item_ids = [];

		// 2. Loop through submitted sections and items: Update or Insert
		if (isset($_POST['course_sections']) && is_array($_POST['course_sections'])) {
			foreach ($_POST['course_sections'] as $section_order => $section_data) {
				$section_id    = isset($section_data['section_id']) ? absint($section_data['section_id']) : 0;
				$section_title = sanitize_text_field($section_data['title'] ?? 'Untitled Section');
				$section_db_data = [ /* ... section data ... */
					'course_id' => $post_id,
					'title' => $section_title,
					'section_order' => $section_order + 1
				];

				if ($section_id > 0 && in_array($section_id, $existing_section_ids)) {
					// UPDATE
					$wpdb->update($sections_table, $section_db_data, ['section_id' => $section_id]);
					$submitted_section_ids[] = $section_id;
				} else {
					// INSERT
					$wpdb->insert($sections_table, $section_db_data);
					$section_id = $wpdb->insert_id;
					if (!$section_id) continue; // Skip items on insert failure
					$submitted_section_ids[] = $section_id;
				}

				// Process Items within this section
				if ($section_id && isset($section_data['items']) && is_array($section_data['items'])) {
					foreach ($section_data['items'] as $item_order => $item_data) {
						$item_id      = isset($item_data['item_id']) ? absint($item_data['item_id']) : 0;
						$item_title   = sanitize_text_field($item_data['title'] ?? 'Untitled Item');
						$content_type = sanitize_key($item_data['content_type'] ?? 'test_series');
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

						if ($item_id > 0 && in_array($item_id, $existing_item_ids)) {
							// UPDATE
							$wpdb->update($items_table, $item_db_data, ['item_id' => $item_id]);
							$submitted_item_ids[] = $item_id;
							$processed_item_ids[] = $item_id;
						} else {
							// INSERT
							$wpdb->insert($items_table, $item_db_data);
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
		$section_ids_to_delete = array_diff($existing_section_ids, $submitted_section_ids);
		$item_ids_to_delete    = array_diff($existing_item_ids, $processed_item_ids);

		// 4. Clean up User Progress for Deleted Items
		if (! empty($item_ids_to_delete)) {
			$ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
			$wpdb->query("DELETE FROM $progress_table WHERE item_id IN ($ids_placeholder)");
		}

		// 5. Delete Orphaned Items
		if (! empty($item_ids_to_delete)) {
			$ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
			$wpdb->query("DELETE FROM $items_table WHERE item_id IN ($ids_placeholder)");
		}

		// 6. Delete Orphaned Sections
		if (! empty($section_ids_to_delete)) {
			$ids_placeholder = implode(',', array_map('absint', $section_ids_to_delete));
			$wpdb->query("DELETE FROM $sections_table WHERE section_id IN ($ids_placeholder)");
		}
		// --- End data processing logic ---
	}

	/**
	 * Automatically creates/updates a qp_plan AND a wc_product based on course settings.
	 * Triggered after the course meta is saved.
	 *
	 * @param int $post_id The ID of the qp_course post being saved.
	 */
	public static function sync_course_plan($post_id)
	{
		// Basic checks
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
			return;
		}

		// We rely on the 'save_post' hook's capability checks, so we remove the
		// specific nonce check to allow this to run during Quick Edit.

		$access_mode = get_post_meta($post_id, '_qp_course_access_mode', true);
		$existing_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);

		// --- Get linked product ID and check if it's ours ---
		$existing_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
		$is_auto_product = $existing_product_id ? get_post_meta($existing_product_id, '_qp_is_auto_generated', true) === 'true' : false;

		// Get the post status
		$post_status = get_post_status($post_id);

		// Get the course details needed for the plan/product
		$course_title     = get_the_title($post_id);
		$duration_value   = get_post_meta($post_id, '_qp_course_access_duration_value', true);
		$duration_unit    = get_post_meta($post_id, '_qp_course_access_duration_unit', true);
		$plan_type = 'combined'; // Use 'combined' to properly scope access

		// Prepare plan post data
		$plan_post_args = [
			'post_title'   => 'Auto: Access Plan for Course "' . $course_title . '"',
			'post_content' => '',
			'post_status'  => 'draft', // Default to draft
			'post_type'    => 'qp_plan',
			'meta_input'   => [
				'_qp_is_auto_generated'       => 'true',
				'_qp_plan_type'               => $plan_type,
				'_qp_plan_duration_value'     => ! empty($duration_value) ? absint($duration_value) : null,
				'_qp_plan_duration_unit'      => ! empty($duration_value) ? sanitize_key($duration_unit) : null,
				'_qp_plan_attempts'           => 0,
				'_qp_plan_course_access_type' => 'specific',
				'_qp_plan_linked_courses'     => [$post_id],
			],
		];

		// --- UPDATED LOGIC: Handle both Plan and Product ---

		// If the course is NOT 'requires_purchase' OR its status is NOT 'publish' (e.g., draft, trash),
		// then the plan and product should be a draft.
		if ($access_mode !== 'requires_purchase' || $post_status !== 'publish') {
			// --- Course is set to FREE or is DRAFT/TRASHED etc. ---

			// 1. Set Plan to Draft
			if (! empty($existing_plan_id)) {
				$existing_plan_post = get_post($existing_plan_id);
				if ($existing_plan_post && $existing_plan_post->post_type === 'qp_plan' && get_post_meta($existing_plan_id, '_qp_is_auto_generated', true) === 'true') {
					// Only update if it's not already a draft
					if ($existing_plan_post->post_status !== 'draft') {
						wp_update_post(['ID' => $existing_plan_id, 'post_status' => 'draft']);
						error_log("QP Auto Sync: Set Plan ID #{$existing_plan_id} to draft for Course #{$post_id}.");
					}
				}
			}

			// 2. Set Auto-Product to Draft
			if ($is_auto_product && class_exists('WooCommerce')) {
				$product = wc_get_product($existing_product_id);
				if ($product && $product->get_status() !== 'draft') {
					$product->set_status('draft');
					$product->save();
					error_log("QP Auto Sync: Set Product ID #{$existing_product_id} to draft for Course #{$post_id}.");
				}
			}
			return; // We are done.

		} else {
			// --- Course is set to REQUIRES PURCHASE AND is PUBLISHED ---

			$plan_post_args['post_status'] = 'publish'; // Set to publish
			$plan_id_to_save = 0;

			// 1. Create or Update the Plan
			if (! empty($existing_plan_id)) {
				$existing_plan_post = get_post($existing_plan_id);
				if ($existing_plan_post && $existing_plan_post->post_type === 'qp_plan' && get_post_meta($existing_plan_id, '_qp_is_auto_generated', true) === 'true') {
					$plan_post_args['ID'] = $existing_plan_id;
					$updated_plan_id      = wp_update_post($plan_post_args, true);
					if (! is_wp_error($updated_plan_id)) {
						$plan_id_to_save = $updated_plan_id;
					}
				} else {
					delete_post_meta($post_id, '_qp_course_auto_plan_id');
					$existing_plan_id = 0; // Force creation
				}
			}

			if (empty($plan_id_to_save) && empty($existing_plan_id)) {
				$new_plan_id = wp_insert_post($plan_post_args, true);
				if (! is_wp_error($new_plan_id)) {
					$plan_id_to_save = $new_plan_id;
					update_post_meta($post_id, '_qp_course_auto_plan_id', $plan_id_to_save);
				}
			}

			// If we still don't have a plan ID, we can't create the product.
			if ($plan_id_to_save <= 0) {
				error_log("QP Auto Sync: FAILED to create/find Plan for Course ID #{$post_id}. Product creation skipped.");
				return;
			}

			// 2. Create or Update the WooCommerce Product
			if (class_exists('WooCommerce')) {

				// Get or Create the 'QP Course Plan' Product Category
				$category_name = 'QP Course (Auto)';
				$category_slug = 'qp-course-plan-auto'; // A clean slug
				$taxonomy = 'product_cat';
				$category_id = null;

				// Check if the term exists
				$term = term_exists($category_name, $taxonomy);

				if ($term !== null && is_array($term)) {
					// Term exists
					$category_id = (int) $term['term_id'];
				} else {
					// Term does not exist, so create it
					$new_term = wp_insert_term($category_name, $taxonomy, ['slug' => $category_slug]);
					if (! is_wp_error($new_term) && is_array($new_term)) {
						$category_id = (int) $new_term['term_id'];
						error_log("QP Auto Sync: Created new product category '{$category_name}' with ID #{$category_id}.");
					} else {
						// Log an error if creation failed
						$error_message = is_wp_error($new_term) ? $new_term->get_error_message() : 'Unknown error creating term.';
						error_log("QP Auto Sync: FAILED to create product category '{$category_name}'. Error: " . $error_message);
					}
				}

				$product_id_to_save = 0;

				// Check if an auto-product already exists
				if ($is_auto_product) {
					$product = wc_get_product($existing_product_id);
					if ($product) {
						$product->set_name($course_title);
						$product->set_status('publish');
						$product->update_meta_data('_qp_linked_plan_id', $plan_id_to_save); // Re-sync plan ID
						$product_id_to_save = $product->save();
						error_log("QP Auto Sync: Updated and Published Product ID #{$product_id_to_save} for Course #{$post_id}");
					} else {
						$is_auto_product = false; // Linked product was not found, force creation
					}
				}

				// If no auto-product was found or it was invalid, create a new one
				if (! $is_auto_product) {
					$product = new \WC_Product_Simple();
					$product->set_name($course_title);
					$product->set_status('publish');
					$product->set_virtual(true);
					$product->set_downloadable(false);
					$product->set_regular_price(''); // Admin must set this
					if ($category_id > 0) {
						$product->set_category_ids([$category_id]);
					}

					// Add our meta data
					$product->update_meta_data('_qp_is_auto_generated', 'true');
					$product->update_meta_data('_qp_linked_plan_id', $plan_id_to_save);
					$product->update_meta_data('_qp_linked_course_id', $post_id);

					$product_id_to_save = $product->save();

					if ($product_id_to_save > 0) {
						// Link this new product back to the course
						update_post_meta($post_id, '_qp_linked_product_id', $product_id_to_save);
						error_log("QP Auto Sync: CREATED and Published Product ID #{$product_id_to_save} for Course #{$post_id}");
					} else {
						error_log("QP Auto Sync: FAILED to create new Product for Course ID #{$post_id}.");
					}
				}
			}
		}
	}

	/**
	 * Automatically creates/updates a wc_product based on a MANUAL plan's settings.
	 * Triggered after the 'qp_plan' post is saved.
	 *
	 * @param int $post_id The ID of the qp_plan post being saved.
	 */
	public static function sync_plan_product($post_id)
	{
		// Basic checks
		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_plan' !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
			return;
		}

		// *** IMPORTANT: This function must ONLY run for MANUAL plans ***
		// If this is an auto-generated plan, it's handled by sync_course_plan.
		if (get_post_meta($post_id, '_qp_is_auto_generated', true) === 'true') {
			return;
		}

		// If WooCommerce is not active, we can't do anything.
		if (! class_exists('WooCommerce')) {
			return;
		}

		$plan_post = get_post($post_id);
		$plan_status = $plan_post->post_status;
		$plan_title = $plan_post->post_title;
		$existing_product_id = get_post_meta($post_id, '_qp_auto_product_id', true);
		$is_auto_product = $existing_product_id ? get_post_meta($existing_product_id, '_qp_is_auto_generated', true) === 'true' : false;

		// If the plan is NOT 'publish', the product should be 'draft'.
		if ($plan_status !== 'publish') {
			if ($is_auto_product) {
				$product = wc_get_product($existing_product_id);
				if ($product && $product->get_status() !== 'draft') {
					$product->set_status('draft');
					$product->save();
					error_log("QP Auto Sync: Set Product ID #{$existing_product_id} to draft for Plan #{$post_id}.");
				}
			}
			return; // We are done.
		}

		// If we are here, the Plan is 'published', so the Product should also be.

		// --- Get or Create the 'QP Course Plan' Product Category ---
		$category_name = 'QP Plan (Auto)';
		$category_slug = 'qp-plan-manual';
		$taxonomy = 'product_cat';
		$category_id = null;
		$term = term_exists($category_name, $taxonomy);
		if ($term !== null && is_array($term)) {
			$category_id = (int) $term['term_id'];
		} else {
			$new_term = wp_insert_term($category_name, $taxonomy, ['slug' => $category_slug]);
			if (! is_wp_error($new_term) && is_array($new_term)) {
				$category_id = (int) $new_term['term_id'];
			}
		}
		// --- End Category ---

		$product_id_to_save = 0;

		if ($is_auto_product) {
			// Update existing auto-product
			$product = wc_get_product($existing_product_id);
			if ($product) {
				$product->set_name($plan_title); // Sync name with plan
				$product->set_status('publish');
				$product->update_meta_data('_qp_linked_plan_id', $post_id); // Ensure plan ID is correct
				if ($category_id > 0) {
					$product->set_category_ids([$category_id]);
				}
				$product_id_to_save = $product->save();
				error_log("QP Auto Sync: Updated and Published Product ID #{$product_id_to_save} for Plan #{$post_id}");
			} else {
				$is_auto_product = false; // Linked product not found, force creation
			}
		}

		if (! $is_auto_product) {
			// Create a new auto-product
			$product = new \WC_Product_Simple();
			$product->set_name($plan_title); // Use plan title
			$product->set_status('publish');
			$product->set_virtual(true);
			$product->set_downloadable(false);
			$product->set_regular_price(''); // Admin must set this

			if ($category_id > 0) {
				$product->set_category_ids([$category_id]);
			}

			// Add our meta data
			$product->update_meta_data('_qp_is_auto_generated', 'true');
			$product->update_meta_data('_qp_linked_plan_id', $post_id);
			// DO NOT add _qp_linked_course_id

			$product_id_to_save = $product->save();

			if ($product_id_to_save > 0) {
				// Link this new product back to the plan
				update_post_meta($post_id, '_qp_auto_product_id', $product_id_to_save);
				error_log("QP Auto Sync: CREATED and Published Product ID #{$product_id_to_save} for Plan #{$post_id}");
			} else {
				error_log("QP Auto Sync: FAILED to create new Product for Plan ID #{$post_id}.");
			}
		}
	}
} // End class Meta_Boxes