<?php
namespace QuestionPress\Admin\Backup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles all backup and restore logic for Question Press.
 */
class Backup_Manager {

    /**
     * @var \wpdb
     */
    private static $wpdb;

    /**
     * Get the backup directory path.
     *
     * @return string
     */
    private static function get_backup_dir_path() {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'qp-backups';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
        }
        return $backup_dir;
    }

    /**
     * Get a list of all tables with the qp_ prefix.
     *
     * @return array
     */
    private static function get_all_qp_tables() {
        global $wpdb;
        $tables = $wpdb->get_col( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->prefix . 'qp_%'
        ) );
        return $tables;
    }

    /**
     * Returns a list of QP tables in an order that respects foreign key dependencies.
     * Tables are listed in order of creation (Parents first, Children last).
     *
     * @return array
     */
    private static function get_tables_in_dependency_order() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        
        return [
            // Level 1: Core Definitions (No FK dependencies)
            $prefix . 'qp_taxonomies',
            
            // Level 2: Terms (Depends on Taxonomies)
            $prefix . 'qp_terms',
            
            // Level 3: Term Meta (Depends on Terms)
            $prefix . 'qp_term_meta',
            
            // Level 4: Groups (Can depend on Terms via denormalized columns)
            $prefix . 'qp_question_groups',
            
            // Level 5: Questions (Depends on Groups, Terms)
            $prefix . 'qp_questions',
            
            // Level 6: Options & Relationships (Depends on Questions, Groups, Terms)
            $prefix . 'qp_options',
            $prefix . 'qp_term_relationships',

            // Level 7: Course Structure (Depends on CPTs)
            $prefix . 'qp_course_sections',
            $prefix . 'qp_course_items',

            // Level 8: User Data & Entitlements (Depends on Users, CPTs)
            $prefix . 'qp_otp_verification',
            $prefix . 'qp_user_entitlements',
            $prefix . 'qp_user_courses',      
            $prefix . 'qp_user_items_progress', 

            // Level 9: Sessions (Depends on Users)
            $prefix . 'qp_user_sessions',
            
            // Level 10: Attempts & Pauses (Depends on Sessions, Questions, Options)
            $prefix . 'qp_user_attempts',
            $prefix . 'qp_session_pauses',
            $prefix . 'qp_revision_attempts',
            
            // Level 11: Other
            $prefix . 'qp_review_later',
            $prefix . 'qp_question_reports',
            $prefix . 'qp_logs',
        ];
    }

    /**
     * Performs a full backup of all QP tables, CPTs, relevant user data, and media.
     *
     * @param string $type The type of backup ('manual' or 'auto').
     * @return array Result of the backup operation.
     */
    public static function perform_backup( $type = 'manual' ) {
        global $wpdb;
        self::$wpdb = $wpdb;
        $backup_data = [
            'version'   => 1.3, // Version bump for full meta support
            'timestamp' => current_time( 'timestamp' ),
            'users'     => [], // User manifest
            'media'     => [], // Media manifest [old_id => filename]
            'cpts'      => [ 
                'posts' => [],
                'postmeta' => [],
            ],
            'tables'    => [], // All qp_ table data
        ];

        $all_qp_tables = self::get_all_qp_tables();
        $all_user_ids = [];
        $all_media_ids = [];

        // 1. Export data from all QP tables
        foreach ( $all_qp_tables as $table ) {
            $table_data = self::$wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
            $backup_data['tables'][ $table ] = $table_data;

            // Collect user IDs and media IDs referenced in tables
            foreach ( $table_data as $row ) {
                if ( isset( $row['user_id'] ) && ! empty( $row['user_id'] ) ) {
                    $all_user_ids[] = (int) $row['user_id'];
                }
                if ( $table === $wpdb->prefix . 'qp_question_groups' && isset( $row['direction_image_id'] ) && ! empty( $row['direction_image_id'] ) ) {
                    $all_media_ids[] = (int) $row['direction_image_id'];
                }
            }
        }

        // 2. Export CPTs (qp_course, qp_plan)
        $post_types_to_backup = ['qp_course', 'qp_plan'];
        $cpt_posts = self::$wpdb->get_results(
            "SELECT * FROM {$wpdb->posts} WHERE post_type IN ('" . implode("','", $post_types_to_backup) . "') AND post_status != 'auto-draft'",
            ARRAY_A
        );
        
        $backup_data['cpts']['posts'] = $cpt_posts;
        $cpt_post_ids = [];
        foreach ($cpt_posts as $post) {
            $cpt_post_ids[] = (int) $post['ID'];
            if ($post['post_author'] > 0) {
                $all_user_ids[] = (int) $post['post_author'];
            }
        }

        // 3. Export Post Meta for those CPTs
        if ( ! empty( $cpt_post_ids ) ) {
            $cpt_postmeta = self::$wpdb->get_results(
                "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $cpt_post_ids) . ")",
                ARRAY_A
            );
            $backup_data['cpts']['postmeta'] = $cpt_postmeta;
            
            // Collect featured images from CPTs
            foreach ($cpt_postmeta as $meta) {
                if ($meta['meta_key'] === '_thumbnail_id' && !empty($meta['meta_value'])) {
                    $all_media_ids[] = (int) $meta['meta_value'];
                }
            }
        }

        // 4. Create User Manifest & Backup ALL User Meta
        $unique_user_ids = array_unique( $all_user_ids );
        foreach ( $unique_user_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                // Fetch ALL user meta
                $all_meta = get_user_meta( $user_id );
                
                // Collect avatar ID for media backup if it exists
                if ( isset( $all_meta['_qp_avatar_attachment_id'][0] ) ) {
                    $all_media_ids[] = (int) $all_meta['_qp_avatar_attachment_id'][0];
                }
                
                $backup_data['users'][ $user_id ] = [
                    'old_id'       => $user_id,
                    'user_login'   => $user->user_login,
                    'user_email'   => $user->user_email,
                    'display_name' => $user->display_name,
                    'roles'        => $user->roles,
                    'all_meta'     => $all_meta, // Backup all meta
                ];
            }
        }

        // 5. Prepare Media Files (Avatars, Directions, Featured Images)
        $unique_media_ids = array_unique( $all_media_ids );
        $temp_media_files = [];
        foreach ( $unique_media_ids as $media_id ) {
            if (empty($media_id)) continue;
            $file_path = get_attached_file( $media_id );
            if ( $file_path && file_exists( $file_path ) ) {
                $filename = basename( $file_path );
                $temp_media_files[ $media_id ] = [
                    'path' => $file_path,
                    'filename' => $filename
                ];
                $backup_data['media'][ $media_id ] = $filename;
            }
        }

        // 6. Write JSON
        $prefix = ( $type === 'auto' ) ? 'qp-auto-backup-' : 'qp-backup-';
        $filename_base = $prefix . current_time( 'Y-m-d-H-i-s' );
        $json_filename = 'backup.json';
        $json_filepath = trailingslashit( self::get_backup_dir_path() ) . $json_filename;

        $json_data = wp_json_encode( $backup_data, JSON_PRETTY_PRINT );
        if ( ! file_put_contents( $json_filepath, $json_data ) ) {
            return ['success' => false, 'message' => 'Could not write JSON backup file to disk. Check permissions.'];
        }

        // 7. Zip it up
        $zip_filename = $filename_base . '.zip';
        $zip_filepath = trailingslashit( self::get_backup_dir_path() ) . $zip_filename;
        $zip = new \ZipArchive();

        if ( $zip->open( $zip_filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            unlink( $json_filepath );
            return ['success' => false, 'message' => 'Could not create ZipArchive.'];
        }

        $zip->addFile( $json_filepath, $json_filename );
        foreach ( $temp_media_files as $media_id => $file_info ) {
            $zip->addFile( $file_info['path'], 'uploads/' . $file_info['filename'] );
        }
        $zip->close();
        unlink( $json_filepath );

        return ['success' => true, 'filename' => $zip_filename];
    }

    /**
     * Performs a restore from a .zip backup file.
     *
     * @param string $filename The name of the backup .zip file.
     * @param string $mode     'merge' (default) or 'overwrite'.
     * @return array Result of the restore operation.
     */
    public static function perform_restore( $filename, $mode = 'merge' ) {
        global $wpdb;
        self::$wpdb = $wpdb;
        
        $filepath = trailingslashit( self::get_backup_dir_path() ) . sanitize_file_name( $filename );
        if ( ! file_exists( $filepath ) ) {
            return ['success' => false, 'message' => 'Backup file not found.'];
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'qp-restore-temp-' . time();
        wp_mkdir_p( $temp_dir );
        wp_mkdir_p( trailingslashit( $temp_dir ) . 'uploads' );

        $zip = new \ZipArchive();
        if ( $zip->open( $filepath ) !== true ) {
            self::cleanup_temp_dir( $temp_dir );
            return ['success' => false, 'message' => 'Could not open backup ZIP file.'];
        }

        $zip->extractTo( $temp_dir );
        $zip->close();
        
        $json_filepath = trailingslashit( $temp_dir ) . 'backup.json';
        if ( ! file_exists( $json_filepath ) ) {
            self::cleanup_temp_dir( $temp_dir );
            return ['success' => false, 'message' => 'backup.json not found inside the ZIP file.'];
        }

        $json_data = file_get_contents( $json_filepath );
        $backup_data = json_decode( $json_data, true );

        if ( is_null( $backup_data ) || !isset( $backup_data['tables'] ) ) {
            self::cleanup_temp_dir( $temp_dir );
            return ['success' => false, 'message' => 'Invalid or corrupt backup.json file.'];
        }

        try {
            $wpdb->query( 'SET foreign_key_checks = 0' );
            $wpdb->query( 'START TRANSACTION' );

            $stats = ['users_mapped' => 0, 'users_created' => 0, 'media_restored' => 0, 'cpts_restored' => 0, 'tables_cleared' => 0, 'rows_inserted' => 0];
            
            // --- 1. Handle Overwrite Mode ---
            if ( $mode === 'overwrite' ) {
                self::cleanup_existing_data();
                $stats['tables_cleared'] = count(self::get_all_qp_tables());
            }

            // --- 2. Restore Users (Map or Create) ---
            $user_id_map = []; // [old_id => new_id]
            if ( isset( $backup_data['users'] ) ) {
                foreach ( $backup_data['users'] as $old_id => $user_info ) {
                    // Try to find existing user by email
                    $existing_user = get_user_by( 'email', $user_info['user_email'] );
                    
                    if ( $existing_user ) {
                        // Map to existing user
                        $user_id_map[ $old_id ] = $existing_user->ID;
                        $stats['users_mapped']++;
                        
                        // For EXISTING users, we ONLY restore QP-specific meta to avoid overwriting permissions/passwords
                        if ( !empty( $user_info['all_meta'] ) ) {
                            foreach ( $user_info['all_meta'] as $key => $values ) {
                                if ( strpos( $key, '_qp_' ) === 0 ) { // Only QP meta
                                    foreach ( $values as $v ) {
                                        update_user_meta( $existing_user->ID, $key, maybe_unserialize( $v ) );
                                    }
                                }
                            }
                        }

                    } else {
                        // Create new user
                        $username = $user_info['user_login'];
                        if ( username_exists( $username ) ) {
                            $username = $username . '_' . wp_generate_password( 4, false );
                        }
                        $new_user_id = wp_create_user( $username, wp_generate_password( 12 ), $user_info['user_email'] );
                        
                        if ( ! is_wp_error( $new_user_id ) ) {
                            wp_update_user( ['ID' => $new_user_id, 'display_name' => $user_info['display_name']] );
                            $user_id_map[ $old_id ] = $new_user_id;
                            
                            // Restore ALL Meta for NEW users
                            if ( !empty( $user_info['all_meta'] ) ) {
                                foreach ( $user_info['all_meta'] as $key => $values ) {
                                    foreach ( $values as $v ) {
                                        // Avoid duplicating critical WP keys that might have been set by wp_create_user
                                        if ( $key !== 'session_tokens' ) {
                                            update_user_meta( $new_user_id, $key, maybe_unserialize( $v ) );
                                        }
                                    }
                                }
                            }
                            $stats['users_created']++;
                        }
                    }
                }
            }

            // --- 3. Restore Media ---
            $media_id_map = []; // [old_id => new_id]
            $media_manifest = $backup_data['media'] ?? [];
            if ( ! empty( $media_manifest ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );

                foreach ( $media_manifest as $old_media_id => $filename ) {
                    $old_file_path = trailingslashit( $temp_dir ) . 'uploads/' . $filename;
                    if ( file_exists( $old_file_path ) ) {
                        // Determine if this file already exists in media library to avoid duplicates
                        // For simplicity in Merge mode, we re-import to ensure IDs map correctly 
                        // unless we implement hash checking. Here we import as new.
                        $file_array = [
                            'name'     => $filename,
                            'tmp_name' => $old_file_path
                        ];
                        $new_attachment_id = media_handle_sideload( $file_array, 0 );
                        
                        if ( ! is_wp_error( $new_attachment_id ) ) {
                            $media_id_map[ $old_media_id ] = $new_attachment_id;
                            $stats['media_restored']++;
                        }
                    }
                }
            }
            
            // --- Update User Avatars with mapped media IDs (if not done in meta loop) ---
            foreach ($user_id_map as $old_uid => $new_uid) {
                // If the user's avatar ID is in our media map, update it
                $old_meta = $backup_data['users'][$old_uid]['all_meta']['_qp_avatar_attachment_id'][0] ?? 0;
                if ($old_meta && isset($media_id_map[$old_meta])) {
                    update_user_meta($new_uid, '_qp_avatar_attachment_id', $media_id_map[$old_meta]);
                }
            }

            // --- 4. Restore CPTs (Always Insert New to prevent collision) ---
            $post_id_map = []; // [old_post_id => new_post_id]
            $cpt_data = $backup_data['cpts'] ?? [];
            
            // First pass: Insert posts
            if (!empty($cpt_data['posts'])) {
                foreach ($cpt_data['posts'] as $post) {
                    $old_post_id = $post['ID'];
                    $post_author = $post['post_author'];
                    
                    // Prepare post data for insertion
                    $post_data = $post;
                    unset($post_data['ID']); // Force new ID
                    
                    // Remap post author
                    $post_data['post_author'] = $user_id_map[$post_author] ?? get_current_user_id();
                    
                    $new_post_id = wp_insert_post($post_data, true);
                    
                    if (!is_wp_error($new_post_id)) {
                        $post_id_map[$old_post_id] = $new_post_id;
                        $stats['cpts_restored']++;
                    }
                }
            }
            
            // Second pass: Update post parents (if any)
            if (!empty($cpt_data['posts'])) {
                foreach ($cpt_data['posts'] as $post) {
                    $old_post_id = $post['ID'];
                    $old_parent_id = $post['post_parent'];
                    
                    if ($old_parent_id > 0 && isset($post_id_map[$old_post_id]) && isset($post_id_map[$old_parent_id])) {
                        $new_post_id = $post_id_map[$old_post_id];
                        $new_parent_id = $post_id_map[$old_parent_id];
                        wp_update_post(['ID' => $new_post_id, 'post_parent' => $new_parent_id]);
                    }
                }
            }

            // Third pass: Insert post meta
             if (!empty($cpt_data['postmeta'])) {
                foreach ($cpt_data['postmeta'] as $meta) {
                    $old_post_id = $meta['post_id'];
                    $meta_key = $meta['meta_key'];
                    $meta_value = $meta['meta_value'];
                    
                    if (isset($post_id_map[$old_post_id])) {
                        $new_post_id = $post_id_map[$old_post_id];
                        
                        // Remap featured image
                        if ($meta_key === '_thumbnail_id' && isset($media_id_map[$meta_value])) {
                            $meta_value = $media_id_map[$meta_value];
                        }
                        
                        // Remap linked plans/courses in meta
                        if (in_array($meta_key, ['_qp_linked_plan_id', '_qp_course_auto_plan_id', '_qp_linked_course_id'])) {
                             if (isset($post_id_map[$meta_value])) {
                                 $meta_value = $post_id_map[$meta_value];
                             }
                        }
                        
                        // Handle array of linked courses
                        if ($meta_key === '_qp_plan_linked_courses') {
                             $courses = maybe_unserialize($meta_value);
                             if (is_array($courses)) {
                                 $new_courses = [];
                                 foreach($courses as $cid) {
                                     if (isset($post_id_map[$cid])) $new_courses[] = $post_id_map[$cid];
                                 }
                                 $meta_value = $new_courses;
                             }
                        }

                        add_post_meta($new_post_id, $meta_key, $meta_value);
                    }
                }
            }

            // --- 5. Import Table Data & Remap IDs ---
            $tables_in_order = self::get_tables_in_dependency_order();
            $backup_tables = $backup_data['tables'];
            
            // ID Map for all QP tables: [ 'table_name' => [ old_id => new_id ] ]
            $id_map = [];
            foreach ($tables_in_order as $t) $id_map[$t] = [];

            foreach ( $tables_in_order as $table ) {
                if ( !isset( $backup_tables[ $table ] ) || !is_array( $backup_tables[ $table ] ) ) continue;

                $pk_column = self::get_primary_key_for_table($table);

                foreach ( $backup_tables[ $table ] as $row ) {
                    $old_pk = ($pk_column && isset($row[$pk_column])) ? $row[$pk_column] : null;

                    // --- Smart Mapping for MERGE Mode ---
                    
                    // 5a. Taxonomies: Check if exists
                    if ( $mode === 'merge' && $table === $wpdb->prefix . 'qp_taxonomies' ) {
                        $existing_id = $wpdb->get_var( $wpdb->prepare("SELECT taxonomy_id FROM $table WHERE taxonomy_name = %s", $row['taxonomy_name']) );
                        if ( $existing_id ) {
                            $id_map[$table][$old_pk] = $existing_id;
                            continue; // Skip insertion
                        }
                    }
                    
                    // 5b. Terms: Check if exists (using slug + mapped taxonomy_id)
                    if ( $mode === 'merge' && $table === $wpdb->prefix . 'qp_terms' ) {
                        // Remap taxonomy_id first to check existence
                        $check_tax_id = $row['taxonomy_id'];
                        if (isset($id_map[$wpdb->prefix.'qp_taxonomies'][$check_tax_id])) {
                             $check_tax_id = $id_map[$wpdb->prefix.'qp_taxonomies'][$check_tax_id];
                        }
                        
                        $existing_term_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT term_id FROM $table WHERE slug = %s AND taxonomy_id = %d", 
                            $row['slug'], $check_tax_id
                        ) );
                        
                        if ( $existing_term_id ) {
                            $id_map[$table][$old_pk] = $existing_term_id;
                            continue; // Skip insertion
                        }
                    }

                    // --- Standard Insertion Logic ---
                    
                    // 1. Remap FKs in the row
                    $row = self::remap_foreign_keys($row, $table, $user_id_map, $media_id_map, $post_id_map, $id_map);
                    
                    // Special handling for reports reason_term_ids (comma separated string)
                    if ($table === $wpdb->prefix . 'qp_question_reports' && isset($row['reason_term_ids'])) {
                         $reasons = explode(',', $row['reason_term_ids']);
                         $new_reasons = [];
                         foreach($reasons as $rid) {
                             if (isset($id_map[$wpdb->prefix.'qp_terms'][$rid])) {
                                 $new_reasons[] = $id_map[$wpdb->prefix.'qp_terms'][$rid];
                             } else {
                                 // If mapped term not found (maybe active status changed), we keep old or drop?
                                 // Safest to drop to avoid pointing to wrong term
                             }
                         }
                         $row['reason_term_ids'] = implode(',', $new_reasons);
                    }

                    // 2. Remove PK to force auto-increment (treat as new entry)
                    if ($pk_column && isset($row[$pk_column])) {
                        unset($row[$pk_column]);
                    }

                    // 3. Insert
                    $result = self::$wpdb->insert( $table, $row );
                    
                    if ($result !== false) {
                        $new_pk = self::$wpdb->insert_id;
                        if ($pk_column && $old_pk) {
                            $id_map[$table][$old_pk] = $new_pk;
                        }
                        if ($table === $wpdb->prefix . 'qp_questions') $stats['questions']++;
                    }
                }
            }
            
            $wpdb->query( 'COMMIT' );
            $wpdb->query( 'SET foreign_key_checks = 1' );
            self::cleanup_temp_dir( $temp_dir );
            
            return ['success' => true, 'message' => 'Restore complete!', 'stats' => $stats];

        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            $wpdb->query( 'SET foreign_key_checks = 1' );
            self::cleanup_temp_dir( $temp_dir );
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    /**
     * Cleans up all existing QP data before a restore.
     */
    private static function cleanup_existing_data() {
        global $wpdb;
        
        $attachment_ids = [];
        
        // 1. Get CPTs and their featured images *before* deleting them
        $post_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('qp_course', 'qp_plan')");
        if (!empty($post_ids)) {
            $thumbnail_ids = $wpdb->get_col(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN (" . implode(',', $post_ids) . ")"
            );
            $attachment_ids = array_merge($attachment_ids, $thumbnail_ids);
        }

        // 2. Get media from QP tables and user meta
        $dir_image_ids = $wpdb->get_col("SELECT direction_image_id FROM {$wpdb->prefix}qp_question_groups WHERE direction_image_id > 0");
        $avatar_ids = $wpdb->get_col("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_qp_avatar_attachment_id' AND meta_value > 0");
        
        $attachment_ids = array_merge($attachment_ids, $dir_image_ids, $avatar_ids);
        $attachment_ids = array_unique(array_filter($attachment_ids));
        
        // 3. Delete all associated media
        foreach ($attachment_ids as $att_id) {
            wp_delete_attachment($att_id, true); // true = force delete
        }

        // 4. Delete CPT posts
        if (!empty($post_ids)) {
            foreach ($post_ids as $post_id) {
                wp_delete_post($post_id, true); // true = force delete
            }
        }
        
        // 5. Truncate all QP tables in reverse dependency order
        $tables_in_order = self::get_tables_in_dependency_order();
        foreach ( array_reverse($tables_in_order) as $table ) {
             self::$wpdb->query( "TRUNCATE TABLE {$table}" );
        }
    }
    
    /**
     * Gets the name of the Primary Key column for a table.
     * @param string $table Full table name with prefix.
     * @return string|null The PK column name, or null.
     */
    private static function get_primary_key_for_table($table) {
        $map = [
            'qp_taxonomies' => 'taxonomy_id', 'qp_terms' => 'term_id', 'qp_term_meta' => 'meta_id',
            'qp_question_groups' => 'group_id', 'qp_questions' => 'question_id', 'qp_options' => 'option_id',
            'qp_user_sessions' => 'session_id', 'qp_user_attempts' => 'attempt_id',
            'qp_course_sections' => 'section_id', 'qp_course_items' => 'item_id',
            'qp_user_entitlements' => 'entitlement_id', 'qp_user_courses' => 'user_course_id',
            'qp_user_items_progress' => 'user_item_id', 'qp_otp_verification' => 'id',
            'qp_session_pauses' => 'pause_id', 'qp_review_later' => 'review_id', 
            'qp_question_reports' => 'report_id', 'qp_revision_attempts' => 'revision_attempt_id',
            'qp_logs' => 'log_id'
        ];
        // Strip prefix for lookup
        global $wpdb;
        $clean_table = str_replace($wpdb->prefix, '', $table);
        return $map[$clean_table] ?? null;
    }

    /**
     * Remaps all foreign key IDs in a data row before insertion.
     * @param array $row The row of data.
     * @param string $table The table this row belongs to.
     * @param array $user_id_map Map of [old_user_id => new_user_id].
     * @param array $media_id_map Map of [old_media_id => new_media_id].
     * @param array $post_id_map Map of [old_post_id => new_post_id].
     * @param array $id_map Map of [table_name => [old_id => new_id]].
     * @return array The re-mapped row.
     */
    private static function remap_foreign_keys($row, $table, $user_id_map, $media_id_map, $post_id_map, $id_map) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // 1. Common Columns
        if (isset($row['user_id']) && isset($user_id_map[$row['user_id']])) {
            $row['user_id'] = $user_id_map[$row['user_id']];
        }
        
        if (isset($row['direction_image_id']) && isset($media_id_map[$row['direction_image_id']])) {
            $row['direction_image_id'] = $media_id_map[$row['direction_image_id']];
        }

        // 2. CPT References
        $cpt_cols = ['course_id', 'plan_id', 'order_id'];
        foreach ($cpt_cols as $col) {
            if (isset($row[$col]) && isset($post_id_map[$row[$col]])) {
                $row[$col] = $post_id_map[$row[$col]];
            }
        }

        // 3. Internal Table References
        $mappings = [
            'group_id' => $prefix.'qp_question_groups',
            'question_id' => $prefix.'qp_questions',
            'duplicate_of' => $prefix.'qp_questions',
            'selected_option_id' => $prefix.'qp_options',
            'session_id' => $prefix.'qp_user_sessions',
            'entitlement_id' => $prefix.'qp_user_entitlements',
            'section_id' => $prefix.'qp_course_sections',
            'item_id' => $prefix.'qp_course_items',
            'last_accessed_item_id' => $prefix.'qp_course_items',
            'term_id' => $prefix.'qp_terms',
            'topic_id' => $prefix.'qp_terms', // Revision attempts use topic_id
            'taxonomy_id' => $prefix.'qp_taxonomies',
            'primary_subject_term_id' => $prefix.'qp_terms',
            'specific_subject_term_id' => $prefix.'qp_terms',
            'primary_source_term_id' => $prefix.'qp_terms',
            'specific_source_term_id' => $prefix.'qp_terms',
            'exam_term_id' => $prefix.'qp_terms',
            'parent' => $prefix.'qp_terms', // Only for qp_terms table
        ];

        foreach ($mappings as $col => $target_table) {
            if (isset($row[$col]) && $row[$col] > 0 && isset($id_map[$target_table][$row[$col]])) {
                $row[$col] = $id_map[$target_table][$row[$col]];
            }
        }
        
        // 4. Special Case: qp_term_relationships (Poly-morphic object_id)
        if ($table === $prefix . 'qp_term_relationships') {
            $type = $row['object_type'];
            $old_id = $row['object_id'];
            $new_id = null;
            
            if ($type === 'question' && isset($id_map[$prefix.'qp_questions'][$old_id])) $new_id = $id_map[$prefix.'qp_questions'][$old_id];
            elseif ($type === 'group' && isset($id_map[$prefix.'qp_question_groups'][$old_id])) $new_id = $id_map[$prefix.'qp_question_groups'][$old_id];
            elseif ($type === 'course' && isset($post_id_map[$old_id])) $new_id = $post_id_map[$old_id];
            elseif ($type === 'plan' && isset($post_id_map[$old_id])) $new_id = $post_id_map[$old_id];
            elseif (strpos($type, '_link') !== false && isset($id_map[$prefix.'qp_terms'][$old_id])) $new_id = $id_map[$prefix.'qp_terms'][$old_id];
            
            if ($new_id) $row['object_id'] = $new_id;
        }

        return $row;
    }
    
    /**
     * Recursively delete a directory and its contents.
     * @param string $dirPath The path to the directory.
     */
    private static function cleanup_temp_dir($dirPath) {
        if (! is_dir($dirPath)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($dirPath);
    }

    /**
     * Renders the list of local backup files as HTML.
     *
     * @return string
     */
    public static function get_local_backups_html() {
        $backup_dir = self::get_backup_dir_path();
        $backup_files = glob( $backup_dir . '/*.zip' );
        if ($backup_files === false) $backup_files = [];
        
        usort($backup_files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        ob_start();

        if ( empty( $backup_files ) ) {
            echo '<tr><td colspan="4" style="text-align: center;">No local backups found.</td></tr>';
        } else {
            foreach ( $backup_files as $file_path ) {
                $filename = basename( $file_path );
                $file_size = size_format( filesize( $file_path ) );
                $file_date = date( 'M j, Y, g:i a', filemtime( $file_path ) );
                $is_auto = (strpos($filename, 'qp-auto-backup-') === 0);
                
                ?>
                <tr data-filename="<?php echo esc_attr( $filename ); ?>">
                    <td data-label="Date">
                        <?php echo esc_html( $file_date ); ?>
                        <?php if ($is_auto): ?>
                            <br><span style="font-size: 0.9em; color: #777; font-style: italic;">(Auto-Backup)</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Name"><?php echo esc_html( $filename ); ?></td>
                    <td data-label="Size"><?php echo esc_html( $file_size ); ?></td>
                    <td data-label="Actions" class="column-actions">
                        <button class="button button-primary qp-restore-btn" data-filename="<?php echo esc_attr( $filename ); ?>">Restore</button>
                        <a href="<?php echo esc_url( content_url( 'uploads/qp-backups/' . $filename ) ); ?>" class="button button-secondary" download>Download</a>
                        <button class="button button-link-delete qp-delete-backup-btn" data-filename="<?php echo esc_attr( $filename ); ?>" title="Delete">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
                <?php
            }
        }
        return ob_get_clean();
    }

    /**
     * Runs the scheduled backup event.
     */
    public static function run_scheduled_backup_event() {
        error_log('QP Cron: Running scheduled backup event...');
        
        $result = self::perform_backup('auto');
        
        if (!$result['success']) {
            error_log('QP Cron: Auto-backup FAILED. Message: ' . $result['message']);
            return;
        }
        
        error_log('QP Cron: Auto-backup successful: ' . $result['filename']);

        // Prune old backups
        $schedule = get_option('qp_auto_backup_schedule', false);
        if (!$schedule || !isset($schedule['keep'])) {
            error_log('QP Cron: No prune settings found. Skipping cleanup.');
            return;
        }
        
        $keep_count = absint($schedule['keep']);
        if ($keep_count <= 0) return; // Keep all
        
        $prune_manual = (bool) ($schedule['prune_manual'] ?? false);
        $backup_dir = self::get_backup_dir_path();
        
        $all_backups = glob($backup_dir . '/qp-*.zip');
        if ($all_backups === false) $all_backups = [];
        
        $auto_backups = [];
        $manual_backups = [];
        
        foreach ($all_backups as $file) {
            if (strpos(basename($file), 'qp-auto-backup-') === 0) {
                $auto_backups[] = $file;
            } else {
                $manual_backups[] = $file;
            }
        }
        
        $sort_by_time_desc = function($a, $b) {
            return filemtime($b) - filemtime($a);
        };
        
        usort($auto_backups, $sort_by_time_desc);
        
        $backups_to_delete = array_slice($auto_backups, $keep_count);
        
        if ($prune_manual) {
            $all_backups_sorted = $all_backups;
            usort($all_backups_sorted, $sort_by_time_desc);
            $backups_to_delete = array_slice($all_backups_sorted, $keep_count);
        }

        if (!empty($backups_to_delete)) {
            foreach ($backups_to_delete as $file_to_delete) {
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                    error_log('QP Cron: Pruned old backup file: ' . basename($file_to_delete));
                }
            }
        } else {
             error_log('QP Cron: No old backups found to prune.');
        }
    }
}