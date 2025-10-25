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