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
        // Assets::instance();
        // Ajax::instance();
        // Shortcodes::instance();
        // Post_Types::instance();
        // REST_API::instance(); // Assuming REST_API is a class handling registration
        // if ( is_admin() ) {
        //     Admin::instance();
        // }
        // If WooCommerce is active
        // if ( class_exists( 'WooCommerce' ) ) {
        //    Integrations\WooCommerce::instance();
        // }
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks() {
        // Register activation/deactivation hooks statically from the main plugin file is safer.
        // add_action( 'init', array( $this, 'init' ), 0 ); // Example hook
    }

    /**
     * Init plugin when WordPress Initialises.
     */
    // public function init() {
        // Actions to perform on WordPress init hook
        // Example: load_plugin_textdomain( 'question-press', false, dirname( QP_PLUGIN_BASENAME ) . '/languages' );
    // }

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