<?php
namespace QuestionPress; // PSR-4 Namespace

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles asset (CSS/JS) registration and enqueuing.
 *
 * @package QuestionPress
 */
class Assets {

    /**
     * The single instance of the class.
     * @var Assets|null
     */
    private static $_instance = null;

    /**
     * Main Assets Instance.
     * Ensures only one instance of Assets is loaded.
     * @static
     * @return Assets Main instance.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor. Hooks into WordPress actions.
     */
    private function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    /**
     * Enqueues scripts and styles for the public-facing pages.
     */
    public function enqueue_public_scripts() {
        // We will move the logic from qp_public_enqueue_scripts() here.
         global $post; // Make post object available

        global $post;
    if (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_dashboard') || has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review'))) {

        wp_enqueue_style('dashicons');

        // File versions for cache busting
        $css_version = file_exists( QP_PLUGIN_PATH . 'assets/css/practice.css' ) ? filemtime( QP_PLUGIN_PATH . 'assets/css/practice.css' ) : QP_PLUGIN_VERSION;
        $practice_js_version = file_exists( QP_PLUGIN_PATH . 'assets/js/practice.js' ) ? filemtime( QP_PLUGIN_PATH . 'assets/js/practice.js' ) : QP_PLUGIN_VERSION;
        $dashboard_js_version = file_exists( QP_PLUGIN_PATH . 'assets/js/dashboard.js' ) ? filemtime( QP_PLUGIN_PATH . 'assets/js/dashboard.js' ) : QP_PLUGIN_VERSION;
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $allowed_roles = isset($options['can_delete_history_roles']) ? $options['can_delete_history_roles'] : ['administrator'];

        // Check if the user's roles intersect with the allowed roles
        $can_delete = !empty(array_intersect($user_roles, $allowed_roles));

        wp_enqueue_style('qp-practice-styles', QP_ASSETS_URL . 'css/practice.css', [], $css_version);
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            $dashboard_css_version = file_exists( QP_PLUGIN_PATH . 'assets/css/dashboard.css' ) ? filemtime( QP_PLUGIN_PATH . 'assets/css/dashboard.css' ) : QP_PLUGIN_VERSION; // Get version for cache busting
            wp_enqueue_style('qp-dashboard-styles', QP_ASSETS_URL . 'css/dashboard.css', ['qp-practice-styles'], $dashboard_css_version); // Make it dependent on practice styles
        }
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

        $options = get_option('qp_settings');
        $shop_page_url = function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/');
        $ajax_data = [
            'ajax_url'           => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('qp_practice_nonce'),
            'enroll_nonce'       => wp_create_nonce('qp_enroll_course_nonce'), // <-- ADD THIS
            'start_course_test_nonce' => wp_create_nonce('qp_start_course_test_nonce'), // <-- ADD THIS
            'dashboard_page_url' => isset($options['dashboard_page']) ? get_permalink($options['dashboard_page']) : home_url('/'),
            'practice_page_url'  => isset($options['practice_page']) ? get_permalink($options['practice_page']) : home_url('/'),
            'review_page_url'    => isset($options['review_page']) ? get_permalink($options['review_page']) : home_url('/'),
            'session_page_url'   => isset($options['session_page']) ? get_permalink($options['session_page']) : home_url('/'),
            'question_order_setting'   => isset($options['question_order']) ? $options['question_order'] : 'random',
            'shop_page_url'      => $shop_page_url,
            'can_delete_history' => $can_delete
        ];

        // --- CORRECTED SCRIPT LOADING LOGIC ---

        // Load dashboard script if the dashboard shortcode is present
        if (has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
            wp_enqueue_script('qp-dashboard-script', QP_ASSETS_URL . 'js/dashboard.js', ['jquery', 'sweetalert2'], $dashboard_js_version, true);
            wp_localize_script('qp-dashboard-script', 'qp_ajax_object', $ajax_data);
        }

        // Load practice script if practice or session shortcodes are present
        if (has_shortcode($post->post_content, 'question_press_practice') || has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review')) {

            wp_enqueue_script('hammer-js', 'https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js', [], '2.0.8', true);
            wp_enqueue_script('qp-practice-script', QP_ASSETS_URL . 'js/practice.js', ['jquery', 'hammer-js'], $practice_js_version, true);
            wp_localize_script('qp-practice-script', 'qp_ajax_object', $ajax_data);
            $qp_settings = get_option('qp_settings');
            wp_localize_script('qp-practice-script', 'qp_practice_settings', [
                'show_counts' => !empty($qp_settings['show_question_counts']),
                'show_topic_meta' => !empty($qp_settings['show_topic_meta'])
            ]);
        }

        // Load KaTeX if any page that can display questions is present
        if (has_shortcode($post->post_content, 'question_press_session') || has_shortcode($post->post_content, 'question_press_review') || has_shortcode($post->post_content, 'question_press_dashboard')) {
            wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
            wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
            wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);

            // Add the inline script to actually render the math
            wp_add_inline_script('katex-auto-render', "
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof renderMathInElement === 'function') {
                        renderMathInElement(document.body, {
                            delimiters: [
                                {left: '$$', right: '$$', display: true},
                                {left: '$', right: '$', display: false},
                                {left: '\\\\[', right: '\\\\]', display: true},
                                {left: '\\\\(', right: '\\\\)', display: false}
                            ],
                            throwOnError: false
                        });
                    }
                });
            ");
        }

        // Localize session data specifically for the session page
        if (has_shortcode($post->post_content, 'question_press_session')) {
            $session_data = QP_Shortcodes::get_session_data_for_script();
            if ($session_data) {
                wp_localize_script('qp-practice-script', 'qp_session_data', $session_data);
            }
        }
    }
    }

    /**
     * Enqueues scripts and styles for the WordPress admin area.
     *
     * @param string $hook_suffix The current admin page hook.
     */
    public function enqueue_admin_scripts( $hook_suffix ) {
        // We will move the logic from qp_admin_enqueue_scripts() here.

        if ($hook_suffix === 'question-press_page_qp-tools') {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
        wp_enqueue_script('qp-backup-restore-script', QP_ASSETS_URL . 'js/backup-restore.js', ['jquery', 'sweetalert2'], '1.0.0', true);
        wp_localize_script('qp-backup-restore-script', 'qp_backup_restore_data', [
            'nonce' => wp_create_nonce('qp_backup_restore_nonce')
        ]);
    }

    if (strpos($hook_suffix, 'qp-') !== false || strpos($hook_suffix, 'question-press') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    if ($hook_suffix === 'question-press_page_qp-question-editor' || $hook_suffix === 'admin_page_qp-edit-group') {
        wp_enqueue_media();
        wp_enqueue_script('qp-media-uploader-script', QP_ASSETS_URL . 'js/media-uploader.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('qp-editor-script', QP_ASSETS_URL . 'js/question-editor.js', ['jquery'], '1.0.1', true);
    }
    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_ASSETS_URL . 'js/quick-edit.js', ['jquery'], '1.0.1', true);
        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce')
        ]);
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_ASSETS_URL . 'js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
    // Check if we are on the 'Add New' or 'Edit' screen for the 'qp_course' post type
    global $pagenow, $typenow;
    if (($pagenow == 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] == 'qp_course') ||
        ($pagenow == 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) == 'qp_course')) {

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue our new course editor script
        $course_editor_js_version = filemtime(QP_PLUGIN_PATH . 'js/course-editor.js'); // For cache busting
        wp_enqueue_script('qp-course-editor-script', QP_ASSETS_URL . 'js/course-editor.js', ['jquery', 'jquery-ui-sortable'], $course_editor_js_version, true);

        // Localize data needed by the script (like existing structure and dropdown options)
        global $post; // Get the current post object
        $course_structure_data = qp_get_course_structure_for_editor($post ? $post->ID : 0); // We will create this helper function next
        $test_series_options = qp_get_test_series_options_for_js(); // And this one too

        wp_localize_script('qp-course-editor-script', 'qpCourseEditorData', [
            'ajax_url' => admin_url('admin-ajax.php'), // Add ajaxurl for convenience
            'save_nonce' => wp_create_nonce('qp_save_course_structure_meta'), // Keep existing save nonce
            'select_nonce' => wp_create_nonce('qp_course_editor_select_nonce'), // Add the NEW nonce
            'structure' => $course_structure_data,
            'testSeriesOptions' => $test_series_options
        ]);
        // Enqueue course editor CSS
        $course_editor_css_version = filemtime(QP_PLUGIN_PATH . 'css/course-editor.css');
        wp_enqueue_style('qp-course-editor-style', QP_ASSETS_URL . 'css/course-editor.css', [], $course_editor_css_version);
    }
    if ($hook_suffix === 'question-press_page_qp-organization' && isset($_GET['tab']) && $_GET['tab'] === 'labels') {
        add_action('admin_footer', function () {
            echo '<script>jQuery(document).ready(function($){$(".qp-color-picker").wpColorPicker();});</script>';
        });
    }

    if ($hook_suffix === 'question-press_page_qp-organization') {
        wp_enqueue_script('qp-organization-script', QP_ASSETS_URL . 'js/organization-page.js', ['jquery'], '1.0.0', true);
    }

    if ($hook_suffix === 'question-press_page_qp-settings') {
        wp_enqueue_script('qp-settings-script', QP_ASSETS_URL . 'js/settings-page.js', ['jquery'], '1.0.0', true);
    }

    if (
        $hook_suffix === 'question-press_page_qp-settings' ||
        $hook_suffix === 'toplevel_page_question-press' ||
        $hook_suffix === 'question-press_page_qp-question-editor' ||
        $hook_suffix === 'admin_page_qp-edit-group'
    ) {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);
    }

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_style('katex-css', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css', [], '0.16.9');
        wp_enqueue_script('katex-js', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js', [], '0.16.9', true);
        wp_enqueue_script('katex-auto-render', 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js', ['katex-js'], '0.16.9', true);

        wp_add_inline_script('katex-auto-render', "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof renderMathInElement === 'function') {
                renderMathInElement(document.getElementById('the-list'), {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false},
                        {left: '\\\\[', right: '\\\\]', display: true},
                        {left: '\\\\(', right: '\\\\)', display: false}
                    ],
                    throwOnError: false
                });
            }
        });
    ");
    }

    if ($hook_suffix === 'toplevel_page_question-press') {
        wp_enqueue_script('qp-quick-edit-script', QP_ASSETS_URL . 'js/quick-edit.js', ['jquery'], '1.0.2', true);
        // Add a nonce specifically for our new admin filters
        wp_localize_script('qp-quick-edit-script', 'qp_admin_filter_data', [
            'nonce' => wp_create_nonce('qp_admin_filter_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);

        global $wpdb;
        $term_table = $wpdb->prefix . 'qp_terms';
        $tax_table = $wpdb->prefix . 'qp_taxonomies';
        $rel_table = $wpdb->prefix . 'qp_term_relationships';

        // Get taxonomy IDs
        $subject_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'subject'");
        $source_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'source'");
        $exam_tax_id = $wpdb->get_var("SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = 'exam'");

        // Get all topics (terms with a parent under the subject taxonomy)
        $all_topics = $wpdb->get_results($wpdb->prepare("SELECT term_id AS topic_id, name AS topic_name, parent AS subject_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $subject_tax_id));

        // all sources (top-level terms under the source taxonomy)
        $all_sources = $wpdb->get_results($wpdb->prepare("SELECT term_id AS source_id, name AS source_name FROM {$term_table} WHERE taxonomy_id = %d AND parent = 0", $source_tax_id));

        // Get all sections (child terms under the source taxonomy)
        $all_sections = $wpdb->get_results($wpdb->prepare("SELECT term_id AS section_id, name AS section_name, parent AS source_id FROM {$term_table} WHERE taxonomy_id = %d AND parent != 0", $source_tax_id));

        // Build the source-to-subject relationship map
        $source_subject_links = $wpdb->get_results(
            "SELECT object_id AS source_id, term_id AS subject_id
             FROM {$rel_table}
             WHERE object_type = 'source_subject_link'"
        );

        // Get all exams
        $all_exams = $wpdb->get_results($wpdb->prepare("SELECT term_id AS exam_id, name AS exam_name FROM {$term_table} WHERE taxonomy_id = %d", $exam_tax_id));

        // Get all exam-to-subject links
        $exam_subject_links = $wpdb->get_results("SELECT object_id AS exam_id, term_id AS subject_id FROM {$rel_table} WHERE object_type = 'exam_subject_link'");

        wp_localize_script('qp-quick-edit-script', 'qp_bulk_edit_data', [
            'sources' => $all_sources,
            'sections' => $all_sections,
            'exams' => $all_exams,
            'exam_subject_links' => $exam_subject_links,
            'source_subject_links' => $source_subject_links,
            'topics' => $all_topics
        ]);

        wp_localize_script('qp-quick-edit-script', 'qp_quick_edit_object', [
            'save_nonce' => wp_create_nonce('qp_save_quick_edit_nonce'),
            'nonce' => wp_create_nonce('qp_practice_nonce')
        ]);
        wp_enqueue_script('qp-multi-select-dropdown-script', QP_ASSETS_URL . 'js/multi-select-dropdown.js', ['jquery'], '1.0.1', true);
    }
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'question-press' ), '1.0' );
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'question-press' ), '1.0' );
    }

} // End class Assets