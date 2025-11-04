<?php

/**
 * Base helper for rewriting WordPress image URLs at runtime.
 *
 * Responsibilities
 *  - Replaces core attachment URLs (`wp_get_attachment_image_src`, `wp_calculate_image_srcset`, `wp_get_attachment_url`).
 *  - Optionally rewrites inline HTML and background-image CSS by buffering shutdown output.
 *  - Provides shared image validation, manipulation helpers, and a contract for concrete optimizers.
 *
 * Filter / Action Reference
 *  - `imagehooks_image_processing_settings`      : filter default settings array before hooks register.
 *  - `imagehooks_image_processing_enabled`   : return false to short-circuit initialization (receives settings).
 *  - `imagehooks_image_resize_shutdown_html` : filter final buffered HTML before it is printed.
 */

declare(strict_types=1);

namespace WordpressImageHooks;

use DomNodeList;
use function add_action;
use function add_filter;
use function apply_filters;
use function home_url;
use function is_admin;
use function is_plugin_active;
use function wp_doing_ajax;
use function wp_doing_cron;

abstract class WordpressImageHooks
{
    private static $instance;
    private $settings = [];
    private $hooksRegistered = false;

    abstract protected function createImage(string $url, array $settings): WordpressImageHooksImage;

    public static function instance(): ?static
    {
        return static::$instance;
    }

    protected function bootstrapHooks(): void
    {

        self::$instance = $this;

        if ($this->hooksRegistered) {
            return;
        }

        if (!$this->isContextValid()) {
            return;
        }

        $settings = $this->settings();

        $this->hooksRegistered = true;
        // Image replacement hooks
        if ($settings['hook_wp_get_attachment_image_src']) {
            add_filter('wp_get_attachment_image_src', [$this, 'filter_get_attachment_image_src'], PHP_INT_MAX, 4);
        }
        if ($settings['hook_wp_calculate_image_srcset']) {
            add_filter('wp_calculate_image_srcset', [$this, 'filter_calculate_image_srcset'], PHP_INT_MAX, 4);
        }
        if ($settings['hook_wp_get_attachment_url']) {
            add_filter('wp_get_attachment_url', [$this, 'filter_get_attachment_url'], PHP_INT_MAX, 2);
        }
        if ($settings['hook_html'] || $settings['hook_html_background_css']) {
            if (
                is_admin()
                || wp_doing_ajax()
                || stripos($_SERVER['REQUEST_URI'], '/wp-json/') === 0
                || (defined('REST_REQUEST') && REST_REQUEST === true)
            ) {
                return;
            }

            // Full output buffering - https://stackoverflow.com/questions/772510/wordpress-filter-to-modify-final-html-output
            $bufferLevel = ob_get_level();
            ob_start();
            add_action('shutdown', function () use ($bufferLevel) {
                $chunks = [];

                try {
                    while (ob_get_level() > $bufferLevel) {
                        $chunk = ob_get_clean();
                        if ($chunk === false) {
                            break;
                        }
                        array_unshift($chunks, $chunk);
                    }
                } catch (\Throwable $e) {
                    $this->log(sprintf('%s - %s', 'shutdown_buffer_cleanup', $e->getMessage()));
                }

                $final = implode('', $chunks);

                try {
                    $final = apply_filters('imagehooks_image_resize_shutdown_html', $final);
                } catch (\Throwable $e) {
                    $this->log(sprintf('%s - %s', 'shutdown_buffer_filter', $e->getMessage()));
                }

                echo $final;
            }, PHP_INT_MIN); // this priority has to be low

            add_filter('imagehooks_image_resize_shutdown_html', function ($content) use ($settings) {
                if (empty($content)) {
                    return $content;
                }

                if ($settings['hook_html']) {
                    try {
                        $content = $this->filter_html($content);
                    } catch (\Throwable $e) {
                        $this->log(sprintf('%s - %s', 'hook_html', $e->getMessage()));
                    }
                }

                if ($settings['hook_html_background_css']) {
                    try {
                        $content = $this->filter_html_background_css($content);
                    } catch (\Throwable $e) {
                        $this->log(sprintf('%s - %s', 'hook_html_background_css', $e->getMessage()));
                    }
                }
                return $content;
            }, PHP_INT_MAX, 1);
        }
    }

