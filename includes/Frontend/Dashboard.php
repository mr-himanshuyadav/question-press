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
		$session_page_url   = isset( $options['session_page'] ) ? get_permalink( $options['session_page'] ) : home_url( '/' );
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
                    // Fetch attempt_count as well
					"SELECT item_id, status, result_data, attempt_count FROM $progress_table WHERE user_id = %d AND item_id IN ($item_ids_placeholder)",
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
                    'attempt_count' => $prog->attempt_count
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
            $is_progressive = ($progression_mode === 'progressive'); // Admins bypass
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
                                    'attempt_count' => 0,
								];
								$status          = $item_progress['status'];
								$session_id_attr = $item_progress['session_id'] ? ' data-session-id="' . esc_attr( $item_progress['session_id'] ) . '"' : '';
                                $attempt_count   = (int) $item_progress['attempt_count'];

                                // --- Progression Logic ---
                                $is_locked = false;
                                if ($is_progressive && !$is_previous_item_complete) {
                                    $is_locked = true;
                                }
                                $row_class = $is_locked ? 'qp-item-locked' : '';
                                
                                // --- Button/Icon Logic ---
                                $status_icon  = '';
                                $button_html  = ''; // We will build this string

                                if ($is_locked) {
                                    $status_icon  = '<span class="dashicons dashicons-lock" style="color: var(--qp-dashboard-text-light);"></span>';
                                    $button_html = sprintf(
                                        '<button class="qp-button qp-button-secondary" style="padding: 4px 10px; font-size: 12px;" disabled>%s</button>',
                                        esc_html__('Locked', 'question-press')
                                    );
                                } elseif ( $item->content_type !== 'test_series' ) {
                                    // Handle non-test items (e.g., lessons)
                                    $status_icon  = '<span class="dashicons dashicons-text"></span>'; // Or other icon
                                    $button_html = sprintf(
                                        '<button class="qp-button qp-button-secondary view-course-content-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                        esc_attr($item->item_id),
                                        esc_html__('View', 'question-press')
                                    );
                                } else {
                                    // This is a test series item, handle its states
                                    switch ( $status ) {
                                        case 'completed':
                                            $status_icon  = '<span class="dashicons dashicons-yes-alt" style="color: var(--qp-dashboard-success);"></span>';
                                            
                                            // 1. Always show the Review button
                                            $button_html = sprintf(
                                                '<button class="qp-button qp-button-secondary view-test-results-btn" data-item-id="%d" %s style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                                esc_attr($item->item_id),
                                                $session_id_attr,
                                                esc_html__('Review', 'question-press')
                                            );

                                            // 2. Conditionally show the Retake button
                                            $allow_retakes = get_post_meta($course_id, '_qp_course_allow_retakes', true);
                                            if ($allow_retakes === '1') {
                                                $retake_limit = absint(get_post_meta($course_id, '_qp_course_retake_limit', true)); // 0 = unlimited
                                                $can_retake = false;
                                                $retake_text = esc_html__('Retake', 'question-press');

                                                if ($retake_limit === 0) {
                                                    $can_retake = true;
                                                } else {
                                                    $retakes_left = $retake_limit - $attempt_count;
                                                    if ($retakes_left > 0) {
                                                        $can_retake = true;
                                                        $retake_text = sprintf(esc_html__('Retake (%d left)', 'question-press'), $retakes_left);
                                                    } else {
                                                        $retake_text = esc_html__('No Retakes Left', 'question-press');
                                                    }
                                                }
                                                
                                                $button_html .= sprintf(
                                                    '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;" %s>%s</button>',
                                                    esc_attr($item->item_id),
                                                    $can_retake ? '' : 'disabled',
                                                    $retake_text
                                                );
                                            }
                                            break;
                                        case 'in_progress':
										    $status_icon  = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-warning-dark);"></span>';
                                            
                                            // Check if we have a valid session ID to resume
                                            if ( ! empty( $item_progress['session_id'] ) ) {
                                                // Create a direct link to the session page
                                                $resume_url = add_query_arg('session_id', $item_progress['session_id'], $session_page_url);
                                                $button_html = sprintf(
                                                    '<a href="%s" class="qp-button qp-button-primary" style="padding: 4px 10px; font-size: 12px; text-decoration: none;">%s</a>',
                                                    esc_url($resume_url),
                                                    esc_html__('Continue', 'question-press')
                                                );
                                            } else {
                                                // Fallback: If session ID is missing (should not happen), use the old button
                                                $button_html = sprintf(
                                                    '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                                    esc_attr($item->item_id),
                                                    esc_html__('Continue', 'question-press')
                                                );
                                            }
										    break;
                                        default: // not_started
                                            $status_icon = '<span class="dashicons dashicons-marker" style="color: var(--qp-dashboard-border);"></span>';
                                            $button_html = sprintf(
                                                '<button class="qp-button qp-button-primary start-course-test-btn" data-item-id="%d" style="padding: 4px 10px; font-size: 12px;">%s</button>',
                                                esc_attr($item->item_id),
                                                esc_html__('Start', 'question-press')
                                            );
                                            break;
                                    }
                                }
								?>
								<div class="qp-course-item-row <?php echo esc_attr($row_class); ?>" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--qp-dashboard-border-light);">
									<span class="qp-course-item-link" style="display: flex; align-items: center; gap: 8px;">
										<?php echo $status_icon; ?>
										<span style="font-weight: 500;"><?php echo esc_html( $item->title ); ?></span>
									</span>
                                    <div class="qp-card-actions"> <?php // Wrap buttons in a container for proper spacing ?>
                                        <?php echo $button_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
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
		$can_terminate = isset($options['allow_session_termination']) && $options['allow_session_termination'] === '1';

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

		// Pre-fetch lineage data for BOTH lists
		list($lineage_cache_paused, $group_to_topic_map_paused, $question_to_group_map_paused) = Dashboard_Manager::prefetch_lineage_data( $paused_sessions );
		list($lineage_cache_completed, $group_to_topic_map_completed, $question_to_group_map_completed) = Dashboard_Manager::prefetch_lineage_data( $session_history );

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
			'practice_page_url'     => $practice_page_url,
			'can_delete'            => $can_delete,
			'can_terminate'         => $can_terminate,
			'session_page_url'      => $session_page_url,
			'review_page_url'       => $review_page_url,
			'existing_course_item_ids' => $existing_course_item_ids,
			'accuracy_stats'        => $accuracy_stats,

			// Pass Paused Sessions and their data
			'paused_sessions'       => $paused_sessions,
			'lineage_cache_paused'  => $lineage_cache_paused,
			'group_to_topic_map_paused' => $group_to_topic_map_paused,
			'question_to_group_map_paused' => $question_to_group_map_paused,

			// Pass Completed Sessions and their data
			'completed_sessions'    => $session_history,
			'lineage_cache_completed' => $lineage_cache_completed,
			'group_to_topic_map_completed' => $group_to_topic_map_completed,
			'question_to_group_map_completed' => $question_to_group_map_completed,
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
		$options = get_option('qp_settings');
        $allow_global_opt_out = (bool) ($options['allow_course_opt_out'] ?? 0);
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
			'allow_global_opt_out'         => $allow_global_opt_out,
			'enrolled_courses_query'       => $enrolled_courses_query,
			'enrolled_courses_data'        => $enrolled_courses_data,
			'purchased_not_enrolled_posts' => $purchased_not_enrolled_posts,
			'user_id'                      => $user_id,
		];

		// Load and return the "my-courses" template
		return Template_Loader::get_html( 'dashboard/my-courses', 'frontend', $template_args );
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