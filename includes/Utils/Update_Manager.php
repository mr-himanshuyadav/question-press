<?php

namespace QuestionPress\Utils;

if (!defined('ABSPATH')) exit;

/**
 * Manages Multi-ABI Release Packages (APKs) and Metadata.
 */
class Update_Manager
{
    private static $base_path = 'questionpress/releases/latest';

    /**
     * Returns the absolute path to the latest release directory.
     */
    public static function get_release_dir()
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . self::$base_path;
    }

    /**
     * Returns the base URL for release files.
     */
    public static function get_release_url()
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['baseurl']) . self::$base_path;
    }

    /**
     * Extracts the uploaded ZIP and processes the release.
     */
    public static function handle_zip_upload($file_path)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $target_dir = self::get_release_dir();

        // 1. Cleanup existing release
        self::cleanup_old_releases();

        // 2. Create directory
        if (!$wp_filesystem->is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // 3. Unzip
        $unzipped = unzip_file($file_path, $target_dir);
        if (is_wp_error($unzipped)) {
            return $unzipped;
        }

        // 4. Parse and Save Metadata to Options
        $info = self::parse_metadata();
        if ($info) {
            $options = get_option('qp_settings', []);
            $options['latest_release_info'] = $info;
            $options['latest_app_version'] = $info['version'];
            $options['latest_app_build'] = $info['build'];
            update_option('qp_settings', $options);
        }

        return true;
    }

    /**
     * Parses output-metadata.json to identify ABI mappings.
     * Schema correction: Uses 'elements' key and filters array traversal.
     */
    public static function parse_metadata()
    {
        $json_path = trailingslashit(self::get_release_dir()) . 'output-metadata.json';
        if (!file_exists($json_path)) {
            return null;
        }

        $content = file_get_contents($json_path);
        $data = json_decode($content, true);

        // Alignment: Schema uses 'elements', not 'artifacts'
        if (!$data || !isset($data['elements'])) {
            return null;
        }

        $variants = [];
        foreach ($data['elements'] as $element) {
            // Default to universal if no filters exist or no ABI filter found
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

            // Defensive Check: Ensure file exists before MD5 calculation
            $md5 = '';
            if (file_exists($file_path)) {
                $md5 = md5_file($file_path);
            }

            $variants[] = [
                'abi'  => $abi,
                'file' => $file_name,
                'md5'  => $md5
            ];
        }

        return [
            'version'  => $data['versionName'] ?? '1.0.0',
            'build'    => $data['versionCode'] ?? 0,
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
                'abi' => $v['abi'],
                'url' => trailingslashit($base_url) . $v['file'],
                'md5' => $v['md5']
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
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        $target_dir = self::get_release_dir();
        if ($wp_filesystem->is_dir($target_dir)) {
            $wp_filesystem->delete($target_dir, true);
        }
    }
}