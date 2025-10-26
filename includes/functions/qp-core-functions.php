<?php
/**
 * Question Press Core Functions
 *
 * General core functions available on both frontend and admin.
 *
 * @package QuestionPress\Functions
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'qp_get_template_html' ) ) {
    /**
     * Load a template file and return its output as a string.
     *
     * Looks for the template in `templates/{$folder}/{$template_name}.php`.
     * Variables can be passed to the template using the $args array.
     *
     * @param string $template_name The name of the template file (without .php).
     * @param string $folder        The subfolder within templates ('frontend' or 'admin'). Default 'frontend'.
     * @param array  $args          Optional. Associative array of variables to pass ($key => $value).
     * Variables will be available in the template as $key.
     * @return string               The output of the template file.
     */
    function qp_get_template_html( $template_name, $folder = 'frontend', $args = [] ) {
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

        // Debugging: Log the final path being checked
        // error_log("Checking template path: " . $template_path);

        if ( ! file_exists( $template_path ) ) {
            error_log( "Question Press Template Error: Template not found at {$template_path}" ); // Keep error log active
            return ""; // Use original name in comment for clarity
        }

        // --- Extract and include logic remains the same ---
        if ( is_array( $args ) && ! empty( $args ) ) {
            extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        }
        ob_start();
        include $template_path;
        $content = ob_get_clean();
        // --- End Extract and include ---

        return $content;
    }
}

// Example function structure:
/*
if ( ! function_exists( 'qp_get_some_value' ) ) {
    function qp_get_some_value( $arg ) {
        Function logic here
        return $result;
    }
}
*/

// We will move functions like qp_get_term_meta, qp_update_term_meta,
// qp_get_or_create_term, qp_get_term_lineage_names, etc., here later.