# Wordpress Image Hooks

Hook library for allowing external plugins to rewrite the image src of images, srcsets, and other images. Designed to be a helper library for Cloudflare Image Resizer and Glide.

WordpressImageHooks handles the logic for hooking into WordPress functions and WordpressImageHooksImage handles the logic for rewriting the image src.

## To Install

composer require midweste/wp-lib-image-hooks

## Implementation

Extend WordpressImageHooks and WordpressImageHooksImage to implement your own rewriting logic.

## Configuration

By default, the settings are below and available via filter **imagehooks_image_resize_settings**.

```
add_filter('imagehooks_image_resize_settings', function ($settings) {
    $settings['enabled'] = true;
    $settings['site_url'] = home_url();
    $settings['site_folder'] = '';
    $settings['site_dir'] = ABSPATH;
    $settings['image_style'] = 'full';
    $settings['fit'] = 'cover';
    $settings['gravity'] = '';
    $settings['quality'] = 80;
    $settings['sharpen'] = 0;
    $settings['format'] = 'auto';
    $settings['onerror'] = 'redirect';
    $settings['metadata'] = 'none';
    $settings['max_width'] = 1920;
    $settings['image_types'] = [
        'jpg',
        'jpeg',
        'gif',
        'png',
        'webp',
        'svg',
    ];
    $settings['hook_wp_get_attachment_image_src'] = true;
    $settings['hook_wp_calculate_image_srcset'] = true;
    $settings['hook_wp_get_attachment_url'] = true;
    $settings['hook_html'] = true;
    $settings['hook_html_background_css'] = true;
});
```

## Filters

apply_filters('imagehooks_image_resize_settings', $settings)

Array. This filter allows you to change default settings.

apply_filters('imagehooks_image_processing_enabled', true)

Boolean. This filter will allow you to add custom logic to exclude or prevent image src rewriting.
