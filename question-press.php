<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           4.0.0
 * Author:            Himanshu
 * Text Domain:       question-press
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
} else {
    // Add admin notice if dependencies missing
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Question Press requires Composer dependencies. Please run "composer install" in the plugin directory.', 'question-press' );
        echo '</p></div>';
    });
    return; // Stop loading if dependencies are missing
}

if ( ! defined( 'QP_PLUGIN_FILE' ) ) {
    define( 'QP_PLUGIN_FILE', __FILE__ );
}

use QuestionPress\Plugin;
use QuestionPress\Activator;
use QuestionPress\Deactivator;


/**
 * Main function for returning the Plugin instance ---
 */
function QuestionPress() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
    return Plugin::instance();
}

// --- Get Plugin running ---
QuestionPress();

// --- Activation / Deactivation Hooks ---
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );
