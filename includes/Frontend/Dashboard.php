<?php

namespace QuestionPress\Frontend;

use QuestionPress\Database\Terms_DB;
use QuestionPress\Utils\Template_Loader;
use QuestionPress\Utils\User_Access;
use \WP_Query;
use \WP_User;

final class Dashboard {

	public static function render() {
		if ( ! is_user_logged_in() ) {
			// Keep login message logic here for now, or move to its own template later
			return '<p>You must be logged in to view your dashboard. <a href="' . wp_login_url( get_permalink() ) . '">Click here to log in.</a></p>';
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
			$attempts_table = $wpdb->prefix . 'qp_user_attempts';
			$stats          = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(CASE WHEN status = 'answered' THEN 1 END) as total_attempted, COUNT(CASE WHEN is_correct = 1 THEN 1 END) as total_correct, COUNT(CASE WHEN is_correct = 0 THEN 1 END) as total_incorrect FROM {$attempts_table} WHERE user_id = %d", $user_id ) );
			$total_attempted  = $stats->total_attempted ?? 0;
			$total_correct    = $stats->total_correct ?? 0;
			$overall_accuracy = ( $total_attempted > 0 ) ? ( $total_correct / $total_attempted ) * 100 : 0;
			$sessions_table   = $wpdb->prefix . 'qp_user_sessions';
			$active_sessions  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('active', 'mock_test', 'paused') ORDER BY start_time DESC", $user_id ) );
			$recent_history   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC LIMIT 5", $user_id ) );
			$review_count     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}qp_review_later WHERE user_id = %d", $user_id ) );
			$correctly_answered_qids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id ) );
			$all_answered_qids       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id ) );
			$never_correct_qids      = array_diff( $all_answered_qids, $correctly_answered_qids );
			$never_correct_count     = count( $never_correct_qids );
			$options                 = get_option( 'qp_settings' );
			$practice_page_url       = isset( $options['practice_page'] ) ? get_permalink( $options['practice_page'] ) : home_url( '/' );
			$session_page_url        = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
			$review_page_url         = isset( $options['review_page'] ) ? get_permalink( $options['review_page'] ) : home_url( '/' );

			echo self::render_overview_content(
				$stats,
				$overall_accuracy,
				$active_sessions,
				$recent_history,
				$review_count,
				$never_correct_count,
				$practice_page_url,
				$session_page_url,
				$review_page_url
			);
		}
		$main_content_html = ob_get_clean();
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
	 * Renders the view for a single specific course, fetching its structure and user progress.
	 */
	public static function render_single_course_view( $course_slug, $user_id ) {
		// Get course WP_Post object by slug, allowing 'expired' status
		$posts_array = get_posts([
			'post_type' => 'qp_course',
			'name' => $course_slug,
			'post_status' => ['publish', 'expired'],
			'posts_per_page' => 1,
			'fields' => 'ids' // Only get ID for efficiency
		]);
		
		$course_id = $posts_array[0] ?? 0;
		$course_post = get_post($course_id);

		// --- Get base dashboard URL ---
		$options            = get_option( 'qp_settings' );
		$dashboard_page_id  = isset( $options['dashboard_page'] ) ? absint( $options['dashboard_page'] ) : 0;
		$base_dashboard_url = $dashboard_page_id ? trailingslashit( get_permalink( $dashboard_page_id ) ) : trailingslashit( home_url() );
		$is_front_page      = ( $dashboard_page_id > 0 && get_option( 'show_on_front' ) == 'page' && get_option( 'page_on_front' ) == $dashboard_page_id );
		$tab_prefix         = $is_front_page ? 'tab/' : '';

		// --- Basic Course Validation ---
		if ( ! $course_post || $course_post->post_type !== 'qp_course' ) {
			echo '<div class="qp-card"><div class="qp-card-content"><p>Error: Course not found.</p></div></div>';
			echo '<a href="' . esc_url( $base_dashboard_url ) . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Dashboard</a>';
			return;
		}

		// --- Access Check ---
		if ( ! User_Access::can_access_course( $user_id, $course_id ) ) {
			echo '<div class="qp-card"><div class="qp-card-content"><p>You do not have permission to view this course.</p></div></div>';
			echo '<a href="' . esc_url( $base_dashboard_url ) . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Dashboard</a>';
			return;
		}

		// --- Fetch Structure Data (Similar to old AJAX handler) ---
		global $wpdb;
		$sections_table = $wpdb->prefix . 'qp_course_sections';
		$items_table    = $wpdb->prefix . 'qp_course_items';
		$progress_table = $wpdb->prefix . 'qp_user_items_progress';

		// Get sections
		$sections = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT section_id, title, description, section_order FROM $sections_table WHERE course_id = %d ORDER BY section_order ASC",
				$course_id
			)
		);

		$items_by_section      = [];
		$all_items_in_course = []; // Store all items flat for progress fetching

		if ( ! empty( $sections ) ) {
			$section_ids     = wp_list_pluck( $sections, 'section_id' );
			$ids_placeholder = implode( ',', array_map( 'absint', $section_ids ) );

			// Get all items for these sections
			$items_raw             = $wpdb->get_results(
				"SELECT item_id, section_id, title, item_order, content_type, content_config
			 FROM $items_table
			 WHERE section_id IN ($ids_placeholder)
			 ORDER BY item_order ASC"
			);
			$all_items_in_course = $items_raw; // Store for progress lookup

			// Organize items by section
			foreach ( $items_raw as $item ) {
				if ( ! isset( $items_by_section[ $item->section_id ] ) ) {
					$items_by_section[ $item->section_id ] = [];
				}
				$items_by_section[ $item->section_id ][] = $item;
			}
		}

		// Fetch user's progress for all items in this course in one query
		$item_ids_in_course = wp_list_pluck( $all_items_in_course, 'item_id' );
		$progress_data      = [];
		if ( ! empty( $item_ids_in_course ) ) {
			$item_ids_placeholder = implode( ',', array_map( 'absint', $item_ids_in_course ) );
			$progress_raw         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT item_id, status, result_data FROM $progress_table WHERE user_id = %d AND item_id IN ($item_ids_placeholder)",
					$user_id
				),
				OBJECT_K
			); // Keyed by item_id

			// Process progress data to extract session_id
			foreach ( $progress_raw as $item_id => $prog ) {
				$session_id = null;
				if ( ! empty( $prog->result_data ) ) {
					$result_data_decoded = json_decode( $prog->result_data, true );
					if ( isset( $result_data_decoded['session_id'] ) ) {
						$session_id = absint( $result_data_decoded['session_id'] );
					}
				}
				$progress_data[ $item_id ] = [
					'status'     => $prog->status,
					'session_id' => $session_id,
				];
			}
		}

		// --- NEW: Determine the correct "Back" URL ---
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$is_enrolled = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_course_id FROM $user_courses_table WHERE user_id = %d AND course_id = %d",
			$user_id, $course_id
		) );
		
		if ( $is_enrolled ) {
			$back_url = $base_dashboard_url . $tab_prefix . 'my-courses/';
		} else {
			$back_url = $base_dashboard_url . $tab_prefix . 'available-courses/';
		}
		// --- END NEW ---


		// --- Render Structure HTML ---
		echo '<div class="qp-course-structure-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">';
		echo '<h2>' . esc_html( get_the_title( $course_id ) ) . '</h2>';
		echo '<a href="' . esc_url( $back_url ) . '" class="qp-button qp-button-secondary qp-back-to-courses-btn">&laquo; Back to Courses</a>';
		echo '</div>'; // Close qp-course-structure-header

		echo '<div class="qp-course-structure-content">';

		if ( ! empty( $sections ) ) {
            
            // --- NEW: Get progression mode ---
            $progression_mode = get_post_meta($course_id, '_qp_course_progression_mode', true);
            $is_progressive = ($progression_mode === 'progressive') && !user_can($user_id, 'manage_options'); // Admins bypass
            $is_previous_item_complete = true; // First item is always unlocked
            // --- END NEW ---

			foreach ( $sections as $section ) {
				?>
				<div class="qp-course-section-card qp-card">
					<div class="qp-card-header">
						<h3><?php echo esc_html( $section->title ); ?></h3>
						<?php if ( ! empty( $section->description ) ) : ?>
							<p style="font-size: 0.9em; color: var(--qp-dashboard-text-light); margin-top: 5px;"><?php echo esc_html( $section->description ); ?></p>
						<?php endif; ?>
					</div>
					<div class="qp-card-content qp-course-items-list">
						<?php
						$items = $items_by_section[ $section->section_id ] ?? [];
						if ( ! empty( $items ) ) {
							foreach ( $items as $item ) {
								$item_progress   = $progress_data[ $item->item_id ] ?? [
									'status'     => 'not_started',
									'session_id' => null,
								];
								$status          = $item_progress['status'];
								$session_id_attr = $item_progress['session_id'] ? ' data-session-id="' . esc_attr( $item_progress['session_id'] ) . '"' : '';

                                // --- NEW: Progression Logic ---
                                $is_locked = false;
                                if ($is_progressive && !$is_previous_item_complete) {
                                    $is_locked = true;
                                }
                                // --- END NEW ---

								$status_icon  = '';
								$button_text  = 'Start';
								$button_class = 'qp-button-primary start-course-test-btn';
                                $button_disabled = $is_locked ? 'disabled' : '';
                                $row_class = $is_locked ? 'qp-item-locked' : '';

                                // --- NEW: Update icon and button based on lock status ---
                                if ($is_locked) {
                                    $status_icon  = '<span class="dashicons dashicons-lock" style="color: var(--qp-dashboard-text-light);"></span>';
                                    $button_text  = 'Locked';
                                    $button_class = 'qp-button-secondary';
                                } else {
                                    // Original logic for non-locked items
								    switch ( $status ) {
									    case 'completed':
										    $status_icon  = '<span class="dashicons dashicons-yes-alt" style="color: var(--qp-dashboard-success);"></span>';
										    $button_text  = 'Review';
										    $button_class = 'qp-button-secondary view-test-results-btn';
										    break;
									    case 'in_progress':
										    $status_icon  = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-warning-dark);"></span>';
										    $button_text  = 'Continue';
										    $button_class = 'qp-button-primary start-course-test-btn';
										    break;
									    default: // not_started
										    $status_icon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-border);"></span>';
										    $button_text = 'Start';
										    $button_class = 'qp-button-primary start-course-test-btn';
										    break;
								    }
                                }

								// Adjust button for non-test items
								if ( $item->content_type !== 'test_series' ) {
									$button_class    = 'qp-button-secondary view-course-content-btn'; // Generic class for other types
									$button_text     = $is_locked ? 'Locked' : 'View'; // Use 'View' or 'Locked'
									$session_id_attr = ''; // No session ID for non-tests
								}
                                // --- END NEW ---

								?>
								<div class="qp-course-item-row <?php echo esc_attr($row_class); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--qp-dashboard-border-light);">
									<span class="qp-course-item-link" style="display: flex; align-items: center; gap: 8px;">
										<?php echo $status_icon; // This now contains the lock icon ?>
										<span style="font-weight: 500;"><?php echo esc_html( $item->title ); ?></span>
									</span>
									<button class="qp-button <?php echo esc_attr( $button_class ); ?>" data-item-id="<?php echo esc_attr( $item->item_id ); ?>" <?php echo $session_id_attr; ?> style="padding: 4px 10px; font-size: 12px;" <?php echo $button_disabled; ?>>
                                        <?php echo esc_html( $button_text ); ?>
                                    </button>
								</div>
								<?php

                                // --- NEW: Update lock for next iteration ---
                                if ($is_progressive) {
                                    $is_previous_item_complete = ($status === 'completed');
                                }
                                // --- END NEW ---
							}
						} else {
							echo '<p style="text-align: center; color: var(--qp-dashboard-text-light); font-style: italic;">No items in this section.</p>';
						}
						?>
					</div>
				</div>
				<?php
			} // end foreach section
		} else {
			echo '<div class="qp-card"><div class="qp-card-content"><p style="text-align: center;">This course has no content yet.</p></div></div>';
		}

		echo '</div>'; // Close qp-course-structure-content
	}

	/**
	 * Renders the content specifically for the Overview section by loading a template.
	 * NOW RETURNS the HTML string.
	 *
	 * @param object $stats                Stats object from DB.
	 * @param float  $overall_accuracy     Calculated accuracy.
	 * @param array  $active_sessions      Array of active/paused sessions.
	 * @param array  $recent_history       Array of recent completed sessions.
	 * @param int    $review_count         Count of review items.
	 * @param int    $never_correct_count  Count of never-correct items.
	 * @param string $practice_page_url    Practice page URL.
	 * @param string $session_page_url     Session page URL.
	 * @param string $review_page_url      Review page URL.
	 * @return string Rendered HTML content.
	 */
	public static function render_overview_content( $stats, $overall_accuracy, $active_sessions, $recent_history, $review_count, $never_correct_count, $practice_page_url, $session_page_url, $review_page_url ) {
		// --- Prefetch lineage data needed for the recent history table ---
		list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data( $recent_history );

		// Prepare arguments array for the template
		$args = [
			'stats'                 => $stats,
			'overall_accuracy'      => $overall_accuracy,
			'active_sessions'       => $active_sessions,
			'recent_history'        => $recent_history,
			'review_count'          => $review_count,
			'never_correct_count'   => $never_correct_count,
			'practice_page_url'     => $practice_page_url,
			'session_page_url'      => $session_page_url,
			'review_page_url'       => $review_page_url,
			'lineage_cache'         => $lineage_cache, // Pass prefetched data
			'group_to_topic_map'    => $group_to_topic_map,
			'question_to_group_map' => $question_to_group_map,
		];

		// Load and return the template HTML - Check path 'dashboard/overview'
		$html = Template_Loader::get_html( 'dashboard/overview', 'frontend', $args );

		if ( empty( trim( $html ) ) || strpos( $html, 'Template not found' ) !== false ) {
			// error_log("Error loading dashboard/overview template or it's empty. Path checked: " . QP_TEMPLATES_DIR . 'frontend/dashboard/overview.php');
			return '<p style="color:red;">Error: Overview template could not be loaded.</p>'; // Return error message
		}

		// Load and return the template HTML
		return $html;
	}

	/**
	 * Renders the content specifically for the History section by loading a template.
	 * NOW PUBLIC STATIC and RETURNS HTML.
	 */
	public static function render_history_content() {
		global $wpdb;
		$user_id        = get_current_user_id();
		$sessions_table = $wpdb->prefix . 'qp_user_sessions';
		$attempts_table = $wpdb->prefix . 'qp_user_attempts';
		$items_table    = $wpdb->prefix . 'qp_course_items'; // <-- Add items table

		$options           = get_option( 'qp_settings' );
		$session_page_url  = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
		$review_page_url   = isset( $options['review_page'] ) ? get_permalink( $options['review_page'] ) : home_url( '/' );
		$practice_page_url = isset( $options['practice_page'] ) ? get_permalink( $options['practice_page'] ) : home_url( '/' );

		$user          = wp_get_current_user();
		$user_roles    = (array) $user->roles;
		$allowed_roles = isset( $options['can_delete_history_roles'] ) ? $options['can_delete_history_roles'] : [ 'administrator' ];
		$can_delete    = ! empty( array_intersect( $user_roles, $allowed_roles ) );

		// Fetch Paused Sessions
		$paused_sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $sessions_table WHERE user_id = %d AND status = 'paused' ORDER BY start_time DESC",
				$user_id
			)
		);

		// Fetch Completed/Abandoned Sessions
		$session_history = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $sessions_table WHERE user_id = %d AND status IN ('completed', 'abandoned') ORDER BY start_time DESC",
				$user_id
			)
		);

		$all_sessions_for_history = array_merge( $paused_sessions, $session_history );

		// Pre-fetch lineage data
		list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data( $all_sessions_for_history );

		// Fetch accuracy stats
		$session_ids_history = wp_list_pluck( $all_sessions_for_history, 'session_id' );
		$accuracy_stats      = [];
		if ( ! empty( $session_ids_history ) ) {
			$ids_placeholder = implode( ',', array_map( 'absint', $session_ids_history ) );
			$results         = $wpdb->get_results(
				"SELECT session_id,
			 COUNT(CASE WHEN is_correct = 1 THEN 1 END) as correct,
			 COUNT(CASE WHEN is_correct = 0 THEN 1 END) as incorrect
			 FROM {$attempts_table}
			 WHERE session_id IN ({$ids_placeholder}) AND status = 'answered'
			 GROUP BY session_id"
			);
			foreach ( $results as $result ) {
				$total_attempted                = (int) $result->correct + (int) $result->incorrect;
				$accuracy                       = ( $total_attempted > 0 ) ? ( ( (int) $result->correct / $total_attempted ) * 100 ) : 0;
				$accuracy_stats[ $result->session_id ] = number_format( $accuracy, 2 ) . '%';
			}
		}

		// --- NEW: Pre-fetch existing course item IDs ---
		$existing_course_item_ids = $wpdb->get_col( "SELECT item_id FROM $items_table" );
		$existing_course_item_ids = array_flip( $existing_course_item_ids ); // Convert to hash map
		// --- END NEW ---

		// Prepare arguments for the template
		$args = [
			'practice_page_url'        => $practice_page_url,
			'can_delete'               => $can_delete,
			'all_sessions'             => $all_sessions_for_history,
			'session_page_url'         => $session_page_url,
			'review_page_url'          => $review_page_url,
			'lineage_cache'            => $lineage_cache,
			'group_to_topic_map'       => $group_to_topic_map,
			'question_to_group_map'    => $question_to_group_map,
			'accuracy_stats'           => $accuracy_stats,
			'existing_course_item_ids' => $existing_course_item_ids, // <-- Pass the item IDs
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/history', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the Review section by loading a template.
	 * NOW PUBLIC STATIC and RETURNS HTML.
	 */
	public static function render_review_content() {
		global $wpdb;
		$user_id         = get_current_user_id();
		$attempts_table  = $wpdb->prefix . 'qp_user_attempts';
		$review_table    = $wpdb->prefix . 'qp_review_later';
		$questions_table = $wpdb->prefix . 'qp_questions';
		$groups_table    = $wpdb->prefix . 'qp_question_groups';
		$rel_table       = $wpdb->prefix . 'qp_term_relationships';
		$term_table      = $wpdb->prefix . 'qp_terms';
		$tax_table       = $wpdb->prefix . 'qp_taxonomies';

		// Fetch Review Later questions (JOIN to get subject name efficiently)
		$subject_tax_id   = $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy_id FROM {$tax_table} WHERE taxonomy_name = %s", 'subject' ) );
		$review_questions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
			 q.question_id, q.question_text,
			 COALESCE(parent_term.name, 'Uncategorized') as subject_name -- Use COALESCE for fallback
			 FROM {$review_table} rl
			 JOIN {$questions_table} q ON rl.question_id = q.question_id
			 LEFT JOIN {$groups_table} g ON q.group_id = g.group_id
			 LEFT JOIN {$rel_table} topic_rel ON g.group_id = topic_rel.object_id AND topic_rel.object_type = 'group'
			 LEFT JOIN {$term_table} topic_term ON topic_rel.term_id = topic_term.term_id AND topic_term.taxonomy_id = %d AND topic_term.parent != 0
			 LEFT JOIN {$term_table} parent_term ON topic_term.parent = parent_term.term_id
			 WHERE rl.user_id = %d
			 ORDER BY rl.review_id DESC",
				$subject_tax_id,
				$user_id
			)
		);

		// Calculate counts for "Practice Your Mistakes"
		$total_incorrect_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT question_id) FROM {$attempts_table} WHERE user_id = %d AND is_correct = 0",
				$user_id
			)
		);
		$correctly_answered_qids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND is_correct = 1", $user_id ) );
		$all_answered_qids       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT question_id FROM {$attempts_table} WHERE user_id = %d AND status = 'answered'", $user_id ) );
		$never_correct_qids      = array_diff( $all_answered_qids, $correctly_answered_qids );
		$never_correct_count     = count( $never_correct_qids );

		// Prepare arguments for the template
		$args = [
			'review_questions'      => $review_questions,
			'never_correct_count'   => $never_correct_count,
			'total_incorrect_count' => $total_incorrect_count,
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/review', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the Progress section by loading a template.
	 * NOW PUBLIC STATIC and RETURNS HTML.
	 */
	public static function render_progress_content() {
		global $wpdb;
		$term_table     = $wpdb->prefix . 'qp_terms';
		$tax_table      = $wpdb->prefix . 'qp_taxonomies';
		$subject_tax_id = $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = %s", 'subject' ) );
		$subjects       = [];
		if ( $subject_tax_id ) {
			// --- NEW: Filter subjects based on user scope ---
			$user_id                   = get_current_user_id();
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
			// If $allowed_subjects_or_all is an empty array, $subjects remains empty, which is correct.
			// --- END NEW SCOPE FILTER ---
		}

		// Prepare arguments for the template
		$args = [
			'subjects' => $subjects,
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/progress', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the "Available Courses" section.
	 * MODIFIED from old render_courses_content().
	 * @return string Rendered HTML content.
	 */
	public static function render_available_courses_content() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		global $wpdb;
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';

		// 1. Get enrolled course IDs (courses to EXCLUDE)
		$enrolled_course_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT course_id FROM $user_courses_table WHERE user_id = %d",
				$user_id
			)
		);
		if ( ! is_array( $enrolled_course_ids ) ) {
			$enrolled_course_ids = []; // Ensure it's an array
		}

		// --- NEW: Find courses the user has purchased but not enrolled in (also to EXCLUDE) ---
		$purchased_course_ids = [];
		$all_published_courses = new \WP_Query( [
			'post_type'      => 'qp_course',
			'post_status'    => 'publish', // Only check 'publish' status for purchasable
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post__not_in'   => $enrolled_course_ids, // Only check non-enrolled courses
		] );

		if ( $all_published_courses->have_posts() ) {
			foreach ( $all_published_courses->posts as $course_id ) {
				$access_result = User_Access::can_access_course( $user_id, $course_id, true );
				// If access is granted by an entitlement (numeric ID), it's a "purchased" course
				if ( is_numeric( $access_result ) ) {
					$purchased_course_ids[] = $course_id;
				}
			}
		}
		// --- END NEW ---

		// 3. Combine all IDs to exclude
		$all_excluded_ids = array_unique( array_merge( $enrolled_course_ids, $purchased_course_ids ) );

		// 4. Get all published/expired courses, EXCLUDING the ones found above
		$args = [
			'post_type'      => 'qp_course',
			'post_status'    => ['publish', 'expired'], // Include expired to show them as "Expired"
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		];

		if ( ! empty( $all_excluded_ids ) ) {
			$args['post__not_in'] = $all_excluded_ids; // <-- Key change: EXCLUDE enrolled AND purchased courses
		}
		
		$available_courses_query = new \WP_Query( $args );
		// --- END MODIFIED QUERY ---

		// 5. Prepare arguments for the template
		$template_args = [
			'available_courses_query' => $available_courses_query,
			'user_id'                 => $user_id,
			// No progress data needed for available courses
		];

		// Load and return the "courses" template (which is now our "available" template)
		return Template_Loader::get_html( 'dashboard/courses', 'frontend', $template_args );
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
		$profile_data = self::get_profile_data( $user_id ); // Use the existing helper

		// Prepare arguments for the template
		$args = [
			'profile_data' => $profile_data,
		];

		// Load and return the template HTML
		return Template_Loader::get_html( 'dashboard/profile', 'frontend', $args );
	}

	/**
	 * Renders the content specifically for the "My Courses" section.
	 * NEW FUNCTION.
	 * @return string Rendered HTML content.
	 */
	public static function render_my_courses_content() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		global $wpdb;
		$user_courses_table = $wpdb->prefix . 'qp_user_courses';
		$items_table = $wpdb->prefix . 'qp_course_items';
		$progress_table = $wpdb->prefix . 'qp_user_items_progress';

		// Get *only* enrolled course IDs
		$enrolled_course_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT course_id FROM $user_courses_table WHERE user_id = %d AND status IN ('enrolled', 'in_progress', 'completed')",
				$user_id
			)
		);

		// --- 1. Query for Enrolled Courses ---
		$enrolled_courses_query = new \WP_Query(); // Empty query by default
		$enrolled_courses_data = [];

		if ( ! empty( $enrolled_course_ids ) ) {
			// Query for the enrolled courses
			$args = [
				'post_type'      => 'qp_course',
				'post_status'    => ['publish', 'expired'], // Show expired courses if enrolled
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'post__in'       => $enrolled_course_ids, // <-- Key change: only query these IDs
			];
			$enrolled_courses_query = new \WP_Query( $args );

			// Prepare progress data
			$ids_placeholder = implode( ',', array_map( 'absint', $enrolled_course_ids ) );
			$total_items_results = $wpdb->get_results(
				"SELECT course_id, COUNT(item_id) as total_items FROM $items_table WHERE course_id IN ($ids_placeholder) GROUP BY course_id",
				OBJECT_K
			);
			$completed_items_results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT course_id, COUNT(user_item_id) as completed_items FROM $progress_table WHERE user_id = %d AND course_id IN ($ids_placeholder) AND status = 'completed' GROUP BY course_id",
					$user_id
				),
				OBJECT_K
			);

			foreach ( $enrolled_course_ids as $course_id ) {
				$total_items = $total_items_results[ $course_id ]->total_items ?? 0;
				$completed_items = $completed_items_results[ $course_id ]->completed_items ?? 0;
				$progress_percent = ( $total_items > 0 ) ? round( ( $completed_items / $total_items ) * 100 ) : 0;
				$enrolled_courses_data[ $course_id ] = [
					'progress'    => $progress_percent,
					'is_complete' => ( $total_items > 0 && $completed_items >= $total_items ),
				];
			}
		}

		// --- 2. Query for Purchased but Not Enrolled Courses ---
		$purchased_not_enrolled_posts = [];
		
		// Get all published courses
		$all_published_courses = new \WP_Query( [
			'post_type'      => 'qp_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids', // Only get IDs
		] );

		if ( $all_published_courses->have_posts() ) {
			foreach ( $all_published_courses->posts as $course_id ) {
				// If NOT enrolled
				if ( ! in_array( $course_id, $enrolled_course_ids ) ) {
					// Check if user has a *paid* entitlement
					$access_result = User_Access::can_access_course( $user_id, $course_id, true );
					
					// is_numeric() checks if access was granted by a specific entitlement ID
					if ( is_numeric( $access_result ) ) {
						$purchased_not_enrolled_posts[] = get_post( $course_id );
					}
				}
			}
		}

		// --- 3. Prepare arguments for the template ---
		$template_args = [
			'enrolled_courses_query'       => $enrolled_courses_query,
			'enrolled_courses_data'        => $enrolled_courses_data,
			'purchased_not_enrolled_posts' => $purchased_not_enrolled_posts,
			'user_id'                      => $user_id,
		];

		// Load and return the "my-courses" template
		return Template_Loader::get_html( 'dashboard/my-courses', 'frontend', $template_args );
	}

	/**
	 * Gathers profile data for the dashboard profile tab.
	 * (Keep this helper method as is - no changes needed here)
	 *
	 * @param int $user_id The ID of the user.
	 * @return array An array containing profile details.
	 */
	private static function get_profile_data( $user_id ) {
		$user_info = get_userdata( $user_id );
		if ( ! $user_info ) {
			return [ // Return default empty values if user not found
				'display_name'          => 'User Not Found',
				'email'                 => '',
				'avatar_url'            => get_avatar_url( 0 ), // Default avatar
				'scope_description'     => 'N/A',
				'allowed_subjects_list' => [],
				'allowed_exams_list'    => [],
			];
		}
		$custom_avatar_id = get_user_meta( $user_id, '_qp_avatar_attachment_id', true );
		$avatar_url       = '';
		if ( ! empty( $custom_avatar_id ) ) {
			$avatar_url = wp_get_attachment_image_url( absint( $custom_avatar_id ), 'thumbnail' );
		}
		if ( empty( $avatar_url ) ) {
			$avatar_url = get_avatar_url( $user_id, [ 'size' => 128, 'default' => 'mystery' ] );
		}
		$scope_description       = 'All Subjects & Exams';
		$allowed_subjects_list   = [];
		$allowed_exams_list      = [];
		$allowed_subject_ids_or_all = User_Access::get_allowed_subject_ids( $user_id );
		if ( $allowed_subject_ids_or_all !== 'all' ) {
			global $wpdb;
			$term_table            = $wpdb->prefix . 'qp_terms';
			$allowed_subject_ids   = $allowed_subject_ids_or_all;
			if ( ! empty( $allowed_subject_ids ) ) {
				$subj_ids_placeholder  = implode( ',', array_map( 'absint', $allowed_subject_ids ) );
				$allowed_subjects_list = $wpdb->get_col( "SELECT name FROM {$term_table} WHERE term_id IN ($subj_ids_placeholder) AND parent = 0 ORDER BY name ASC" );
			}
			$direct_exams_json = get_user_meta( $user_id, '_qp_allowed_exam_term_ids', true );
			$direct_exam_ids   = json_decode( $direct_exams_json, true );
			if ( ! is_array( $direct_exam_ids ) ) {
				$direct_exam_ids = [];
			}
			$final_allowed_exam_ids = array_map( 'absint', $direct_exam_ids );
			if ( ! empty( $final_allowed_exam_ids ) ) {
				$exam_ids_placeholder = implode( ',', $final_allowed_exam_ids );
				$allowed_exams_list   = $wpdb->get_col( "SELECT name FROM {$term_table} WHERE term_id IN ($exam_ids_placeholder) ORDER BY name ASC" );
			}
			if ( empty( $allowed_subjects_list ) && empty( $allowed_exams_list ) ) {
				$scope_description = 'No specific scope assigned.';
			} else {
				$scope_parts = [];
				if ( ! empty( $allowed_exams_list ) ) {
					$scope_parts[] = 'Allowed Exams: ' . implode( ', ', array_map( 'esc_html', $allowed_exams_list ) );
				}
				if ( ! empty( $allowed_subjects_list ) ) {
					$scope_parts[] = 'Accessible Subjects: ' . implode( ', ', array_map( 'esc_html', $allowed_subjects_list ) );
				}
				$scope_description = implode( '; ', $scope_parts );
			}
		}
		return [
			'display_name'          => $user_info->display_name,
			'email'                 => $user_info->user_email,
			'avatar_url'            => $avatar_url,
			'scope_description'     => $scope_description,
			'allowed_subjects_list' => $allowed_subjects_list,
			'allowed_exams_list'    => $allowed_exams_list,
		];
	}

	/**
	 * NEW HELPER: Prefetches lineage data needed for session lists.
	 */
	private static function prefetch_lineage_data( $sessions ) {
		global $wpdb;
		$all_session_qids = [];
		foreach ( $sessions as $session ) {
			$qids = json_decode( $session->question_ids_snapshot, true );
			if ( is_array( $qids ) ) {
				$all_session_qids = array_merge( $all_session_qids, $qids );
			}
		}

		$lineage_cache         = [];
		$group_to_topic_map    = [];
		$question_to_group_map = [];

		if ( ! empty( $all_session_qids ) ) {
			$unique_qids = array_unique( array_map( 'absint', $all_session_qids ) );
			if ( empty( $unique_qids ) ) {
				return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ]; // Avoid empty IN clause
			}

			$qids_placeholder = implode( ',', $unique_qids );

			$tax_table       = $wpdb->prefix . 'qp_taxonomies';
			$term_table      = $wpdb->prefix . 'qp_terms';
			$rel_table       = $wpdb->prefix . 'qp_term_relationships';
			$questions_table = $wpdb->prefix . 'qp_questions';
			$subject_tax_id  = $wpdb->get_var( "SELECT taxonomy_id FROM $tax_table WHERE taxonomy_name = 'subject'" );

			$q_to_g_results = $wpdb->get_results( "SELECT question_id, group_id FROM {$questions_table} WHERE question_id IN ($qids_placeholder)" );
			foreach ( $q_to_g_results as $res ) {
				$question_to_group_map[ $res->question_id ] = $res->group_id;
			}

			$all_group_ids = array_unique( array_values( $question_to_group_map ) );
			if ( empty( $all_group_ids ) ) {
				return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ]; // Avoid empty IN clause
			}

			$group_ids_placeholder = implode( ',', $all_group_ids );

			$g_to_t_results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.object_id, r.term_id
				 FROM {$rel_table} r JOIN {$term_table} t ON r.term_id = t.term_id
				 WHERE r.object_type = 'group' AND r.object_id IN ($group_ids_placeholder) AND t.taxonomy_id = %d",
					$subject_tax_id
				)
			);
			foreach ( $g_to_t_results as $res ) {
				$group_to_topic_map[ $res->object_id ] = $res->term_id;
			}

			// Pre-populate lineage cache for all topics found
			$all_topic_ids = array_unique( array_values( $group_to_topic_map ) );
			if ( ! empty( $all_topic_ids ) ) {
				foreach ( $all_topic_ids as $topic_id ) {
					if ( ! isset( $lineage_cache[ $topic_id ] ) ) {
						$current_term_id   = $topic_id;
						$root_subject_name = 'N/A';
						for ( $i = 0; $i < 10; $i++ ) {
							$term = $wpdb->get_row( $wpdb->prepare( "SELECT name, parent FROM $term_table WHERE term_id = %d", $current_term_id ) );
							if ( ! $term || $term->parent == 0 ) {
								$root_subject_name = $term ? $term->name : 'N/A';
								break;
							}
							$current_term_id = $term->parent;
						}
						$lineage_cache[ $topic_id ] = $root_subject_name;
					}
				}
			}
		}
		return [ $lineage_cache, $group_to_topic_map, $question_to_group_map ];
	}

	/**
	 * NEW HELPER: Determines the display name for a session's mode.
	 */
	public static function get_session_mode_name( $session, $settings ) {
		$mode = 'Practice'; // Default
		if ( $session->status === 'paused' ) {
			$mode = 'Paused Session';
		} elseif ( isset( $settings['practice_mode'] ) ) {
			switch ( $settings['practice_mode'] ) {
				case 'revision':
					$mode = 'Revision';
					break;
				case 'mock_test':
					$mode = 'Mock Test';
					break;
				case 'Incorrect Que. Practice':
					$mode = 'Incorrect Practice';
					break;
				case 'Section Wise Practice':
					$mode = 'Section Practice';
					break;
			}
		} elseif ( isset( $settings['subject_id'] ) && $settings['subject_id'] === 'review' ) {
			$mode = 'Review Session';
		}
		return $mode;
	}

	/**
	 * NEW HELPER: Gets the subject display string for a session.
	 */
	public static function get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map ) {
		global $wpdb;
		$term_table = $wpdb->prefix . 'qp_terms';

		$session_qids     = json_decode( $session->question_ids_snapshot, true );
		$subjects_display = 'N/A';

		if ( is_array( $session_qids ) && ! empty( $session_qids ) ) {
			$mode = self::get_session_mode_name( $session, $settings ); // Use the mode helper

			if ( $mode === 'Section Practice' ) {
				// Get source hierarchy for the first question
				$first_question_id = $session_qids[0];
				$source_hierarchy  = Terms_DB::get_source_hierarchy_for_question( $first_question_id ); // Assumes this function exists globally
				$subjects_display  = ! empty( $source_hierarchy ) ? implode( ' / ', $source_hierarchy ) : 'N/A';
			} else {
				$session_subjects = [];
				foreach ( $session_qids as $qid ) {
					$gid      = $question_to_group_map[ $qid ] ?? null;
					$topic_id = $gid ? ( $group_to_topic_map[ $gid ] ?? null ) : null;
					if ( $topic_id && isset( $lineage_cache[ $topic_id ] ) ) {
						$session_subjects[] = $lineage_cache[ $topic_id ];
					}
				}
				$subjects_display = ! empty( $session_subjects ) ? implode( ', ', array_unique( array_filter( $session_subjects, fn( $s ) => $s !== 'N/A' ) ) ) : 'N/A';
				if ( empty( $subjects_display ) ) {
					$subjects_display = 'N/A';
				}
			}
		}
		return $subjects_display;
	}

	/**
	 * NEW HELPER: Gets the result display string for a session.
	 */
	public static function get_session_result_display( $session, $settings ) {
		if ( $session->status === 'paused' ) {
			return '-'; // No result for paused
		}

		$is_scored = isset( $settings['marks_correct'] );
		if ( $is_scored ) {
			return number_format( (float) $session->marks_obtained, 1 ) . ' Score';
		} else {
			$total_attempted = (int) $session->correct_count + (int) $session->incorrect_count;
			// Calculate accuracy
			$accuracy = ( $total_attempted > 0 ) ? ( ( (int) $session->correct_count / $total_attempted ) * 100 ) : 0;
			// Format to two decimal places and add '%'
			return number_format( $accuracy, 2 ) . '%';
		}
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
		list($lineage_cache, $group_to_topic_map, $question_to_group_map) = self::prefetch_lineage_data( $session_history );

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
				$mode             = self::get_session_mode_name( $session, $settings );
				$subjects_display = self::get_session_subjects_display( $session, $settings, $lineage_cache, $group_to_topic_map, $question_to_group_map );
				$result_display   = self::get_session_result_display( $session, $settings );
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