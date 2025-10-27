<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           3.5.1
 * Author:            Himanshu
 * Text Domain:       question-press
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// --- NEW: Load Composer autoloader ---
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
} else {
    // Add admin notice if dependencies missing (keep this check)
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Question Press requires Composer dependencies. Please run "composer install" in the plugin directory.', 'question-press' );
        echo '</p></div>';
    });
    return; // Stop loading if dependencies are missing
}

// --- NEW: Define Plugin File Constant ---
if ( ! defined( 'QP_PLUGIN_FILE' ) ) {
    define( 'QP_PLUGIN_FILE', __FILE__ );
}

// --- NEW: Use statements for namespaced classes we will create ---
use QuestionPress\Plugin;
use QuestionPress\Activator; // (Keep commented for now)
use QuestionPress\Deactivator; // (Keep commented for now)
use QuestionPress\Database\Questions_DB;
use QuestionPress\Database\Terms_DB;


/**
 * --- NEW: Main function for returning the Plugin instance ---
 */
function QuestionPress() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
    return Plugin::instance();
}

// --- NEW: Get Plugin running ---
QuestionPress();

// --- NEW: Activation / Deactivation Hooks (Keep commented for now) ---
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// =========================================================================
// --- BELOW IS YOUR ORIGINAL CODE - MODIFY AS INSTRUCTED ---
// =========================================================================

/**
 * Start session on init hook.
 */
function qp_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Add meta box for Plan Details.
 */
function qp_add_plan_details_meta_box() {
    add_meta_box(
        'qp_plan_details_meta_box',           // Unique ID
        __('Plan Details', 'question-press'), // Box title
        'qp_render_plan_details_meta_box',    // Callback function
        'qp_plan',                            // Post type
        'normal',                             // Context (normal = main column)
        'high'                                // Priority
    );
}

/**
 * Render the HTML content for the Plan Details meta box.
 */
function qp_render_plan_details_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_plan_details_meta', 'qp_plan_details_nonce');

    // Get existing meta values
    $plan_type = get_post_meta($post->ID, '_qp_plan_type', true);
    $duration_value = get_post_meta($post->ID, '_qp_plan_duration_value', true);
    $duration_unit = get_post_meta($post->ID, '_qp_plan_duration_unit', true);
    $attempts = get_post_meta($post->ID, '_qp_plan_attempts', true);
    $course_access_type = get_post_meta($post->ID, '_qp_plan_course_access_type', true);
    $linked_courses_raw = get_post_meta($post->ID, '_qp_plan_linked_courses', true);
    $linked_courses = is_array($linked_courses_raw) ? $linked_courses_raw : []; // Ensure it's an array
    $description = get_post_meta($post->ID, '_qp_plan_description', true);

    // Get all published courses for selection
    $courses = get_posts([
        'post_type' => 'qp_course',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    ?>
    <style>
        .qp-plan-meta-box table { width: 100%; border-collapse: collapse; }
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

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            const planTypeSelect = $('#qp_plan_type');
            const courseAccessSelect = $('#qp_plan_course_access_type');
            const metaBox = $('.qp-plan-meta-box');

            function toggleFields() {
                const selectedType = planTypeSelect.val();
                const selectedCourseAccess = courseAccessSelect.val();

                metaBox.find('.conditional-field').each(function() {
                    const $fieldRow = $(this);
                    const dependsOn = $fieldRow.data('depends-on') ? $fieldRow.data('depends-on').split(' ') : [];
                    const subDependsOn = $fieldRow.data('sub-depends-on'); // For specific course selection

                    let show = false;
                    if (dependsOn.includes(selectedType)) {
                        show = true;
                        // Handle sub-dependency for specific courses
                        if (subDependsOn === 'specific' && selectedCourseAccess !== 'specific') {
                            show = false;
                        }
                    }

                    if (show) {
                        $fieldRow.slideDown(200);
                        // Make inputs required if needed (optional)
                         //$fieldRow.find('input, select').prop('required', true);
                    } else {
                        $fieldRow.slideUp(200);
                        // Remove required attribute if hidden (optional)
                         //$fieldRow.find('input, select').prop('required', false);
                    }
                });
            }

            // Initial toggle on page load
            toggleFields();

            // Retoggle when plan type or course access type changes
            planTypeSelect.on('change', toggleFields);
            courseAccessSelect.on('change', toggleFields);
        });
    </script>
    <?php
}

/**
 * Save the meta box data when the 'qp_plan' post type is saved.
 */
function qp_save_plan_details_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_plan_details_nonce']) || !wp_verify_nonce($_POST['qp_plan_details_nonce'], 'qp_save_plan_details_meta')) {
        return $post_id;
    }

    // Check if the current user has permission to save the post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Don't save if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $post_id;
    }

    // Check post type is correct
    if ('qp_plan' !== get_post_type($post_id)) {
        return $post_id;
    }

    // Sanitize and save meta fields
    $fields_to_save = [
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
            // Handle potentially empty values for numbers if needed
             if (($sanitize_func === 'absint' || $sanitize_func === 'intval') && $value === 0 && !isset($_POST[$meta_key])) {
                 // If the field wasn't submitted (e.g., hidden conditionally), don't save 0, save empty or delete meta
                 delete_post_meta($post_id, $meta_key);
                 continue;
             }
            update_post_meta($post_id, $meta_key, $value);
        } else {
             // If field is not set (e.g. conditional fields that are hidden), delete existing meta
            delete_post_meta($post_id, $meta_key);
        }
    }

    // Handle the linked courses array separately
    if (isset($_POST['_qp_plan_linked_courses']) && is_array($_POST['_qp_plan_linked_courses'])) {
        $linked_courses = array_map('absint', $_POST['_qp_plan_linked_courses']);
        update_post_meta($post_id, '_qp_plan_linked_courses', $linked_courses);
    } else {
         // If no courses are selected or the field is hidden, ensure the meta is removed or empty
        update_post_meta($post_id, '_qp_plan_linked_courses', []);
    }
}

/**
 * Add meta box for Course Access Settings (Revised).
 */
function qp_add_course_access_meta_box() {
    add_meta_box(
        'qp_course_access_meta_box',          // Unique ID
        __('Course Access & Monetization', 'question-press'), // Updated Box title
        'qp_render_course_access_meta_box',   // Callback function
        'qp_course',                          // Post type
        'side',                               // Context (side = right column)
        'high'                                // Priority
    );
}

/**
 * Render the HTML content for the Course Access meta box (Revised).
 */
function qp_render_course_access_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_course_access_meta', 'qp_course_access_nonce');

    // Get existing meta values
    $access_mode = get_post_meta($post->ID, '_qp_course_access_mode', true) ?: 'free'; // Default to free
    $duration_value = get_post_meta($post->ID, '_qp_course_access_duration_value', true);
    $duration_unit = get_post_meta($post->ID, '_qp_course_access_duration_unit', true) ?: 'day'; // Default unit
    $linked_product_id = get_post_meta($post->ID, '_qp_linked_product_id', true);
    $auto_plan_id = get_post_meta($post->ID, '_qp_course_auto_plan_id', true); // Get the auto-generated plan ID

    // Get all published WooCommerce products for selection
    $products = wc_get_products([
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'return' => 'objects',
    ]);

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
        <label for="qp_course_access_mode"><?php _e('Access Mode:', 'question-press'); ?></label>
        <select name="_qp_course_access_mode" id="qp_course_access_mode">
            <option value="free" <?php selected($access_mode, 'free'); ?>><?php _e('Free (Public Enrollment)', 'question-press'); ?></option>
            <option value="requires_purchase" <?php selected($access_mode, 'requires_purchase'); ?>><?php _e('Requires Purchase', 'question-press'); ?></option>
        </select>
    </p>

    <div id="qp-purchase-fields">
        <p>
            <label><?php _e('Access Duration:', 'question-press'); ?></label>
            <div class="duration-group">
                <input type="number" name="_qp_course_access_duration_value" value="<?php echo esc_attr($duration_value); ?>" min="1" placeholder="e.g., 30">
                <select name="_qp_course_access_duration_unit">
                    <option value="day" <?php selected($duration_unit, 'day'); ?>>Day(s)</option>
                    <option value="month" <?php selected($duration_unit, 'month'); ?>>Month(s)</option>
                    <option value="year" <?php selected($duration_unit, 'year'); ?>>Year(s)</option>
                </select>
            </div>
             <small class="description"><?php _e('How long access lasts after purchase. Leave blank for lifetime access.', 'question-press'); ?></small>
        </p>

        <p>
            <label for="qp_linked_product_id"><?php _e('Linked WooCommerce Product:', 'question-press'); ?></label>
            <select name="_qp_linked_product_id" id="qp_linked_product_id">
                <option value="">— <?php _e('Select Product', 'question-press'); ?> —</option>
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
            <small class="description"><?php _e('Product users click "Purchase" for. Ensure this product is linked to the correct auto-generated or manual plan.', 'question-press'); ?></small>
        </p>

        <?php if ($auto_plan_id && get_post($auto_plan_id)) : ?>
             <p id="qp-auto-plan-info">
                 This course automatically manages Plan ID #<?php echo esc_html($auto_plan_id); ?>.
                 <a href="<?php echo esc_url(get_edit_post_link($auto_plan_id)); ?>" target="_blank">View Plan</a><br>
                 Ensure your Linked Product above uses this Plan ID.
             </p>
        <?php elseif ($access_mode === 'requires_purchase') : ?>
             <p id="qp-auto-plan-info">
                 A Plan will be automatically created/updated when you save this course. Link your WC Product to that Plan ID.
             </p>
        <?php endif; ?>

    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#qp_course_access_mode').on('change', function() {
                if ($(this).val() === 'requires_purchase') {
                    $('#qp-purchase-fields').slideDown(200);
                } else {
                    $('#qp-purchase-fields').slideUp(200);
                    // Clear fields when switching to free
                    // $('#qp-purchase-fields input[type="number"]').val('');
                    // $('#qp-purchase-fields select').val('');
                }
            }).trigger('change'); // Trigger on load to set initial state
        });
    </script>
    <?php
}

/**
 * Save the meta box data when the 'qp_course' post type is saved (Revised).
 * This function ONLY saves the course meta. Auto-plan logic will be separate.
 */
function qp_save_course_access_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) {
        return $post_id;
    }

    // Check permissions, autosave, post type
    if (!current_user_can('edit_post', $post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id)) {
        return $post_id;
    }

    // Save Access Mode
    $access_mode = isset($_POST['_qp_course_access_mode']) ? sanitize_key($_POST['_qp_course_access_mode']) : 'free';
    update_post_meta($post_id, '_qp_course_access_mode', $access_mode);

    // Save fields only if requires_purchase is selected
    if ($access_mode === 'requires_purchase') {
        // Save Duration Value (allow empty for lifetime)
        $duration_value = isset($_POST['_qp_course_access_duration_value']) ? absint($_POST['_qp_course_access_duration_value']) : '';
        update_post_meta($post_id, '_qp_course_access_duration_value', $duration_value);

        // Save Duration Unit
        $duration_unit = isset($_POST['_qp_course_access_duration_unit']) ? sanitize_key($_POST['_qp_course_access_duration_unit']) : 'day';
        update_post_meta($post_id, '_qp_course_access_duration_unit', $duration_unit);

        // Save Linked Product ID
        $product_id = isset($_POST['_qp_linked_product_id']) ? absint($_POST['_qp_linked_product_id']) : '';
        update_post_meta($post_id, '_qp_linked_product_id', $product_id);

    } else {
        // Delete monetization meta if mode is free
        delete_post_meta($post_id, '_qp_course_access_duration_value');
        delete_post_meta($post_id, '_qp_course_access_duration_unit');
        delete_post_meta($post_id, '_qp_linked_product_id');
        // We keep '_qp_course_auto_plan_id' even if switched to free,
        // so we don't lose the link if switched back later.
    }
}

/**
 * Automatically creates or updates a qp_plan post based on course settings.
 * Triggered after the course meta is saved.
 *
 * @param int $post_id The ID of the qp_course post being saved.
 */
