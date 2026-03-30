<?php

namespace QuestionPress\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Manages Multi-ABI Release Packages (APKs) and Metadata.
 */
class Update_Manager
{
    private static $base_path = 'questionpress/releases/latest';

    public static function get_release_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . self::$base_path;
    }

    public static function get_release_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['baseurl']) . self::$base_path;
    }

    /**
     * Extracts the uploaded ZIP and processes the release.
     */
    public static function handle_zip_upload($file_path)
    {
        add_filter('filesystem_method', function() { return 'direct'; });
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            return new \WP_Error('fs_init_fail', 'Could not initialize the WordPress filesystem.');
        }

        $target_dir = self::get_release_dir();

        self::cleanup_old_releases();

        if (!$wp_filesystem->is_dir($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                if (!$wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                    error_log("QP Update Manager: Failed to create directory " . $target_dir);
                    return new \WP_Error('mkdir_fail', 'Could not create release directory: ' . $target_dir);
                }
            }
        }

        $unzipped = unzip_file($file_path, $target_dir);
        if (is_wp_error($unzipped)) {
            error_log("QP Unzip Error: " . $unzipped->get_error_message());
            return $unzipped;
        }

        $info = self::parse_metadata();
        if (!$info) {
            return new \WP_Error('metadata_missing', 'The zip was extracted but output-metadata.json was not found or is invalid.');
        }

        $options = get_option('qp_settings', []);
        $options['latest_release_info'] = $info;
        $options['latest_app_version'] = $info['version'];
        $options['latest_app_build'] = $info['build'];
        update_option('qp_settings', $options);

        return true;
    }

    /**
     * Parses output-metadata.json with correct version extraction.
     */
    public static function parse_metadata()
    {
        $json_path = trailingslashit(self::get_release_dir()) . 'output-metadata.json';
        if (!file_exists($json_path)) return null;

        $content = file_get_contents($json_path);
        $data = json_decode($content, true);

        // Validation: Schema uses 'elements' array
        if (!$data || empty($data['elements']) || !is_array($data['elements'])) {
            return null;
        }

        // Extract global version info from the FIRST element as per schema
        $first_element = $data['elements'][0];
        $version_name  = $first_element['versionName'] ?? '1.0.0';
        $version_code  = $first_element['versionCode'] ?? 0;

        $variants = [];
        foreach ($data['elements'] as $element) {
            $abi = 'universal';
            
            if (!empty($element['filters']) && is_array($element['filters'])) {
                foreach ($element['filters'] as $filter) {
                    if (isset($filter['filterType']) && $filter['filterType'] === 'ABI') {
                        $abi = $filter['value'];
                        break;
                    }
                }
            }

            $file_name = $element['outputFile'] ?? '';
            if (empty($file_name)) continue;

            $file_path = trailingslashit(self::get_release_dir()) . $file_name;
            
            $size = '0 KB';
            $md5  = '';

            if (file_exists($file_path) && is_readable($file_path)) {
                $size = size_format(filesize($file_path), 1);
                $md5  = md5_file($file_path);
            }

            $variants[] = [
                'abi'  => $abi,
                'file' => $file_name,
                'md5'  => $md5,
                'size' => $size
            ];
        }

        return [
            'version'  => $version_name,
            'build'    => $version_code,
            'variants' => $variants
        ];
    }

    /**
     * Returns structured update data for the API.
     */
    public static function get_update_info()
    {
        $options = get_option('qp_settings', []);
        $info = $options['latest_release_info'] ?? null;

        if (!$info) return null;

        $base_url = self::get_release_url();
        $mapped_variants = [];

        foreach ($info['variants'] as $v) {
            $mapped_variants[] = [
                'abi'  => $v['abi'],
                'url'  => trailingslashit($base_url) . $v['file'],
                'md5'  => $v['md5'],
                'size' => $v['size']
            ];
        }

        return [
            'version'  => $info['version'],
            'build'    => $info['build'],
            'variants' => $mapped_variants
        ];
    }

    /**
     * Removes all files in the latest release directory.
     */
    public static function cleanup_old_releases()
    {
        add_filter('filesystem_method', function() { return 'direct'; });
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $target_dir = self::get_release_dir();
        if ($wp_filesystem && $wp_filesystem->is_dir($target_dir)) {
            $wp_filesystem->delete($target_dir, true);
        }
    }
}