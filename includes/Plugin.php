<?php
// Use the correct namespace based on composer.json
namespace QuestionPress;
/**
 * Main Question Press Plugin Class.
 *
 * @package QuestionPress
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use QuestionPress\Assets;
use QuestionPress\Post_Types;
use QuestionPress\Taxonomies; // Just in case if migrated to native taxonomies later
use QuestionPress\Database\DB as QP_DB;
use QuestionPress\Admin\Admin_Menu;
use QuestionPress\Admin\Admin_Utils;
use QuestionPress\Admin\Meta_Boxes;

/**
 * Final QuestionPress Class.
 * Prevents class extension.
 */
final class Plugin {

    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '3.5.1'; // Update this as you release new versions

    /**
     * The single instance of the class.
     *
     * @var Plugin|null
     */
    private static $_instance = null;

    /**
     * Instance of the Admin Menu handler.
     *
     * @var Admin_Menu|null
     */
    private $admin_menu = null;

    /**
     * Main Plugin Instance.
     *
     * Ensures only one instance of the plugin class is loaded or can be loaded.
     *
     * @static
     * @return Plugin - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        QP_DB::init();
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define Core Constants.
     */
    private function define_constants() {
        // QP_PLUGIN_FILE is defined in the main question-press.php
        define( 'QP_PLUGIN_VERSION', $this->version );
        define( 'QP_PLUGIN_PATH', plugin_dir_path( QP_PLUGIN_FILE ) );
        define( 'QP_PLUGIN_URL', plugin_dir_url( QP_PLUGIN_FILE ) );
        define( 'QP_PLUGIN_BASENAME', plugin_basename( QP_PLUGIN_FILE ) );
        define( 'QP_TEMPLATES_DIR', QP_PLUGIN_PATH . 'templates/' );
        define( 'QP_ASSETS_URL', QP_PLUGIN_URL . 'assets/' );
    }

    /**
     * Include required core files used in admin and on the frontend.
     *
     * Note: Classes here will be autoloaded by Composer, but we might need
     * to include procedural function files or instantiate core components.
     */
    private function includes() {
        // Example: Include global functions file (we'll create this next)
         require_once QP_PLUGIN_PATH . 'includes/functions/qp-core-functions.php';

        // Example: Instantiate core components (we'll add these classes later)
        Assets::instance();
        // Ajax::instance();
        // Shortcodes::instance();
        Post_Types::instance();
        Taxonomies::instance(); // Just in case if migrated to native taxonomies later
        // REST_API::instance(); // Assuming REST_API is a class handling registration
        if ( is_admin() ) {
            $this->admin_menu = new Admin_Menu();
            // Admin::instance();
        }
        // If WooCommerce is active
        // if ( class_exists( 'WooCommerce' ) ) {
        //    Integrations\WooCommerce::instance();
        // }
    }

