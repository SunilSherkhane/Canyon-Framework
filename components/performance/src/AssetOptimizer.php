<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Asset Optimization
 *
 * Handles minification, combining, lazy-loading, CDN URLs, critical CSS,
 * and browser cache headers. based on QUIC.cloud performance features.
 */
class AssetOptimizer
{
    /** @var string CDN base URL */
    protected static string $cdnUrl = '';

    /** @var bool Enable minification */
    protected static bool $minifyEnabled = true;

    /** @var bool Enable CSS combination */
    protected static bool $combineCss = false;

    /** @var bool Enable JS combination */
    protected static bool $combineJs = false;

    /** @var bool Enable lazy loading */
    protected static bool $lazyLoad = true;

    /** @var bool Enable async CSS */
    protected static bool $asyncCss = false;

    /** @var bool Enable deferred JS */
    protected static bool $deferJs = true;

    /** @var array Minified files cache */
    protected static array $minified = [];

    /** @var string Cache directory */
    protected static string $cacheDir;

    /**
     * Initialize asset optimizer.
     */
    public static function init(): void
    {
        self::$cacheDir = CANYON_STORAGE_PATH . '/cache/assets';
        if (!is_dir(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0750, true);
        }
    }

    // -----------------------------------------------------------------------
    // CDN
    // -----------------------------------------------------------------------

    /**
     * Set CDN base URL.
     */
    public static function setCdnUrl(string $url): void
    {
        self::$cdnUrl = rtrim($url, '/');
    }

    /**
     * Get asset URL (with CDN if configured).
     */
    public static function url(string $path): string
    {
        if (self::$cdnUrl && str_starts_with($path, '/')) {
            return self::$cdnUrl . $path;
        }
        return asset($path);
    }

    // -----------------------------------------------------------------------
    // Minification
    // -----------------------------------------------------------------------

    /**
     * Minify CSS content.
     */
    public static function minifyCss(string $css): string
    {
        if (!self::$minifyEnabled) {
            return $css;
        }

        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        // Remove spaces around selectors and properties
        $css = preg_replace('\s*([{}:;,])\s*', '$1', $css);
        // Remove last semicolon
        $css = str_replace(';}', '}', $css);
        return trim($css);
    }

