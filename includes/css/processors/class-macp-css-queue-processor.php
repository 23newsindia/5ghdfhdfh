<?php
/**
 * Handles background processing of CSS optimization
 */


class MACP_CSS_Queue_Processor {
    private $table_name;
    private $batch_size = 5;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'macp_used_css';
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
            // Skip robots.txt and invalid URLs
            if (strpos($item->url, 'robots.txt') !== false || !filter_var($item->url, FILTER_VALIDATE_URL)) {
                $this->mark_as_error($item->id, 'Invalid URL or robots.txt');
                continue;
            }

            MACP_Debug::log('Processing URL: ' . $item->url);

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

            // Extract CSS files using DOMDocument
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);
            $stylesheets = $xpath->query('//link[@rel="stylesheet"]');

            if ($stylesheets->length === 0) {
                throw new Exception('No CSS files found');
            }

            // Process each stylesheet
            $optimized_css = '';
            foreach ($stylesheets as $stylesheet) {
                $href = $stylesheet->getAttribute('href');
                if (empty($href)) continue;

                // Make URL absolute if needed
                if (strpos($href, 'http') !== 0) {
                    $href = rtrim($item->url, '/') . '/' . ltrim($href, '/');
                }

                $css_content = wp_remote_retrieve_body(wp_remote_get($href));
                if ($css_content) {
                    // Process CSS content
                    $processed_css = $this->process_css_content($css_content, $html);
                    $optimized_css .= "/* Source: {$href} */\n" . $processed_css . "\n";
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
            }

        } catch (Exception $e) {
            MACP_Debug::log('Error processing CSS for URL ' . $item->url . ': ' . $e->getMessage());
            $this->mark_as_error($item->id, $e->getMessage());
        }
    }
}

private function process_css_content($css, $html) {
    // Remove comments
    $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    
    // Extract selectors
    preg_match_all('/([^{]+){[^}]*}/', $css, $matches);
    
    $used_css = '';
    foreach ($matches[0] as $rule) {
        $selector = trim(preg_replace('/\s*{.*$/s', '', $rule));
        
        // Skip @-rules
        if (strpos($selector, '@') === 0) {
            $used_css .= $rule . "\n";
            continue;
        }
        
        try {
            $xpath = $this->convert_css_to_xpath($selector);
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath_obj = new DOMXPath($dom);
            
            if ($xpath_obj->query($xpath)->length > 0) {
                $used_css .= $rule . "\n";
            }
        } catch (Exception $e) {
            // Keep selector if conversion fails
            $used_css .= $rule . "\n";
        }
    }
    
    return $used_css;
}

private function convert_css_to_xpath($selector) {
    $converter = new CssSelectorConverter();
    return $converter->toXPath($selector);
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
