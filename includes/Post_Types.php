<?php

namespace QuestionPress; // PSR-4 Namespace

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

use QuestionPress\Admin\Views\Course_Editor_Helper;
use QuestionPress\Utils\User_Access;

/**
 * Handles the registration of Custom Post Types.
 *
 * @package QuestionPress
 */
class Post_Types
{

    /**
     * The single instance of the class.
     * @var Post_Types|null
     */
    private static $_instance = null;

    /**
     * Main Post_Types Instance.
     * @static
     * @return Post_Types Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor. Hooks into WordPress 'init'.
     */
    private function __construct()
    {
        add_action('init', [$this, 'register_post_types'], 5); // Register early on init
        add_action('init', [$this, 'register_custom_statuses']);
    }

    /**
     * Register core post types.
     */
    public function register_post_types()
    {
        $this->register_course_post_type();
        $this->register_plan_post_type();
    }

    /**
     * Register the 'Course' Custom Post Type.
     * (Code moved from the original qp_register_course_post_type function)
     */
    private function register_course_post_type()
    {
        $labels = [
            'name'                  => _x('Courses', 'Post type general name', 'question-press'),
            'singular_name'         => _x('Course', 'Post type singular name', 'question-press'),
            'menu_name'             => _x('Courses', 'Admin Menu text', 'question-press'),
            'name_admin_bar'        => _x('Course', 'Add New on Toolbar', 'question-press'),
            'add_new'               => __('Add New', 'question-press'),
            'add_new_item'          => __('Add New Course', 'question-press'),
            'new_item'              => __('New Course', 'question-press'),
            'edit_item'             => __('Edit Course', 'question-press'),
            'view_item'             => __('View Course', 'question-press'),
            'all_items'             => __('All Courses', 'question-press'),
            'search_items'          => __('Search Courses', 'question-press'),
            'parent_item_colon'     => __('Parent Course:', 'question-press'),
            'not_found'             => __('No courses found.', 'question-press'),
            'not_found_in_trash'    => __('No courses found in Trash.', 'question-press'),
            'featured_image'        => _x('Course Cover Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'question-press'),
            'set_featured_image'    => _x('Set cover image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'question-press'),
            'remove_featured_image' => _x('Remove cover image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'question-press'),
            'use_featured_image'    => _x('Use as cover image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'question-press'),
            'archives'              => _x('Course archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'question-press'),
            'insert_into_item'      => _x('Insert into course', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'question-press'),
            'uploaded_to_this_item' => _x('Uploaded to this course', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'question-press'),
            'filter_items_list'     => _x('Filter courses list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'question-press'),
            'items_list_navigation' => _x('Courses list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'question-press'),
            'items_list'            => _x('Courses list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'question-press'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true, // Make it public
            'publicly_queryable' => true, // Allow it to be queried
            'show_ui'            => true,  // Show in the admin UI
            'show_in_menu'       => true,  // Show as a top-level menu item
            'query_var'          => true, // Allow query var
            'rewrite'            => ['slug' => 'course'], //Set the URL slug to /course/
            'capability_type'    => 'post', // Use standard post capabilities
            'has_archive'        => false, // No archive page needed
            'hierarchical'       => false, // Courses are not hierarchical like pages
            'menu_position'      => 26,    // Position below Question Press (usually 25)
            'menu_icon'          => 'dashicons-welcome-learn-more', // Choose an appropriate icon
            'supports'           => ['title', 'author'], // Features we want initially
            'show_in_rest'       => false, // Disable Block Editor support for now
        ];

        register_post_type('qp_course', $args);
    }

    /**
     * Register our custom 'expired' post status.
     * Hooked to 'init'.
     */
    public function register_custom_statuses()
    {
        register_post_status('expired', [
            'label'                     => _x('Expired', 'post status label', 'question-press'),
            'label_count'               => _n_noop('Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'question-press'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'post_type'                 => ['qp_course'],
        ]);
    }

    /**
     * Register the 'Plan' Custom Post Type.
     * (Code moved from the original qp_register_plan_post_type function)
     */
    private function register_plan_post_type()
    {
        $labels = [
            'name'                  => _x('Plans', 'Post type general name', 'question-press'),
            'singular_name'         => _x('Plan', 'Post type singular name', 'question-press'),
            'menu_name'             => _x('Monetization Plans', 'Admin Menu text', 'question-press'),
            'name_admin_bar'        => _x('Plan', 'Add New on Toolbar', 'question-press'),
            'add_new'               => __('Add New Plan', 'question-press'),
            'add_new_item'          => __('Add New Plan', 'question-press'),
            'new_item'              => __('New Plan', 'question-press'),
            'edit_item'             => __('Edit Plan', 'question-press'),
            'view_item'             => __('View Plan', 'question-press'), // Should not be viewable on frontend
            'all_items'             => __('All Plans', 'question-press'),
            'search_items'          => __('Search Plans', 'question-press'),
            'parent_item_colon'     => __('Parent Plan:', 'question-press'), // Not applicable, but standard label
            'not_found'             => __('No plans found.', 'question-press'),
            'not_found_in_trash'    => __('No plans found in Trash.', 'question-press'),
        ];

        $args = [
            'labels'             => $labels,
            'description'        => __('Defines access plans for Question Press features.', 'question-press'),
            'public'             => false, // Not publicly viewable on frontend
            'publicly_queryable' => false, // Not queryable directly
            'show_ui'            => true,  // Show in admin UI
            'show_in_menu'       => 'question-press', // Show under the main Question Press menu
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post', // Use standard post capabilities (adjust if needed)
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null, // Will appear as submenu
            'supports'           => ['title', 'editor'], // Only title needed initially, details via meta
            'show_in_rest'       => false, // Disable Gutenberg for this CPT
        ];

        register_post_type('qp_plan', $args);
    }

    /**
     * Hides auto-generated plans from the "All Plans" admin list.
     * Hooked to 'pre_get_posts'.
     *
     * @param \WP_Query $query The main WordPress query object.
     */
    public static function hide_auto_plans_from_admin_list($query)
    {
        // Check if we are in the admin, on the main query, and for the 'qp_plan' post type
        if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== 'qp_plan') {
            return;
        }

        // Get the current screen to ensure we're only modifying the "All Plans" list
        $screen = get_current_screen();
        if ($screen && $screen->id === 'edit-qp_plan') {

            // Get existing meta query if any
            $meta_query = $query->get('meta_query') ?: [];

            // Add our condition to exclude posts where _qp_is_auto_generated is 'true'
            // This will automatically include posts where the key doesn't exist (manual plans)
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => '_qp_is_auto_generated',
                    'compare' => 'NOT EXISTS', // Show manual plans
                ],
                [
                    'key'     => '_qp_is_auto_generated',
                    'value'   => 'true',
                    'compare' => '!=' // Show plans where key is not 'true'
                ]
            ];

            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Modifies the post count object for the 'qp_plan' post type to exclude auto-generated plans.
     * Hooked to 'wp_count_posts'.
     *
     * @param stdClass $counts  An object containing post counts.
     * @param string   $type    The post type.
     * @return stdClass The modified counts object.
     */
    public static function filter_plan_view_counts($counts, $type)
    {
        // Only modify counts for the 'qp_plan' post type in the admin
        if (! is_admin() || $type !== 'qp_plan') {
            return $counts;
        }

        global $wpdb;

        // This query counts all 'qp_plan' posts, grouped by status,
        // that do NOT have the meta key '_qp_is_auto_generated' set to 'true'.
        // This correctly includes manual plans (where key is NULL) and excludes auto-plans.
        $query = "
            SELECT p.post_status, COUNT( * ) AS num_posts 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m ON (p.ID = m.post_id AND m.meta_key = '_qp_is_auto_generated')
            WHERE p.post_type = 'qp_plan'
            AND (m.meta_value IS NULL OR m.meta_value != 'true')
            GROUP BY p.post_status
        ";

        $results = $wpdb->get_results($query, ARRAY_A);

        // Create a new, empty counts object
        $new_counts = new \stdClass();
        foreach (get_post_stati() as $status) {
            $new_counts->$status = 0;
        }

        // Populate the new counts object with our custom query results
        foreach ((array) $results as $row) {
            if (isset($new_counts->{$row['post_status']})) {
                $new_counts->{$row['post_status']} = (int) $row['num_posts'];
            }
        }

        return $new_counts;
    }

    /**
     * Adds our custom post states to the post list.
     * Hooked to 'display_post_states'.
     */
    public static function add_custom_post_states($post_states, $post)
    {
        // Only check for 'qp_course' post type
        if ($post->post_type === 'qp_course') {
            $access_mode = get_post_meta($post->ID, '_qp_course_access_mode', true);

            // If mode is 'free', add the state
            if (empty($access_mode) || $access_mode === 'free') {
                $post_states['qp_free_course'] = __('Free Course', 'question-press');
            }

            // --- ADDED: Check for expired status ---
            if ($post->post_status === 'expired') {
                $post_states['qp_expired_course'] = __('Expired', 'question-press');
            }
        }
        return $post_states;
    }

    /**
     * Adds 'Expired' to the list of status views (e.g., "All | Published | Expired | Trash").
     * Hooked to 'views_edit-qp_course'.
     */
    public static function add_expired_to_course_views($views)
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_status = 'expired'", 'qp_course'));

        if ($count > 0) {
            $views['expired'] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
                esc_url(add_query_arg(['post_status' => 'expired'], 'edit.php?post_type=qp_course')),
                (get_query_var('post_status') === 'expired') ? 'current' : '',
                esc_html__('Expired', 'question-press'),
                $count
            );
        }
        return $views;
    }

    /**
     * Sets the custom columns for the 'qp_course' list table.
     * Hooked from Plugin.php
     */
    public static function set_course_columns( $columns ) {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['access_mode'] = __( 'Access Mode', 'question-press' );
        $new_columns['linked_product'] = __( 'Linked Product', 'question-press' );
        $new_columns['expiry_date'] = __( 'Expiry Date', 'question-press' );
        $new_columns['date'] = $columns['date'];
        
        return $new_columns;
    }

    /**
     * Renders the content for custom 'qp_course' columns.
     * Hooked from Plugin.php
     */
    public static function render_course_columns( $column_name, $post_id ) {
        switch ( $column_name ) {
            case 'access_mode':
                $access_mode = get_post_meta( $post_id, '_qp_course_access_mode', true ) ?: 'free';
                if ( $access_mode === 'free' ) {
                    echo '<span style="color: #2e7d32; font-weight: 600;">' . esc_html__( 'Free', 'question-press' ) . '</span>';
                } else {
                    echo '<span style="color: #f57f17; font-weight: 600;">' . esc_html__( 'Paid', 'question-press' ) . '</span>';
                }
                break;

            case 'linked_product':
                $product_id = get_post_meta( $post_id, '_qp_linked_product_id', true );
                if ( ! $product_id ) {
                    echo '<em>' . esc_html__( 'None', 'question-press' ) . '</em>';
                    break;
                }
                $product = get_post( $product_id );
                if ( ! $product ) {
                    echo '<span style="color: #c00;">' . esc_html__( 'Product #', 'question-press' ) . esc_html( $product_id ) . ' (Missing)</span>';
                    break;
                }
                $edit_link = get_edit_post_link( $product_id );
                echo '<strong><a href="' . esc_url( $edit_link ) . '" title="' . esc_attr( $product->post_title ) . '">' . esc_html( wp_trim_words( $product->post_title, 5, '...' ) ) . '</a></strong>';
                echo '<br>(ID: ' . esc_html( $product_id ) . ')';
                break;

            case 'expiry_date':
                $expiry_date = get_post_meta( $post_id, '_qp_course_expiry_date', true );
                if ( ! empty( $expiry_date ) ) {
                    $expiry_timestamp = strtotime( $expiry_date );
                    $current_timestamp = current_time( 'timestamp' );
                    $date_format = get_option( 'date_format' );
                    $formatted_date = date_i18n( $date_format, $expiry_timestamp );

                    if ( $expiry_timestamp < $current_timestamp ) {
                        echo '<span style="color: #c00; font-weight: 600;">' . esc_html( $formatted_date ) . '</span>';
                    } else {
                        echo esc_html( $formatted_date );
                    }
                } else {
                    echo '<em>' . esc_html__( 'No Expiry', 'question-press' ) . '</em>';
                }
                break;
        }
    }

    /**
     * Sets the custom columns for the 'qp_plan' list table.
     * Hooked from Plugin.php
     */
    public static function set_plan_columns( $columns ) {
        // This removes the 'author' column if it exists
        unset($columns['author']);
        
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['plan_type'] = __( 'Plan Type', 'question-press' );
        $new_columns['duration'] = __( 'Duration', 'question-press' );
        $new_columns['attempts'] = __( 'Attempts', 'question-press' );
        $new_columns['linked_course'] = __( 'Linked Course(s)', 'question-press' );
        $new_columns['date'] = $columns['date'];
        
        return $new_columns;
    }

    /**
     * Renders the content for custom 'qp_plan' columns.
     * Hooked from Plugin.php
     */
    public static function render_plan_columns( $column_name, $post_id ) {
        // This list only shows manual plans
        switch ( $column_name ) {
            case 'plan_type':
                $plan_type = get_post_meta( $post_id, '_qp_plan_type', true );
                if ( $plan_type ) {
                    echo '<code>' . esc_html( $plan_type ) . '</code>';
                } else {
                    echo '<em>' . esc_html__( 'Not Set', 'question-press' ) . '</em>';
                }
                break;

            case 'duration':
                $value = get_post_meta( $post_id, '_qp_plan_duration_value', true );
                $unit = get_post_meta( $post_id, '_qp_plan_duration_unit', true );
                if ( ! empty( $value ) && ! empty( $unit ) ) {
                    echo '<strong>' . esc_html( $value ) . ' ' . esc_html( $unit ) . '(s)</strong>';
                } else {
                    echo '<em>' . esc_html__( 'N/A', 'question-press' ) . '</em>';
                }
                break;

            case 'attempts':
                $attempts = get_post_meta( $post_id, '_qp_plan_attempts', true );
                if ( ! empty( $attempts ) ) {
                    echo '<strong>' . esc_html( number_format_i18n( $attempts ) ) . '</strong>';
                } else {
                    echo '<em>' . esc_html__( 'N/A', 'question-press' ) . '</em>';
                }
                break;

            case 'linked_course':
                // This is for manual plans, so we check '_qp_plan_linked_courses'
                $course_ids = get_post_meta( $post_id, '_qp_plan_linked_courses', true );
                if ( empty( $course_ids ) || ! is_array( $course_ids ) ) {
                    echo '<em>' . esc_html__( 'None', 'question-press' ) . '</em>';
                    break;
                }

                $links = [];
                foreach( $course_ids as $course_id ) {
                    $course = get_post( $course_id );
                    if ( ! $course ) {
                        $links[] = '<span style="color: #c00;">' . esc_html__( 'Course #', 'question-press' ) . esc_html( $course_id ) . ' (Missing)</span>';
                    } else {
                        $edit_link = get_edit_post_link( $course_id );
                        $links[] = '<strong><a href="' . esc_url( $edit_link ) . '" title="' . esc_attr( $course->post_title ) . '">' . esc_html( wp_trim_words( $course->post_title, 5, '...' ) ) . '</a></strong> (ID: ' . esc_html( $course_id ) . ')';
                    }
                }
                echo implode( '<br>', $links );
                break;
        }
    }

    /**
     * Injects course syllabus and action buttons into the_content.
     * Hooked to 'the_content' from Plugin.php.
     *
     * @param string $content The original post content (the course description).
     * @return string The modified content with our syllabus appended.
     */
    public static function inject_course_details( $content ) {
        // Check if we are on a single 'qp_course' page and in the main WordPress loop
        if ( is_singular( 'qp_course' ) && in_the_loop() && is_main_query() ) {
            
            $post_id = get_the_ID();
            $user_id = get_current_user_id();
            $new_content = ''; // This will hold our syllabus and button
            global $wpdb;

            // --- 1. Generate the Action Button ---
            ob_start();
            $user_courses_table = $wpdb->prefix . 'qp_user_courses';
            $is_enrolled = $wpdb->get_var($wpdb->prepare(
                "SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d AND status IN ('enrolled', 'in_progress', 'completed')",
                $user_id, $post_id
            ));
            
            $button_html = '';

            if ($is_enrolled) {
                // User is enrolled, link them to the "My Courses" tab
                $options = get_option('qp_settings');
                $dashboard_page_id = isset($options['dashboard_page']) ? absint($options['dashboard_page']) : 0;
                $base_dashboard_url = $dashboard_page_id ? trailingslashit(get_permalink($dashboard_page_id)) : home_url('/');
                $is_front_page = ($dashboard_page_id > 0 && get_option('show_on_front') == 'page' && get_option('page_on_front') == $dashboard_page_id);
                $tab_prefix = $is_front_page ? 'tab/' : '';
                $my_courses_url = $base_dashboard_url . $tab_prefix . 'my-courses/';
                
                $button_html = sprintf(
                    '<a href="%s" class="qp-button qp-button-secondary">%s</a>',
                    esc_url($my_courses_url),
                    __('View in My Courses', 'question-press')
                );
            } else {
                // User is NOT enrolled. Check if they can enroll or must purchase.
                $access_mode = get_post_meta($post_id, '_qp_course_access_mode', true) ?: 'free';
                $course_status = get_post_status($post_id);
                // Check access, ignoring enrollment (true)
                $access_result = \QuestionPress\Utils\User_Access::can_access_course($user_id, $post_id, true);

                if ($course_status === 'expired') {
                    $button_html = sprintf('<button class="qp-button" disabled>%s</button>', __('Course Expired', 'question-press'));
                } elseif ($access_mode === 'free') {
                    $button_html = sprintf('<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>', $post_id, __('Enroll Free', 'question-press'));
                } elseif ($access_mode === 'requires_purchase') {
                    if (is_numeric($access_result)) { // Access granted by a specific entitlement ID
                        $button_html = sprintf('<button class="qp-button qp-button-secondary qp-enroll-course-btn" data-course-id="%d">%s</button>', $post_id, __('Enroll Now (Purchased)', 'question-press'));
                    } else { // Access is 'false', they need to buy it
                        $linked_product_id = get_post_meta($post_id, '_qp_linked_product_id', true);
                        $product_url = $linked_product_id ? get_permalink($linked_product_id) : '#';
                        $button_html = sprintf('<a href="%s" class="qp-button qp-button-primary">%s</a>', esc_url($product_url), __('Purchase Access', 'question-press'));
                    }
                }
            }
            
            // Wrap the button in a styled box
            echo '<div class="qp-course-action-box">' . $button_html . '</div>';
            $new_content .= ob_get_clean();


            // --- 2. Generate the Syllabus ---
            ob_start();
            // Use static::class since we added the 'use' statement
            $structure = Course_Editor_Helper::get_course_structure_for_editor($post_id);

            // --- NEW: Fetch progress data for progression logic ---
            $progress_data = [];
            $all_item_ids = [];
            if (!empty($structure['sections'])) {
                foreach ($structure['sections'] as $section) {
                    if (!empty($section['items'])) {
                        $all_item_ids = array_merge($all_item_ids, wp_list_pluck($section['items'], 'item_id'));
                    }
                }
            }
            if ($user_id > 0 && !empty($all_item_ids)) {
                $progress_table = $wpdb->prefix . 'qp_user_items_progress';
                $item_ids_placeholder = implode(',', array_map('absint', $all_item_ids));
                $progress_raw = $wpdb->get_results($wpdb->prepare(
                    "SELECT item_id, status FROM $progress_table WHERE user_id = %d AND item_id IN ($item_ids_placeholder)",
                    $user_id
                ), OBJECT_K);
                if ($progress_raw) {
                    $progress_data = $progress_raw;
                }
            }
            // --- END NEW ---
            
            if (!empty($structure['sections'])) {
                // --- NEW: Get progression mode ---
                $progression_mode = get_post_meta($post_id, '_qp_course_progression_mode', true);
                $is_progressive = ($progression_mode === 'progressive') && !user_can($user_id, 'manage_options'); // Admins bypass
                $is_previous_item_complete = true; // First item is always unlocked
                // --- END NEW ---

                echo '<div class="qp-course-syllabus">';
                echo '<h2>' . esc_html__('Syllabus', 'question-press') . '</h2>';
                
                foreach ($structure['sections'] as $section) {
                    echo '<div class="qp-syllabus-section">';
                    echo '<h4 class="qp-syllabus-section-title">' . esc_html($section['title']) . '</h4>';
                    
                    if (!empty($section['items'])) {
                        echo '<ul class="qp-syllabus-items">';
                        foreach ($section['items'] as $item) {
                            $item_status = $progress_data[$item->item_id]->status ?? 'not_started';
                            $is_locked = false;

                            // --- NEW: Check lock status ---
                            if ($is_progressive && !$is_previous_item_complete) {
                                $is_locked = true;
                            }
                            // --- END NEW ---

                            $icon_class = 'dashicons-text'; // Default icon
                            if ($item->content_type === 'test_series') {
                                $icon_class = 'dashicons-forms'; // Test icon
                            }
                            // Add other icon types here if needed
                            
                            // --- NEW: Modify icon and class if locked ---
                            if ($is_locked) {
                                $icon_class = 'dashicons-lock';
                                echo '<li class="qp-item-locked">';
                            } else {
                                echo '<li>';
                            }
                            // --- END NEW ---

                            echo '<span class="dashicons ' . $icon_class . '"></span>' . esc_html($item->title) . '</li>';

                            // --- NEW: Update lock for next iteration ---
                            if ($is_progressive) {
                                $is_previous_item_complete = ($item_status === 'completed');
                            }
                            // --- END NEW ---
                        }
                        echo '</ul>';
                    }
                    echo '</div>'; // close .qp-syllabus-section
                }
                echo '</div>'; // close .qp-course-syllabus
            }
            $new_content .= ob_get_clean();
            
            // Append our new content to the original post content
            return $content . $new_content;
        }
        
        // For all other pages, return the content exactly as it was.
        return $content;
    }

    /** Cloning/Unserializing prevention */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cloning is forbidden.', 'question-press'), '1.0');
    }
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing instances of this class is forbidden.', 'question-press'), '1.0');
    }
} // End class Post_Types