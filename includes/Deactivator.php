<?php
namespace QuestionPress; // PSR-4 Namespace

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @package QuestionPress
 */
class Deactivator {

    /**
     * Deactivation hook callback.
     * Cleans up scheduled events, etc. (Does NOT delete data).
     */
    public static function deactivate() {
        // ... Paste original qp_deactivate_plugin code here ...

        // Example: Clear scheduled cron jobs if you added any
         wp_clear_scheduled_hook('qp_check_entitlement_expiration_hook');
         wp_clear_scheduled_hook('qp_cleanup_abandoned_sessions_event');
         wp_clear_scheduled_hook('qp_scheduled_backup_hook'); // Clear backup hook

        // Ensure flush_rewrite_rules() is called
        flush_rewrite_rules();
    }

} // End class Deactivator