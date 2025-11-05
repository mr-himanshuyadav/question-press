<?php
/**
 * Plugin Name:       Question Press
 * Description:       A complete plugin for creating, managing, and practicing questions.
 * Version:           4.1.3
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

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

try {
    $myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/mr-himanshuyadav/question-press/', // Your GitHub repo URL
        __FILE__, // Path to the main plugin file
        'question-press' // The plugin's slug (from your file name)
    );

    // (Optional) If your repo is PRIVATE, you MUST set an authentication token.
    // Create a "Fine-Grained Personal Access Token" on GitHub with "Contents: Read-only" permission.
    // $myUpdateChecker->setAuthentication('YOUR_GITHUB_TOKEN_HERE');

    // This is the CRITICAL part for the vendor/ folder.
    // It tells the checker to download the 'question-press.zip' asset from a release,
    // not the default "Source code (zip)". We will create this file in Step 2.
    $myUpdateChecker->setReleaseAsset('question-press.zip');

    // (Optional) You can set it to check a specific branch, like 'main'
    // $myUpdateChecker->setBranch('main'); 

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
