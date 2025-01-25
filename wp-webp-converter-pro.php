<?php
/**
 * Plugin Name: WebP Converter Pro
 * Plugin URI: https://go.enginyr.ing/CwiK
 * Description: Advanced WebP conversion with bulk processing and optimized delivery. Powered by high-performance hosting.
 * Version: 2.0.1
 * Author: ENGINYRING
 * Author URI: https://www.enginyring.com/
 * License: GPL v3
 */

defined('ABSPATH') || exit;

class WebP_Converter_Pro {
    private $cache_dir;
    private $default_quality = 80;
    private $size_threshold = 100 * 1024;
    private $high_compression_quality = 60;
    private $batch_sizes = [10, 20, 50, 100];
    private $conversion_option_name = 'webp_conversion_progress';
    private $debug = false;
    private $min_memory_limit = 256;  // Minimum recommended memory limit in MB
    private $min_execution_time = 300; // Minimum recommended execution time in seconds

    public function __construct() {
        $this->debug = defined('WP_DEBUG') && WP_DEBUG;
        $this->cache_dir = WP_CONTENT_DIR . '/cache/webp-converter/';
        
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            file_put_contents($this->cache_dir . '.htaccess', 
                "Options -Indexes\nOrder allow,deny\nAllow from all\n" .
                "<FilesMatch \"\\.webp$\">\nOrder allow,deny\nAllow from all\n</FilesMatch>");
        }

        // Core functionality hooks
        add_action('init', [$this, 'setup_hooks']);
        add_action('template_redirect', [$this, 'maybe_handle_image_request'], 5);
        
        // Server environment check
        add_action('admin_init', [$this, 'check_server_environment']);
        
        // Output buffering for HTML modification
        if (!is_admin()) {
            add_action('template_redirect', [$this, 'start_html_processing'], 1);
            add_action('shutdown', [$this, 'end_html_processing'], 0);
        }

