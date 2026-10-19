<?php

namespace Canyon\Core;

/**
 * Canyon Framework - AI Response
 *
 * Standardized response object for AI operations.
 */
class AiResponse
{
    /** @var string Response content */
    public readonly string $content;

    /** @var int Tokens used */
    public readonly int $tokensUsed;

    /** @var string Model used */
    public readonly string $model;

    /** @var string Provider name */
    public readonly string $provider;

    /** @var string Finish reason */
    public readonly string $finishReason;

    /** @var array Raw provider response */
    public readonly array $raw;

    /**
     * Constructor.
     */
    public function __construct(array $data)
    {
        $this->content = $data['content'] ?? '';
        $this->tokensUsed = $data['tokens_used'] ?? 0;
        $this->model = $data['model'] ?? 'unknown';
        $this->provider = $data['provider'] ?? 'unknown';
        $this->finishReason = $data['finish_reason'] ?? 'stop';
        $this->raw = $data['raw'] ?? [];
    }

    /**
     * Get response as string.
     */
    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'tokens_used' => $this->tokensUsed,
            'model' => $this->model,
            'provider' => $this->provider,
            'finish_reason' => $this->finishReason,
        ];
    }

    /**
     * Check if response is empty.
     */
    public function isEmpty(): bool
    {
        return empty(trim($this->content));
    }

    /**
     * Get word count.
     */
    public function wordCount(): int
    {
        return str_word_count($this->content);
    }

    /**
     * Get character count.
     */
    public function charCount(): int
    {
        return strlen($this->content);
    }

    /**
     * Get estimated reading time (minutes).
     */
    public function readingTime(): int
    {
        $words = $this->wordCount();
        return (int) ceil($words / 200); // 200 WPM average
    }

    /**
     * Truncate content to a maximum length.
     */
    public function truncate(int $maxLength = 100, string $suffix = '...'): string
    {
        if (strlen($this->content) <= $maxLength) {
            return $this->content;
        }
        return substr($this->content, 0, $maxLength - strlen($suffix)) . $suffix;
    }

    /**
     * Get JSON representation.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Check if response contains specific text.
     */
    public function contains(string $text): bool
    {
        return str_contains(strtolower($this->content), strtolower($text));
    }

    /**
     * Extract code blocks from response.
     */
    public function extractCodeBlocks(): array
    {
        preg_match_all('/```(\w+)?\n(.*?)```/s', $this->content, $matches, PREG_SET_ORDER);
        
        $blocks = [];
        foreach ($matches as $match) {
            $blocks[] = [
                'language' => $match[1] ?? '',
                'code' => trim($match[2]),
            ];
        }
        
        return $blocks;
    }

    /**
     * Extract URLs from response.
     */
    public function extractUrls(): array
    {
        preg_match_all('/https?:\/\/[^\s<>"{}|\\^`\[\]]+/', $this->content, $matches);
        return $matches[0] ?? [];
    }

    /**
     * Get first paragraph.
     */
    public function firstParagraph(): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($this->content));
        return $paragraphs[0] ?? '';
    }
}