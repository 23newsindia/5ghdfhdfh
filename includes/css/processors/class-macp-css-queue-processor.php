<?php
/**
 * Handles background processing of CSS optimization
 */
class MACP_CSS_Queue_Processor {
    private $table_name;
    private $batch_size = 5;
    private $optimizer;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'macp_used_css';
        $this->optimizer = new MACP_CSS_Optimizer();
        
        // Register cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        add_action('macp_process_css_queue', [$this, 'process_queue']);
    }

    public function add_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => 'Every Five Minutes'
        );
        return $schedules;
    }

    public function process_queue() {
        global $wpdb;

        MACP_Debug::log('Starting CSS queue processing');

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE status = 'pending' 
            AND retries < 3 
            LIMIT %d",
            $this->batch_size
        ));

        if (empty($items)) {
            MACP_Debug::log('No pending items in queue');
            return;
        }

        foreach ($items as $item) {
            try {
                MACP_Debug::log('Processing URL: ' . $item->url);
                
                $response = wp_remote_get($item->url);
                if (is_wp_error($response)) {
                    $this->update_status($item->id, 'error', $response->get_error_message());
                    continue;
                }

                $html = wp_remote_retrieve_body($response);
                if (empty($html)) {
                    $this->update_status($item->id, 'error', 'Empty response');
                    continue;
                }

                $optimized_css = $this->optimizer->optimize($html);
                if ($optimized_css) {
                    $wpdb->update(
                        $this->table_name,
                        [
                            'css' => $optimized_css,
                            'status' => 'completed',
                            'modified' => current_time('mysql')
                        ],
                        ['id' => $item->id],
                        ['%s', '%s', '%s'],
                        ['%d']
                    );
                    MACP_Debug::log('Successfully optimized CSS for URL: ' . $item->url);
                } else {
                    $this->update_status($item->id, 'error', 'Failed to optimize CSS');
                }
            } catch (Exception $e) {
                MACP_Debug::log('Error processing CSS: ' . $e->getMessage());
                $this->update_status($item->id, 'error', $e->getMessage());
            }
        }
    }

    private function update_status($id, $status, $error = '') {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            [
                'status' => $status,
                'error_message' => $error,
                'retries' => new Raw('retries + 1'),
                'modified' => current_time('mysql')
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
    }
}