function qp_sync_course_plan($post_id) {
    // Basic checks (already done in qp_save_course_access_meta, but good practice)
    if ( (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 'qp_course' !== get_post_type($post_id) || !current_user_can('edit_post', $post_id) ) {
        return;
    }
    // Verify nonce again, just to be safe, using the nonce from the access meta save
    if (!isset($_POST['qp_course_access_nonce']) || !wp_verify_nonce($_POST['qp_course_access_nonce'], 'qp_save_course_access_meta')) {
        return;
    }

    $access_mode = get_post_meta($post_id, '_qp_course_access_mode', true);

    // Only proceed if the course requires purchase
    if ($access_mode !== 'requires_purchase') {
        // Optional: If switched from paid to free, we could potentially update the linked plan's status,
        // but for now, we'll just leave the plan as is to preserve access for past purchasers.
        return;
    }

    // Get the course details needed for the plan
    $course_title = get_the_title($post_id);
    $duration_value = get_post_meta($post_id, '_qp_course_access_duration_value', true);
    $duration_unit = get_post_meta($post_id, '_qp_course_access_duration_unit', true);
    $existing_plan_id = get_post_meta($post_id, '_qp_course_auto_plan_id', true);

    // Determine plan type based on duration
    $plan_type = !empty($duration_value) ? 'time_limited' : 'unlimited'; // Course access implies unlimited attempts

    // Prepare plan post data
    $plan_post_args = [
        'post_title' => 'Auto: Access Plan for Course "' . $course_title . '"',
        'post_content' => '', // Content not needed
        'post_status' => 'publish', // Auto-publish the plan
        'post_type' => 'qp_plan',
        'meta_input' => [ // Use meta_input for direct meta saving/updating
            '_qp_is_auto_generated' => 'true', // Flag this as auto-managed
            '_qp_plan_type' => $plan_type,
            '_qp_plan_duration_value' => !empty($duration_value) ? absint($duration_value) : null,
            '_qp_plan_duration_unit' => !empty($duration_value) ? sanitize_key($duration_unit) : null,
            '_qp_plan_attempts' => null, // Course access plans grant unlimited attempts within duration
            '_qp_plan_course_access_type' => 'specific',
            '_qp_plan_linked_courses' => [$post_id], // Link specifically to this course ID
            // '_qp_plan_description' => 'Automatically generated plan for ' . $course_title, // Optional description
        ],
    ];

    $plan_id_to_save = 0;

    // Check if a plan already exists and is valid
    if (!empty($existing_plan_id)) {
         $existing_plan_post = get_post($existing_plan_id);
         // Check if the post exists and is indeed a qp_plan
         if ($existing_plan_post && $existing_plan_post->post_type === 'qp_plan') {
             // Update existing plan
             $plan_post_args['ID'] = $existing_plan_id; // Add ID for update
             $updated_plan_id = wp_update_post($plan_post_args, true); // true returns WP_Error on failure
             if (!is_wp_error($updated_plan_id)) {
                 $plan_id_to_save = $updated_plan_id;
                 error_log("QP Auto Plan: Updated Plan ID #{$plan_id_to_save} for Course ID #{$post_id}");
             } else {
                 error_log("QP Auto Plan: FAILED to update Plan ID #{$existing_plan_id} for Course ID #{$post_id}. Error: " . $updated_plan_id->get_error_message());
             }
         } else {
             // The linked ID was invalid, clear it and create a new one
             delete_post_meta($post_id, '_qp_course_auto_plan_id');
             $existing_plan_id = 0; // Force creation below
         }
    }

    // Create new plan if no valid existing one was found/updated
    if (empty($plan_id_to_save) && empty($existing_plan_id)) {
        $new_plan_id = wp_insert_post($plan_post_args, true); // true returns WP_Error on failure
        if (!is_wp_error($new_plan_id)) {
            $plan_id_to_save = $new_plan_id;
            // Save the new plan ID back to the course meta
            update_post_meta($post_id, '_qp_course_auto_plan_id', $plan_id_to_save);
            error_log("QP Auto Plan: CREATED Plan ID #{$plan_id_to_save} for Course ID #{$post_id}");
        } else {
            error_log("QP Auto Plan: FAILED to create new Plan for Course ID #{$post_id}. Error: " . $new_plan_id->get_error_message());
        }
    }

}

/**
 * Checks if a user has access to a specific course via a relevant entitlement OR existing enrollment.
 * Differentiates between plans granting general attempts and those granting specific course access.
 *
 * @param int  $user_id              The ID of the user to check.
 * @param int  $course_id            The ID of the course (qp_course post ID) to check access for.
 * @param bool $ignore_enrollment_check Optional. If true, skips the check for existing enrollment. Defaults to false.
 * @return bool True if the user has access, false otherwise.
 */
function qp_user_can_access_course($user_id, $course_id, $ignore_enrollment_check = false) {
    if (empty($user_id) || empty($course_id)) {
        return false;
    }

    // 1. Admins always have access
    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    // 2. Check if the course is explicitly marked as free
    $access_mode = get_post_meta($course_id, '_qp_course_access_mode', true);
    if ($access_mode === 'free') {
        return true; // Free courses are always accessible
    }

    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';
    $current_time = current_time('mysql');

    // --- Get the auto-generated plan ID specifically linked to this course ---
    $auto_plan_id_for_course = get_post_meta($course_id, '_qp_course_auto_plan_id', true);

    // 3. Check for ACTIVE entitlements that grant access specifically to THIS course
    $active_entitlements = $wpdb->get_results($wpdb->prepare(
        "SELECT entitlement_id, plan_id
         FROM {$entitlements_table}
         WHERE user_id = %d
         AND status = 'active'
         AND (expiry_date IS NULL OR expiry_date > %s)",
        $user_id,
        $current_time
    ));

    if (!empty($active_entitlements)) {
        foreach ($active_entitlements as $entitlement) {
            $plan_id = $entitlement->plan_id;
            $plan_post = get_post($plan_id);

            // Skip if plan doesn't exist or isn't a qp_plan
            if (!$plan_post || $plan_post->post_type !== 'qp_plan') {
                continue;
            }

            // *** NEW CHECK: Verify the Plan Type ***
            $plan_type = get_post_meta($plan_id, '_qp_plan_type', true);
            $is_course_access_plan = in_array($plan_type, ['course_access', 'combined']);
            // Check if this entitlement is for the specific auto-generated plan linked to the course
            $is_auto_plan_for_this_course = ($auto_plan_id_for_course && $plan_id == $auto_plan_id_for_course);

            // Only proceed if the plan type is suitable OR it's the specific auto-plan for this course
            if ($is_course_access_plan || $is_auto_plan_for_this_course) {
                // Now check if this suitable plan grants access (all or specific)
                $course_access_type = get_post_meta($plan_id, '_qp_plan_course_access_type', true);
                $linked_courses_raw = get_post_meta($plan_id, '_qp_plan_linked_courses', true);
                $linked_courses = is_array($linked_courses_raw) ? $linked_courses_raw : [];

                if ($course_access_type === 'all' || ($course_access_type === 'specific' && in_array($course_id, $linked_courses))) {
                    // Found a valid entitlement of the correct type granting access to this course
                    return true;
                }
            }
            // *** END NEW CHECK ***
        }
    }
    // If no suitable entitlement granted access, proceed to enrollment check (unless ignored)

    // 4. Check for existing enrollment IF the flag allows it
    if (!$ignore_enrollment_check) {
        $is_enrolled = $wpdb->get_var($wpdb->prepare(
            "SELECT user_course_id FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
            $user_id,
            $course_id
        ));

        if ($is_enrolled) {
            return true; // Access granted due to existing enrollment
        }
    }

    // 5. If none of the above grant access, deny.
    return false;
}

/**
 * Ensures the entitlement expiration cron job is scheduled.
 * Runs on WordPress initialization.
 */
function qp_ensure_cron_scheduled() {
    if (!wp_next_scheduled('qp_check_entitlement_expiration_hook')) {
        wp_schedule_event(time(), 'daily', 'qp_check_entitlement_expiration_hook');
        error_log("QP Cron: Re-scheduled entitlement expiration check on init.");
    }
}

/**
 * The callback function executed by the WP-Cron job to update expired entitlements.
 */
function qp_run_entitlement_expiration_check() {
    error_log("QP Cron: Running entitlement expiration check...");
    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $current_time = current_time('mysql');

    // Find entitlement records that are 'active' but whose expiry date is in the past
    $expired_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT entitlement_id
         FROM {$entitlements_table}
         WHERE status = 'active'
         AND expiry_date IS NOT NULL
         AND expiry_date <= %s",
        $current_time
    ));

    if (!empty($expired_ids)) {
        $ids_placeholder = implode(',', array_map('absint', $expired_ids));

        // Update the status of these records to 'expired'
        $updated_count = $wpdb->query(
            "UPDATE {$entitlements_table}
             SET status = 'expired'
             WHERE entitlement_id IN ($ids_placeholder)"
        );

        if ($updated_count !== false) {
             error_log("QP Cron: Marked {$updated_count} entitlements as expired.");
        } else {
             error_log("QP Cron: Error updating expired entitlements. DB Error: " . $wpdb->last_error);
        }
    } else {
        error_log("QP Cron: No expired entitlements found to update.");
    }
}

/**
 * Add custom field to WooCommerce Product Data > General tab for Simple products.
 */
