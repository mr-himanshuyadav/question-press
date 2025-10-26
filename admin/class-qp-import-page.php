<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class QP_Import_Page
{
    /**
     * Renders the Import page.
     * Handles the form submission OR displays the upload form template.
     */
    public static function render()
    {
        // Check if the form has been submitted and a file is uploaded
        if (isset($_POST['submit']) && isset($_FILES['question_zip_file'])) {
            // Nonce and file error checks are handled inside the importer
            $importer = new QP_Importer();
            $importer->handle_import(); // This method will echo the results page
        } else {
            // No submission, so just render the upload form
            self::render_upload_form();
        }
    }

    /**
     * Fetches data and renders the upload form template.
     * This method is now private as it's only called by self::render().
     */
    private static function render_upload_form()
    {
        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $label_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'label'");

        $all_labels = [];
        if ($label_tax_id) {
            $all_labels = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d ORDER BY name ASC",
                $label_tax_id
            ));
        }
        
        // Prepare arguments for the template
        $args = [
            'all_labels' => $all_labels,
        ];
        
        // Load and echo the template
        echo qp_get_template_html( 'tools-import', 'admin', $args );
    }
}