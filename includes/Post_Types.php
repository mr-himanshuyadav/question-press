<?php
namespace QuestionPress; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the registration of Custom Post Types.
 *
 * @package QuestionPress
 */
class Post_Types {

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
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor. Hooks into WordPress 'init'.
     */
    private function __construct() {
        add_action( 'init', [ $this, 'register_post_types' ], 5 ); // Register early on init
    }

    /**
     * Register core post types.
     */
    public function register_post_types() {
        $this->register_course_post_type();
        $this->register_plan_post_type();
    }

    /**
     * Register the 'Course' Custom Post Type.
     * (Code moved from the original qp_register_course_post_type function)
     */
    private function register_course_post_type() {
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
        'public'             => false, // Not publicly viewable on the frontend directly via its slug
        'publicly_queryable' => false, // Not queryable in the main WP query
        'show_ui'            => true,  // Show in the admin UI
        'show_in_menu'       => true,  // Show as a top-level menu item
        'query_var'          => false, // No query variable needed
        'rewrite'            => false, // No URL rewriting needed
        'capability_type'    => 'post', // Use standard post capabilities
        'has_archive'        => false, // No archive page needed
        'hierarchical'       => false, // Courses are not hierarchical like pages
        'menu_position'      => 26,    // Position below Question Press (usually 25)
        'menu_icon'          => 'dashicons-welcome-learn-more', // Choose an appropriate icon
        'supports'           => ['title', 'editor', 'author'], // Features we want initially
        'show_in_rest'       => false, // Disable Block Editor support for now
    ];

    register_post_type('qp_course', $args);
    }

    /**
     * Register the 'Plan' Custom Post Type.
     * (Code moved from the original qp_register_plan_post_type function)
     */
    private function register_plan_post_type() {
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

    /** Cloning/Unserializing prevention */
    public function __clone() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'question-press' ), '1.0' ); }
    public function __wakeup() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'question-press' ), '1.0' ); }

} // End class Post_Types