function qp_add_plan_link_to_simple_products() {
    global $post;

    // Get all published 'qp_plan' posts
    $plans = get_posts([
        'post_type' => 'qp_plan',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $options = ['' => __('— Select a Question Press Plan —', 'question-press')];
    if ($plans) {
        foreach ($plans as $plan) {
            $options[$plan->ID] = esc_html($plan->post_title);
        }
    }

    // Output the WooCommerce field
    woocommerce_wp_select([
        'id'          => '_qp_linked_plan_id',
        'label'       => __('Question Press Plan', 'question-press'),
        'description' => __('Link this product to a Question Press monetization plan. This grants access when the order is completed.', 'question-press'),
        'desc_tip'    => true,
        'options'     => $options,
        'value'       => get_post_meta($post->ID, '_qp_linked_plan_id', true), // Get current value
    ]);
}

/**
 * Save the custom field for Simple products.
 */
function qp_save_plan_link_simple_product($post_id) {
    $plan_id = isset($_POST['_qp_linked_plan_id']) ? absint($_POST['_qp_linked_plan_id']) : '';
    update_post_meta($post_id, '_qp_linked_plan_id', $plan_id);
}

/**
 * Add custom field to WooCommerce Product Data > Variations tab for Variable products.
 */
function qp_add_plan_link_to_variable_products($loop, $variation_data, $variation) {
    // Get all published 'qp_plan' posts (reuse logic or query again)
    $plans = get_posts([
        'post_type' => 'qp_plan',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $options = ['' => __('— Select a Question Press Plan —', 'question-press')];
    if ($plans) {
        foreach ($plans as $plan) {
            $options[$plan->ID] = esc_html($plan->post_title);
        }
    }

    // Output the WooCommerce field for variations
    woocommerce_wp_select([
        'id'            => "_qp_linked_plan_id[{$loop}]", // Needs array index for variations
        'label'         => __('Question Press Plan', 'question-press'),
        'description'   => __('Link this variation to a Question Press monetization plan.', 'question-press'),
        'desc_tip'      => true,
        'options'       => $options,
        'value'         => get_post_meta($variation->ID, '_qp_linked_plan_id', true), // Get value for this variation ID
        'wrapper_class' => 'form-row form-row-full', // Ensure it takes full width in variation options
    ]);
}

/**
 * Save the custom field for Variable products (variations).
 */
function qp_save_plan_link_variable_product($variation_id, $i) {
    $plan_id = isset($_POST['_qp_linked_plan_id'][$i]) ? absint($_POST['_qp_linked_plan_id'][$i]) : '';
    update_post_meta($variation_id, '_qp_linked_plan_id', $plan_id);
}

/**
 * Add meta box for Course Structure.
 */
function qp_add_course_structure_meta_box() {
    add_meta_box(
        'qp_course_structure_meta_box', // Unique ID
        __('Course Structure', 'question-press'), // Box title
        'qp_render_course_structure_meta_box', // Callback function
        'qp_course', // Post type
        'normal', // Context (normal = main column)
        'high' // Priority
    );
}

/**
 * Render the HTML content for the Course Structure meta box.
 * (Initial static structure - JS will make it dynamic later)
 */
function qp_render_course_structure_meta_box($post) {
    // Add a nonce field for security
    wp_nonce_field('qp_save_course_structure_meta', 'qp_course_structure_nonce');

    // Basic structure - we will load saved data and make this dynamic later
    ?>
    <div id="qp-course-structure-container">
        <p>Define the sections and content items for this course below. Drag and drop to reorder.</p>

        <div id="qp-sections-list">
            <?php
            // --- Placeholder for loading existing sections/items later ---
            // For now, it's empty, ready for JS.
            ?>
        </div>

        <p>
            <button type="button" id="qp-add-section-btn" class="button button-secondary">
                <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> Add Section
            </button>
        </p>
    </div>

    <?php
    // --- Add some basic CSS (will be moved/refined later) ---
    ?>
    <style>
        #qp-sections-list .qp-section {
            border: 1px solid #ccd0d4;
            margin-bottom: 15px;
            background: #fff;
            border-radius: 4px;
        }
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
        .qp-section-controls button, .qp-item-controls button {
            margin-left: 5px;
        }
        .qp-section-content {
            padding: 15px;
        }
        .qp-items-list {
            margin-left: 10px;
            border-left: 3px solid #eef2f5;
            padding-left: 15px;
            min-height: 30px; /* Area to drop items */
        }
        .qp-course-item {
            border: 1px dashed #dcdcde;
            padding: 10px;
            margin-bottom: 10px;
            background: #fdfdfd;
            border-radius: 3px;
        }
         .qp-item-header {
             display: flex; justify-content: space-between; align-items: center;
             margin-bottom: 10px; cursor: move; padding-bottom: 5px; border-bottom: 1px solid #eee;
         }
         .qp-item-title-input { font-weight: bold; border: none; box-shadow: none; padding: 2px 5px; background: transparent; flex-grow: 1; }
        .qp-item-config { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
        .qp-config-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px; }
        .qp-config-row label { display: block; font-weight: 500; margin-bottom: 3px; font-size: 0.9em; }
        .qp-config-row select, .qp-config-row input { width: 100%; box-sizing: border-box; }
        .qp-item-config .qp-marks-group { display: flex; gap: 10px; }
        .qp-item-config .qp-marks-group > div { flex: 1; }
    </style>
    <?php
}

/**
 * Save the course structure data when the 'qp_course' post type is saved.
 * Handles updates, inserts, and deletions intelligently.
 * Cleans up user progress for deleted items.
 */
function qp_save_course_structure_meta($post_id) {
    // Check nonce
    if (!isset($_POST['qp_course_structure_nonce']) || !wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) {
        return $post_id;
    }

    // Check if the current user has permission to save the post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Don't save if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $post_id;
    }

    // Check post type
    if ('qp_course' !== get_post_type($post_id)) {
        return $post_id;
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';

    // --- Data processing ---

    // 1. Fetch Existing Structure IDs from DB
    $existing_section_ids = $wpdb->get_col($wpdb->prepare("SELECT section_id FROM $sections_table WHERE course_id = %d", $post_id));
    $existing_item_ids = $wpdb->get_col($wpdb->prepare("SELECT item_id FROM $items_table WHERE course_id = %d", $post_id));

    $submitted_section_ids = [];
    $submitted_item_ids = [];
    $processed_item_ids = []; // Keep track of item IDs processed (inserted or updated)

    // 2. Loop through submitted sections and items: Update or Insert
    if (isset($_POST['course_sections']) && is_array($_POST['course_sections'])) {
        foreach ($_POST['course_sections'] as $section_order => $section_data) {
            $section_id = isset($section_data['section_id']) ? absint($section_data['section_id']) : 0;
            $section_title = sanitize_text_field($section_data['title'] ?? 'Untitled Section');

            $section_db_data = [
                'course_id' => $post_id,
                'title' => $section_title,
                'section_order' => $section_order + 1 // Ensure correct 1-based order
            ];

            if ($section_id > 0 && in_array($section_id, $existing_section_ids)) {
                // UPDATE existing section
                $wpdb->update($sections_table, $section_db_data, ['section_id' => $section_id]);
                $submitted_section_ids[] = $section_id;
            } else {
                // INSERT new section
                $wpdb->insert($sections_table, $section_db_data);
                $section_id = $wpdb->insert_id; // Get the new ID for items below
                 if (!$section_id) {
                    // Handle potential insert error, maybe log it
                    continue; // Skip items for this failed section insert
                 }
                 $submitted_section_ids[] = $section_id;
            }

            // Process Items within this section
            if ($section_id && isset($section_data['items']) && is_array($section_data['items'])) {
                foreach ($section_data['items'] as $item_order => $item_data) {
                    $item_id = isset($item_data['item_id']) ? absint($item_data['item_id']) : 0;
                    $item_title = sanitize_text_field($item_data['title'] ?? 'Untitled Item');
                    $content_type = sanitize_key($item_data['content_type'] ?? 'test_series'); // Default to test_series

                    // --- Process Configuration ---
                    $config = [];
                    if ($content_type === 'test_series' && isset($item_data['config'])) {
                         $raw_config = $item_data['config'];
                        $config = [
                            'time_limit'      => isset($raw_config['time_limit']) ? absint($raw_config['time_limit']) : 0,
                            'scoring_enabled' => isset($raw_config['scoring_enabled']) ? 1 : 0,
                            'marks_correct'   => isset($raw_config['marks_correct']) ? floatval($raw_config['marks_correct']) : 1,
                            'marks_incorrect' => isset($raw_config['marks_incorrect']) ? floatval($raw_config['marks_incorrect']) : 0,
                        ];
                        // Process selected questions (string to array)
                        if (isset($raw_config['selected_questions']) && !empty($raw_config['selected_questions'])) {
                            $question_ids_str = sanitize_text_field($raw_config['selected_questions']);
                            $question_ids = array_filter(array_map('absint', explode(',', $question_ids_str)));
                            if (!empty($question_ids)) {
                                $config['selected_questions'] = $question_ids;
                            }
                        }
                    } // Add 'else if' blocks here for other content types

                    $item_db_data = [
                        'section_id' => $section_id,
                        'course_id' => $post_id,
                        'title' => $item_title,
                        'item_order' => $item_order + 1,
                        'content_type' => $content_type,
                        'content_config' => wp_json_encode($config)
                    ];

                    if ($item_id > 0 && in_array($item_id, $existing_item_ids)) {
                        // UPDATE existing item
                        $wpdb->update($items_table, $item_db_data, ['item_id' => $item_id]);
                        $submitted_item_ids[] = $item_id;
                        $processed_item_ids[] = $item_id; // Track processed item
                    } else {
                        // INSERT new item
                        $wpdb->insert($items_table, $item_db_data);
                         $new_item_id = $wpdb->insert_id;
                         if ($new_item_id) {
                            $submitted_item_ids[] = $new_item_id;
                            $processed_item_ids[] = $new_item_id; // Track processed item
                         } else {
                            // Handle potential insert error
                         }
                    }
                } // end foreach item
            } // end if section_id and items exist
        } // end foreach section
    } // end if course_sections exist

    // 3. Identify Sections and Items to Delete
    $section_ids_to_delete = array_diff($existing_section_ids, $submitted_section_ids);
    $item_ids_to_delete = array_diff($existing_item_ids, $processed_item_ids); // Use processed_item_ids

    // 4. *** CRITICAL STEP: Clean up User Progress for Deleted Items ***
    if (!empty($item_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
        $wpdb->query("DELETE FROM $progress_table WHERE item_id IN ($ids_placeholder)");
        // Log this action (optional)
        error_log('QP Course Save: Cleaned up progress for deleted item IDs: ' . $ids_placeholder);
    }

    // 5. Delete Orphaned Items (associated with kept sections but removed in UI, or from deleted sections)
    if (!empty($item_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $item_ids_to_delete));
        $wpdb->query("DELETE FROM $items_table WHERE item_id IN ($ids_placeholder)");
    }

    // 6. Delete Orphaned Sections (and implicitly cascade delete remaining items if DB constraints were set, although we deleted items explicitly above)
    if (!empty($section_ids_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $section_ids_to_delete));
        // We already deleted items, just need to delete sections now
        $wpdb->query("DELETE FROM $sections_table WHERE section_id IN ($ids_placeholder)");
    }

    // Note: No explicit return needed as this hooks into save_post action
}



/**
 * Adds a "(Question Press)" indicator to the plugin's pages in the admin list.
 *
 * @param array   $post_states An array of post states.
 * @param WP_Post $post        The current post object.
 * @return array  The modified array of post states.
 */
function qp_add_page_indicator($post_states, $post)
{
    // Get the saved IDs of our plugin's pages
    $qp_settings = get_option('qp_settings', []);
    $qp_page_ids = [
        $qp_settings['practice_page'] ?? 0,
        $qp_settings['dashboard_page'] ?? 0,
        $qp_settings['session_page'] ?? 0,
        $qp_settings['review_page'] ?? 0,
    ];

    // Check if the current page's ID is one of our plugin's pages
    if (in_array($post->ID, $qp_page_ids)) {
        $post_states['question_press_page'] = 'Question Press';
    }

    return $post_states;
}

function qp_add_screen_options()
{
    $option = 'per_page';
    $args = [
        'label'   => 'Questions per page',
        'default' => 20,
        'option'  => 'qp_questions_per_page'
    ];
    add_screen_option($option, $args);
    new QP_Questions_List_Table(); // Instantiate table to register columns
}

/**
 * Add screen options for the Entitlements list table.
 */
function qp_add_entitlements_screen_options() {
    $screen = get_current_screen();
    // Check if we are on the correct screen
    if ($screen && $screen->id === 'question-press_page_qp-user-entitlements') {
        QP_Entitlements_List_Table::add_screen_options();
    }
}

// Filter to save the screen option (reuse existing function if desired, or keep separate)
function qp_save_entitlements_screen_options($status, $option, $value) {
    if ('entitlements_per_page' === $option) {
        return $value;
    }
    // Important: Return the original status for other options
    return $status;
}

function qp_save_screen_options($status, $option, $value)
{
    if ('qp_questions_per_page' === $option) {
        return $value;
    }
    return $status;
}

/**
 * Helper function to retrieve the existing course structure for the editor.
 *
 * @param int $course_id The ID of the course post.
 * @return array The structured course data.
 */
function qp_get_course_structure_for_editor($course_id) {
    if (!$course_id) {
        return ['sections' => []]; // Return empty structure for new courses
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $structure = ['sections' => []];

    $sections = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
        $course_id
    ));

    if (empty($sections)) {
        return $structure;
    }

    $section_ids = wp_list_pluck($sections, 'section_id');
    $ids_placeholder = implode(',', array_map('absint', $section_ids));

    $items_raw = $wpdb->get_results("SELECT item_id, section_id, title, item_order, content_type, content_config FROM $items_table WHERE section_id IN ($ids_placeholder) ORDER BY item_order ASC");

    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->content_config = json_decode($item->content_config, true); // Decode JSON
        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    foreach ($sections as $section) {
        $structure['sections'][] = [
            'id' => $section->section_id,
            'title' => $section->title,
            'description' => $section->description,
            'order' => $section->section_order,
            'items' => $items_by_section[$section->section_id] ?? []
        ];
    }

    return $structure;
}

/**
 * UPDATED: qp_get_test_series_options_for_js
 * Also fetches source terms and source-subject links needed for modal filters.
 */
function qp_get_test_series_options_for_js() {
    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $rel_table = $wpdb->prefix . 'qp_term_relationships';

    $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'");
    $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'source'");

    // Fetch ALL subjects and topics together
    $all_subject_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
        $subject_tax_id
    ), ARRAY_A); // Fetch as associative arrays for JS

    // Fetch ALL source terms (including sections)
    $all_source_terms = $wpdb->get_results($wpdb->prepare(
        "SELECT term_id as id, name, parent FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
        $source_tax_id
    ), ARRAY_A);

     // Fetch source-subject links
     $source_subject_links = $wpdb->get_results(
        "SELECT object_id AS source_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'source_subject_link'",
        ARRAY_A
     );


    return [
        'allSubjectTerms' => $all_subject_terms,
        'allSourceTerms' => $all_source_terms, // Add source terms
        'sourceSubjectLinks' => $source_subject_links, // Add source-subject links
    ];
}

// Used on export page

/**
 * Determines the allowed subject term IDs for a given user based on their scope settings.
 * Reads _qp_allowed_exam_term_ids and _qp_allowed_subject_term_ids from usermeta.
 *
 * @param int $user_id The ID of the user to check.
 * @return string|array Returns 'all' if access is unrestricted, or an array of allowed subject term IDs. Returns empty array if user_id is invalid.
 */
function qp_get_allowed_subject_ids_for_user($user_id) {
    $user_id = absint($user_id);
    if (empty($user_id)) {
        return []; // No access for non-logged-in or invalid ID
    }

    // Admins always have full access (capability check)
    if (user_can($user_id, 'manage_options')) {
        return 'all';
    }

    // Get stored scope settings from user meta
    $allowed_exams_json = get_user_meta($user_id, '_qp_allowed_exam_term_ids', true);
    $direct_subjects_json = get_user_meta($user_id, '_qp_allowed_subject_term_ids', true);

    // Decode JSON, default to empty array if invalid, null, or not set
    $allowed_exam_ids = json_decode($allowed_exams_json, true);
    $direct_subject_ids = json_decode($direct_subjects_json, true);

    // Ensure they are arrays after decoding
    if (!is_array($allowed_exam_ids)) { $allowed_exam_ids = []; }
    if (!is_array($direct_subject_ids)) { $direct_subject_ids = []; }

    // If both settings are empty arrays (meaning unrestricted), grant access to all subjects
    if (empty($allowed_exam_ids) && empty($direct_subject_ids)) {
        return 'all';
    }

    global $wpdb;
    // Start with the directly allowed subjects
    $final_allowed_subject_ids = $direct_subject_ids;

    // If specific exams are allowed, find subjects linked to them
    if (!empty($allowed_exam_ids)) {
        // Ensure IDs are integers before using in query
        $exam_ids_sanitized = array_map('absint', $allowed_exam_ids);
        // Prevent query errors if array becomes empty after sanitization
        if (!empty($exam_ids_sanitized)) {
             $exam_ids_placeholder = implode(',', $exam_ids_sanitized);
             $rel_table = $wpdb->prefix . 'qp_term_relationships';

             // Find subject term_ids linked to the allowed exam object_ids
             $subjects_from_exams = $wpdb->get_col(
                "SELECT DISTINCT term_id
                 FROM {$rel_table}
                 WHERE object_type = 'exam_subject_link'
                 AND object_id IN ($exam_ids_placeholder)"
             );

             // If subjects are found, merge them with the directly allowed ones
             if (!empty($subjects_from_exams)) {
                // Ensure these are also integers
                $subjects_from_exams_int = array_map('absint', $subjects_from_exams);
                $final_allowed_subject_ids = array_merge($final_allowed_subject_ids, $subjects_from_exams_int);
             }
        }
    }

    // Return the unique list of combined subject IDs (ensure all are integers)
    return array_unique(array_map('absint', $final_allowed_subject_ids));
}

/**
 * Helper function to migrate term relationships from questions to their parent groups for a specific taxonomy.
 *
 * @param string $taxonomy_name The name of the taxonomy to process (e.g., 'subject' for topics, 'source' for sources/sections).
 * @param string $log_prefix A prefix for logging messages (e.g., 'Topic', 'Source/Section').
 * @return array A report of the migration containing counts and skipped items.
 */