    public function settings(): array
    {
        if (!empty($this->settings)) {
            return $this->settings;
        }

        $settings = [
            'enabled' => true,
            'site_url' => home_url(),
            'site_aliases' => [],
            'site_folder' => '',
            'site_dir' => ABSPATH,
            'image_style' => 'full',
            'fit' => 'cover',
            'gravity' => '',
            'quality' => 50,
            'sharpen' => 0,
            'format' => 'auto',
            'onerror' => 'redirect',
            'metadata' => 'none',
            'max_width' => 1920,
            'image_types' => [
                'jpg',
                'jpeg',
                'gif',
                'png',
                'webp',
                // 'svg',
            ],
            'hook_wp_get_attachment_image_src' => true,
            'hook_wp_calculate_image_srcset' => true,
            'hook_wp_get_attachment_url' => true,
            'hook_html' => true,
            'hook_html_background_css' => true,
            // 'hook_css' => true,
        ];

        // if (defined('CF_IMAGE_RESIZE_SETTINGS') && is_array(CF_IMAGE_RESIZE_SETTINGS)) {
        //     $filtered = array_filter(CF_IMAGE_RESIZE_SETTINGS, function ($key) use ($settings) {
        //         return array_key_exists($key, $settings);
        //     }, ARRAY_FILTER_USE_KEY);
        //     $settings = array_replace_recursive($settings, $filtered);
        // }

        $this->settings = $this->hookSettings(apply_filters('imagehooks_image_processing_settings', $settings));
        return $this->settings;
    }

    protected function setting(string $key, $fallback = null): mixed
    {
        $settings = $this->settings();
        return (isset($settings[$key])) ? $settings[$key] : $fallback;
    }

    protected function hookSettings(array $settings): array
    {
        return $settings;
    }

    protected function log(string $message): void
    {
        error_log(sprintf('[%s]: %s', $_SERVER['REQUEST_URI'], $message));
    }

    /*
     * Check if we are in a valid context to run
     * @return bool
     */
    protected function isContextValid(): bool
    {
        if (is_admin()) {
            return false;
        }

        if (
            wp_doing_cron()
            || (defined('WP_CLI') && \WP_CLI)
            || PHP_SAPI === 'cli'
        ) {
            return false;
        }

        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }

