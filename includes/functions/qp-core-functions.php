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
     * This is a wrapper for the \QuestionPress\Utils\Template_Loader::get_html() method
     * for backward compatibility.
     *
     * @param string $template_name The name of the template file (without .php).
     * @param string $folder        The subfolder within templates ('frontend' or 'admin'). Default 'frontend'.
     * @param array  $args          Optional. Associative array of variables to pass ($key => $value).
     * @return string               The output of the template file.
     */
    function qp_get_template_html( $template_name, $folder = 'frontend', $args = [] ) {
        return \QuestionPress\Utils\Template_Loader::get_html( $template_name, $folder, $args );
    }
}