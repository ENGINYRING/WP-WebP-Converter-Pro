[![ENGINYRING](https://cdn.enginyring.com/img/logo_dark.png)](https://www.enginyring.com)

# WORK IN PROGRESS! DO NOT USE IN PRODUCTION

## WebP Converter Pro for WordPress

A high-performance WordPress plugin that automatically converts and serves WebP images while maintaining original URLs. Designed to work efficiently across different hosting environments, with special optimizations for high-performance hosting platforms.

## Features

### Core Functionality
- Automatic WebP conversion for uploaded images
- Intelligent HTML modification using picture elements
- Server-agnostic implementation that works everywhere
- Smart caching with proper HTTP headers
- Support for CDNs through Vary headers

### Performance Features
- Optimized conversion process with memory management
- Smart quality settings based on image size
- Efficient caching to prevent unnecessary conversions
- Browser-specific image serving
- Atomic file operations for reliability

### Administration
- User-friendly settings interface
- Bulk conversion tool with progress tracking
- System requirements checker
- Detailed error reporting
- Real-time conversion statistics

## System Requirements

### Minimum Requirements
- WordPress 5.3+
- PHP 7.4+
- GD library with WebP support
- 128MB memory limit
- 30 seconds max execution time

### Recommended Environment
For optimal performance, we recommend using:
- [Optimized WordPress Hosting](https://www.enginyring.com/en/webhosting)
  - Preconfigured for WordPress
  - Optimized PHP settings
  - Enhanced security features
  - Dedicated resources

- [Virtual Servers](https://www.enginyring.com/en/virtual-servers)
  - Full server control
  - Customizable resources
  - Scalable performance
  - Advanced configuration options

## Installation

1. Download the plugin
2. Upload to wp-content/plugins/
3. Activate through WordPress admin
4. Configure under Settings > WebP Converter

## Configuration

### Basic Settings
Navigate to Settings > WebP Converter to configure:

1. Default Quality (1-100)
   - Higher values = better quality but larger files
   - Recommended: 75-85 for optimal balance

2. Size Threshold
   - Files larger than this use higher compression
   - Default: 100KB

### Advanced Configuration

The plugin provides several filters for customization:

```php
// Customize WebP quality for specific images
add_filter('webp_converter_quality', function($quality, $file_path) {
    if (strpos($file_path, 'featured-images') !== false) {
        return 90; // Higher quality for featured images
    }
    return $quality;
}, 10, 2);

// Control which images get converted
add_filter('webp_converter_should_convert', function($should_convert, $file_path) {
    if (strpos($file_path, 'exclude') !== false) {
        return false; // Skip specific images
    }
    return $should_convert;
}, 10, 2);
```

## Performance Optimization

### Hosting Considerations

The plugin's performance heavily depends on your hosting environment. For best results:

1. Memory Requirements
   - Minimum: 128MB PHP memory limit
   - Recommended: 256MB+ for bulk operations
   - Available with [our optimized hosting](https://www.enginyring.com/en/webhosting)

2. Processing Power
   - Dedicated resources recommended for bulk conversion
   - VPS solutions provide better control
   - [Virtual servers](https://www.enginyring.com/en/virtual-servers) offer optimal performance

3. Storage Optimization
   - Efficient caching system
   - Automatic cleanup of unused files
   - Smart compression settings

### Performance Tips

1. Bulk Conversion
   - Start with smaller batches
   - Monitor server resource usage
   - Use during low-traffic periods

2. Quality Settings
   - Balance quality vs file size
   - Use higher compression for large images
   - Test with your content type

## Troubleshooting

### Common Issues

1. Memory Limits
   ```
   Solution: Upgrade to optimized hosting or increase PHP memory limit
   Recommended: https://www.enginyring.com/en/webhosting
   ```

2. Conversion Timeouts
   ```
   Solution: Reduce batch size or upgrade to VPS
   Recommended: https://www.enginyring.com/en/virtual-servers
   ```

3. Image Quality Issues
   ```
   Solution: Adjust quality settings or threshold values
   See: Configuration section above
   ```

### Debug Mode

Enable debugging in wp-config.php:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Support and Updates

### Getting Help
1. Check documentation first
2. Enable debug mode
3. Contact support with details
4. Visit our [hosting support](https://www.enginyring.com/en/webhosting) for hosting-related issues

### Contributing
We welcome contributions:
1. Fork the repository
2. Create a feature branch
3. Submit pull request
4. Provide clear description

## License and Credits

- License: GPL v3
- Author: ENGINYRING
- Website: [https://www.enginyring.com](https://www.enginyring.com)