function qp_migrate_taxonomy_relationships($taxonomy_name, $log_prefix)
{
    global $wpdb;
    $rel_table = $wpdb->prefix . 'qp_term_relationships';
    $term_table = $wpdb->prefix . 'qp_terms';
    $tax_table = $wpdb->prefix . 'qp_taxonomies';
    $q_table = $wpdb->prefix . 'qp_questions';

    $migrated_count = 0;
    $deleted_count = 0;
    $skipped = [];

    // Get the taxonomy ID for the given taxonomy name
    $taxonomy_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $taxonomy_name));
    if (!$taxonomy_id) {
        $skipped[] = "{$log_prefix} Migration: Taxonomy '{$taxonomy_name}' not found. Skipping.";
        return ['migrated' => 0, 'deleted' => 0, 'skipped' => $skipped];
    }

    // 1. Find all unique group-term pairs by inspecting question relationships.
    // For each group, we find the single, most representative term.
    $group_to_term_map = [];
    $question_relationships = $wpdb->get_results($wpdb->prepare("
        SELECT q.group_id, r.term_id, t.parent
        FROM {$q_table} q
        JOIN {$rel_table} r ON q.question_id = r.object_id AND r.object_type = 'question'
        JOIN {$term_table} t ON r.term_id = t.term_id
        WHERE t.taxonomy_id = %d AND q.group_id > 0
    ", $taxonomy_id));

    foreach ($question_relationships as $rel) {
        // For topics (subject taxonomy), we only care about children (parent != 0)
        if ($taxonomy_name === 'subject' && $rel->parent == 0) {
            continue;
        }
        // For a group, we always want to store the most specific (deepest) term.
        // A child term (parent != 0) is always more specific than a parent term.
        if (!isset($group_to_term_map[$rel->group_id]) || $rel->parent != 0) {
            $group_to_term_map[$rel->group_id] = $rel->term_id;
        }
    }

    // 2. Insert the new group-level relationships.
    foreach ($group_to_term_map as $group_id => $term_id) {
        if ($group_id > 0 && $term_id > 0) {
            // First, delete any existing relationship for this group and taxonomy to avoid conflicts
            $wpdb->query($wpdb->prepare(
                "DELETE r FROM {$rel_table} r
                 JOIN {$term_table} t ON r.term_id = t.term_id
                 WHERE r.object_id = %d AND r.object_type = 'group' AND t.taxonomy_id = %d",
                $group_id,
                $taxonomy_id
            ));

            // Now insert the new, correct relationship
            $result = $wpdb->insert($rel_table, [
                'object_id' => $group_id,
                'term_id' => $term_id,
                'object_type' => 'group'
            ]);

            if ($result) {
                $migrated_count++;
            }
        } else {
            $skipped[] = "{$log_prefix} Link: Skipped relationship for Group ID {$group_id} and Term ID {$term_id} due to invalid ID.";
        }
    }

    // 3. Delete all old question-level relationships for this taxonomy.
    $deleted_count = $wpdb->query($wpdb->prepare(
        "DELETE r FROM {$rel_table} r
         JOIN {$term_table} t ON r.term_id = t.term_id
         WHERE r.object_type = 'question' AND t.taxonomy_id = %d",
        $taxonomy_id
    ));

    return ['migrated' => $migrated_count, 'deleted' => $deleted_count, 'skipped' => $skipped];
}

// FORM & ACTION HANDLERS
function qp_handle_form_submissions()
{
    if (isset($_GET['page']) && $_GET['page'] === 'qp-organization') {
        QP_Sources_Page::handle_forms();
        QP_Subjects_Page::handle_forms();
        QP_Labels_Page::handle_forms();
        QP_Exams_Page::handle_forms();
    }
    QP_Export_Page::handle_export_submission();
    QP_Backup_Restore_Page::handle_forms();
    QP_Settings_Page::register_settings();
    qp_handle_save_question_group();
}

/**
 * Handles saving the user's subject scope (allowed exams/subjects) from the User Entitlements page.
 */
function qp_handle_save_user_scope() {
    // 1. Security Checks
    if (
        !isset($_POST['_qp_scope_nonce']) ||
        !wp_verify_nonce($_POST['_qp_scope_nonce'], 'qp_save_user_scope_nonce')
    ) {
        wp_die(__('Security check failed.', 'question-press'));
    }

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to manage user scope.', 'question-press'));
    }

    // 2. Get and Sanitize User ID
    $user_id = isset($_POST['user_id_to_update']) ? absint($_POST['user_id_to_update']) : 0;
    if ($user_id <= 0 || !get_userdata($user_id)) {
         wp_die(__('Invalid User ID specified.', 'question-press'));
    }

    // 3. Get and Sanitize Selected Exams and Subjects
    // If the checkbox array is not submitted (nothing checked), default to an empty array.
    $allowed_exams = isset($_POST['allowed_exams']) && is_array($_POST['allowed_exams'])
                     ? array_map('absint', $_POST['allowed_exams'])
                     : [];

    $allowed_subjects = isset($_POST['allowed_subjects']) && is_array($_POST['allowed_subjects'])
                        ? array_map('absint', $_POST['allowed_subjects'])
                        : [];

    // 4. Update User Meta
    // Store as JSON for easier handling on retrieval
    update_user_meta($user_id, '_qp_allowed_exam_term_ids', json_encode($allowed_exams));
    update_user_meta($user_id, '_qp_allowed_subject_term_ids', json_encode($allowed_subjects));

    // 5. Redirect back with success message
    $redirect_url = add_query_arg([
        'page' => 'qp-user-entitlements',
        'user_id' => $user_id,
        'message' => 'scope_updated' // Use this to display success notice on the page
    ], admin_url('admin.php'));

    wp_safe_redirect($redirect_url);
    exit;
}

function qp_handle_save_question_group()
{
    if (!isset($_POST['save_group']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'qp_save_question_group_nonce')) {
        return;
    }

    global $wpdb;
    $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
    $is_editing = $group_id > 0;

    // --- Get group-level data from the form ---
    $direction_text = isset($_POST['direction_text']) ? stripslashes($_POST['direction_text']) : '';
    $direction_image_id = absint($_POST['direction_image_id']);
    $is_pyq = isset($_POST['is_pyq']) ? 1 : 0;
    $pyq_year = isset($_POST['pyq_year']) ? sanitize_text_field($_POST['pyq_year']) : '';
    $questions_from_form = isset($_POST['questions']) ? (array) $_POST['questions'] : [];

    if (empty($_POST['subject_id']) || empty($questions_from_form)) {
        // A subject is required to save a group.
        // Silently fail if no subject is selected to avoid errors on page load.
        return;
    }

    // --- Save Group Data ---
    $group_data = [
        'direction_text'     => wp_kses_post($direction_text),
        'direction_image_id' => $direction_image_id,
        'is_pyq'             => $is_pyq,
        'pyq_year'           => $is_pyq ? $pyq_year : null,
    ];

    if ($is_editing) {
        // Use the new update method
        Questions_DB::update_group( $group_id, $group_data );
    } else {
        // Use the new insert method and get the new ID
        $new_group_id = Questions_DB::insert_group( $group_data );
        if ($new_group_id) {
            $group_id = $new_group_id; // Assign the new ID for subsequent operations
        } else {
            // Handle error - maybe set an admin notice and return?
            wp_die('Error creating question group.'); // Simple error handling for now
            return;
        }
    }

    // --- CONSOLIDATED Group-Level Term Relationship Handling ---
    if ($group_id) {
        $rel_table = "{$wpdb->prefix}qp_term_relationships";
        $term_table = "{$wpdb->prefix}qp_terms";
        $tax_table = "{$wpdb->prefix}qp_taxonomies";

        $group_taxonomies_to_manage = ['subject', 'source', 'exam'];
        $tax_ids_to_clear = [];
        foreach ($group_taxonomies_to_manage as $tax_name) {
            $tax_id = $wpdb->get_var($wpdb->prepare("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", $tax_name));
            if ($tax_id) $tax_ids_to_clear[] = $tax_id;
        }

        // 1. Delete all existing relationships for this group across managed taxonomies
        if (!empty($tax_ids_to_clear)) {
            $tax_ids_placeholder = implode(',', $tax_ids_to_clear);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'group' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id IN ($tax_ids_placeholder))",
                $group_id
            ));
        }

        // 2. Determine the new terms to apply
        $terms_to_apply_to_group = [];
        // Subject/Topic: Use the most specific topic selected, which represents the entire subject hierarchy.
        if (!empty($_POST['topic_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['topic_id']);
        } elseif (!empty($_POST['subject_id'])) {
            // Fallback to parent subject if no topic is chosen
            $terms_to_apply_to_group[] = absint($_POST['subject_id']);
        }

        // Source/Section: Use the most specific term selected.
        if (!empty($_POST['section_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['section_id']);
        } elseif (!empty($_POST['source_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['source_id']);
        }

        // Exam: Apply if PYQ is checked and an exam is selected.
        if ($is_pyq && !empty($_POST['exam_id'])) {
            $terms_to_apply_to_group[] = absint($_POST['exam_id']);
        }

        // 3. Insert the new, clean relationships for the group
        foreach (array_unique($terms_to_apply_to_group) as $term_id) {
            if ($term_id > 0) {
                $wpdb->insert($rel_table, ['object_id' => $group_id, 'term_id' => $term_id, 'object_type' => 'group']);
            }
        }
    }

    // --- Process Individual Questions (and their Label relationships) ---
    $q_table = "{$wpdb->prefix}qp_questions";
    $o_table = "{$wpdb->prefix}qp_options";
    $existing_q_ids = $is_editing ? $wpdb->get_col($wpdb->prepare("SELECT question_id FROM $q_table WHERE group_id = %d", $group_id)) : [];
    $submitted_q_ids = [];

    foreach ($questions_from_form as $q_data) {
        $question_text = isset($q_data['question_text']) ? stripslashes($q_data['question_text']) : '';
        if (empty(trim($question_text))) continue;

        $question_id = isset($q_data['question_id']) ? absint($q_data['question_id']) : 0;
        $is_question_complete = !empty($q_data['correct_option_id']);

        $question_db_data = [
            'group_id' => $group_id,
            'question_number_in_section' => isset($q_data['question_number_in_section']) ? sanitize_text_field($q_data['question_number_in_section']) : '',
            'question_text' => wp_kses_post($question_text),
            'question_text_hash' => md5(strtolower(trim(preg_replace('/\s+/', '', $question_text)))),
            'status' => $is_question_complete ? 'publish' : 'draft',
        ];

        if ($question_id > 0 && in_array($question_id, $existing_q_ids)) {
            $wpdb->update($q_table, $question_db_data, ['question_id' => $question_id]);
        } else {
            $wpdb->insert($q_table, $question_db_data);
            $question_id = $wpdb->insert_id;
        }
        $submitted_q_ids[] = $question_id;

        if ($question_id > 0) {
            // Handle Question-Level Relationships (LABELS ONLY)
            $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'label'");
            if ($label_tax_id) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$rel_table} WHERE object_id = %d AND object_type = 'question' AND term_id IN (SELECT term_id FROM {$term_table} WHERE taxonomy_id = %d)", $question_id, $label_tax_id));
            }
            $labels = isset($q_data['labels']) ? array_map('absint', $q_data['labels']) : [];
            foreach ($labels as $label_id) {
                if ($label_id > 0) {
                    $wpdb->insert($rel_table, ['object_id' => $question_id, 'term_id' => $label_id, 'object_type' => 'question']);
                }
            }

            // Get original correct option ID BEFORE saving new options
            $original_correct_option_id = QuestionPress\Database\Questions_DB::get_correct_option_id($question_id);

            // Call the new static method to save options
            $correct_option_id_set = QuestionPress\Database\Questions_DB::save_options_for_question($question_id, $q_data);

            // --- Re-evaluation logic (moved here) ---
            if ($original_correct_option_id != $correct_option_id_set) {
                qp_re_evaluate_question_attempts($question_id, absint($correct_option_id_set));
            }
        }
    }   

    // --- Clean up removed questions ---
    $questions_to_delete = array_diff($existing_q_ids, $submitted_q_ids);
    if (!empty($questions_to_delete)) {
        $ids_placeholder = implode(',', array_map('absint', $questions_to_delete));
        $wpdb->query("DELETE FROM $o_table WHERE question_id IN ($ids_placeholder)");
        $wpdb->query("DELETE FROM $rel_table WHERE object_id IN ($ids_placeholder) AND object_type = 'question'");
        $wpdb->query("DELETE FROM $q_table WHERE question_id IN ($ids_placeholder)");
    }

    // --- Final Redirect ---
    if ($is_editing && empty($submitted_q_ids)) {
    // Use the new method to delete the group and its relationships
    QuestionPress\Database\Questions_DB::delete_group_and_contents($group_id);
    wp_safe_redirect(admin_url('admin.php?page=question-press&message=1')); // Keep redirect
    exit;
}

    $redirect_url = $is_editing
        ? admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1')
        : admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=2');

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        wp_send_json_success(['redirect_url' => $redirect_url]);
    }

    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Re-evaluates all attempts for a specific question after its correct answer has changed.
 * It also recalculates and updates the stats for all affected sessions.
 *
 * @param int $question_id The ID of the question that was updated.
 * @param int $new_correct_option_id The ID of the new correct option.
 */
function qp_re_evaluate_question_attempts($question_id, $new_correct_option_id)
{
    global $wpdb;
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // 1. Find all session IDs that have an attempt for this question.
    $affected_session_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT session_id FROM {$attempts_table} WHERE question_id = %d",
        $question_id
    ));

    if (empty($affected_session_ids)) {
        return; // No attempts to update.
    }

    // 2. Update the is_correct status for all attempts of this question.
    // Set is_correct = 1 where the selected option matches the new correct option.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$attempts_table} SET is_correct = 1 WHERE question_id = %d AND selected_option_id = %d",
        $question_id,
        $new_correct_option_id
    ));
    // Set is_correct = 0 for all other attempts of this question.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$attempts_table} SET is_correct = 0 WHERE question_id = %d AND selected_option_id != %d",
        $question_id,
        $new_correct_option_id
    ));

    // 3. Loop through each affected session and recalculate its score.
    foreach ($affected_session_ids as $session_id) {
        $session = $wpdb->get_row($wpdb->prepare("SELECT settings_snapshot FROM {$sessions_table} WHERE session_id = %d", $session_id));
        if (!$session) continue;

        $settings = json_decode($session->settings_snapshot, true);
        $marks_correct = $settings['marks_correct'] ?? 0;
        $marks_incorrect = $settings['marks_incorrect'] ?? 0;

        // Recalculate counts directly from the attempts table for this session
        $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 1", $session_id));
        $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND is_correct = 0", $session_id));

        $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);

        // Update the session record with the new, accurate counts and score.
        $wpdb->update(
            $sessions_table,
            [
                'correct_count' => $correct_count,
                'incorrect_count' => $incorrect_count,
                'marks_obtained' => $final_score
            ],
            ['session_id' => $session_id]
        );
    }
}