        if (is_admin()) {
            $this->init_admin_hooks();
        }
    }

    public function check_server_environment() {
        $memory_limit = $this->get_memory_limit_mb();
        $max_execution_time = ini_get('max_execution_time');
        
        if ($memory_limit < $this->min_memory_limit || 
            (intval($max_execution_time) > 0 && intval($max_execution_time) < $this->min_execution_time)) {
            add_action('admin_notices', function() use ($memory_limit, $max_execution_time) {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo 'WebP Converter Pro works best with optimized hosting environments. Your current setup:<br>';
                echo sprintf('Memory Limit: %dMB (Recommended: %dMB+)<br>', $memory_limit, $this->min_memory_limit);
                echo sprintf('Max Execution Time: %ds (Recommended: %ds+)<br>', $max_execution_time, $this->min_execution_time);
                echo 'For optimal performance, consider upgrading to: <br>';
                echo '- <a href="https://www.enginyring.com/en/webhosting" target="_blank">High-Performance WordPress Hosting</a><br>';
                echo '- <a href="https://www.enginyring.com/en/virtual-servers" target="_blank">Dedicated Virtual Servers</a>';
                echo '</p></div>';
            });
        }
    }

    private function get_memory_limit_mb() {
        $memory_limit = ini_get('memory_limit');
        $limit_unit = strtoupper(substr($memory_limit, -1));
        $limit_value = intval($memory_limit);
        
        switch ($limit_unit) {
            case 'G':
                return $limit_value * 1024;
            case 'M':
                return $limit_value;
            case 'K':
                return $limit_value / 1024;
            default:
                return $limit_value / (1024 * 1024);
        }
    }

    public function setup_hooks() {
        if (!session_id()) {
            @session_start();
        }
        $this->maybe_detect_webp_support();
    }

    private function maybe_detect_webp_support() {
        if (!isset($_SESSION['webp_support'])) {
            $_SESSION['webp_support'] = $this->client_accepts_webp();
        }
    }

    public function start_html_processing() {
        if ($this->should_process_page()) {
            ob_start([$this, 'process_html_content']);
        }
    }

    public function end_html_processing() {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private function should_process_page() {
        return !is_admin() && 
               !defined('DOING_AJAX') && 
               !defined('DOING_CRON') &&
               $this->client_accepts_webp();
    }

    public function process_html_content($html) {
        if (!preg_match('/<html[^>]*>/i', $html)) {
            return $html;
        }

        if ($this->debug) {
            $start_time = microtime(true);
        }

        $upload_dir = wp_upload_dir();
        $upload_url = preg_quote($upload_dir['baseurl'], '/');
        
        $processed_html = preg_replace_callback(
            '/<img([^>]+)src=([\'"])(' . $upload_url . '\/[^"\']+\.(jpe?g|png|gif))([\'"])([^>]*)>/i',
            [$this, 'process_image_tag'],
            $html
        );

        if ($this->debug) {
            $time_taken = microtime(true) - $start_time;
            error_log(sprintf(
                'WebP Converter: HTML processing completed in %.4f seconds',
                $time_taken
            ));
        }

        return $processed_html;
    }

    private function get_webp_url($original_url) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['baseurl'], '', $original_url);
        $cache_key = md5($relative_path);
        
        // Generate the correct cache URL
        $cache_url = content_url('/cache/webp-converter/' . $cache_key . '.webp');
        
        // Check if the cache file exists
        $cache_path = $this->cache_dir . $cache_key . '.webp';
        if (!file_exists($cache_path)) {
            return false;
        }

        return $cache_url;
    }

    private function process_image_tag($matches) {
        $before_src = $matches[1];
        $quote = $matches[2];
        $src = $matches[3];
        $after_src = $matches[6];

        $webp_url = $this->get_webp_url($src);
        if (!$webp_url) {
            return $matches[0];
        }

        return sprintf(
            '<picture><source srcset="%s" type="image/webp"><img%ssrc=%s%s%s%s></picture>',
            $webp_url,
            $before_src,
            $quote,
            $src,
            $quote,
            $after_src
        );
    }

    public function maybe_handle_image_request() {
        $request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        if (!preg_match('#^/wp-content/uploads/(.+)\.(jpe?g|png|gif)$#i', $request_uri, $matches)) {
            return;
        }

        $image_path = $matches[1] . '.' . $matches[2];
        $original_path = WP_CONTENT_DIR . '/uploads/' . $image_path;

        if (!file_exists($original_path)) {
            return;
        }

        if (!$this->client_accepts_webp()) {
            return;
        }

        $webp_path = $this->get_or_create_webp($original_path);
        if ($webp_path) {
            $this->serve_webp_image($webp_path, $original_path);
            exit;
        }
    }

    private function get_or_create_webp($original_path) {
        $relative_path = str_replace(WP_CONTENT_DIR . '/uploads/', '', $original_path);
        $cache_key = md5($relative_path);
        $webp_path = $this->cache_dir . $cache_key . '.webp';

        if (!file_exists($webp_path) || filemtime($webp_path) < filemtime($original_path)) {
            try {
                $this->convert_to_webp($original_path, $webp_path);
            } catch (Exception $e) {
                if ($this->debug) {
                    error_log('WebP conversion failed: ' . $e->getMessage());
                }
                return false;
            }
        }

        return file_exists($webp_path) ? $webp_path : false;
    }

    private function convert_to_webp($source_path, $destination_path) {
        $image_info = getimagesize($source_path);
        if ($image_info === false) {
            throw new Exception('Invalid image file');
        }

        // Create image resource based on type
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($source_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($source_path);
                imagepalettetotruecolor($source);
                imagealphablending($source, true);
                imagesavealpha($source, true);
                break;
            default:
                throw new Exception('Unsupported format');
        }

        if (!$source) {
            throw new Exception('Failed to create image resource');
        }

        // Calculate quality for WebP conversion
        $quality = $this->calculate_quality($source_path, $image_info[2]);

        // Convert to WebP
        $temp_path = $destination_path . '.tmp';
        $success = imagewebp($source, $temp_path, $quality);
        imagedestroy($source);

        if (!$success) {
            if (file_exists($temp_path)) {
                unlink($temp_path);
            }
            throw new Exception('WebP conversion failed');
        }

        // Verify the WebP file is smaller than the original
        if (filesize($temp_path) >= filesize($source_path)) {
            unlink($temp_path);
            throw new Exception('WebP file is larger than original');
        }

        // Move the temporary file to the final destination
        if (!rename($temp_path, $destination_path)) {
            unlink($temp_path);
            throw new Exception('Failed to save WebP file');
        }

        // Set proper permissions
        chmod($destination_path, 0644);
    }

    private function serve_webp_image($webp_path, $original_path) {
        $etag = '"' . md5_file($webp_path) . '"';
        
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && 
            trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            status_header(304);
            exit;
        }

        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($webp_path));
        header('Cache-Control: public, max-age=31536000');
        header('ETag: ' . $etag);
        header('Vary: Accept');
        
        readfile($webp_path);
        exit;
    }

    public function init_admin_hooks() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'check_gd_support']);
        add_action('wp_ajax_webp_bulk_convert', [$this, 'handle_bulk_conversion']);
        add_action('admin_footer', [$this, 'bulk_conversion_js']);
        add_action('admin_post_webp_reset_conversion', [$this, 'reset_conversion']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'webp-converter') === false) {
            return;
        }

        wp_enqueue_style('webp-converter-admin', plugins_url('assets/css/admin.css', __FILE__));
        wp_enqueue_script('webp-converter-admin', plugins_url('assets/js/admin.js', __FILE__), ['jquery'], '2.0.1', true);
    }

    public function check_gd_support() {
        if (!function_exists('imagewebp')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'WebP Converter Pro requires GD library with WebP support. ';
                echo 'We recommend using <a href="https://www.enginyring.com/en/webhosting" target="_blank">';
                echo 'optimized WordPress hosting</a> or a ';
                echo '<a href="https://www.enginyring.com/en/virtual-servers" target="_blank">';
                echo 'properly configured virtual server</a> for optimal performance.';
                echo '</p></div>';
            });
        }
    }

    public function admin_menu() {
        add_options_page(
            'WebP Settings',
            'WebP Converter',
            'manage_options',
            'webp-converter',
            [$this, 'settings_page']
        );
        
        add_submenu_page(
            null,
            'Bulk Conversion',
            'Bulk Convert',
            'manage_options',
            'webp-bulk-convert',
            [$this, 'bulk_page']
        );

        add_submenu_page(
            'webp-converter',
            'System Requirements',
            'System Requirements',
            'manage_options',
            'webp-system-requirements',
            [$this, 'system_requirements_page']
        );
    }

    public function system_requirements_page() {
        $memory_limit = $this->get_memory_limit_mb();
        $max_execution_time = ini_get('max_execution_time');
        ?>
        <div class="wrap">
            <h1>System Requirements</h1>
            
            <div class="card">
                <h2>Current Server Configuration</h2>
                <table class="widefat">
                    <tr>
                        <td>PHP Version</td>
                        <td><?php echo PHP_VERSION; ?></td>
                        <td><?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : '⚠️'; ?></td>
                    </tr>
                    <tr>
                        <td>Memory Limit</td>
                        <td><?php echo $memory_limit; ?>MB</td>
                        <td><?php echo $memory_limit >= $this->min_memory_limit ? '✅' : '⚠️'; ?></td>
                    </tr>
                    <tr>
                        <td>Max Execution Time</td>
                        <td><?php echo $max_execution_time; ?>s</td>
                        <td><?php echo $max_execution_time >= $this->min_execution_time ? '✅' : '⚠️'; ?></td>
                    </tr>
                    <tr>
                        <td>WebP Support</td>
                        <td><?php echo function_exists('imagewebp') ? 'Yes' : 'No'; ?></td>
                        <td><?php echo function_exists('imagewebp') ? '✅' : '⚠️'; ?></td>
                    </tr>
                </table>

                <?php if ($memory_limit < $this->min_memory_limit || 
                         $max_execution_time < $this->min_execution_time ||
                         !function_exists('imagewebp')): ?>
                    <div class="notice notice-warning inline">
                        <p>Your server configuration may not be optimal for image conversion.</p>
                        <p>We recommend:</p>
                        <ul>
                            <li><a href="https://www.enginyring.com/en/webhosting" target="_blank">
                                Optimized WordPress Hosting</a> - Preconfigured for optimal performance</li>
                            <li><a href="https://www.enginyring.com/en/virtual-servers" target="_blank">
                                Virtual Servers</a> - Full control over server configuration</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('webp_converter_options', 'webp_converter_quality');
        register_setting('webp_converter_options', 'webp_converter_threshold');
        register_setting('webp_converter_options', 'webp_delete_originals');
        
        add_option($this->conversion_option_name, [
            'processed' => 0,
            'total' => 0,
            'current_batch' => 0,
            'errors' => []
        ]);

        $this->default_quality = get_option('webp_converter_quality', 80);
        $this->size_threshold = get_option('webp_converter_threshold', 102400);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>WebP Converter Settings</h1>
            
            <div class="notice notice-info">
                <p>WebP Converter Pro works best with optimized hosting environments. 
                   For optimal performance, ensure your server meets our 
                   <a href="<?php echo admin_url('admin.php?page=webp-system-requirements'); ?>">system requirements</a>.</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('webp_converter_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Default Quality</th>
                        <td>
                            <input type="number" name="webp_converter_quality"
                                   value="<?php echo esc_attr(get_option('webp_converter_quality', 80)); ?>"
                                   min="1" max="100" required>
                            <p class="description">Higher values mean better quality but larger file size. Recommended: 70-85</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Size Threshold (KB)</th>
                        <td>
                            <input type="number" name="webp_converter_threshold"
                                   value="<?php echo esc_attr(round(get_option('webp_converter_threshold', 102400) / 1024)); ?>"
                                   min="100" required>
                            <p class="description">Files larger than this will use higher compression</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h2>Bulk Conversion</h2>
                <p>Convert all existing images to WebP format in batches.</p>
                <a href="<?php echo admin_url('admin.php?page=webp-bulk-convert'); ?>" 
                   class="button button-primary">
                    Start Bulk Conversion
                </a>
            </div>

            <?php if ($this->debug): ?>
            <div class="card">
                <h2>Debug Information</h2>
                <p>Cache Directory: <?php echo esc_html($this->cache_dir); ?></p>
                <p>Memory Limit: <?php echo $this->get_memory_limit_mb(); ?>MB</p>
                <p>Max Execution Time: <?php echo ini_get('max_execution_time'); ?>s</p>
                <p>GD Version: <?php echo function_exists('gd_info') ? gd_info()['GD Version'] : 'Not Available'; ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function bulk_page() {
        ?>
        <div class="wrap">
            <h1>Bulk WebP Conversion</h1>
            
            <?php if ($this->get_memory_limit_mb() < $this->min_memory_limit): ?>
            <div class="notice notice-warning">
                <p>Your server's memory limit may be too low for bulk conversion. 
                   Consider upgrading to <a href="https://www.enginyring.com/en/webhosting" target="_blank">
                   optimized WordPress hosting</a> for better performance.</p>
            </div>
            <?php endif; ?>
            
            <div id="webp-conversion-progress" style="display:none;">
                <div class="progress-bar">
                    <div class="progress"></div>
                </div>
                <p class="status">Preparing conversion...</p>
                <p class="errors"></p>
            </div>
            
            <form method="post" id="webp-bulk-start">
                <p>
                    <label>Batch Size:
                        <select name="batch_size">
                            <?php foreach ($this->batch_sizes as $size): ?>
                                <option value="<?php echo esc_attr($size); ?>">
                                    <?php echo esc_html($size); ?> images per batch
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="description">Larger batch sizes are faster but may timeout on some servers</p>
                </p>
                
                <p>
                    <label>
                        <input type="checkbox" name="delete_originals">
                        Delete original files after successful conversion
                    </label>
                    <p class="description">Warning: This will permanently delete original images</p>
                </p>
                
                <?php wp_nonce_field('webp_bulk_action', 'webp_bulk_nonce'); ?>
                <?php submit_button('Start Conversion', 'primary', 'start-conversion'); ?>
            </form>
        </div>

        <style>
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #f1f1f1;
            border-radius: 3px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress {
            width: 0;
            height: 100%;
            background: #2271b1;
            transition: width 0.3s ease;
        }
        .errors {
            color: #d63638;
            margin-top: 15px;
        }
        </style>
        <?php
    }

    public function bulk_conversion_js() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'webp-bulk-convert') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#webp-bulk-start').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $progress = $('#webp-conversion-progress');
            var $status = $progress.find('.status');
            var $errors = $progress.find('.errors');
            
            $form.find('input,select').prop('disabled', true);
            $progress.show();
            $status.html('Preparing conversion...');
            $errors.html('');
            
            var data = {
                action: 'webp_bulk_convert',
                security: $('[name="webp_bulk_nonce"]').val(),
                batch_size: $('[name="batch_size"]').val(),
                delete_originals: $('[name="delete_originals"]').prop('checked') ? 1 : 0
            };

            function processBatch() {
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        var progress = response.data.progress;
                        var percentage = response.data.percentage.toFixed(1);
                        
                        $('.progress').css('width', percentage + '%');
                        $status.html(
                            `Processed ${progress.processed} of ${progress.total} images (Batch ${progress.current_batch})`
                        );
                        
                        if (progress.errors.length) {
                            $errors.html(
                                'Errors:<br>' + progress.errors.join('<br>')
                            );
                        }
                        
                        if (progress.processed < progress.total) {
                            setTimeout(processBatch, 1000);
                        } else {
                            $status.append('<br><strong>Conversion complete!</strong>');
                            $form.find('input,select').prop('disabled', false);
                        }
                    } else {
                        handleError(response.data);
                    }
                }).fail(function(xhr) {
                    handleError(xhr.responseJSON ? xhr.responseJSON.data : 'Server error: ' + xhr.statusText);
                });
            }

            function handleError(error) {
                var errorMessage = 'An error occurred during conversion.';
                
                if (typeof error === 'string') {
                    errorMessage = error;
                } else if (error && error.message) {
                    errorMessage = error.message;
                } else if (error && typeof error === 'object') {
                    errorMessage = JSON.stringify(error);
                }

                $errors.html('Error: ' + errorMessage);
                $form.find('input,select').prop('disabled', false);
                
                if (errorMessage.includes('timeout') || errorMessage.includes('memory')) {
                    $errors.append('<br><br>Consider upgrading to ' +
                        '<a href="https://www.enginyring.com/en/webhosting" target="_blank">' +
                        'optimized WordPress hosting</a> for better performance.');
                }
            }
            
            processBatch();
        });
    });
    </script>
    <?php
}

