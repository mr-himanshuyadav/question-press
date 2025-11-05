<?php
namespace QuestionPress\Database;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for Database interactions.
 * Provides access to WordPress database objects.
 *
 * @package QuestionPress\Database
 */
abstract class DB {

    /**
     * WordPress Database instance.
     * @var \wpdb
     */
    protected static $wpdb;

    /**
     * Initialize the database connection.
     * Needs to be called once, typically by the main plugin class.
     */
    public static function init() {
        global $wpdb;
        self::$wpdb = $wpdb;
    }

    /**
     * Constructor (private to prevent instantiation directly).
     */
    private function __construct() {}

} // End class DB