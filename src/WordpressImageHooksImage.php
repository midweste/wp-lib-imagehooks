<?php


declare(strict_types=1);

namespace WordpressImageHooks;

use function wp_getimagesize;
use function wp_json_encode;

abstract class WordpressImageHooksImage
{

    abstract public function imageOptimize(array $manipulations = []): string;
    abstract public function isOptimizedImage(): bool;
    abstract public function imageStripOptimization(): string;

    protected array $settings = [];
    protected string $originalSrc = '';
    protected string $relativePath = '';
    protected string $sitePath = '';
    // protected array $localAliases = [];
    protected int $maxWidth = 1920;

    public function __construct(string $image_source, array $settings)
    {
        $this->setSettings($settings);
        // checks occur in setSrc so settings need to be established before src is set
        $this->setSrc($image_source);
    }

    public static function create(string $image_source, array $settings): static
    {
        return new static($image_source, $settings);
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getSetting(string $key, $default = null): mixed
    {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }

    public function setSettings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    protected function setSetting(string $key, $value): self
    {
        $settings = $this->getSettings();
        $settings[$key] = $value;
        return $this->setSettings($settings);
    }

    public function setSrc(string $image_source): self
    {
        $this->originalSrc = $image_source;
        $this->setRelativePath($image_source);

        if (!$this->isLocalResource()) {
            throw new \InvalidArgumentException('Not a local resource: ' . $image_source);
        }
        if (!$this->isValidImage()) {
            throw new \InvalidArgumentException('Not a valid image: ' . $image_source);
        }
        if (!is_readable($this->getAbsolutePath())) {
            throw new \InvalidArgumentException('Image not found or inaccessible: ' . $this->getAbsolutePath());
        }
        return $this;
    }

    public function getSrc(): string
    {
        return $this->originalSrc;
    }

    protected function setRelativePath(string $relativePath): self
    {
        $this->relativePath = $this->urlRelative($relativePath);
        $this->relativePath = $this->imageStripOptimization();
        $this->relativePath = $this->getSrcFullSize();
        return $this;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function setSitePath(string $sitePath): self
    {
        $this->sitePath = rtrim($sitePath, '/');
        return $this;
    }

    public function getSitePath(): string
    {
        if ($this->sitePath === '') {
            $this->sitePath = rtrim(ABSPATH, '/');
        }
        return $this->sitePath;
    }

    public function setLocalAliases(array $aliases): self
    {
        foreach ($aliases as $key => $value) {
            $alias = wp_parse_url($value, PHP_URL_HOST);
            if (empty($alias)) {
                throw new \InvalidArgumentException('Not a valid URL alias: ' . $value);
            }
            // $this->localAliases[$key] = $alias;
        }
        return $this->setSetting('site_aliases', $aliases);
    }

    public function getLocalAliases(): array
    {
        return $this->getSetting('site_aliases', []);
    }

    public function getImageTypes(): array
    {
        return $this->getSetting('image_types', [
            'jpg',
            'jpeg',
            'gif',
            'png',
            'webp',
        ]);
    }

    public function setImageTypes(array $types): self
    {
        return $this->setSetting('image_types', $types);
    }


    /*
     * Check if this is a valid image based on defined types.
     * @return bool
     */
    protected function isValidImage(): bool
    {
        $image = $this->getSrc();

        // ignore data:image
        if (stripos($image, 'data:image') === 0) {
            return false;
        }

        $path = $this->getRelativePath();
        $types = implode('|', $this->getImageTypes());
        if (preg_match('/\.(?:' . $types . ')/i', $path, $matches, PREG_OFFSET_CAPTURE, 0)) {
            return true;
        }
        return false;
    }

    /*
     * Check if this is a local resource
     * @return bool
     */
    public function isLocalResource(): bool
    {
        $url = $this->getSrc();

        if (stripos($url, '//') === 0) {
            return false;
        }

        if (stripos($url, '/') === 0) {
            return true;
        }
        if (stripos($url, 'data:image') === 0) {
            return true;
        }
        if ($this->isOptimizedImage($url)) {
            return true;
        }

        $remoteHost = wp_parse_url($url, PHP_URL_HOST);
        if (empty($remoteHost)) {
            return true;
        }

        // allowed aliases
        foreach ($this->getLocalAliases() as $aliasHost) {
            if (strcasecmp($remoteHost, $aliasHost) === 0) {
                return true;
            }
        }

        // main site host
        $siteHost = wp_parse_url(site_url(), PHP_URL_HOST);
        if (strcasecmp($remoteHost, $siteHost) === 0) {
            return true;
        }

        return false;
    }

    /*
     * Try to remove size from image filename to get the full size image
     * @return string
     */
    protected function getSrcFullSize(): string
    {
        // get specified size as the source image
        // $image_style = $this->setting('image_style');
        // if ($image_style !== 'full') {
        //     $id = \_\image_parent_id($image_url);

        //     list($img_url, $width, $height, $is_intermediate) = \wp_get_attachment_image_url($id, $image_style);
        //     if (isset($img_url)) {
        //         return str_replace(site_url(), '', $img_url);
        //     }

        //     // get original fullsize image if we cant get specified size
        //     $original = \wp_get_original_image_url($id);
        //     if ($original) {
        //         return str_replace(site_url(), '', $original);
        //     }
        // }

        $pattern = '/^(.*)-\d*x\d*\.([A-Za-z]*)$/';
        $local = $this->getRelativePath();
        $stripped = preg_replace($pattern, '${1}.${2}', $local);
        if (!is_string($stripped) || $stripped === '') {
            return $local;
        }
        return file_exists($this->getSitePath() . $stripped) ? $stripped : $local;
    }

    /*
     * Try to extract size from image filename
     * @return array
     */
    public function getImageDimensions(): array
    {
        $path = $this->getRelativePath();

        // Try extract from img url (eg: /wp-content/uploads/2020/07/project-9-1200x848.jpg)
        $m = preg_match('/(([0-9]{1,4})x([0-9]{1,4})){1}\.[A-Za-z]+$/', $path, $matches);
        if ($m === 1) {
            return $this->applyMaxDimensions((int) $matches[2], (int) $matches[3]);
        }

        if (!file_exists($this->getSitePath() . $path)) {
            return [1, 1];
        }

        $sizes = wp_getimagesize($this->getSitePath() . $path) ?: [0, 0];
        $w = is_array($sizes) && isset($sizes[0]) ? (int) $sizes[0] : 0;
        $h = is_array($sizes) && isset($sizes[1]) ? (int) $sizes[1] : 0;
        return ($w > 0 && $h > 0) ? [$w, $h] : [1, 1];
    }

    public function getAbsolutePath(): string
    {
        return $this->getSitePath() . $this->getRelativePath();
    }

    /*
     * Apply max image size
     * @return array
     */
    protected function applyMaxDimensions($w, $h): array
    {
        $w = (int) $w;
        $h = (int) $h;

        $max_width = (int) $this->maxWidth;
        if (empty($max_width)) {
            return [$w, $h];
        }

        // todo: get max width from settings
        if ($w <= $max_width) {
            return [$w, $h];
        }

        // Guard against division by zero or negative heights
        if ($h <= 0) {
            return [$w, $h];
        }

        $ratio = $w / $h;
        $w = $max_width;
        $h = (int) round($w / $ratio);
        return [$w, $h];
    }

    /*
     * Build a cache key based on manipulations and image path
     * @return string
     */
    public function cacheBuildKey(array $manipulations): string
    {
        $imagePath = $this->getRelativePath();

        $payload = [
            'path' => $imagePath,
            'settings' => $this->cacheNormalizePayload($manipulations),
        ];

        $encoded = wp_json_encode($payload);
        if ($encoded === false) {
            $encoded = serialize($payload);
        }

        return md5($encoded);
    }

    /*
     * Recursively sort the payload for consistent cache keys
     * @return mixed
     */
    protected function cacheNormalizePayload($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $key => $child) {
            $value[$key] = $this->cacheNormalizePayload($child);
        }

        if (!$isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Return a relative link for any host
     *
     * @param string $uri
     * @return string
     */
    private function urlRelative(string $uri): string
    {
        $p = wp_parse_url($uri);
        if ($p === null || $p === false) {
            return $uri;
        }
        $path = $p['path'] ?? '';
        if ($path === '' || $path === '/') {
            return '/';
        }
        return '/' . rtrim(ltrim($path, '/'), '/');

        // $urls =  [
        //     'https://www.example.com',
        //     'https://www.example.com/',
        //     'https://www.example.com?v=7516fd43adaa',
        //     'https://www.example.com/?v=7516fd43adaa',
        //     '/asdf/asdf?v=7516fd43adaa',
        //     '/?v=7516fd43adaa',
        //     '?v=7516fd43adaa',

        // ];
        // $parsed = [];
        // foreach ($urls as $url) {
        //     $parsed[$url] = url_relative($url);
        // }
        // d($parsed);
        // exit();
    }

    /**
     * Return a relative uri only for links on current host
     *
     * @param string $uri
     * @return string
     */
    private function urlServerRelative(string $uri, string $host): string
    {
        $p = wp_parse_url($uri);
        if ($p === null || $p === false) {
            return $uri;
        }
        // bail on links to other servers
        $host_check = wp_parse_url($host, PHP_URL_HOST);
        if (!empty($p['host']) && strtolower($p['host']) !== strtolower($host_check)) {
            return $uri;
        }
        $qs = (!empty($p['query'])) ? '?' . $p['query'] : '';
        if (empty($p['path']) || $p['path'] === '/') {
            return '/' . $qs;
        }
        $path = $p['path'] ?? '';
        return '/' . rtrim(ltrim($path, '/'), '/') . $qs;

        // $urls =  [
        //     'https://www.example.com',
        //     'https://www.example.com/',
        //     'https://www.example.com?v=7516fd43adaa',
        //     'https://www.example.com/?v=7516fd43adaa',
        //     'https://tcbwoo.lndo.site',
        //     'https://tcbwoo.lndo.site/',
        //     'https://tcbwoo.lndo.site?v=7516fd43adaa',
        //     'https://tcbwoo.lndo.site/?v=7516fd43adaa',
        //     '/asdf/asdf?v=7516fd43adaa',
        //     '/?v=7516fd43adaa',
        //     '?v=7516fd43adaa',

        // ];
        // $parsed = [];
        // foreach ($urls as $url) {
        //     $parsed[$url] = url_relative($url);
        // }
        // d($parsed);
        // exit();

    }

    /**
     * Relative URI including query (not used for filesystem paths)
     */
    private function urlRelativeWithQuery(string $uri): string
    {
        $p = wp_parse_url($uri);
        if ($p === null || $p === false) {
            return $uri;
        }
        $qs = (!empty($p['query'])) ? '?' . $p['query'] : '';
        if (empty($p['path']) || $p['path'] === '/') {
            return '/' . $qs;
        }
        $path = $p['path'] ?? '';
        return '/' . rtrim(ltrim($path, '/'), '/') . $qs;
    }
}
