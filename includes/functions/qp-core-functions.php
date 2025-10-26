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
        // Construct the full path to the template file
        $template_path = QP_TEMPLATES_DIR . trailingslashit( sanitize_key( $folder ) ) . sanitize_key( $template_name ) . '.php';

        if ( ! file_exists( $template_path ) ) {
            // Optional: Log an error or return an error message
            // error_log( "Question Press Template Error: Template not found at {$template_path}" );
            return "";
        }

        // Extract the arguments array into individual variables
        if ( is_array( $args ) && ! empty( $args ) ) {
            extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        }

        // Start output buffering
        ob_start();

        // Include the template file
        include $template_path;

        // Get the buffered content and clean the buffer
        $content = ob_get_clean();

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