    /**
     * Minify JavaScript content.
     */
    public static function minifyJs(string $js): string
    {
        if (!self::$minifyEnabled) {
            return $js;
        }

        // Remove single-line comments (but preserve URLs)
        $js = preg_replace('~(^|[^:])//.*$~m', '$1', $js);
        // Remove multi-line comments
        $js = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $js);
        // Remove extra whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        // Remove spaces around operators
        $js = preg_replace('/\s*([=+\-*\/<>!&|,:;(){}\[\]])\s*/', '$1', $js);
        return trim($js);
    }

    /**
     * Minify HTML content.
     */
    public static function minifyHtml(string $html): string
    {
        if (!self::$minifyEnabled) {
            return $html;
        }

        // Remove HTML comments (but preserve conditional comments)
        $html = preg_replace('/<!--(?!\[if\s).*?-->/s', '', $html);
        // Remove whitespace between tags
        $html = preg_replace('/>\s+</', '><', $html);
        // Collapse multiple spaces
        $html = preg_replace('/\s+/', ' ', $html);
        return trim($html);
    }

    /**
     * Minify a file (CSS or JS).
     */
    public static function minifyFile(string $file): string
    {
        if (!file_exists($file)) {
            return '';
        }

        $content = file_get_contents($file);
        $ext = pathinfo($file, PATHINFO_EXTENSION);

        return match ($ext) {
            'css' => self::minifyCss($content),
            'js' => self::minifyJs($content),
            default => $content,
        };
    }

    // -----------------------------------------------------------------------
    // Combining
    // -----------------------------------------------------------------------

    /**
     * Combine multiple CSS files.
     */
    public static function combineCss(array $files): string
    {
        $combined = '';
        foreach ($files as $file) {
            if (file_exists($file)) {
                $combined .= self::minifyCss(file_get_contents($file)) . "\n";
            }
        }
        return $combined;
    }

    /**
     * Combine multiple JS files.
     */
    public static function combineJs(array $files): string
    {
        $combined = '';
        foreach ($files as $file) {
            if (file_exists($file)) {
                $combined .= self::minifyJs(file_get_contents($file)) . ";\n";
            }
        }
        return $combined;
    }

    // -----------------------------------------------------------------------
    // Lazy Loading
    // -----------------------------------------------------------------------

    /**
     * Add lazy loading to an image tag.
     */
    public static function lazyImage(string $src, string $alt = '', array $attrs = []): string
    {
        $attrs['loading'] = 'lazy';
        $attrs['decoding'] = 'async';
        if (!isset($attrs['src'])) {
            $attrs['src'] = $src;
        }
        $attrs['alt'] = $alt;

        $attrStr = self::buildAttributes($attrs);
        return "<img {$attrStr}>";
    }

    /**
     * Add lazy loading to an iframe.
     */
    public static function lazyIframe(string $src, array $attrs = []): string
    {
        $attrs['loading'] = 'lazy';
        $attrs['src'] = $src;

        $attrStr = self::buildAttributes($attrs);
        return "<iframe {$attrStr}></iframe>";
    }

    /**
     * Process HTML content to add lazy loading to images.
     */
    public static function lazyLoadHtml(string $html): string
    {
        if (!self::$lazyLoad) {
            return $html;
        }

        // Add loading="lazy" to img tags that don't have it
        $html = preg_replace(
            '/<img(?![^>]*loading=)([^>]*)>/i',
            '<img loading="lazy" decoding="async"$1>',
            $html
        );

        // Add loading="lazy" to iframe tags that don't have it
        $html = preg_replace(
            '/<iframe(?![^>]*loading=)([^>]*)>/i',
            '<iframe loading="lazy"$1>',
            $html
        );

        return $html;
    }

    // -----------------------------------------------------------------------
    // Async / Defer
    // -----------------------------------------------------------------------

    /**
     * Load CSS asynchronously.
     */
    public static function asyncCss(string $href, array $attrs = []): string
    {
        $attrs['rel'] = 'preload';
        $attrs['as'] = 'style';
        $attrs['onload'] = "this.onload=null;this.rel='stylesheet'";

        $attrStr = self::buildAttributes($attrs);
        return "<link {$attrStr}>";
    }

    /**
     * Load JS with defer.
     */
    public static function deferJs(string $src, array $attrs = []): string
    {
        $attrs['defer'] = true;
        $attrs['src'] = $src;

        $attrStr = self::buildAttributes($attrs);
        return "<script {$attrStr}></script>";
    }

    /**
     * Load JS with async.
     */
    public static function asyncJs(string $src, array $attrs = []): string
    {
        $attrs['async'] = true;
        $attrs['src'] = $src;

        $attrStr = self::buildAttributes($attrs);
        return "<script {$attrStr}></script>";
    }

    // -----------------------------------------------------------------------
    // Critical CSS
    // -----------------------------------------------------------------------

    /**
     * Generate critical CSS from HTML content.
     */
    public static function generateCriticalCss(string $html, string $cssFile = ''): string
    {
        $critical = [];

        if (file_exists($cssFile)) {
            $css = file_get_contents($cssFile);
            // Extract selectors for elements found in HTML
            preg_match_all('/([^{]+)\{([^}]+)\}/', $css, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $selector = trim($match[1]);
                if (self::selectorInUse($selector, $html)) {
                    $critical[] = $selector . '{' . trim($match[2]) . '}';
                }
            }
        }

        $criticalCss = implode("\n", $critical);

        // Cache it
        $cacheFile = self::$cacheDir . '/critical_' . md5($html) . '.css';
        file_put_contents($cacheFile, $criticalCss);

        return $criticalCss;
    }

    /**
     * Check if a CSS selector is used in HTML.
     */
    protected static function selectorInUse(string $selector, string $html): bool
    {
        // Simple check: look for class or ID in HTML
        if (str_starts_with($selector, '.')) {
            $class = substr($selector, 1);
            return str_contains($html, "class=\"{$class}") || str_contains($html, " {$class}");
        }
        if (str_starts_with($selector, '#')) {
            $id = substr($selector, 1);
            return str_contains($html, "id=\"{$id}\"");
        }
        // Tag selector
        return str_contains($html, '<' . trim($selector, ' .#'));
    }

    // -----------------------------------------------------------------------
    // Browser Cache Headers
    // -----------------------------------------------------------------------

    /**
     * Send browser cache headers.
     */
    public static function cacheHeaders(int $maxAge = 86400): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: public, max-age=' . $maxAge);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        header('Pragma: cache');
    }

    /**
     * Send no-cache headers.
     */
    public static function noCacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // -----------------------------------------------------------------------
    // DNS Prefetch
    // -----------------------------------------------------------------------

    /**
     * Send DNS prefetch headers.
     */
    public static function dnsPrefetch(array $domains): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($domains as $domain) {
            header('X-DNS-Prefetch-Control: on', false);
            echo '<link rel="dns-prefetch" href="' . $domain . '">' . "\n";
        }
    }

    // -----------------------------------------------------------------------
    // Responsive Placeholders
    // -----------------------------------------------------------------------

    /**
     * Generate responsive image placeholder.
     */
    public static function placeholder(int $width, int $height, string $text = ''): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">';
        $svg .= '<rect width="100%" height="100%" fill="#f0f0f0"/>';
        $svg .= '<text x="50%" y="50%" text-anchor="middle" fill="#999" font-size="14">' . $text . '</text>';
        $svg .= '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // -----------------------------------------------------------------------
    // HTTP/2 Push
    // -----------------------------------------------------------------------

    /**
     * Send HTTP/2 push headers for CSS/JS.
     */
    public static function http2Push(array $assets): void
    {
        if (headers_sent()) {
            return;
        }

        $pushes = [];
        foreach ($assets as $asset) {
            $type = pathinfo($asset, PATHINFO_EXTENSION);
            $as = $type === 'css' ? 'style' : 'script';
            $pushes[] = '<' . self::url($asset) . '>; rel=preload; as=' . $as;
        }

        if (!empty($pushes)) {
            header('Link: ' . implode(', ', $pushes), false);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build HTML attributes string.
     */
    protected static function buildAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            if ($value === true) {
                $parts[] = $key;
            } elseif ($value !== false && $value !== null) {
                $parts[] = $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Get file hash for cache busting.
     */
    public static function fileHash(string $file): string
    {
        if (!file_exists($file)) {
            return '';
        }
        return substr(md5_file($file), 0, 8);
    }

    /**
     * Get versioned asset URL.
     */
    public static function versioned(string $path): string
    {
        $file = CANYON_BASE_PATH . '/public' . $path;
        $hash = self::fileHash($file);
        if ($hash) {
            $sep = str_contains($path, '?') ? '&' : '?';
            return self::url($path) . $sep . 'v=' . $hash;
        }
        return self::url($path);
    }
}