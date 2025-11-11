<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           4.2.1
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
    require_once plugin_dir_path( __FILE__ ) . 'includes/Utils/Practice_Manager.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Rest_Api/PracticeController.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/Rest_Api/CourseController.php'; // Added
} else {
    // Add admin notice if dependencies missing
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Question Press requires Composer dependencies. Please run "composer install" in the plugin directory.', 'question-press' );
        echo '</p></div>';
    });
    return; // Stop loading if dependencies are missing
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

try {
    //
    // STEP 2: Use ::github() to explicitly create a GitHub checker
    //
    $myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/mr-himanshuyadav/question-press/',
        __FILE__,
        'question-press'
    );

    //
    // STEP 3: Add your authentication token (for private repos)
    //
    $myUpdateChecker->setAuthentication('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');

    //
    // STEP 5: Make sure setBranch is commented out, as it overrides setReleaseAsset
    //
    // $myUpdateChecker->setBranch('feat/update-from-github'); // <-- DO NOT USE THIS

} catch (Exception $e) {
    // Handle potential error, e.g., log it
    error_log('Error initializing Question Press update checker: ' . $e->getMessage());
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