/**
 * Performs the core backup creation process and saves the file locally.
 *
 * @param string $type The type of backup ('manual' or 'auto').
 * @return array An array containing 'success' status and a 'message' or 'filename'.
 */
function qp_perform_backup($type = 'manual')
{
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }

    $tables_to_backup = [
        'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
        'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
        'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
        'qp_terms', 'qp_term_meta', 'qp_term_relationships',
    ];
    $full_table_names = array_map(fn($table) => $wpdb->prefix . $table, $tables_to_backup);

    $backup_data = [];
    foreach ($full_table_names as $table) {
        $table_name_without_prefix = str_replace($wpdb->prefix, '', $table);
        $backup_data[$table_name_without_prefix] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
    }

    $backup_data['plugin_settings'] = ['qp_settings' => get_option('qp_settings')];

    // *** THIS IS THE FIX: Part 1 - Create the Image Map ***
    $image_ids = $wpdb->get_col("SELECT DISTINCT direction_image_id FROM {$wpdb->prefix}qp_question_groups WHERE direction_image_id IS NOT NULL AND direction_image_id > 0");
    $image_map = [];
    $images_to_zip = [];
    if (!empty($image_ids)) {
        foreach ($image_ids as $image_id) {
            $image_path = get_attached_file($image_id);
            if ($image_path && file_exists($image_path)) {
                $image_filename = basename($image_path);
                $image_map[$image_id] = $image_filename; // Map ID to filename
                $images_to_zip[$image_filename] = $image_path; // Store unique paths to zip
            }
        }
    }
    $backup_data['image_map'] = $image_map; // Add the map to the backup data

    $json_data = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json_filename = 'database.json';
    $temp_json_path = trailingslashit($backup_dir) . $json_filename;
    file_put_contents($temp_json_path, $json_data);

    $prefix = ($type === 'auto') ? 'qp-auto-backup-' : 'qp-backup-';
    $timestamp = current_time('mysql');
    $datetime = new DateTime($timestamp);
    $timezone_abbr = 'IST';
    $backup_filename = $prefix . $datetime->format('Y-m-d_H-i-s') . '_' . $timezone_abbr . '.zip';
    $zip_path = trailingslashit($backup_dir) . $backup_filename;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return ['success' => false, 'message' => 'Cannot create ZIP archive.'];
    }

    $zip->addFile($temp_json_path, $json_filename);

    if (!empty($images_to_zip)) {
        $zip->addEmptyDir('images');
        foreach ($images_to_zip as $filename => $path) {
            $zip->addFile($path, 'images/' . $filename);
        }
    }

    $zip->close();
    unlink($temp_json_path);
    qp_prune_old_backups();

    return ['success' => true, 'filename' => $backup_filename];
}

/**
 * Intelligently prunes old backup files based on saved schedule settings.
 * Correctly sorts by file modification time and respects pruning rules.
 */
function qp_prune_old_backups()
{
    $schedule = get_option('qp_auto_backup_schedule', false);
    if (!$schedule || !isset($schedule['keep'])) {
        return; // No schedule or keep limit set, so do nothing.
    }

    $backups_to_keep = absint($schedule['keep']);
    $prune_manual = !empty($schedule['prune_manual']);

    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    if (!is_dir($backup_dir)) {
        return;
    }

    $all_files_in_dir = array_diff(scandir($backup_dir), ['..', '.']);

    // Create a detailed list of backup files with their timestamps
    $backup_files_with_time = [];
    foreach ($all_files_in_dir as $file) {
        $is_auto = strpos($file, 'qp-auto-backup-') === 0;
        $is_manual = strpos($file, 'qp-backup-') === 0;

        if ($is_auto || $is_manual) {
            $backup_files_with_time[] = [
                'name' => $file,
                'type' => $is_auto ? 'auto' : 'manual',
                'time' => filemtime(trailingslashit($backup_dir) . $file)
            ];
        }
    }

    // Determine which files are candidates for deletion
    $candidate_files = [];
    if ($prune_manual) {
        // If pruning manual, all backups are candidates
        $candidate_files = $backup_files_with_time;
    } else {
        // Otherwise, only auto-backups are candidates
        foreach ($backup_files_with_time as $file_data) {
            if ($file_data['type'] === 'auto') {
                $candidate_files[] = $file_data;
            }
        }
    }

    if (count($candidate_files) <= $backups_to_keep) {
        return; // Nothing to do
    }

    // **CRITICAL FIX:** Sort candidates by their actual file time, oldest first
    usort($candidate_files, function ($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    $backups_to_delete = array_slice($candidate_files, 0, count($candidate_files) - $backups_to_keep);

    foreach ($backups_to_delete as $file_data_to_delete) {
        unlink(trailingslashit($backup_dir) . $file_data_to_delete['name']);
    }
}

/**
 * The function that runs on the scheduled cron event to create a backup.
 */
function qp_run_scheduled_backup_event()
{
    qp_prune_old_backups();
    qp_perform_backup('auto');
}

/**
 * Scans the backup directory and returns the HTML for the local backups table body.
 *
 * @return string The HTML for the table rows.
 */
function qp_get_local_backups_html()
{
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    $backup_url_base = trailingslashit($upload_dir['baseurl']) . 'qp-backups';
    $backups = file_exists($backup_dir) ? array_diff(scandir($backup_dir), ['..', '.']) : [];

    // --- NEW SORTING LOGIC ---
    $sorted_backups = [];
    if (!empty($backups)) {
        $files_with_time = [];
        foreach ($backups as $backup_file) {
            $file_path = trailingslashit($backup_dir) . $backup_file;
            if (is_dir($file_path)) continue;
            $files_with_time[$backup_file] = filemtime($file_path);
        }

        // Sort by time descending, then by name ascending for tie-breaking
        uksort($files_with_time, function ($a, $b) use ($files_with_time) {
            if ($files_with_time[$a] == $files_with_time[$b]) {
                return strcmp($a, $b); // Sort by name if times are identical
            }
            // Primary sort by modification time, descending
            return $files_with_time[$b] <=> $files_with_time[$a];
        });
        $sorted_backups = array_keys($files_with_time);
    }
    // --- END NEW SORTING LOGIC ---

    ob_start();

    if (empty($sorted_backups)) { // Use the new sorted array
        echo '<tr class="no-items"><td class="colspanchange" colspan="4">No local backups found.</td></tr>';
    } else {
        foreach ($sorted_backups as $backup_file) { // Iterate over the new sorted array
            $file_path = trailingslashit($backup_dir) . $backup_file;
            $file_url = trailingslashit($backup_url_base) . $backup_file;

            $file_size = size_format(filesize($file_path));
            $file_timestamp_gmt = filemtime($file_path);
            $file_date = get_date_from_gmt(date('Y-m-d H:i:s', $file_timestamp_gmt), 'M j, Y, g:i a');
    ?>
            <tr data-filename="<?php echo esc_attr($backup_file); ?>">
                <td><?php echo esc_html($file_date); ?></td>
                <td>
                    <?php if (strpos($backup_file, 'qp-auto-backup-') === 0) : ?>
                        <span style="background-color: #dadae0ff; color: #383d42ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">AUTO</span>
                    <?php else : ?>
                        <span style="background-color: #d8e7f2ff; color: #0f82e7ff; padding: 2px 6px; font-size: 10px; border-radius: 3px; font-weight: 600; vertical-align: middle; margin-left: 5px;">MANUAL</span>
                    <?php endif; ?>
                    <?php echo esc_html($backup_file); ?>
                </td>
                <td><?php echo esc_html($file_size); ?></td>
                <td>
                    <a href="<?php echo esc_url($file_url); ?>" class="button button-secondary" download>Download</a>
                    <button type="button" class="button button-primary qp-restore-btn">Restore</button>
                    <button type="button" class="button button-link-delete qp-delete-backup-btn">Delete</button>
                </td>
            </tr>
    <?php
        }
    }
    return ob_get_clean();
}

/**
 * Performs the core backup restore process from a given filename.
 *
 * @param string $filename The name of the backup .zip file in the qp-backups directory.
 * @return array An array containing 'success' status and a 'message' or 'stats'.
 */
function qp_perform_restore($filename)
{
    @ini_set('max_execution_time', 300);
    @ini_set('memory_limit', '256M');

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'qp-backups';
    $file_path = trailingslashit($backup_dir) . $filename;
    $temp_extract_dir = trailingslashit($backup_dir) . 'temp_restore_' . time();

    if (!file_exists($file_path)) {
        return ['success' => false, 'message' => 'Backup file not found on server.'];
    }

    wp_mkdir_p($temp_extract_dir);
    $zip = new ZipArchive;
    if ($zip->open($file_path) !== TRUE) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'Failed to open the backup file.'];
    }
    $zip->extractTo($temp_extract_dir);
    $zip->close();

    $json_file_path = trailingslashit($temp_extract_dir) . 'database.json';
    if (!file_exists($json_file_path)) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'database.json not found in the backup file.'];
    }

    $backup_data = json_decode(file_get_contents($json_file_path), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        qp_delete_dir($temp_extract_dir);
        return ['success' => false, 'message' => 'Invalid JSON in backup file.'];
    }

    // --- Image ID Mapping ---
    $old_to_new_id_map = [];
    $images_dir = trailingslashit($temp_extract_dir) . 'images';
    if (isset($backup_data['image_map']) && is_array($backup_data['image_map']) && file_exists($images_dir)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        foreach ($backup_data['image_map'] as $old_id => $image_filename) {
            $image_path = trailingslashit($images_dir) . $image_filename;
            if (file_exists($image_path)) {
                $existing_attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $wpdb->esc_like($image_filename)));
                
                if ($existing_attachment_id) {
                    $new_id = $existing_attachment_id;
                } else {
                    $new_id = media_handle_sideload(['name' => $image_filename, 'tmp_name' => $image_path], 0);
                }

                if (!is_wp_error($new_id)) {
                    $old_to_new_id_map[$old_id] = $new_id;
                }
            }
        }
    }

    if (isset($backup_data['qp_question_groups']) && !empty($old_to_new_id_map)) {
        foreach ($backup_data['qp_question_groups'] as &$group) {
            if (!empty($group['direction_image_id']) && isset($old_to_new_id_map[$group['direction_image_id']])) {
                $group['direction_image_id'] = $old_to_new_id_map[$group['direction_image_id']];
            }
        }
        unset($group);
    }
    
    // --- Clear Existing Data ---
    $tables_to_clear = [
        'qp_question_groups', 'qp_questions', 'qp_options', 'qp_report_reasons',
        'qp_question_reports', 'qp_logs', 'qp_user_sessions', 'qp_session_pauses',
        'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts', 'qp_taxonomies',
        'qp_terms', 'qp_term_meta', 'qp_term_relationships',
    ];
    $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables_to_clear as $table) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$table}");
    }
    $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

    // --- Deduplicate Attempts and Calculate Stats ---
    $duplicates_handled = 0;
    if (!empty($backup_data['qp_user_attempts'])) {
        $original_attempt_count = count($backup_data['qp_user_attempts']);
        $unique_attempts = [];
        foreach ($backup_data['qp_user_attempts'] as $attempt) {
            $key = $attempt['session_id'] . '-' . $attempt['question_id'];
            if (!isset($unique_attempts[$key])) {
                $unique_attempts[$key] = $attempt;
            } else {
                $existing_attempt = $unique_attempts[$key];
                if (!empty($attempt['selected_option_id']) && empty($existing_attempt['selected_option_id'])) {
                    $unique_attempts[$key] = $attempt;
                }
            }
        }
        $final_attempts = array_values($unique_attempts);
        $duplicates_handled = $original_attempt_count - count($final_attempts);
        $backup_data['qp_user_attempts'] = $final_attempts;
    }

    // *** THIS IS THE FIX: Calculate stats AFTER data processing ***
    $stats = [
        'questions' => isset($backup_data['qp_questions']) ? count($backup_data['qp_questions']) : 0,
        'options' => isset($backup_data['qp_options']) ? count($backup_data['qp_options']) : 0,
        'sessions' => isset($backup_data['qp_user_sessions']) ? count($backup_data['qp_user_sessions']) : 0,
        'attempts' => isset($backup_data['qp_user_attempts']) ? count($backup_data['qp_user_attempts']) : 0,
        'reports' => isset($backup_data['qp_question_reports']) ? count($backup_data['qp_question_reports']) : 0,
        'duplicates_handled' => $duplicates_handled
    ];
    // *** END FIX ***

    // --- Insert Restored Data into Database ---
    $restore_order = [
        'qp_taxonomies', 'qp_terms', 'qp_term_meta', 'qp_term_relationships', 'qp_question_groups', 
        'qp_questions', 'qp_options', 'qp_report_reasons', 'qp_question_reports', 'qp_logs', 
        'qp_user_sessions', 'qp_session_pauses', 'qp_user_attempts', 'qp_review_later', 'qp_revision_attempts'
    ];
    foreach ($restore_order as $table_name) {
        if (!empty($backup_data[$table_name])) {
            $rows = $backup_data[$table_name];
            $chunks = array_chunk($rows, 100);
            foreach ($chunks as $chunk) {
                if (empty($chunk)) continue;
                $columns = array_keys($chunk[0]);
                $placeholders = [];
                $values = [];
                foreach ($chunk as $row) {
                    $row_placeholders = [];
                    foreach ($columns as $column) {
                        $row_placeholders[] = '%s';
                        $values[] = $row[$column];
                    }
                    $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
                }
                $query = "INSERT INTO {$wpdb->prefix}{$table_name} (`" . implode('`, `', $columns) . "`) VALUES " . implode(', ', $placeholders);
                if ($wpdb->query($wpdb->prepare($query, $values)) === false) {
                    qp_delete_dir($temp_extract_dir);
                    return ['success' => false, 'message' => "An error occurred while restoring '{$table_name}'. DB Error: " . $wpdb->last_error];
                }
            }
        }
    }

    if (isset($backup_data['plugin_settings'])) {
        update_option('qp_settings', $backup_data['plugin_settings']['qp_settings']);
    }

    qp_delete_dir($temp_extract_dir);
    return ['success' => true, 'stats' => $stats];
}

