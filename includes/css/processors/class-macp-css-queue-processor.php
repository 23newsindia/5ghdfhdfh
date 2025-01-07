<?php
/**
 * Handles background processing of CSS optimization
 */
class MACP_CSS_Queue_Processor {
    private $table_name;
    private $batch_size = 5;
    private $extractor;
    private $minifier;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'macp_used_css';
        $this->extractor = new MACP_CSS_Extractor();
        $this->minifier = new MACP_CSS_Minifier();
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

                // Skip invalid URLs or robots.txt
                if (!filter_var($item->url, FILTER_VALIDATE_URL) || strpos($item->url, 'robots.txt') !== false) {
                    $this->mark_as_error($item->id, 'Invalid URL');
                    continue;
                }

                // Fetch page content
                $response = wp_remote_get($item->url, [
                    'timeout' => 30,
                    'sslverify' => false
                ]);

                if (is_wp_error($response)) {
                    throw new Exception('Failed to fetch URL: ' . $response->get_error_message());
                }

                $html = wp_remote_retrieve_body($response);
                if (empty($html)) {
                    throw new Exception('Empty response from URL');
                }

                // Extract CSS files
                $css_files = $this->extractor->extract_css_files($html);
                if (empty($css_files)) {
                    throw new Exception('No CSS files found');
                }

                // Process CSS
                $optimized_css = '';
                foreach ($css_files as $file) {
                    $css_content = wp_remote_retrieve_body(wp_remote_get($file));
                    if ($css_content) {
                        $optimized_css .= $this->minifier->process($css_content);
                    }
                }

                if (empty($optimized_css)) {
                    throw new Exception('No CSS content processed');
                }

                // Save optimized CSS
                $hash = md5($optimized_css);
                $cache_dir = WP_CONTENT_DIR . '/cache/macp/used-css/';
                
                if (!file_exists($cache_dir)) {
                    wp_mkdir_p($cache_dir);
                }

                $file_path = $cache_dir . $hash . '.css';
                if (file_put_contents($file_path, $optimized_css)) {
                    // Update database
                    $wpdb->update(
                        $this->table_name,
                        [
                            'css' => $optimized_css,
                            'hash' => $hash,
                            'status' => 'completed',
                            'modified' => current_time('mysql')
                        ],
                        ['id' => $item->id],
                        ['%s', '%s', '%s', '%s'],
                        ['%d']
                    );

                    MACP_Debug::log('Successfully processed CSS for URL: ' . $item->url);
                } else {
                    throw new Exception('Failed to save optimized CSS file');
                }

            } catch (Exception $e) {
                MACP_Debug::log('Error processing CSS for URL ' . $item->url . ': ' . $e->getMessage());
                $this->mark_as_error($item->id, $e->getMessage());
            }
        }
    }

    private function mark_as_error($id, $message) {
        global $wpdb;
        
        $wpdb->update(
            $this->table_name,
            [
                'status' => 'error',
                'error_message' => $message,
                'retries' => $wpdb->get_var($wpdb->prepare(
                    "SELECT retries FROM {$this->table_name} WHERE id = %d",
                    $id
                )) + 1,
                'modified' => current_time('mysql')
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
    }
}