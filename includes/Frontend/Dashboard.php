<?php

namespace QuestionPress\Frontend;

use QuestionPress\Database\Terms_DB;
use QuestionPress\Utils\Template_Loader;
use QuestionPress\Utils\User_Access;
use \WP_Query;
use \WP_User;
use QuestionPress\Utils\Dashboard_Manager;

final class Dashboard {

	public static function render() {
		if ( ! is_user_logged_in() ) {
            $options = get_option('qp_settings');
            $signup_page_id = $options['signup_page'] ?? 0;
            $signup_page_url = $signup_page_id ? get_permalink($signup_page_id) : '';

            $args = [
                'signup_page_url' => $signup_page_url,
                'redirect_url' => get_permalink(), // Redirect back to this dashboard page
            ];
			return Template_Loader::get_html('auth/login-prompt-page', 'frontend', $args);
		}

		// --- Fetch common data ---
		$current_user = wp_get_current_user();
		$user_id      = $current_user->ID;
		
		// --- Entitlement Summary Logic (Keep as is) ---
		$access_status_message = '';
		global $wpdb; // Ensure $wpdb is available
		$entitlements_table = $wpdb->prefix . 'qp_user_entitlements';
		$current_time       = current_time( 'mysql' );
		$active_entitlements_for_display = $wpdb->get_results(
			$wpdb->prepare( /* ... existing query ... */
				"SELECT e.entitlement_id, e.plan_id, e.remaining_attempts, e.expiry_date, p.post_title as plan_title
			 FROM {$entitlements_table} e
			 LEFT JOIN {$wpdb->posts} p ON e.plan_id = p.ID
			 WHERE e.user_id = %d AND e.status = 'active' AND (e.expiry_date IS NULL OR e.expiry_date > %s)
			 ORDER BY e.expiry_date ASC, e.entitlement_id ASC",
				$user_id,
				$current_time
			)
		);
		
		$shop_page_url = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );
		$link_text     = empty( $shop_page_url ) ? 'Purchase Access' : 'Purchase More';
		$entitlement_summary = [];
		if ( ! empty( $active_entitlements_for_display ) ) {
			foreach ( $active_entitlements_for_display as $entitlement ) {
				$plan_title_raw = $entitlement->plan_title ?? '';
				$clean_plan_title = preg_replace( '/^Auto: Access Plan for Course "([^"]+)"$/', '$1', $plan_title_raw );

				if ( empty( $clean_plan_title ) ) {
					if ( ! empty( $plan_title_raw ) ) {
						$clean_plan_title = $plan_title_raw;
					} else {
						$clean_plan_title = 'Plan ID #' . $entitlement->plan_id . ' (Missing)';
					}
				}
				$summary_line     = '<strong>' . esc_html( $clean_plan_title ) . '</strong>';
				$details          = [];
				if ( ! is_null( $entitlement->remaining_attempts ) ) {
					$details[] = number_format_i18n( $entitlement->remaining_attempts ) . ' attempts left';
				} else {
					$details[] = 'Unlimited attempts';
				}
				if ( ! is_null( $entitlement->expiry_date ) ) {
					$expiry_timestamp = strtotime( $entitlement->expiry_date );
					$details[]        = 'expires ' . date_i18n( get_option( 'date_format' ), $expiry_timestamp );
				} else {
					$details[] = 'never expires';
				}
				$summary_line       .= ': ' . implode( ', ', $details );
				$entitlement_summary[] = $summary_line;
			}
			$access_status_message = implode( '<br>', $entitlement_summary );
		} else {
			$access_status_message = 'No active plan found. <a href="' . esc_url( $shop_page_url ) . '">' . esc_html( $link_text ) . '</a>';
		}

