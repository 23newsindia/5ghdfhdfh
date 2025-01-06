<?php
/**
 * Manages CSS processing and optimization
 */
class MACP_CSS_Processor_Manager {
    private $table_name;
    private $debug;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'macp_used_css';
        $this->debug = new MACP_Debug();
        
        // Initialize hooks
        if (get_option('macp_remove_unused_css', 0)) {
            add_action('wp', [$this, 'maybe_process_page']);
        }
    }

    public function maybe_process_page() {
        if (is_admin() || is_user_logged_in()) {
            return;
        }

        $url = MACP_URL_Helper::get_current_url();
        $this->debug->log('Checking URL for CSS processing: ' . $url);

        global $wpdb;
        
        // Check if URL is already in queue or processed
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE url = %s",
            $url
        ));

        if (!$existing) {
            // Add to queue
            $wpdb->insert(
                $this->table_name,
                [
                    'url' => $url,
                    'status' => 'pending',
                    'modified' => current_time('mysql'),
                    'last_accessed' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s']
            );
            $this->debug->log('Added URL to CSS processing queue: ' . $url);
        } elseif ($existing->status === 'completed') {
            // Update last accessed time
            $wpdb->update(
                $this->table_name,
                ['last_accessed' => current_time('mysql')],
                ['id' => $existing->id],
                ['%s'],
                ['%d']
            );
        }
    }
}