<?php
if (!defined('ABSPATH')) exit;

class QP_Updater {

    private $file;
    private $plugin;
    private $basename;
    private $active;
    private $github_repo;
    private $plugin_slug;

    public function __construct($file) {
        $this->file = $file;
        add_action('admin_init', array($this, 'set_plugin_properties'));
        return $this;
    }

    public function set_plugin_properties() {
        $this->plugin = get_plugin_data($this->file);
        $this->basename = plugin_basename($this->file);
        $this->active = is_plugin_active($this->basename);
        // The GitHub repository name, e.g., "user/repo"
        $this->github_repo = 'mr-himanshuyadav/question-press'; 
        $this->plugin_slug = 'question-press';
    }

    public function init() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_updates'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 10, 3);
    }

    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $latest_release = $this->get_repository_info();
        if ($latest_release && version_compare($this->plugin['Version'], $latest_release->tag_name, '<')) {
            $transient->response[$this->basename] = (object) array(
                'slug'        => $this->plugin_slug,
                'new_version' => $latest_release->tag_name,
                'package'     => $latest_release->zipball_url,
                'url'         => 'https://github.com/' . $this->github_repo
            );
        }

        return $transient;
    }

    public function plugin_api_call($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return $res;
        }

        $latest_release = $this->get_repository_info();
        if (!$latest_release) {
            return $res;
        }

        $res = new stdClass();
        $res->name = $this->plugin['Name'];
        $res->slug = $this->plugin_slug;
        $res->version = $latest_release->tag_name;
        $res->author = $this->plugin['AuthorName'];
        $res->homepage = $this->plugin['PluginURI'];
        $res->requires = $this->plugin['RequiresWP'];
        $res->tested = $this->plugin['TestedUpTo'];
        $res->download_link = $latest_release->zipball_url;
        $res->trunk = $latest_release->zipball_url;
        $res->last_updated = $latest_release->published_at;
        $res->sections = array(
            'description' => $this->plugin['Description'],
            'changelog' => $latest_release->body
        );

        return $res;
    }

    private function get_repository_info() {
        if (get_transient('qp_github_update')) {
            return get_transient('qp_github_update');
        }

        $response = wp_remote_get("https://api.github.com/repos/{$this->github_repo}/releases/latest");
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        if (is_object($release)) {
            set_transient('qp_github_update', $release, HOUR_IN_SECONDS);
            return $release;
        }

        return false;
    }
}