    /**
     * Initialize WordPress hooks.
     */
    private function init_hooks() {
        // Actions
        add_action('plugins_loaded', [$this, 'load_plugin_textdomain']); // CORRECT - Use plugins_loaded hook
        add_action('init', 'qp_start_session', 1);
        add_action('init', 'qp_ensure_cron_scheduled');
        add_action('init', [$this, 'register_shortcodes']); // Register shortcodes via class method
        add_action('init', 'qp_add_dashboard_rewrite_rules');
        add_action('rest_api_init', ['\QP_Rest_Api', 'register_routes']);
        add_action('admin_init', 'qp_handle_form_submissions');
        add_action('admin_init', 'qp_handle_report_actions');
        add_action('admin_init', 'qp_handle_resolve_from_editor');
        add_action('admin_init', 'qp_handle_log_settings_forms');
        add_action('admin_init', 'qp_redirect_wp_profile_page');
        add_action('admin_post_qp_save_user_scope', [\QuestionPress\Admin\Views\User_Entitlements_Page::class, 'handle_save_scope']); // CHANGED CALLBACK
        // Register admin menus if in admin area
        if ( is_admin() && isset($this->admin_menu) ) {
             add_action('admin_menu', [$this->admin_menu, 'register_menus']);
        }

        // Register Meta Boxes (only in admin)
        add_action('admin_menu', 'qp_add_report_count_to_menu', 99);
        if ( is_admin() ) {
            add_action('add_meta_boxes_qp_plan', [Meta_Boxes::class, 'add_plan_details']);
            add_action('save_post_qp_plan', [Meta_Boxes::class, 'save_plan_details']);

            // Course Access Meta Box (NEW)
            add_action('add_meta_boxes_qp_course', [Meta_Boxes::class, 'add_course_access']); // CHANGED CALLBACK
            add_action('save_post_qp_course', [Meta_Boxes::class, 'save_course_access'], 30, 1); // CHANGED CALLBACK

            // Course Structure Meta Box (NEW)
            add_action('add_meta_boxes', [Meta_Boxes::class, 'add_course_structure']);
            add_action('save_post_qp_course', [Meta_Boxes::class, 'save_course_structure']);
        }
        add_action('save_post_qp_course', 'qp_sync_course_plan', 40, 1);
        add_action('save_post_qp_course', 'qp_recalculate_course_progress_on_save', 20, 1);
        add_action('qp_check_entitlement_expiration_hook', 'qp_run_entitlement_expiration_check');
        add_action('woocommerce_product_options_general_product_data', 'qp_add_plan_link_to_simple_products');
        add_action('woocommerce_process_product_meta_simple', 'qp_save_plan_link_simple_product');
        add_action('woocommerce_product_after_variable_attributes', 'qp_add_plan_link_to_variable_products', 10, 3);
        add_action('woocommerce_save_product_variation', 'qp_save_plan_link_variable_product', 10, 2);
        add_action('woocommerce_order_status_completed', 'qp_grant_access_on_order_complete', 10, 1);
        add_action('qp_scheduled_backup_hook', 'qp_run_scheduled_backup_event');
        add_action('admin_head', [\QuestionPress\Admin\Views\User_Entitlements_Page::class, 'add_screen_options']);
        add_action('admin_head', [Assets::instance(), 'enqueue_dynamic_admin_styles']); // CHANGED CALLBACK
        add_action('wp', 'qp_schedule_session_cleanup');
        add_action('qp_cleanup_abandoned_sessions_event', 'qp_cleanup_abandoned_sessions');
        add_action('before_delete_post', 'qp_cleanup_course_data_on_delete', 10, 1);
        add_action('delete_user', 'qp_cleanup_user_data_on_delete', 10, 1);

        // AJAX Actions (already using class methods)
        add_action('wp_ajax_qp_save_profile', [\QuestionPress\Ajax\Profile_Ajax::class, 'save_profile']);
        add_action('wp_ajax_qp_change_password', [\QuestionPress\Ajax\Profile_Ajax::class, 'change_password']);
        add_action('wp_ajax_qp_upload_avatar', [\QuestionPress\Ajax\Profile_Ajax::class, 'upload_avatar']);
        add_action('wp_ajax_start_practice_session', [\QuestionPress\Ajax\Session_Ajax::class, 'start_practice_session']);
        add_action('wp_ajax_qp_start_incorrect_practice_session', [\QuestionPress\Ajax\Session_Ajax::class, 'start_incorrect_practice_session']);
        add_action('wp_ajax_qp_start_mock_test_session', [\QuestionPress\Ajax\Session_Ajax::class, 'start_mock_test_session']);
        add_action('wp_ajax_start_revision_session', [\QuestionPress\Ajax\Session_Ajax::class, 'start_revision_session']);
        add_action('wp_ajax_qp_start_review_session', [\QuestionPress\Ajax\Session_Ajax::class, 'start_review_session']);
        add_action('wp_ajax_update_session_activity', [\QuestionPress\Ajax\Session_Ajax::class, 'update_session_activity']);
        add_action('wp_ajax_end_practice_session', [\QuestionPress\Ajax\Session_Ajax::class, 'end_practice_session']);
        add_action('wp_ajax_delete_empty_session', [\QuestionPress\Ajax\Session_Ajax::class, 'delete_empty_session']);
        add_action('wp_ajax_delete_user_session', [\QuestionPress\Ajax\Session_Ajax::class, 'delete_user_session']);
        add_action('wp_ajax_delete_revision_history', [\QuestionPress\Ajax\Session_Ajax::class, 'delete_revision_history']);
        add_action('wp_ajax_qp_pause_session', [\QuestionPress\Ajax\Session_Ajax::class, 'pause_session']);
        add_action('wp_ajax_qp_terminate_session', [\QuestionPress\Ajax\Session_Ajax::class, 'terminate_session']);
        add_action('wp_ajax_start_course_test_series', [\QuestionPress\Ajax\Session_Ajax::class, 'start_course_test_series']);
        add_action('wp_ajax_check_answer', [\QuestionPress\Ajax\Practice_Ajax::class, 'check_answer']);
        add_action('wp_ajax_qp_save_mock_attempt', [\QuestionPress\Ajax\Practice_Ajax::class, 'save_mock_attempt']);
        add_action('wp_ajax_qp_update_mock_status', [\QuestionPress\Ajax\Practice_Ajax::class, 'update_mock_status']);
        add_action('wp_ajax_expire_question', [\QuestionPress\Ajax\Practice_Ajax::class, 'expire_question']);
        add_action('wp_ajax_skip_question', [\QuestionPress\Ajax\Practice_Ajax::class, 'skip_question']);
        add_action('wp_ajax_qp_toggle_review_later', [\QuestionPress\Ajax\Practice_Ajax::class, 'toggle_review_later']);
        add_action('wp_ajax_get_single_question_for_review', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_single_question_for_review']);
        add_action('wp_ajax_submit_question_report', [\QuestionPress\Ajax\Practice_Ajax::class, 'submit_question_report']);
        add_action('wp_ajax_get_report_reasons', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_report_reasons']);
        add_action('wp_ajax_get_unattempted_counts', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_unattempted_counts']);
        add_action('wp_ajax_get_question_data', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_question_data']);
        add_action('wp_ajax_get_topics_for_subject', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_topics_for_subject']);
        add_action('wp_ajax_get_sections_for_subject', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_sections_for_subject']);
        add_action('wp_ajax_get_sources_for_subject', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_sources_for_subject_cascading']);
        add_action('wp_ajax_get_child_terms', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_child_terms_cascading']);
        add_action('wp_ajax_get_progress_data', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_progress_data']);
        add_action('wp_ajax_get_sources_for_subject_progress', [\QuestionPress\Ajax\Practice_Ajax::class, 'get_sources_for_subject_progress']);
        add_action('wp_ajax_qp_check_remaining_attempts', [\QuestionPress\Ajax\Practice_Ajax::class, 'check_remaining_attempts']);
        add_action('wp_ajax_enroll_in_course', [\QuestionPress\Ajax\Practice_Ajax::class, 'enroll_in_course']);
        add_action('wp_ajax_qp_search_questions_for_course', [\QuestionPress\Ajax\Practice_Ajax::class, 'search_questions_for_course']);
        add_action('wp_ajax_get_topics_for_list_table_filter', [\QuestionPress\Ajax\Admin_Ajax::class, 'get_topics_for_list_table_filter']);
        add_action('wp_ajax_get_sources_for_list_table_filter', [\QuestionPress\Ajax\Admin_Ajax::class, 'get_sources_for_list_table_filter']);
        add_action('wp_ajax_qp_get_quick_edit_form', [\QuestionPress\Ajax\Admin_Ajax::class, 'get_quick_edit_form']);
        add_action('wp_ajax_save_quick_edit_data', [\QuestionPress\Ajax\Admin_Ajax::class, 'save_quick_edit_data']);
        add_action('wp_ajax_qp_create_backup', [\QuestionPress\Ajax\Admin_Ajax::class, 'create_backup']);
        add_action('wp_ajax_qp_delete_backup', [\QuestionPress\Ajax\Admin_Ajax::class, 'delete_backup']);
        add_action('wp_ajax_qp_restore_backup', [\QuestionPress\Ajax\Admin_Ajax::class, 'restore_backup']);
        add_action('wp_ajax_regenerate_api_key', [\QuestionPress\Ajax\Admin_Ajax::class, 'regenerate_api_key']);
        add_action('wp_ajax_get_practice_form_html', 'qp_get_practice_form_html_ajax'); // Keep as global for now
        add_action('wp_ajax_get_course_structure', 'qp_get_course_structure_ajax'); // Keep as global for now

        // Filters
        add_filter('query_vars', 'qp_register_query_vars');
        if ( is_admin() ) {
            add_filter('display_post_states', [Admin_Utils::class, 'add_page_indicator'], 10, 2); // CHANGED CALLBACK
        }
        add_filter('set-screen-option', [\QuestionPress\Admin\Views\User_Entitlements_Page::class, 'save_screen_options'], 10, 3);
        add_filter('set-screen-option', [\QuestionPress\Admin\Views\All_Questions_Page::class, 'save_screen_options'], 10, 3);

        // Activation hook is registered in main file, but flush is needed here too
        // Ensure QP_PLUGIN_FILE is available or replace with __FILE__ relative path if needed
        if (defined('QP_PLUGIN_FILE')) {
             register_activation_hook(QP_PLUGIN_FILE, 'qp_flush_rewrite_rules_on_activate');
        }
    }

    /**
     * Init plugin when WordPress Initialises.
     */
    // public function init() {
        // Actions to perform on WordPress init hook
        // Example: load_plugin_textdomain( 'question-press', false, dirname( QP_PLUGIN_BASENAME ) . '/languages' );
    // }

    /**
     * Register frontend shortcodes.
     */
    public function register_shortcodes() {
        // Use placeholder classes for now, assuming they exist in the global namespace
        // We will create/move these later and update namespaces.
        // If QP_Shortcodes and QP_Dashboard are already namespaced, update the use statements at the top.
        add_shortcode('question_press_practice', ['\QP_Shortcodes', 'render_practice_form']);
        add_shortcode('question_press_session', ['\QP_Shortcodes', 'render_session_page']);
        add_shortcode('question_press_review', ['\QP_Shortcodes', 'render_review_page']);
        add_shortcode('question_press_dashboard', ['\QP_Dashboard', 'render']);
    }

    /**
     * Load the plugin text domain for translation.
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'question-press', // Your text domain
            false,            // Deprecated argument
            dirname( plugin_basename( QP_PLUGIN_FILE ) ) . '/languages/' // Path to the languages folder
        );
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'question-press' ), '1.0' );
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'question-press' ), '1.0' );
    }
}