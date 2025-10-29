<?php

/*
 * Plugin Name:       WordPress Image Hooks
 * Version:           2025.10.06.15.07.14
 * Plugin URI:        https://github.org/midweste/wp-image-hooks
 * Description:       Hook library for allowing external plugins to rewrite the image src of images, srcsets, and other images.
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-image-hooks
 * Update URI:        https://api.github.com/repos/midweste/wp-image-hooks/commits/main
 * License:           MIT
 * Requires:          wp-image-hooks
 */

use WordpressImageHooks\WordpressImageHooks;

/**
 * Get the WordpressImageHooks instance
 *
 * @return WordpressImageHooks
 */
function wp_imagehooks(): WordpressImageHooks
{
    return WordpressImageHooks::instance();
}

/**
 * Get Image Resizer URI from an image path
 *
 * @param string $image_path
 * @param integer|null $width
 * @param integer|null $height
 * @param string|null $ref
 * @return string
 */
function wp_imagehooks_image_resizer_uri(string $image_path, ?int $width = 0, ?int $height = 0, ?string $ref = '', ?array $settings = []): string
{
    return wp_imagehooks()->hookImageUri($image_path, $width, $height, $ref, $settings);
}

/**
 * Get Image Resizer URI from an attachment ID
 *
 * @param integer $attachment_id
 * @param integer|null $width
 * @param integer|null $height
 * @param string|null $ref
 * @return string
 */
function wp_imagehooks_image_resizer_uri_by_id(int $attachment_id, ?int $width = 0, ?int $height = 0, ?string $ref = '', ?array $settings = []): string
{
    $image = wp_get_attachment_image_src($attachment_id, 'full');
    if (empty($image)) {
        return '';
    }
    return wp_imagehooks_image_resizer_uri($image[0], $width, $height, $ref, $settings);
}

// is_readable(__DIR__ . '/vendor/autoload.php') && require_once __DIR__ . '/vendor/autoload.php';
