<?php
namespace QuestionPress; // PSR-4 Namespace

/**
 * Just in case if migrated to native taxonomies later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the registration of Custom Taxonomies.
 * (Currently unused as custom tables are used, but placeholder for structure)
 *
 * @package QuestionPress
 */
class Taxonomies {

    /**
     * The single instance of the class.
     * @var Taxonomies|null
     */
    private static $_instance = null;

    /**
     * Main Taxonomies Instance.
     * @static
     * @return Taxonomies Main instance.
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
        // add_action( 'init', [ $this, 'register_taxonomies' ], 5 );
    }

    /**
     * Register core taxonomies. (Placeholder)
     */
    public function register_taxonomies() {
        // If migrating later, registration logic would go here.
    }

    /** Cloning/Unserializing prevention */
    public function __clone() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'question-press' ), '1.0' ); }
    public function __wakeup() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'question-press' ), '1.0' ); }

} // End class Taxonomies