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
    } 
    public function process_queue() {
        global $wpdb;

        MACP_Debug::log('Starting CSS queue processing');

        // Get pending items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE status = 'pending' 
            AND retries < 3 
            ORDER BY id ASC
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
                
                // Fetch page content
                $response = wp_remote_get($item->url);
                if (is_wp_error($response)) {
                    throw new Exception('Failed to fetch URL: ' . $response->get_error_message());
                }

                $html = wp_remote_retrieve_body($response);
                if (empty($html)) {
                    throw new Exception('Empty response from URL');
                }

                // Process CSS
                $optimized_css = $this->optimizer->optimize($html);
                if (!$optimized_css) {
                    throw new Exception('Failed to optimize CSS');
                }

                // Update database with results
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

                MACP_Debug::log('Successfully processed CSS for URL: ' . $item->url);

            } catch (Exception $e) {
                MACP_Debug::log('Error processing CSS for URL ' . $item->url . ': ' . $e->getMessage());
                
                // Update retry count and status
                $wpdb->update(
                    $this->table_name,
                    [
                        'status' => 'error',
                        'error_message' => $e->getMessage(),
                        'retries' => $item->retries + 1,
                        'modified' => current_time('mysql')
                    ],
                    ['id' => $item->id],
                    ['%s', '%s', '%d', '%s'],
                    ['%d']
                );
            }
        }
    }
}