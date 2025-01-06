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

            // Extract CSS
            $extractor = new MACP_CSS_Extractor();
            $css_files = $extractor->extract_css_files($html);
            $used_selectors = $extractor->extract_used_selectors($html);

            // Process CSS
            $optimized_css = '';
            foreach ($css_files as $file) {
                $css_content = wp_remote_retrieve_body(wp_remote_get($file));
                if ($css_content) {
                    $processor = new MACP_CSS_Minifier();
                    $optimized_css .= $processor->process($css_content);
                }
            }

            // Update database with results
            $wpdb->update(
                $this->table_name,
                [
                    'css' => $optimized_css,
                    'hash' => md5($optimized_css),
                    'status' => 'completed',
                    'modified' => current_time('mysql')
                ],
                ['id' => $item->id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            MACP_Debug::log('Successfully processed CSS for URL: ' . $item->url);

        } catch (Exception $e) {
            MACP_Debug::log('Error processing CSS for URL ' . $item->url . ': ' . $e->getMessage());
            
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
