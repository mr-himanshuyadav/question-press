<?php
namespace QuestionPress\Utils;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles loading view templates from the /templates/ directory.
 *
 * @package QuestionPress\Utils
 */
class Template_Loader {

    /**
     * Load a template file and return its output as a string.
     *
     * Looks for the template in `templates/{$folder}/{$template_name}.php`.
     * Variables can be passed to the template using the $args array.
     *
     * @param string $template_name The name of the template file (without .php).
     * @param string $folder        The subfolder within templates ('frontend' or 'admin'). Default 'frontend'.
     * @param array  $args          Optional. Associative array of variables to pass ($key => $value).
     * @return string               The output of the template file.
     */
    public static function get_html( $template_name, $folder = 'frontend', $args = [] ) {
        // --- NEW LOGIC TO HANDLE SUBDIRECTORIES ---
        $template_name = ltrim( $template_name, '/' ); // Remove leading slash if any
        $folder        = sanitize_key( $folder ); // Sanitize the main folder ('frontend' or 'admin')

        // Separate potential subdirectories from the filename
        $path_parts = explode( '/', $template_name );
        $filename   = sanitize_key( array_pop( $path_parts ) ); // Get and sanitize the last part (filename)
        $sub_path   = '';

        // Sanitize and rejoin any subdirectory parts
        if ( ! empty( $path_parts ) ) {
            $sanitized_parts = array_map( 'sanitize_key', $path_parts );
            $sub_path        = implode( '/', $sanitized_parts ) . '/'; // Reconstruct sub-path with trailing slash
        }

        // Construct the full path correctly
        $template_path = QP_TEMPLATES_DIR . trailingslashit( $folder ) . $sub_path . $filename . '.php';
        // --- END NEW LOGIC ---

        if ( ! file_exists( $template_path ) ) {
            error_log( "Question Press Template Error: Template not found at {$template_path}" );
            return ""; 
        }

        // Make variables available to the included template
        if ( is_array( $args ) && ! empty( $args ) ) {
            extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        }
        
        ob_start();
        include $template_path;
        $content = ob_get_clean();

        return $content;
    }
}