        // Check if cf-image-resizing.php plugin is activated
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('is_plugin_active') && is_plugin_active('cf-image-resizing/cf-image-resizing.php')) {
            add_action('admin_notices', function () {
                echo <<<'HTML'
                <div class="notice notice-error">
                    <p><strong>WordPress Image Hooks:</strong> The plugin <code>cf-image-resizing/cf-image-resizing.php</code> is activated and providing image resizing. Please disable this plugin.</p>
                </div>
                HTML;
            });
            return false;
        }

        $settings = $this->settings();
        if (!apply_filters('imagehooks_image_processing_enabled', true, $settings)) {
            return false;
        }
        if (!isset($settings['enabled']) || $settings['enabled'] !== true) {
            return false;
        }

        return true;
    }


    /** --------------- Hooks --------------- */

    public function filter_get_attachment_image_src($image, $attachment_id, $size, $icon)
    {
        // No image, there is nothing to do here.
        if (!isset($image[0])) {
            return $image;
        }

        // Many times the hook filter_get_attachment_url has ran before this, but it can only determine size based on the full image
        // We need to see if we are using at a smaller size than originally determined by filter_get_attachment_url
        // so we need to skip any check of the image already being optimized


        // $image_path = $this->getSourceImagePath($image[0]);
        try {
            $image[0] = $this->hookImageUri($image[0], $image[1], $image[2], 'image_src');
        } catch (\Throwable $e) {
            $this->log(sprintf('%s (%s, %d) - %s', __METHOD__, $image[0], $attachment_id, $e->getMessage()));
        }
        return $image;
    }

    public function filter_calculate_image_srcset($size_array, $image_src, $image_meta, $attachment_id)
    {
        foreach ($size_array as $key => $value) {
            try {
                $size_array[$key]['url'] = $this->hookImageUri($value['url'], $key, 0, 'srcset');
            } catch (\Throwable $e) {
                $this->log(sprintf('%s (%s, %d) - %s', __METHOD__, $image_src, $attachment_id, $e->getMessage()));
                continue;
            }
        }
        return $size_array;
    }

    public function filter_get_attachment_url($url, $post_id)
    {
        try {
            $url = $this->hookImageUri($url, null, null, 'attachment_url');
        } catch (\Throwable $e) {
            $this->log(sprintf('%s (%s, %d) - %s', __METHOD__, $url, $post_id, $e->getMessage()));
        }
        return $url;
    }

    public function filter_html_background_css(string $html): string
    {
        $regex = '/(background(?:-image)?\s?:?\s*url\s*\(\s*[\'"]?(.*?)[\'"]?\s*\))/i';

        if (preg_match_all($regex, $html, $matches)) {
            $imageUrls = array_combine($matches[1], $matches[2]);
            $imageUrls = array_filter($imageUrls); // Remove empty values
        }

        if (empty($imageUrls)) {
            return $html;
        }

        $optimize = [];
        foreach ($imageUrls as $match => $image) {
            $optimize[$match] = str_replace($image, $this->hookImageUri($image, null, null, 'regex'), $match);
        }

        foreach ($optimize as $old => $new) {
            $html = str_replace($old, $new, $html);
        }

        return $html;
    }

    public function filter_html(string $html): string
    {
        $dom = new \DOMDocument();

        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        if (!$loaded) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);

        $imageChanges = $this->domElementResize($xpath->query('//img'), 'src');
        $videoChanges = $this->domElementResize($xpath->query('//video'), 'poster');

        if (!$imageChanges && !$videoChanges) {
            return $html;
        }

        $newHtml = $dom->saveHTML();
        return ($newHtml === false) ? $html : $newHtml;
    }

    protected function domElementResize(DomNodeList $elements, string $src_name): bool
    {
        $changed = false;
        if ($elements->length === 0) {
            return $changed;
        }

        foreach ($elements as $element) {
            /** @var \DOMElement $element */
            $src = $element->getAttribute($src_name);
            $width = $element->getAttribute('width');
            $height = $element->getAttribute('height');

            if (empty($src)) {
                continue;
            }

            // dont re-optimize already optimized images, this is to catch any images that were optimized by other filters
            $image = $this->createImageInstance($src);
            if ($image === null || $image->isOptimizedImage()) {
                continue;
            }

            if (is_numeric($width) && is_numeric($height)) {
                $cfurl = $this->hookImageUri($src, (int) $width, (int) $height, 'dom');
            } elseif (is_numeric($width)) {
                $cfurl = $this->hookImageUri($src, (int) $width, null, 'dom');
            } else {
                $cfurl = $this->hookImageUri($src, null, null, 'dom');
            }
            $element->setAttribute($src_name, $cfurl);
            $changed = true;


            // handle img srcset
            $srcset = $element->getAttribute('srcset');
            if ($src_name === 'src' && !empty($srcset)) {
                $sources = explode('w,', $srcset);
                if (!empty($sources)) {
                    $sources = array_map('trim', $sources);
                    $srcset_elements = [];
                    foreach ($sources as $source) {
                        if (strpos($source, ' ') === false) {
                            break;
                        }
                        list($srcset_path, $srcset_width) = explode(' ', trim($source));
                        if (!is_numeric($srcset_width)) {
                            break;
                        }
                        $srcset_cf_path = $this->hookImageUri($srcset_path, (int) trim($srcset_width), null, 'dom');
                        $srcset_elements[] = $srcset_cf_path . ' ' . $srcset_width . 'w';
                    }
                    $srcset_cf = implode(', ', $srcset_elements);
                    $element->setAttribute('srcset', $srcset_cf);
                }
            }
        }
        return $changed;
    }

    private function createImageInstance(string $src): ?WordpressImageHooksImage
    {
        try {
            $image = $this->createImage($src, $this->settings());
        } catch (\InvalidArgumentException $e) {
            // not a valid image
            // $this->log(sprintf('%s (%s) - %s', __METHOD__, $src, $e->getMessage()));
            return null;
        } catch (\Throwable $e) {
            // other error
            $this->log(sprintf('%s (%s) - %s', __METHOD__, $src, $e->getMessage()));
            return null;
        }
        return $image;
    }

    public function hookImageUri(string $image_path, $width = 0, $height = 0, ?string $ref = '', array $manipulations = []): string
    {
        $width = is_numeric($width) ? (int) floor($width) : 0;
        $height = is_numeric($height) ? (int) floor($height) : 0;

        $image = $this->createImageInstance($image_path);
        if ($image === null) {
            return $image_path;
        }

        $image_path = $image->getRelativePath();
        if (empty($image_path)) {
            return $image_path;
        }

        // build manipulations
        $manipulations = array_merge([
            'ref' => $ref,
        ], $manipulations);

        // add width and height
        if (!empty($width) && !empty($height)) {
            // provided width and height
            $sizes = [$width, $height];
            // $settings['fit'] = $this->setting('fit');
        } elseif (!empty($width) && empty($height)) {
            // find width and height from image
            $ogsizes = $image->getImageDimensions();
            $ratio = $ogsizes[0] / $ogsizes[1];
            $sizes = [$width, round($width / $ratio)];
            // $settings['fit'] = $this->setting('fit');
        } else {
            // find width and height from image
            $sizes = $image->getImageDimensions();
            // $settings['fit'] = $this->setting('fit');
        }

        if (!empty($sizes[0])) {
            $manipulations['width'] = $sizes[0];
        }
        if (!empty($sizes[1])) {
            $manipulations['height'] = $sizes[1];
        }

        // set width and height of a max width
        $max_width = $this->setting('max_width');
        if (!empty($manipulations['width']) && $manipulations['width'] > $max_width) {
            if ($manipulations['width'] && $manipulations['height']) {
                $ratio = $max_width / $manipulations['width'];
                $manipulations['width'] = $max_width;
                $manipulations['height'] = round($manipulations['height'] * $ratio);
            } else {
                $manipulations['width'] = $max_width;
                // unset($settings['height']);
            }
        }

        // cache
        static $cache = [];
        $cacheKey = $image->cacheBuildKey($manipulations);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // optimize uri
        $optimized_url = $image->imageOptimize($manipulations);
        // d($source_image_path, $optimized_uri);
        if (filter_var($optimized_url, FILTER_VALIDATE_URL) === false && !is_file(ABSPATH . ltrim($optimized_url, '/'))) {
            return $image_path;
        }
        $cache[$cacheKey] = $optimized_url;
        return $optimized_url;
    }

    // public function hook_template_redirect()
    // {
    //     $cssRequest = get_query_var('css_request');
    //     if (empty($cssRequest)) {
    //         return;
    //     }

    //     // Security check to prevent directory traversal attacks
    //     if (strpos($cssRequest, '..') !== false) {
    //         // Bad request
    //         status_header(400);
    //         exit;
    //     }

    //     // Optional: Verify the request is for a valid CSS file within your theme or plugin directory
    //     $cssFilePath = get_stylesheet_directory() . '/' . $cssRequest; // Example for theme
    //     // $cssFilePath = WP_PLUGIN_DIR . '/your-plugin-name/' . $cssRequest; // Example for plugin

    //     if (file_exists($cssFilePath)) {
    //         // Process the CSS file
    //         $cssContent = file_get_contents($cssFilePath);
    //         // Perform your replacements here
    //         $cssContent = $this->hook_html_background_css($cssContent);

    //         // Set the correct content type
    //         header('Content-Type: text/css');
    //         echo $cssContent;
    //         exit;
    //     } else {
    //         // File not found
    //         status_header(404);
    //         exit;
    //     }
    // }
}