		// --- NEW: Pre-fetch course counts for conditional tabs ---
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$enrolled_course_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT course_id FROM $user_courses_table WHERE user_id = %d AND status IN ('enrolled', 'in_progress', 'completed')",
				$user_id
			)
		);
		$enrolled_course_count = count($enrolled_course_ids);

		// --- REVISED: Get ACCURATE available course count ---
		$available_course_count = 0;
		// Get all PUBLISHED courses
		$all_published_courses = new \WP_Query( [
			'post_type'      => 'qp_course',
			'post_status'    => 'publish', // Only check 'publish' status for purchasable
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		if ( $all_published_courses->have_posts() ) {
			foreach ( $all_published_courses->posts as $course_id ) {
				// Skip if already enrolled
				if ( in_array( $course_id, $enrolled_course_ids ) ) {
					continue;
				}
				
				// Check if user has a *paid* entitlement (ignoring enrollment)
				$access_result = User_Access::can_access_course( $user_id, $course_id, true );
				
				// If it's a free course (true) OR a paid course they DON'T have access to (false)
				// then it is "available" to be shown.
				if ( $access_result === true || $access_result === false ) {
					$available_course_count++;
				}
				// If access_result is numeric, they've purchased it, so it's NOT "available".
			}
		}
		// --- END REVISED COUNT ---


		// --- Determine active tab ---
		$current_tab         = get_query_var( 'qp_tab', 'overview' );
		$current_course_slug = get_query_var( 'qp_course_slug' );

		// --- Get Sidebar HTML ---
		// Pass the new counts to the sidebar renderer
		$sidebar_html = self::render_sidebar( $current_user, $access_status_message, $current_tab, $enrolled_course_count, $available_course_count );

		// --- Get Main Content HTML ---
		ob_start();
		// --- Main Conditional Rendering Logic (MODIFIED) ---
		if ( $current_tab === 'courses' && ! empty( $current_course_slug ) ) {
			// This is the single course view
			self::render_single_course_view( $current_course_slug, $user_id );
		} elseif ( $current_tab === 'my-courses' ) {
			echo self::render_my_courses_content(); // NEW
		} elseif ( $current_tab === 'available-courses' ) {
			echo self::render_available_courses_content(); // MODIFIED
		} elseif ( $current_tab === 'history' ) {
			echo self::render_history_content();
		} elseif ( $current_tab === 'review' ) {
			echo self::render_review_content();
		        } elseif ( $current_tab === 'progress' ) {
		            echo self::render_progress_content();
		        } elseif ( $current_tab === 'profile' ) {
		            echo self::render_profile_content();
		        } else {
		            // --- Overview Tab (default) ---
		            echo self::render_overview_content( $user_id );
		        }		$main_content_html = ob_get_clean();
		// --- End Main Content HTML ---

		// --- Load Wrapper Template ---
		return Template_Loader::get_html(
			'dashboard/dashboard-wrapper',
			'frontend',
			[
				'sidebar_html'      => $sidebar_html,
				'main_content_html' => $main_content_html,
			]
		);
	}

	/**
	 * Renders the sidebar HTML by loading the template.
	 * NOW RETURNS the HTML string.
	 *
	 * @param WP_User $current_user        The current user object.
	 * @param string  $access_status_message HTML access status message.
	 * @param string  $active_tab          Slug of the active tab.
	 * @param int     $enrolled_course_count Count of user's enrolled courses.
	 * @param int     $available_course_count Count of available (non-enrolled) courses.
	 * @return string  The rendered sidebar HTML.
	 */
	public static function render_sidebar( $current_user, $access_status_message, $active_tab, $enrolled_course_count, $available_course_count ) {
		$options            = get_option( 'qp_settings' );
		$dashboard_page_id  = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;
		$base_dashboard_url = $dashboard_page_id ? trailingslashit( get_permalink( $dashboard_page_id ) ) : home_url( '/' );
		$is_front_page      = ( $dashboard_page_id > 0 && get_option( 'show_on_front' ) == 'page' && get_option( 'page_on_front' ) == $dashboard_page_id );

		// --- NEW: Dynamically build the tabs array ---
		$tabs = [
			'overview' => [
				'label' => 'Overview',
				'icon'  => 'chart-pie',
			],
		];

		// Conditionally add "My Courses" tab
		if ( $enrolled_course_count > 0 ) {
			$tabs['my-courses'] = [
				'label' => 'My Courses',
				'icon'  => 'welcome-learn-more',
			];
		}

		// Add the static middle tabs
		$tabs['history'] = [
			'label' => 'History',
			'icon'  => 'list-view',
		];
		$tabs['review'] = [
			'label' => 'Review',
			'icon'  => 'star-filled',
		];
		$tabs['progress'] = [
			'label' => 'Progress',
			'icon'  => 'chart-bar',
		];

		// Conditionally add "Available Courses" tab
		if ( $available_course_count > 0 ) {
			$tabs['available-courses'] = [
				'label' => 'Available Courses',
				'icon'  => 'store',
			];
		}
		
		// Add the final static tab
		$tabs['profile'] = [
			'label' => 'Profile',
			'icon'  => 'admin-users',
		];
		// --- END NEW DYNAMIC TABS ---


		// --- Get Avatar HTML ---
		$custom_avatar_id   = get_user_meta( $current_user->ID, '_qp_avatar_attachment_id', true );
		$sidebar_avatar_url = '';
		if ( ! empty( $custom_avatar_id ) ) {
			$sidebar_avatar_url = wp_get_attachment_image_url( absint( $custom_avatar_id ), [ 64, 64 ] );
		}
		if ( ! empty( $sidebar_avatar_url ) ) {
			$avatar_html = '<img src="' . esc_url( $sidebar_avatar_url ) . '" alt="Profile Picture" width="64" height="64" class="avatar avatar-64 photo">';
		} else {
			$avatar_html = get_avatar( $current_user->ID, 64 );
		}
		// --- End Avatar HTML ---

		$logout_url = wp_logout_url( get_permalink() ); // Use current page as redirect_to

		// Prepare arguments for the template
		$args = [
			'current_user'          => $current_user,
			'access_status_message' => $access_status_message,
			'active_tab'            => $active_tab,
			'tabs'                  => $tabs,
			'base_dashboard_url'    => $base_dashboard_url,
			'logout_url'            => $logout_url,
			'avatar_html'           => $avatar_html,
			'is_front_page'         => $is_front_page,
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/sidebar', 'frontend', $args );
	}

	/**
	 * Renders the view for a single specific course.
     * (Refactored to use Dashboard_Manager and load a template)
	 */
	public static function render_single_course_view( $course_slug, $user_id ) {
		// Get course WP_Post object by slug
		$posts_array = get_posts([
			'post_type' => 'qp_course',
			'name' => $course_slug,
			'post_status' => ['publish', 'expired'],
			'posts_per_page' => 1,
			'fields' => 'ids'
		]);
		
		$course_id = $posts_array[0] ?? 0;

        // --- Get base dashboard URL (for error display) ---
		$options            = get_option( 'qp_settings' );
		$dashboard_page_id  = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;
		$base_dashboard_url = $dashboard_page_id ? trailingslashit( get_permalink( $dashboard_page_id ) ) : trailingslashit( home_url() );

        // 1. Get all data from the centralized manager function
        $data = Dashboard_Manager::get_course_structure_data( $course_id, $user_id );

        // 2. Handle errors
        if ( is_null( $data ) ) {
			echo '<div class="qp-card"><div class="qp-card-content"><p>Error: Course not found or you do not have permission to view it.</p></div></div>';
			echo '<a href="' . esc_url( $base_dashboard_url ) . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Dashboard</a>';
			return;
        }

		// 3. Load the template
		echo Template_Loader::get_html( 'dashboard/single-course-view', 'frontend', $data );
	}

	/**
	 * Renders the content specifically for the Overview section by loading a template.
	 * NOW RETURNS the HTML string.
	 *
	 * @param int $user_id The ID of the current user.
	 * @return string Rendered HTML content.
	 */
	public static function render_overview_content( $user_id ) {
		$overview_data = Dashboard_Manager::get_overview_data( $user_id );

		// Prepare arguments array for the template
		$args = [
			'stats'                 => $overview_data['stats'],
			'overall_accuracy'      => $overview_data['overall_accuracy'],
			'review_count'          => $overview_data['review_count'],
			'never_correct_count'   => $overview_data['never_correct_count'],
			'practice_page_url'     => $overview_data['practice_page_url'],
			'session_page_url'      => $overview_data['session_page_url'],
			'review_page_url'       => $overview_data['review_page_url'],
			'allow_termination'     => $overview_data['allow_termination'],
			'history_tab_url'       => $overview_data['history_tab_url'],
			'accuracy_stats'        => $overview_data['accuracy_stats'],

			// Active Sessions & Data
			'active_sessions'       => $overview_data['active_sessions'],
			'lineage_cache_active'  => $overview_data['lineage_cache_active'],
			'group_to_topic_map_active' => $overview_data['group_to_topic_map_active'],
			'question_to_group_map_active' => $overview_data['question_to_group_map_active'],

			// Recent History & Data
			'recent_history'        => $overview_data['recent_history'],
			'lineage_cache_recent'  => $overview_data['lineage_cache_recent'],
			'group_to_topic_map_recent' => $overview_data['group_to_topic_map_recent'],
			'question_to_group_map_recent' => $overview_data['question_to_group_map_recent'],
		];

		// Load and return the template HTML
		$html = Template_Loader::get_html( 'dashboard/overview', 'frontend', $args );

		if ( empty( trim( $html ) ) || strpos( $html, 'Template not found' ) !== false ) {
			return '<p style="color:red;">Error: Overview template could not be loaded.</p>';
		}

		return $html;
	}

	/**
	 * Renders the content specifically for the History section by loading a template.
	 * (Refactored to use Dashboard_Manager)
	 */
	public static function render_history_content() {
		$user_id = get_current_user_id();
		
        // Get all data from the centralized manager function
        $data = Dashboard_Manager::get_history_data( $user_id );

		// Load and return the template HTML, passing all data
		return Template_Loader::get_html( 'dashboard/history', 'frontend', $data );
	}

	/**
	 * Renders the content specifically for the Review section by loading a template.
	 * (Refactored to use Dashboard_Manager)
	 */
	public static function render_review_content() {
		$user_id = get_current_user_id();
        
        // 1. Get all data from the centralized manager function
		$data = Dashboard_Manager::get_review_data( $user_id );

		// 2. Load and return the template HTML
        // The template 'dashboard/review.php' doesn't need any changes.
		return Template_Loader::get_html( 'dashboard/review', 'frontend', $data );
	}
	
	/**
	 * Renders the content specifically for the Progress section by loading a template.
	 * * @return string Rendered HTML content.
	 */
	public static function render_progress_content() {
		global $wpdb;
		$user_id = get_current_user_id();

		// 1. Fetch performance data using the centralized manager (Architect's Path)
		$progress_data = Dashboard_Manager::get_progress_data( $user_id );

		// 2. Fetch subjects for administrative filters (Preserving existing scope logic)
		$term_table     = $wpdb->prefix . 'qp_terms';
		$tax_table      = $wpdb->prefix . 'qp_taxonomies';
		$subject_tax_id = $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = %s", 'subject' ) );
		$subjects       = [];

		if ( $subject_tax_id ) {
			$allowed_subjects_or_all = User_Access::get_allowed_subject_ids( $user_id );

			if ( $allowed_subjects_or_all === 'all' ) {
				// User has access to all, fetch all subjects
				$subjects = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND name != 'Uncategorized' AND parent = 0 ORDER BY name ASC",
						$subject_tax_id
					)
				);
			} elseif ( is_array( $allowed_subjects_or_all ) && ! empty( $allowed_subjects_or_all ) ) {
				// User has specific access, fetch only those subjects
				$ids_placeholder = implode( ',', array_map( 'absint', $allowed_subjects_or_all ) );
				$subjects        = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT term_id, name FROM {$term_table} WHERE taxonomy_id = %d AND term_id IN ($ids_placeholder) AND parent = 0 ORDER BY name ASC",
						$subject_tax_id
					)
				);
			}
		}

		// 3. Prepare arguments for the template loader
		$args = [
			'subjects'      => $subjects,
			'progress_data' => $progress_data, // Wire the aggregated stats to the template
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/progress', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the "Available Courses" section.
	 * (Refactored to use Dashboard_Manager)
	 * @return string Rendered HTML content.
	 */
	public static function render_available_courses_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

        // 1. Get all data from the centralized manager function
        $data = Dashboard_Manager::get_available_courses_data( $user_id );

		// 2. Load and return the "courses" template
        // This template (dashboard/courses.php) doesn't need any changes
        // as it already expects the '$available_courses_query' variable.
		return Template_Loader::get_html( 'dashboard/courses', 'frontend', $data );
	}

	/**
	 * Renders the content specifically for the Profile section by loading a template.
	 * NOW PUBLIC STATIC and RETURNS HTML.
	 * Fetches user data and displays it using cards.
	 */
	public static function render_profile_content() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your profile.', 'question-press' ) . '</p>';
		}

		$user_id      = get_current_user_id();
		$profile_data = Dashboard_Manager::get_profile_data( $user_id ); // Use the existing helper

		// Prepare arguments for the template
		$args = [
			'profile_data' => $profile_data,
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/profile', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the "My Courses" section.
	 * (Refactored to use Dashboard_Manager)
	 * @return string Rendered HTML content.
	 */
	public static function render_my_courses_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

        // Get all data from the centralized manager function
        $data = Dashboard_Manager::get_my_courses_data( $user_id );
		
		// Load and return the "my-courses" template
		return Template_Loader::get_html( 'dashboard/my-courses', 'frontend', $data );
	}


	// --- Keep the render_sessions_tab_content function, but make it private ---
	public static function render_sessions_tab_content() {
		global $wpdb;
		$user_id        = get_current_user_id();
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';

		// Fetching data remains the same
		$options           = get_option( 'qp_settings' );
		$session_page_url  = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
		$review_page_url   = isset( $options['review_page'] ) ? get_permalink( $options['review_page'] ) : home_url( '/' );
		$practice_page_url = isset( $options['practice_page'] ) ? get_permalink( $options['practice_page'] ) : home_url( '/' );

		$user          = wp_get_current_user();
		$user_roles    = (array) $user->roles;
		$allowed_roles = isset( $options['can_delete_history_roles'] ) ? $options['can_delete_history_roles'] : [ 'administrator' ];
		$can_delete    = ! empty( array_intersect( $user_roles, $allowed_roles ) );

		$session_history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $sessions_table
			 WHERE user_id = %d AND status IN ('completed', 'abandoned', 'paused')
			 ORDER BY CASE WHEN status = 'paused' THEN 0 ELSE 1 END, start_time DESC",
				$user_id
			)
		);

		// Pre-fetch lineage data
		list($lineage_cache, $group_to_topic_map, $question_to_group_map) = Dashboard_Manager::prefetch_lineage_data( $session_history );

		// Display Header Actions
		echo '<div class="qp-history-header">
			 <h3 style="margin:0;">Practice History</h3>
			 <div class="qp-history-actions">
				 <a href="' . esc_url( $practice_page_url ) . '" class="qp-button qp-button-primary">Practice</a>';
		if ( $can_delete ) {
			echo '<button id="qp-delete-history-btn" class="qp-button qp-button-danger">Clear History</button>';
		}
		echo '</div></div>';

		// Display Table
		echo '<table class="qp-dashboard-table">
			 <thead><tr><th>Date</th><th>Mode</th><th>Context</th><th>Result</th><th>Status</th><th>Actions</th></tr></thead>
			 <tbody>';

		if ( ! empty( $session_history ) ) {
			foreach ( $session_history as $session ) {
				$settings         = json_decode( $session->settings_snapshot, true );
				$mode             = Dashboard_Manager::get_session_mode_name( $session, $settings );
				$subjects_display = Dashboard_Manager::get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map );
				$result_display   = Dashboard_Manager::get_session_result_display( $session, $settings );
				$status_display   = ucfirst( $session->status ); // Simple status for now
				if ( $session->status === 'abandoned' ) {
					$status_display = 'Abandoned';
				}
				if ( $session->end_reason === 'autosubmitted_timer' ) {
					$status_display = 'Auto-Submitted';
				}

				$row_class = $session->status === 'paused' ? 'class="qp-session-paused"' : '';
				echo '<tr ' . $row_class . '>
					 <td data-label="Date">' . date_format( date_create( $session->start_time ), 'M j, Y, g:i a' ) . '</td>
					 <td data-label="Mode">' . esc_html( $mode ) . '</td>
					 <td data-label="Context">' . esc_html( $subjects_display ) . '</td>
					 <td data-label="Result"><strong>' . esc_html( $result_display ) . '</strong></td>
					 <td data-label="Status">' . esc_html( $status_display ) . '</td>
					 <td data-label="Actions" class="qp-actions-cell">';

				if ( $session->status === 'paused' ) {
					echo '<a href="' . esc_url( add_query_arg( 'session_id', $session->session_id, $session_page_url ) ) . '" class="qp-button qp-button-primary">Resume</a>';
				} else {
					echo '<a href="' . esc_url( add_query_arg( 'session_id', $session->session_id, $review_page_url ) ) . '" class="qp-button qp-button-secondary">Review</a>';
				}
				if ( $can_delete ) {
					echo '<button class="qp-delete-session-btn" data-session-id="' . esc_attr( $session->session_id ) . '">Delete</button>';
				}
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="6" style="text-align: center;">You have no completed practice sessions yet.</td></tr>';
		}
		echo '</tbody></table>';
	}
}