public function handle_bulk_conversion() {
    check_ajax_referer('webp_bulk_action', 'security');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    $batch_size = absint($_POST['batch_size'] ?? 20);
    $delete_originals = isset($_POST['delete_originals']);
    $progress = get_option($this->conversion_option_name);

    // Initialize progress if empty or reset
    if (empty($progress) || $progress['total'] === 0) {
        $attachments = new WP_Query([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'], // Only JPEG and PNG
            'post_status' => 'inherit', // Ensure attachments are published
            'posts_per_page' => -1, // Get all images
            'fields' => 'ids', // Only get attachment IDs
            'no_found_rows' => true, // Skip counting total rows for performance
        ]);
        
        if (!$attachments->have_posts()) {
            wp_send_json_error([
                'message' => 'No images found for conversion.',
                'details' => 'Please upload some JPEG or PNG images to the media library.'
            ]);
        }
        
        $progress = [
            'total' => $attachments->post_count,
            'processed' => 0,
            'current_batch' => 0,
            'errors' => []
        ];
    }

    // Get the next batch of images
    $attachments = new WP_Query([
        'post_type' => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png'],
        'post_status' => 'inherit',
        'posts_per_page' => $batch_size,
        'offset' => $progress['processed'],
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);

    foreach ($attachments->posts as $attachment_id) {
        try {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path) {
                throw new Exception('Unable to get file path for attachment ID: ' . $attachment_id);
            }

            if (!file_exists($file_path) || !is_readable($file_path)) {
                throw new Exception('File not accessible: ' . basename($file_path));
            }

            $cache_file = $this->cache_dir . md5(basename($file_path)) . '.webp';
            
            if ($this->get_memory_limit_mb() < $this->min_memory_limit) {
                throw new Exception(
                    'Insufficient memory for conversion. Consider upgrading to ' .
                    '<a href="https://www.enginyring.com/en/webhosting" target="_blank">optimized hosting</a>.'
                );
            }

            if (!file_exists($cache_file)) {
                $this->convert_to_webp($file_path, $cache_file);
            }
            
            if ($delete_originals && file_exists($cache_file)) {
                $this->handle_original_deletion($attachment_id, $file_path, $cache_file);
            }
            
            $progress['processed']++;
        } catch (Exception $e) {
            $progress['errors'][] = sprintf(
                'Error converting %s: %s',
                basename($file_path),
                $e->getMessage()
            );
            
            if ($this->debug) {
                error_log('WebP Converter Error: ' . $e->getMessage());
            }
        }
    }

    $progress['current_batch']++;
    update_option($this->conversion_option_name, $progress);

    $percentage = 0;
    if ($progress['total'] > 0) {
        $percentage = ($progress['processed'] / $progress['total']) * 100;
    }

    wp_send_json_success([
        'progress' => $progress,
        'percentage' => $percentage,
        'remaining' => $progress['total'] - $progress['processed']
    ]);
}
	private function calculate_quality($source_path, $image_type) {
    // Default quality for JPEG images
    $quality = $this->default_quality;

    // Higher quality for PNG images to preserve transparency
    if ($image_type === IMAGETYPE_PNG) {
        $quality = $this->high_compression_quality;
    }

    // Adjust quality for large files
    $file_size = filesize($source_path);
    if ($file_size > $this->size_threshold) {
        $quality = $this->high_compression_quality;
    }

    return $quality;
	}
	
    private function handle_original_deletion($attachment_id, $original_path, $webp_path) {
        if (!file_exists($webp_path)) {
            throw new Exception('WebP version not found before deletion attempt');
        }

        if (filesize($webp_path) === 0) {
            throw new Exception('WebP file is empty');
        }

        if (unlink($original_path)) {
            update_attached_file($attachment_id, $webp_path);
            $relative_path = str_replace(WP_CONTENT_DIR . '/uploads/', '', $webp_path);
            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
            
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $data) {
                    $size_file = path_join(dirname($original_path), $data['file']);
                    if (file_exists($size_file)) {
                        unlink($size_file);
                    }
                }
            }
        } else {
            throw new Exception('Failed to delete original file');
        }
    }

    private function client_accepts_webp() {
        if (isset($_SESSION['webp_support'])) {
            return $_SESSION['webp_support'];
        }

        $accepts_webp = false;
        
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accepts_webp = strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!$accepts_webp && !empty($user_agent)) {
            if (preg_match('/Chrome\/([0-9]+)/', $user_agent, $matches)) {
                $accepts_webp = intval($matches[1]) >= 32;
            } elseif (preg_match('/Opera\/([0-9]+\.[0-9]+)/', $user_agent, $matches)) {
                $accepts_webp = intval($matches[1]) >= 19;
            } elseif (preg_match('/Android [0-9]+\.[0-9]+/', $user_agent)) {
                $accepts_webp = version_compare(substr($user_agent, 8, 3), '4.2', '>=');
            }
        }

        if (session_id()) {
            $_SESSION['webp_support'] = $accepts_webp;
        }

        if ($this->debug) {
            error_log(sprintf(
                'WebP Support Check: %s (Accept: %s, UA: %s)',
                $accepts_webp ? 'Yes' : 'No',
                $_SERVER['HTTP_ACCEPT'] ?? 'not set',
                $user_agent
            ));
        }

        return $accepts_webp;
    }

    public function reset_conversion() {
        check_admin_referer('webp_reset_action', 'webp_reset_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        delete_option($this->conversion_option_name);
        wp_redirect(admin_url('admin.php?page=webp-bulk-convert'));
        exit;
    }

    private function render_hosting_recommendations() {
        $memory_limit = $this->get_memory_limit_mb();
        $max_execution_time = ini_get('max_execution_time');
        
        if ($memory_limit < $this->min_memory_limit || 
            $max_execution_time < $this->min_execution_time) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Optimize Your WebP Conversion:</strong></p>
                <p>Your current server configuration may limit conversion performance:</p>
                <ul>
                    <?php if ($memory_limit < $this->min_memory_limit): ?>
                        <li>Memory Limit: <?php echo $memory_limit; ?>MB 
                            (Recommended: <?php echo $this->min_memory_limit; ?>MB+)</li>
                    <?php endif; ?>
                    <?php if ($max_execution_time < $this->min_execution_time): ?>
                        <li>Max Execution Time: <?php echo $max_execution_time; ?>s 
                            (Recommended: <?php echo $this->min_execution_time; ?>s+)</li>
                    <?php endif; ?>
                </ul>
                <p>Recommended solutions:</p>
                <ul>
                    <li><a href="https://www.enginyring.com/en/webhosting" target="_blank">
                        Optimized WordPress Hosting</a> - Preconfigured for optimal performance</li>
                    <li><a href="https://www.enginyring.com/en/virtual-servers" target="_blank">
                        Virtual Servers</a> - Full control over server configuration</li>
                </ul>
            </div>
            <?php
        }
    }
}

// Initialize plugin
new WebP_Converter_Pro();

// Activation hook
register_activation_hook(__FILE__, function() {
    add_option('webp_converter_quality', 80);
    add_option('webp_converter_threshold', 102400);
    add_option('webp_converter_flush_needed', '1');

    $cache_dir = WP_CONTENT_DIR . '/cache/webp-converter/';
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    delete_option('webp_converter_quality');
    delete_option('webp_converter_threshold');
    delete_option('webp_converter_flush_needed');
    flush_rewrite_rules();
});
