<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Preload Crawler
 *
 * Smart preload crawler for warming cache with SEO-friendly sitemap support.
 * Inspired by QUIC.cloud smart preload crawler.
 */
class PreloadCrawler
{
    /** @var array Crawl queue */
    protected static array $queue = [];

    /** @var array Crawled URLs */
    protected static array $crawled = [];

    /** @var array Crawl settings */
    protected static array $settings = [
        'max_pages' => 100,
        'delay_ms' => 100,
        'timeout' => 30,
        'user_agent' => 'CanyonBot/1.0',
        'respect_robots' => true,
        'follow_redirects' => true,
        'max_depth' => 3,
    ];

    /** @var array Crawl statistics */
    protected static array $stats = [
        'started' => 0,
        'finished' => 0,
        'total_urls' => 0,
        'successful' => 0,
        'failed' => 0,
        'total_bytes' => 0,
    ];

    /** @var string Sitemap URL */
    protected static string $sitemapUrl = '';

    /**
     * Initialize preload crawler.
     */
    public static function init(): void
    {
        self::$stats['started'] = time();
    }

    /**
     * Set sitemap URL.
     */
    public static function setSitemap(string $url): void
    {
        self::$sitemapUrl = $url;
    }

    /**
     * Load URLs from sitemap.
     */
    public static function loadSitemap(string $url = ''): array
    {
        $url = $url ?: self::$sitemapUrl;
        if (empty($url)) {
            return [];
        }

        $content = self::fetch($url);
        if (empty($content)) {
            return [];
        }

        $urls = [];
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            return [];
        }

        foreach ($xml->url as $entry) {
            $urls[] = (string) $entry->loc;
        }

        return $urls;
    }

    /**
     * Add URL to crawl queue.
     */
    public static function enqueue(string $url, int $depth = 0): void
    {
        if (in_array($url, self::$crawled)) {
            return;
        }

        self::$queue[] = [
            'url' => $url,
            'depth' => $depth,
            'added_at' => time(),
        ];
    }

    /**
     * Crawl all queued URLs.
     */
    public static function crawl(): array
    {
        self::init();
        self::$stats['total_urls'] = count(self::$queue);

        while (!empty(self::$queue) && count(self::$crawled) < self::$settings['max_pages']) {
            $item = array_shift(self::$queue);
            $url = $item['url'];
            $depth = $item['depth'];

            if (in_array($url, self::$crawled)) {
                continue;
            }

            // Respect delay
            usleep(self::$settings['delay_ms'] * 1000);

            // Fetch URL
            $result = self::fetch($url);

            if ($result['success']) {
                self::$crawled[] = $url;
                self::$stats['successful']++;
                self::$stats['total_bytes'] += $result['size'];

                // Extract links for further crawling
                if ($depth < self::$settings['max_depth']) {
                    $links = self::extractLinks($result['content'], $url);
                    foreach ($links as $link) {
                        self::enqueue($link, $depth + 1);
                    }
                }
            } else {
                self::$stats['failed']++;
            }
        }

        self::$stats['finished'] = time();
        return self::getReport();
    }

    /**
     * Fetch a URL.
     */
    public static function fetch(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => self::$settings['follow_redirects'],
            CURLOPT_TIMEOUT => self::$settings['timeout'],
            CURLOPT_USERAGENT => self::$settings['user_agent'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);

        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'http_code' => $httpCode,
            'content' => $content ?: '',
            'size' => $size,
            'url' => $url,
        ];
    }

    /**
     * Extract links from HTML content.
     */
    public static function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];
        $baseUrlParts = parse_url($baseUrl);
        $baseOrigin = ($baseUrlParts['scheme'] ?? 'http') . '://' . ($baseUrlParts['host'] ?? '');

        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);

        foreach ($matches[1] as $href) {
            // Skip anchors, javascript, mailto
            if (str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            // Resolve relative URLs
            if (str_starts_with($href, '/')) {
                $href = $baseOrigin . $href;
            } elseif (!str_starts_with($href, 'http')) {
                $href = $baseOrigin . '/' . ltrim($href, '/');
            }

            // Only crawl same origin
            $hrefOrigin = parse_url($href, PHP_URL_SCHEME) . '://' . parse_url($href, PHP_URL_HOST);
            if ($hrefOrigin === $baseOrigin) {
                $links[] = $href;
            }
        }

        return array_unique($links);
    }

    /**
     * Check robots.txt.
     */
    public static function checkRobots(string $url): bool
    {
        if (!self::$settings['respect_robots']) {
            return true;
        }

        $parts = parse_url($url);
        $robotsUrl = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . '/robots.txt';

        $content = self::fetch($robotsUrl);
        if (!$content['success']) {
            return true;
        }

        // Simple check: look for Disallow
        $path = $parts['path'] ?? '/';
        $lines = explode("\n", $content['content']);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), 'Disallow:')) {
                $disallowed = trim(substr($line, 9));
                if (str_starts_with($path, $disallowed)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Preload a single URL.
     */
    public static function preload(string $url): array
    {
        if (!self::checkRobots($url)) {
            return ['success' => false, 'reason' => 'robots.txt disallowed'];
        }

        $result = self::fetch($url);
        if ($result['success']) {
            // Warm cache for this URL
            Cache::put('preload_' . md5($url), $result['content'], 3600);
        }

        return $result;
    }

    /**
     * Preload multiple URLs.
     */
    public static function preloadUrls(array $urls): array
    {
        $results = [];
        foreach ($urls as $url) {
            $results[$url] = self::preload($url);
        }
        return $results;
    }

    /**
     * Get crawl report.
     */
    public static function getReport(): array
    {
        $duration = self::$stats['finished'] - self::$stats['started'];
        return [
            'statistics' => self::$stats,
            'duration_seconds' => $duration,
            'pages_per_second' => $duration > 0 ? round(self::$stats['successful'] / $duration, 2) : 0,
            'crawled_urls' => self::$crawled,
            'queued_urls' => array_column(self::$queue, 'url'),
        ];
    }

    /**
     * Get crawl statistics.
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * Set crawl settings.
     */
    public static function configure(array $settings): void
    {
        self::$settings = array_merge(self::$settings, $settings);
    }

    /**
     * Get settings.
     */
    public static function getSettings(): array
    {
        return self::$settings;
    }

    /**
     * Reset crawler state.
     */
    public static function reset(): void
    {
        self::$queue = [];
        self::$crawled = [];
        self::$stats = [
            'started' => 0,
            'finished' => 0,
            'total_urls' => 0,
            'successful' => 0,
            'failed' => 0,
            'total_bytes' => 0,
        ];
    }
}