/**
 * Helper function to recursively delete a directory.
 *
 * @param string $dirPath The path to the directory to delete.
 */
function qp_delete_dir($dirPath)
{
    if (!is_dir($dirPath)) {
        return;
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        is_dir($file) ? qp_delete_dir($file) : unlink($file);
    }
    rmdir($dirPath);
}



function qp_admin_head_styles_for_list_table()
{
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_question-press') {
    ?>
        <style type="text/css">
            .qp-multi-select-dropdown {
                position: relative;
                display: inline-block;
                vertical-align: middle;
            }

            .qp-multi-select-list {
                display: none;
                position: absolute;
                background-color: white;
                border: 1px solid #ccc;
                z-index: 1000;
                padding: 10px;
                max-height: 250px;
                overflow-y: auto;
            }

            .qp-multi-select-list label {
                display: block;
                white-space: nowrap;
                padding: 5px;
            }

            .qp-multi-select-list label:hover {
                background-color: #f1f1f1;
            }

            .qp-organization-table .column-name {
                width: 35%;
            }

            .qp-organization-table .column-description {
                width: 50%;
            }

            .qp-organization-table .column-count {
                width: 15%;
                text-align: center;
            }
        </style>
    <?php
    }
}

function qp_handle_report_actions()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['action'])) {
        return;
    }

    global $wpdb;
    $reports_table = "{$wpdb->prefix}qp_question_reports";

    // Handle single resolve action
    if ($_GET['action'] === 'resolve_report' && isset($_GET['question_id'])) {
        $question_id = absint($_GET['question_id']);
        check_admin_referer('qp_resolve_report_' . $question_id);
        $wpdb->update($reports_table, ['status' => 'resolved'], ['question_id' => $question_id, 'status' => 'open']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&message=3'));
        exit;
    }

    // Handle single re-open action
    if ($_GET['action'] === 'reopen_report' && isset($_GET['question_id'])) {
        $question_id = absint($_GET['question_id']);
        check_admin_referer('qp_reopen_report_' . $question_id);
        $wpdb->update($reports_table, ['status' => 'open'], ['question_id' => $question_id, 'status' => 'resolved']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=4'));
        exit;
    }

    // Handle clearing all resolved reports
    if ($_GET['action'] === 'clear_resolved_reports') {
        check_admin_referer('qp_clear_all_reports_nonce');
        $wpdb->delete($reports_table, ['status' => 'resolved']);
        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=reports&status=resolved&message=5'));
        exit;
    }
}

/**
 * Handles resolving all open reports for a group from the question editor page.
 */
function qp_handle_resolve_from_editor()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-edit-group' || !isset($_GET['action']) || $_GET['action'] !== 'resolve_group_reports') {
        return;
    }

    $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
    if (!$group_id) return;

    check_admin_referer('qp_resolve_group_reports_' . $group_id);

    global $wpdb;
    $questions_in_group_ids = $wpdb->get_col($wpdb->prepare("SELECT question_id FROM {$wpdb->prefix}qp_questions WHERE group_id = %d", $group_id));

    if (!empty($questions_in_group_ids)) {
        $ids_placeholder = implode(',', $questions_in_group_ids);
        $wpdb->query("UPDATE {$wpdb->prefix}qp_question_reports SET status = 'resolved' WHERE question_id IN ({$ids_placeholder}) AND status = 'open'");
    }

    // Redirect back to the editor page with a success message
    wp_safe_redirect(admin_url('admin.php?page=qp-edit-group&group_id=' . $group_id . '&message=1'));
    exit;
}

/**
 * Register custom query variables for dashboard routing.
 *
 * @param array $vars Existing query variables.
 * @return array Modified query variables.
 */
function qp_register_query_vars($vars) {
    $vars[] = 'qp_tab';          // To identify the main dashboard section (e.g., 'history', 'courses')
    $vars[] = 'qp_course_slug'; // To identify a specific course by its slug
    return $vars;
}

/**
 * Add rewrite rules for the dynamic dashboard URLs.
 */
function qp_add_dashboard_rewrite_rules() {
    $options = get_option('qp_settings');
    // Ensure 'dashboard_page' key exists before accessing it
    $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;

    // Only add rules if a valid dashboard page ID is set
    if ($dashboard_page_id > 0) {
        // Get the slug (URL path) of the selected dashboard page
        $dashboard_slug = get_post_field('post_name', $dashboard_page_id);

        // Proceed only if we successfully retrieved the slug
        if ($dashboard_slug) {
            // Rule for specific course: /dashboard-slug/courses/course-slug/
            // Maps the URL to index.php?pagename=[dashboard-slug]&qp_tab=courses&qp_course_slug=[course-slug]
            add_rewrite_rule(
                '^' . $dashboard_slug . '/courses/([^/]+)/?$', // Matches /dashboard-slug/courses/ANYTHING/
                'index.php?pagename=' . $dashboard_slug . '&qp_tab=courses&qp_course_slug=$matches[1]',
                'top' // Process this rule early
            );

            // Rule for profile tab: /dashboard-slug/profile/
            add_rewrite_rule(
                '^' . $dashboard_slug . '/profile/?$', // Matches /dashboard-slug/profile/
                'index.php?pagename=' . $dashboard_slug . '&qp_tab=profile', // Map to profile tab
                'top'
            );

            // Rule for main tab: /dashboard-slug/tab-name/
            // Maps the URL to index.php?pagename=[dashboard-slug]&qp_tab=[tab-name]
            add_rewrite_rule(
                '^' . $dashboard_slug . '/([^/]+)/?$', // Matches /dashboard-slug/ANYTHING/
                'index.php?pagename=' . $dashboard_slug . '&qp_tab=$matches[1]',
                'top' // Process this rule early
            );

            // Optional: Rule for the base dashboard URL /dashboard-slug/
            // WordPress might handle this automatically if the page structure is correct,
            // but adding it explicitly can sometimes help.
            add_rewrite_rule(
                '^' . $dashboard_slug . '/?$', // Matches /dashboard-slug/
                'index.php?pagename=' . $dashboard_slug, // Just load the dashboard page
                'top'
            );
        } else {
            // Log an error or add an admin notice if the slug couldn't be found
            error_log('Question Press: Could not retrieve slug for dashboard page ID: ' . $dashboard_page_id);
        }
    } else {
        // Log an error or add an admin notice if the dashboard page isn't set
        error_log('Question Press: Dashboard page ID not set in options.');
    }
}

/**
 * Flush rewrite rules on plugin activation.
 */
function qp_flush_rewrite_rules_on_activate() {
    // Ensure our rules are added before flushing
    qp_add_dashboard_rewrite_rules();
    // Flush the rules
    flush_rewrite_rules();
}
// Make sure QP_PLUGIN_FILE is defined correctly (it should be from your main plugin file)
if (defined('QP_PLUGIN_FILE')) {
    register_activation_hook(QP_PLUGIN_FILE, 'qp_flush_rewrite_rules_on_activate');
}


/**
 * Flush rewrite rules on plugin deactivation.
 */
function qp_flush_rewrite_rules_on_deactivate() {
    // Flush the rules to remove ours
    flush_rewrite_rules();
}

function qp_get_practice_form_html_ajax()
{
    check_ajax_referer('qp_practice_nonce', 'nonce');
    wp_send_json_success(['form_html' => QP_Shortcodes::render_practice_form()]);
}


/**
 * Adds a notification bubble with the count of open reports to the admin menu.
 */
function qp_add_report_count_to_menu()
{
    global $wpdb, $menu, $submenu;

    // Only show the count to users who can manage the plugin
    if (!current_user_can('manage_options')) {
        return;
    }

    $reports_table = $wpdb->prefix . 'qp_question_reports';
    // Get the count of open reports (not just distinct questions)
    $open_reports_count = (int) $wpdb->get_var("SELECT COUNT(report_id) FROM {$reports_table} WHERE status = 'open'");

    if ($open_reports_count > 0) {
        // Create the bubble HTML using standard WordPress classes
        $bubble = " <span class='awaiting-mod'><span class='count-{$open_reports_count}'>{$open_reports_count}</span></span>";

        // Determine if we are on a Question Press admin page.
        $is_qp_page = (isset($_GET['page']) && strpos($_GET['page'], 'qp-') === 0) || (isset($_GET['page']) && $_GET['page'] === 'question-press');

        // Only add the bubble to the top-level menu if we are NOT on a Question Press page.
        if (!$is_qp_page) {
            foreach ($menu as $key => $value) {
                if ($value[2] == 'question-press') {
                    $menu[$key][0] .= $bubble;
                    break;
                }
            }
        }

        // Always add the bubble to the "Reports" submenu item regardless of the current page.
        if (isset($submenu['question-press'])) {
            foreach ($submenu['question-press'] as $key => $value) {
                if ($value[2] == 'qp-logs-reports') {
                    $submenu['question-press'][$key][0] .= $bubble;
                    break;
                }
            }
        }
    }
}

/**
 * Helper function to calculate final stats and update a session record.
 *
 * @param int    $session_id The ID of the session to finalize.
 * @param string $new_status The status to set for the session (e.g., 'completed', 'abandoned').
 * @param string|null $end_reason The reason the session ended.
 * @return array|null An array of summary data, or null if the session was empty.
 */
function qp_finalize_and_end_session($session_id, $new_status = 'completed', $end_reason = null)
{
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'qp_user_sessions';
    $attempts_table = $wpdb->prefix . 'qp_user_attempts';
    $pauses_table = $wpdb->prefix . 'qp_session_pauses';

    $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE session_id = %d", $session_id));
    if (!$session) {
        return null;
    }

    // Check for any answered attempts.
    $total_answered_attempts = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$attempts_table} WHERE session_id = %d AND status = 'answered'",
        $session_id
    ));

    // If there are no answered attempts, delete the session immediately and stop.
    if ($total_answered_attempts === 0) {
        $wpdb->delete($sessions_table, ['session_id' => $session_id]);
        $wpdb->delete($attempts_table, ['session_id' => $session_id]); // Also clear any skipped/expired attempts
        return null; // Indicate that the session was empty and deleted
    }

    // If we are here, it means there were attempts, so we proceed to finalize.
    $settings = json_decode($session->settings_snapshot, true);
    $marks_correct = $settings['marks_correct'] ?? 0;
    $marks_incorrect = $settings['marks_incorrect'] ?? 0;
    $is_mock_test = isset($settings['practice_mode']) && $settings['practice_mode'] === 'mock_test';

    if ($is_mock_test) {
        // Grade any unanswered mock test questions
        $answered_attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT attempt_id, question_id, selected_option_id FROM {$attempts_table} WHERE session_id = %d AND mock_status IN ('answered', 'answered_and_marked_for_review')",
            $session_id
        ));
        $options_table = $wpdb->prefix . 'qp_options';
        foreach ($answered_attempts as $attempt) {
            $is_correct = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT is_correct FROM {$options_table} WHERE option_id = %d AND question_id = %d",
                $attempt->selected_option_id,
                $attempt->question_id
            ));
            $wpdb->update($attempts_table, ['is_correct' => $is_correct ? 1 : 0], ['attempt_id' => $attempt->attempt_id]);
        }
        $all_question_ids_in_session = json_decode($session->question_ids_snapshot, true);
        $interacted_question_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT question_id FROM {$attempts_table} WHERE session_id = %d", $session_id));
        $not_viewed_ids = array_diff($all_question_ids_in_session, $interacted_question_ids);
        foreach ($not_viewed_ids as $question_id) {
            $wpdb->insert($attempts_table, [
                'session_id' => $session_id,
                'user_id' => $session->user_id,
                'question_id' => $question_id,
                'status' => 'skipped',
                'mock_status' => 'not_viewed'
            ]);
        }
        $wpdb->query($wpdb->prepare("UPDATE {$attempts_table} SET status = 'skipped' WHERE session_id = %d AND mock_status IN ('viewed', 'marked_for_review')", $session_id));
    }

    $correct_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 1", $session_id));
    $incorrect_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND is_correct = 0", $session_id));
    $total_attempted = $correct_count + $incorrect_count;
    $not_viewed_count = 0;
    if ($is_mock_test) {
        $unattempted_count = count(json_decode($session->question_ids_snapshot, true)) - $total_attempted;
        $not_viewed_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND mock_status = 'not_viewed'", $session_id));
    } else {
        $unattempted_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $attempts_table WHERE session_id = %d AND status = 'skipped'", $session_id));
    }
    $skipped_count = $unattempted_count;
    $final_score = ($correct_count * $marks_correct) + ($incorrect_count * $marks_incorrect);
    $end_time_for_calc = ($new_status === 'abandoned' && !empty($session->last_activity) && $session->last_activity !== '0000-00-00 00:00:00') ? $session->last_activity : current_time('mysql');
    $end_time_gmt = get_gmt_from_date($end_time_for_calc);
    $start_time_gmt = get_gmt_from_date($session->start_time);
    $total_session_duration = strtotime($end_time_gmt) - strtotime($start_time_gmt);
    $total_active_seconds = max(0, $total_session_duration);
    if (!$is_mock_test) {
        $pause_records = $wpdb->get_results($wpdb->prepare("SELECT pause_time, resume_time FROM {$pauses_table} WHERE session_id = %d", $session_id));
        $total_pause_duration = 0;
        foreach ($pause_records as $pause) {
            $resume_time_gmt = $pause->resume_time ? get_gmt_from_date($pause->resume_time) : $end_time_gmt;
            $pause_time_gmt = get_gmt_from_date($pause->pause_time);
            $total_pause_duration += strtotime($resume_time_gmt) - strtotime($pause_time_gmt);
        }
        $total_active_seconds = max(0, $total_session_duration - $total_pause_duration);
    }

    $wpdb->update($sessions_table, [
        'end_time' => $end_time_for_calc,
        'status' => $new_status,
        'end_reason' => $end_reason,
        'total_active_seconds' => $total_active_seconds,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
        'not_viewed_count' => $not_viewed_count,
        'marks_obtained' => $final_score
    ], ['session_id' => $session_id]);

    // --- NEW: Update Course Item Progress if applicable ---
    if (($new_status === 'completed' || $new_status === 'abandoned') && // Only update progress if session truly ended
        isset($settings['course_id']) && isset($settings['item_id'])) {

        $course_id = absint($settings['course_id']);
        $item_id = absint($settings['item_id']);
        $user_id = $session->user_id; // Get user ID from the session object
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';
        $items_table = $wpdb->prefix . 'qp_course_items'; // <<< Keep this variable definition

        // *** START NEW CHECK ***
        // Check if the course item still exists before trying to update progress
        $item_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE item_id = %d AND course_id = %d",
            $item_id,
            $course_id
        ));

        if ($item_exists) {
            // *** Item exists, proceed with updating progress ***

            // Prepare result data (customize as needed)
            $result_data = json_encode([
                'score' => $final_score,
                'correct' => $correct_count,
                'incorrect' => $incorrect_count,
                'skipped' => $skipped_count,
                'not_viewed' => $not_viewed_count, // Include if relevant (from mock tests)
                'total_attempted' => $total_attempted,
                'session_id' => $session_id // Store the session ID for potential review linking
            ]);

            // Use REPLACE INTO for simplicity
            $wpdb->query($wpdb->prepare(
                "REPLACE INTO {$progress_table} (user_id, item_id, course_id, status, completion_date, result_data, last_viewed)
                 VALUES (%d, %d, %d, %s, %s, %s, %s)",
                $user_id,
                $item_id,
                $course_id,
                'completed', // Mark item as completed when session ends
                current_time('mysql'), // Completion date
                $result_data,
                current_time('mysql') // Update last viewed as well
            ));

            // Note: Calculation and update of overall course progress should happen ONLY if the item exists
            // --- Calculate and Update Overall Course Progress ---
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';

            // Get total number of items in the course
            $total_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
                $course_id
            ));

            // Get number of completed items for the user in this course
            $completed_items = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
                $user_id,
                $course_id
            ));

            // Calculate percentage
            $progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;

            // Check if course is now fully complete
            $new_course_status = 'in_progress'; // Default
            if ($total_items > 0 && $completed_items >= $total_items) {
                $new_course_status = 'completed';
            }

            // Get the current completion date (if any) to avoid overwriting it
            $current_completion_date = $wpdb->get_var($wpdb->prepare(
                "SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
                $user_id, $course_id
            ));
            $completion_date_to_set = $current_completion_date;

            if ($new_course_status === 'completed' && is_null($current_completion_date)) {
                $completion_date_to_set = current_time('mysql');
            } elseif ($new_course_status !== 'completed') {
                 $completion_date_to_set = null;
            }

            // Update the user's overall course record
            $wpdb->update(
                $user_courses_table,
                [
                    'progress_percent' => $progress_percent,
                    'status'           => $new_course_status,
                    'completion_date'  => $completion_date_to_set
                ],
                [ 'user_id'   => $user_id, 'course_id' => $course_id ],
                ['%d', '%s', '%s'],
                ['%d', '%d']
            );
            // --- End Overall Course Progress Update ---

        } else {
            // *** Item does NOT exist, skip progress update ***
            // Optional: Log this occurrence for debugging
            error_log("QP Session Finalize: Skipped progress update for user {$user_id}, course {$course_id}, because item {$item_id} no longer exists.");
        }
        // *** END NEW CHECK ***

    } // This closing brace corresponds to the "if (isset($settings['course_id']) ...)" check
    // --- END Course Item Progress Update ---

    return [
        'final_score' => $final_score,
        'total_attempted' => $total_attempted,
        'correct_count' => $correct_count,
        'incorrect_count' => $incorrect_count,
        'skipped_count' => $skipped_count,
        'not_viewed_count' => $not_viewed_count,
        'settings' => $settings,
    ];
}

/**
 * Schedules the session cleanup event if it's not already scheduled.
 */
function qp_schedule_session_cleanup()
{
    if (!wp_next_scheduled('qp_cleanup_abandoned_sessions_event')) {
        wp_schedule_event(time(), 'hourly', 'qp_cleanup_abandoned_sessions_event');
    }
}

/**
 * The function that runs on the scheduled cron event to clean up old sessions.
 */
function qp_cleanup_abandoned_sessions()
{
    global $wpdb;
    $options = get_option('qp_settings');
    $timeout_minutes = isset($options['session_timeout']) ? absint($options['session_timeout']) : 20;

    if ($timeout_minutes < 5) {
        $timeout_minutes = 20;
    }

    $sessions_table = $wpdb->prefix . 'qp_user_sessions';

    // --- 1. Handle Expired Mock Tests ---
    $active_mock_tests = $wpdb->get_results(
        "SELECT session_id, start_time, settings_snapshot FROM {$sessions_table} WHERE status = 'mock_test'"
    );

    foreach ($active_mock_tests as $test) {
        $settings = json_decode($test->settings_snapshot, true);
        $duration_seconds = $settings['timer_seconds'] ?? 0;

        if ($duration_seconds <= 0) continue;

        $start_time_gmt = get_gmt_from_date($test->start_time);
        $start_timestamp = strtotime($start_time_gmt);
        $end_timestamp = $start_timestamp + $duration_seconds;

        // If the current time is past the test's official end time, finalize it as abandoned.
        if (time() > $end_timestamp) {
            // Our updated function will delete it if empty, or mark as abandoned if there are attempts.
            qp_finalize_and_end_session($test->session_id, 'abandoned', 'abandoned_by_system');
        }
    }

    // --- 2. Handle Abandoned 'active' sessions ---
    $abandoned_sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, settings_snapshot FROM {$sessions_table}
         WHERE status = 'active' AND last_activity < NOW() - INTERVAL %d MINUTE",
        $timeout_minutes
    ));

    if (!empty($abandoned_sessions)) {
        foreach ($abandoned_sessions as $session) {
            $settings = json_decode($session->settings_snapshot, true);
            $is_section_practice = isset($settings['practice_mode']) && $settings['practice_mode'] === 'Section Wise Practice';

            if ($is_section_practice) {
                // For section practice, just pause the session instead of abandoning it.
                $wpdb->update(
                    $sessions_table,
                    ['status' => 'paused'],
                    ['session_id' => $session->session_id]
                );
            } else {
                // For all other modes, use the standard abandon/delete logic.
                qp_finalize_and_end_session($session->session_id, 'abandoned', 'abandoned_by_system');
            }
        }
    }
}

function qp_handle_log_settings_forms()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'qp-logs-reports' || !isset($_GET['tab']) || $_GET['tab'] !== 'log_settings') {
        return;
    }

    global $wpdb;
    $term_table = $wpdb->prefix . 'qp_terms';

    // Add/Update Reason
    if (isset($_POST['action']) && ($_POST['action'] === 'add_reason' || $_POST['action'] === 'update_reason') && check_admin_referer('qp_add_edit_reason_nonce')) {
        $reason_text = sanitize_text_field($_POST['reason_text']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $reason_type = isset($_POST['reason_type']) ? sanitize_key($_POST['reason_type']) : 'report';
        $taxonomy_id = absint($_POST['taxonomy_id']);

        $term_data = [
            'name' => $reason_text,
            'slug' => sanitize_title($reason_text),
            'taxonomy_id' => $taxonomy_id,
        ];

        if ($_POST['action'] === 'update_reason') {
            $term_id = absint($_POST['term_id']);
            $wpdb->update($term_table, $term_data, ['term_id' => $term_id]);
        } else {
            $wpdb->insert($term_table, $term_data);
            $term_id = $wpdb->insert_id;
        }

        if ($term_id) {
            Terms_DB::update_meta($term_id, 'is_active', $is_active);
            Terms_DB::update_meta($term_id, 'type', $reason_type);
        }

        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings&message=1'));
        exit;
    }

    // Delete Reason
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['reason_id']) && check_admin_referer('qp_delete_reason_' . absint($_GET['reason_id']))) {
        $term_id_to_delete = absint($_GET['reason_id']);
        $reports_table = $wpdb->prefix . 'qp_question_reports';

        // Check if the reason is in use by any reports
        $usage_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$reports_table} WHERE reason_term_id = %d",
            $term_id_to_delete
        ));

        if ($usage_count > 0) {
            // If it's in use, set an error message and redirect
            $message = sprintf('This reason cannot be deleted because it is currently used in %d report(s).', $usage_count);
            QP_Sources_Page::set_message($message, 'error');
        } else {
            // If not in use, proceed with deletion
            $wpdb->delete($wpdb->prefix . 'qp_term_meta', ['term_id' => $term_id_to_delete]);
            $wpdb->delete($term_table, ['term_id' => $term_id_to_delete]);
            QP_Sources_Page::set_message('Reason deleted successfully.', 'updated');
        }

        wp_safe_redirect(admin_url('admin.php?page=qp-logs-reports&tab=log_settings'));
        exit;
    }
}




// New Development - Subscriptions

/**
 * Grant Question Press entitlement when a specific WooCommerce order is completed.
 * Reads linked plan data and creates a record in wp_qp_user_entitlements.
 *
 * @param int $order_id The ID of the completed order.
 */
function qp_grant_access_on_order_complete($order_id) {
    error_log("QP Access Hook: Processing Order #{$order_id}"); // Log start
    $order = wc_get_order($order_id);

    // Check if the order is valid and paid (or processing if allowing access before full payment)
    if (!$order || !$order->is_paid()) { // Stricter check: use is_paid() for completed orders
        error_log("QP Access Hook: Order #{$order_id} not valid or not paid.");
        return;
    }

    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log("QP Access Hook: No user ID associated with Order #{$order_id}. Cannot grant entitlement.");
        return; // Cannot grant entitlement to guest users
    }

    global $wpdb;
    $entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
    $granted_entitlement = false;

    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $target_id = $variation_id > 0 ? $variation_id : $product_id; // Use variation ID if available

        // Get the linked plan ID from product/variation meta
        $linked_plan_id = get_post_meta($target_id, '_qp_linked_plan_id', true);

        if (!empty($linked_plan_id)) {
            $plan_id = absint($linked_plan_id);
            $plan_post = get_post($plan_id);

            // Ensure the linked plan exists and is published
            if ($plan_post && $plan_post->post_type === 'qp_plan' && $plan_post->post_status === 'publish') {
                error_log("QP Access Hook: Found linked Plan ID #{$plan_id} for item in Order #{$order_id}");

                // Get plan details from post meta
                $plan_type = get_post_meta($plan_id, '_qp_plan_type', true);
                $duration_value = get_post_meta($plan_id, '_qp_plan_duration_value', true);
                $duration_unit = get_post_meta($plan_id, '_qp_plan_duration_unit', true);
                $attempts = get_post_meta($plan_id, '_qp_plan_attempts', true);

                $start_date = current_time('mysql');
                $expiry_date = null;
                $remaining_attempts = null;

                // Calculate expiry date if applicable
                if (($plan_type === 'time_limited' || $plan_type === 'combined') && !empty($duration_value) && !empty($duration_unit)) {
                    try {
                         // Use WordPress timezone for calculation start point
                         $start_datetime = new DateTime($start_date, wp_timezone());
                         $start_datetime->modify('+' . absint($duration_value) . ' ' . sanitize_key($duration_unit));
                         $expiry_date = $start_datetime->format('Y-m-d H:i:s');
                         error_log("QP Access Hook: Calculated expiry date for Plan ID #{$plan_id}: {$expiry_date}");
                    } catch (Exception $e) {
                         error_log("QP Access Hook: Error calculating expiry date for Plan ID #{$plan_id} - " . $e->getMessage());
                         $expiry_date = null; // Fallback if calculation fails
                    }
                } elseif ($plan_type === 'unlimited') {
                     $expiry_date = null; // Explicitly null for unlimited time
                     $remaining_attempts = null; // Explicitly null for unlimited attempts
                     error_log("QP Access Hook: Plan ID #{$plan_id} is Unlimited type.");
                }


                // Set remaining attempts if applicable
                if (($plan_type === 'attempt_limited' || $plan_type === 'combined') && !empty($attempts)) {
                    $remaining_attempts = absint($attempts);
                    error_log("QP Access Hook: Setting attempts for Plan ID #{$plan_id}: {$remaining_attempts}");
                } elseif ($plan_type === 'unlimited') {
                    $remaining_attempts = null; // Explicitly null for unlimited attempts
                }

                // Insert the new entitlement record
                $inserted = $wpdb->insert(
                    $entitlements_table,
                    [
                        'user_id' => $user_id,
                        'plan_id' => $plan_id,
                        'order_id' => $order_id,
                        'start_date' => $start_date,
                        'expiry_date' => $expiry_date, // NULL if not time-based or unlimited
                        'remaining_attempts' => $remaining_attempts, // NULL if not attempt-based or unlimited
                        'status' => 'active',
                    ],
                    [ // Data formats
                        '%d', // user_id
                        '%d', // plan_id
                        '%d', // order_id
                        '%s', // start_date
                        '%s', // expiry_date (can be NULL)
                        '%d', // remaining_attempts (can be NULL)
                        '%s', // status
                    ]
                );

                if ($inserted) {
                    error_log("QP Access Hook: Successfully inserted entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}");
                    $granted_entitlement = true;
                     // Optional: Add an order note
                     $order->add_order_note(sprintf('Granted Question Press access via Plan ID %d.', $plan_id));
                    // Consider breaking if you only want to grant one plan per order,
                    // or allow multiple plans if purchased together. Let's allow multiple for now.
                    // break;
                } else {
                     error_log("QP Access Hook: FAILED to insert entitlement record for User #{$user_id}, Plan #{$plan_id}, Order #{$order_id}. DB Error: " . $wpdb->last_error);
                     $order->add_order_note(sprintf('ERROR: Failed to grant Question Press access for Plan ID %d. DB Error: %s', $plan_id, $wpdb->last_error), true); // Add as private note
                }
            } else {
                 error_log("QP Access Hook: Linked Plan ID #{$linked_plan_id} not found or not published for item in Order #{$order_id}");
            }
        } else {
             // error_log("QP Access Hook: No QP Plan linked for product/variation ID #{$target_id} in Order #{$order_id}"); // This might be too verbose if many unrelated products are ordered.
        }
    } // end foreach item

    if (!$granted_entitlement) {
        error_log("QP Access Hook: No Question Press entitlements were granted for Order #{$order_id}.");
    }
}


// Courses Section on Dashboard
/**
 * AJAX handler to fetch the structure (sections and items) for a specific course.
 * Also fetches the user's progress for items within that course.
 */
function qp_get_course_structure_ajax() {
    check_ajax_referer('qp_practice_nonce', 'nonce'); // Re-use the existing frontend nonce

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Not logged in.']);
    }

    $course_id = isset($_POST['course_id']) ? absint($_POST['course_id']) : 0;
    $user_id = get_current_user_id();

    if (!$course_id) {
        wp_send_json_error(['message' => 'Invalid course ID.']);
    }

    // --- NEW: Check if user has access to this course before proceeding ---
    if (!qp_user_can_access_course($user_id, $course_id)) {
        wp_send_json_error(['message' => 'You do not have access to view this course structure.', 'code' => 'access_denied']);
        return; // Stop execution
    }

    global $wpdb;
    $sections_table = $wpdb->prefix . 'qp_course_sections';
    $items_table = $wpdb->prefix . 'qp_course_items';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $course_title = get_the_title($course_id); // Get course title from wp_posts

    $structure = [
        'course_id' => $course_id,
        'course_title' => $course_title,
        'sections' => []
    ];

    // Get sections for the course
    $sections = $wpdb->get_results($wpdb->prepare(
        "SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
        $course_id
    ));

    if (empty($sections)) {
        wp_send_json_success($structure); // Send structure with empty sections array
        return;
    }

    $section_ids = wp_list_pluck($sections, 'section_id');
    $ids_placeholder = implode(',', array_map('absint', $section_ids));

    // Get all items for these sections, including progress status and result data
    $items_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT i.item_id, i.section_id, i.title, i.item_order, i.content_type, p.status, p.result_data -- <<< ADD p.result_data
         FROM $items_table i
         LEFT JOIN {$wpdb->prefix}qp_user_items_progress p ON i.item_id = p.item_id AND p.user_id = %d AND p.course_id = %d
         WHERE i.section_id IN ($ids_placeholder)
         ORDER BY i.item_order ASC",
        $user_id,
        $course_id
    ));

    // Organize items by section
    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->status = $item->status ?? 'not_started'; // Use fetched status or default

        // --- ADD THIS BLOCK ---
        $item->session_id = null; // Default to null
        if (!empty($item->result_data)) {
            $result_data_decoded = json_decode($item->result_data, true);
            if (isset($result_data_decoded['session_id'])) {
                $item->session_id = absint($result_data_decoded['session_id']);
            }
        }
        unset($item->result_data); // Don't need to send the full result data to JS for this
        // --- END ADDED BLOCK ---

        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    // Get user's progress for these items in this course
    $progress_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT item_id, status FROM $progress_table WHERE user_id = %d AND course_id = %d",
        $user_id,
        $course_id
    ), OBJECT_K); // Keyed by item_id for easy lookup

    // Organize items by section
    $items_by_section = [];
    foreach ($items_raw as $item) {
        $item->status = $progress_raw[$item->item_id]->status ?? 'not_started'; // Add status
        if (!isset($items_by_section[$item->section_id])) {
            $items_by_section[$item->section_id] = [];
        }
        $items_by_section[$item->section_id][] = $item;
    }

    // Build the final structure
    foreach ($sections as $section) {
        $structure['sections'][] = [
            'id' => $section->section_id,
            'title' => $section->title,
            'description' => $section->description,
            'order' => $section->section_order,
            'items' => $items_by_section[$section->section_id] ?? []
        ];
    }

    wp_send_json_success($structure);
}

/**
 * Cleans up related enrollment and progress data when a qp_course post is deleted.
 * Hooks into 'before_delete_post'.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function qp_cleanup_course_data_on_delete($post_id) {
    // Check if the post being deleted is actually a 'qp_course'
    if (get_post_type($post_id) === 'qp_course') {
        global $wpdb;
        $user_courses_table = $wpdb->prefix . 'qp_user_courses';
        $progress_table = $wpdb->prefix . 'qp_user_items_progress';

        // Delete item progress records associated with this course first
        $wpdb->delete($progress_table, ['course_id' => $post_id], ['%d']);

        // Then delete the main enrollment records for this course
        $wpdb->delete($user_courses_table, ['course_id' => $post_id], ['%d']);
    }
}

/**
 * Cleans up related course enrollment and progress data when a WordPress user is deleted.
 * Hooks into 'delete_user'.
 *
 * @param int $user_id The ID of the user being deleted.
 */
function qp_cleanup_user_data_on_delete($user_id) {
    global $wpdb;
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $sessions_table = $wpdb->prefix . 'qp_user_sessions'; // Added sessions
    $attempts_table = $wpdb->prefix . 'qp_user_attempts'; // Added attempts
    $review_table = $wpdb->prefix . 'qp_review_later'; // Added review later
    $reports_table = $wpdb->prefix . 'qp_question_reports'; // Added reports

    // Sanitize the user ID just in case
    $user_id_to_delete = absint($user_id);
    if ($user_id_to_delete <= 0) {
        return; // Invalid user ID
    }

    // Delete item progress first
    $wpdb->delete($progress_table, ['user_id' => $user_id_to_delete], ['%d']);

    // Then delete enrollments
    $wpdb->delete($user_courses_table, ['user_id' => $user_id_to_delete], ['%d']);

    // Also delete sessions, attempts, review list, and reports by this user
    $wpdb->delete($attempts_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($sessions_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($review_table, ['user_id' => $user_id_to_delete], ['%d']);
    $wpdb->delete($reports_table, ['user_id' => $user_id_to_delete], ['%d']);

}

/**
 * Recalculates overall course progress for all enrolled users when a course is saved.
 * Hooks into 'save_post_qp_course' after the structure meta is saved.
 *
 * @param int $post_id The ID of the course post being saved.
 */
function qp_recalculate_course_progress_on_save($post_id) {
    // Check nonce (from the meta box save action)
    if (!isset($_POST['qp_course_structure_nonce']) || !wp_verify_nonce($_POST['qp_course_structure_nonce'], 'qp_save_course_structure_meta')) {
        return; // Nonce check failed or not our save action
    }

    // Check if the current user has permission
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Don't run on autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check post type is correct
    if ('qp_course' !== get_post_type($post_id)) {
        return;
    }

    global $wpdb;
    $items_table = $wpdb->prefix . 'qp_course_items';
    $user_courses_table = $wpdb->prefix . 'qp_user_courses';
    $progress_table = $wpdb->prefix . 'qp_user_items_progress';
    $course_id = $post_id; // For clarity

    // 1. Get the NEW total number of items in this course
    $total_items = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(item_id) FROM $items_table WHERE course_id = %d",
        $course_id
    ));

    // 2. Get all users enrolled in this course
    $enrolled_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $user_courses_table WHERE course_id = %d",
        $course_id
    ));

    if (empty($enrolled_user_ids)) {
        return; // No users enrolled, nothing to update
    }

    // 3. Loop through each enrolled user and update their progress
    foreach ($enrolled_user_ids as $user_id) {
        // Get the number of items this user has completed for this course
        $completed_items = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(user_item_id) FROM $progress_table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
            $user_id,
            $course_id
        ));

        // Calculate the new progress percentage
        $progress_percent = ($total_items > 0) ? round(($completed_items / $total_items) * 100) : 0;

        // Determine the new overall course status for the user
        $new_course_status = 'in_progress'; // Default
        if ($total_items > 0 && $completed_items >= $total_items) {
            $new_course_status = 'completed';
        }

        // Get the current completion date (if any) to avoid overwriting it
        $current_completion_date = $wpdb->get_var($wpdb->prepare(
            "SELECT completion_date FROM {$user_courses_table} WHERE user_id = %d AND course_id = %d",
            $user_id, $course_id
        ));
        $completion_date_to_set = $current_completion_date; // Keep existing by default
        if ($new_course_status === 'completed' && is_null($current_completion_date)) {
            $completion_date_to_set = current_time('mysql'); // Set completion date only if newly completed
        } elseif ($new_course_status !== 'completed') {
             $completion_date_to_set = null; // Reset completion date if no longer complete
        }


        // Update the user's course enrollment record
        $wpdb->update(
            $user_courses_table,
            [
                'progress_percent' => $progress_percent,
                'status'           => $new_course_status,
                'completion_date'  => $completion_date_to_set // Set potentially updated completion date
            ],
            [
                'user_id'   => $user_id,
                'course_id' => $course_id
            ],
            ['%d', '%s', '%s'], // Data formats
            ['%d', '%d']  // Where formats
        );
    }
}


// Profile Management

/**
 * Redirects non-admin users trying to access the default WordPress profile page
 * to the frontend Question Press dashboard profile tab.
 */
function qp_redirect_wp_profile_page() {
    // Check if we are trying to access the profile page and it's not an AJAX request
    if (is_admin() && !defined('DOING_AJAX') && $GLOBALS['pagenow'] === 'profile.php') {
        // Check if the current user DOES NOT have the 'manage_options' capability (adjust if needed)
        if (!current_user_can('manage_options')) {
            // Get the URL for the frontend dashboard profile tab
            $options = get_option('qp_settings');
            $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
            $profile_url = home_url('/'); // Default fallback

            if ($dashboard_page_id > 0) {
                $base_dashboard_url = trailingslashit(get_permalink($dashboard_page_id));
                $profile_url = $base_dashboard_url . 'profile/'; // Construct the profile tab URL
            }

            // Redirect the user
            wp_redirect($profile_url);
            exit; // Stop further execution
        }
    }
}
