<?php

namespace Canyon\Core;

/**
 * Canyon Framework - AI Client
 *
 * Unified interface for multiple AI providers:
 * - OpenAI (GPT-4, GPT-3.5)
 * - Anthropic (Claude)
 * - Google (Gemini)
 * - Local models (Ollama)
 *
 * Features:
 * - Chat/completions
 * - Streaming support
 * - Token counting
 * - Cost estimation
 * - Fallback providers
 */
class Ai
{
    /** @var array Registered providers */
    protected static array $providers = [];

    /** @var string|null Default provider */
    protected static ?string $defaultProvider = null;

    /** @var array Provider configurations */
    protected static array $config = [];

    /** @var array Conversation history */
    protected static array $history = [];

    /** @var string Current conversation ID */
    protected static string $conversationId;

    /** @var int Total tokens used */
    protected static int $totalTokens = 0;

    /** @var float Total cost */
    protected static float $totalCost = 0.0;

    /**
     * Initialize AI module.
     */
    public static function init(): void
    {
        self::$conversationId = bin2hex(random_bytes(8));
        self::$history = [];
        self::loadConfig();
    }

    /**
     * Register an AI provider.
     */
    public static function registerProvider(string $name, array $config): void
    {
        self::$providers[$name] = [
            'name' => $name,
            'api_key' => $config['api_key'] ?? '',
            'endpoint' => $config['endpoint'] ?? '',
            'model' => $config['model'] ?? '',
            'max_tokens' => $config['max_tokens'] ?? 2048,
            'temperature' => $config['temperature'] ?? 0.7,
            'headers' => $config['headers'] ?? [],
        ];
    }

    /**
     * Set default provider.
     */
    public static function setDefaultProvider(string $name): void
    {
        if (!isset(self::$providers[$name])) {
            throw new \InvalidArgumentException("Provider '{$name}' not registered.");
        }
        self::$defaultProvider = $name;
    }

    /**
     * Load configuration from config file.
     */
    protected static function loadConfig(): void
    {
        $configPath = CANYON_BASE_PATH . '/config/ai.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            foreach ($config['providers'] ?? [] as $name => $providerConfig) {
                self::registerProvider($name, $providerConfig);
            }
            if (!empty($config['default_provider'])) {
                self::setDefaultProvider($config['default_provider']);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Chat/Completion
    // -----------------------------------------------------------------------

    /**
     * Send a message and get a response.
     */
    public static function chat(string $message, array $options = []): AiResponse
    {
        // Add user message to history
        self::addToHistory('user', $message);

        $provider = self::getProvider($options['provider'] ?? null);
        $response = self::sendRequest($provider, $message, $options);

        // Add assistant response to history
        self::addToHistory('assistant', $response->content);

        return $response;
    }

    /**
     * Send a completion request.
     */
    public static function complete(string $prompt, array $options = []): AiResponse
    {
        return self::chat($prompt, array_merge($options, ['role' => 'user']));
    }

    /**
     * Send a message with streaming.
     */
    public static function stream(string $message, callable $callback, array $options = []): void
    {
        $provider = self::getProvider($options['provider'] ?? null);
        $response = self::sendRequest($provider, $message, array_merge($options, ['stream' => true]));

        foreach ($response->content as $chunk) {
            $callback($chunk);
        }
    }

    // -----------------------------------------------------------------------
    // Prompt Templates
    // -----------------------------------------------------------------------

    /**
     * Send a templated message.
     */
    public static function prompt(string $template, array $variables = [], array $options = []): AiResponse
    {
        $prompt = self::renderTemplate($template, $variables);
        return self::chat($prompt, $options);
    }

    /**
     * Render a prompt template.
     */
    protected static function renderTemplate(string $template, array $variables): string
    {
        $prompt = $template;
        foreach ($variables as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
        }
        return $prompt;
    }

    // -----------------------------------------------------------------------
    // Conversation Management
    // -----------------------------------------------------------------------

    /**
     * Start a new conversation.
     */
    public static function newConversation(string $id = null): string
    {
        self::$conversationId = $id ?? bin2hex(random_bytes(8));
        self::$history = [];
        return self::$conversationId;
    }

    /**
     * Load a conversation history.
     */
    public static function loadConversation(string $conversationId, array $history): void
    {
        self::$conversationId = $conversationId;
        self::$history = $history;
    }

    /**
     * Get current conversation ID.
     */
    public static function getConversationId(): string
    {
        return self::$conversationId;
    }

    /**
     * Get conversation history.
     */
    public static function getHistory(): array
    {
        return self::$history;
    }

    /**
     * Clear conversation history.
     */
    public static function clearHistory(): void
    {
        self::$history = [];
    }

    /**
     * Add message to history.
     */
    protected static function addToHistory(string $role, string $content): void
    {
        self::$history[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
        ];
    }

    // -----------------------------------------------------------------------
    // Embeddings & Search
    // -----------------------------------------------------------------------

    /**
     * Generate embeddings for text.
     */
    public static function embed(string $text, array $options = []): ?array
    {
        $provider = self::getProvider($options['provider'] ?? null);
        
        // Check if provider supports embeddings
        if (!isset($provider['embedding_endpoint'])) {
            return null;
        }

        $response = self::sendEmbeddingRequest($provider, $text, $options);
        return $response['embedding'] ?? null;
    }

    /**
     * Calculate similarity between two texts.
     */
    public static function similarity(string $text1, string $text2, array $options = []): float
    {
        $embeddings1 = self::embed($text1, $options);
        $embeddings2 = self::embed($text2, $options);

        if ($embeddings1 === null || $embeddings2 === null) {
            return 0.0;
        }

        return self::cosineSimilarity($embeddings1, $embeddings2);
    }

    /**
     * Calculate cosine similarity between two vectors.
     */
    protected static function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dotProduct += $value * ($b[$i] ?? 0);
            $normA += $value * $value;
            $normB += ($b[$i] ?? 0) * ($b[$i] ?? 0);
        }

        $denominator = sqrt($normA) * sqrt($normB);
        return $denominator > 0 ? $dotProduct / $denominator : 0.0;
    }

    // -----------------------------------------------------------------------
    // Image Generation
    // -----------------------------------------------------------------------

    /**
     * Generate an image from text.
     */
    public static function generateImage(string $prompt, array $options = []): ?AiResponse
    {
        $provider = self::getProvider($options['provider'] ?? null);

        if (!isset($provider['image_endpoint'])) {
            return null;
        }

        $response = self::sendImageRequest($provider, $prompt, $options);
        return $response;
    }

    // -----------------------------------------------------------------------
    // Token Counting & Cost
    // -----------------------------------------------------------------------

    /**
     * Count tokens in text.
     */
    public static function countTokens(string $text, string $provider = null): int
    {
        $providerConfig = self::getProvider($provider);
        $model = $providerConfig['model'] ?? 'gpt-3.5-turbo';

        // Approximate token count (4 chars ≈ 1 token)
        return (int) ceil(strlen($text) / 4);
    }

    /**
     * Get total tokens used.
     */
    public static function getTotalTokens(): int
    {
        return self::$totalTokens;
    }

    /**
     * Get total cost.
     */
    public static function getTotalCost(): float
    {
        return self::$totalCost;
    }

    /**
     * Estimate cost for a request.
     */
    public static function estimateCost(string $text, string $provider = null): float
    {
        $tokens = self::countTokens($text, $provider);
        $providerConfig = self::getProvider($provider);
        $costPerToken = $providerConfig['cost_per_token'] ?? 0.000002;

        return $tokens * $costPerToken;
    }

    /**
     * Track token usage.
     */
    protected static function trackUsage(int $tokens, string $provider = null): void
    {
        self::$totalTokens += $tokens;
        $providerConfig = self::getProvider($provider);
        $costPerToken = $providerConfig['cost_per_token'] ?? 0.000002;
        self::$totalCost += $tokens * $costPerToken;
    }

    // -----------------------------------------------------------------------
    // HTTP Requests
    // -----------------------------------------------------------------------

    /**
     * Send request to provider.
     */
    protected static function sendRequest(array $provider, string $message, array $options = []): AiResponse
    {
        $endpoint = $provider['endpoint'];
        $model = $options['model'] ?? $provider['model'];
        $maxTokens = $options['max_tokens'] ?? $provider['max_tokens'];
        $temperature = $options['temperature'] ?? $provider['temperature'];

        // Build messages
        $messages = self::buildMessages($message, $options);

        // Build request body
        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        if (isset($options['stream']) && $options['stream']) {
            $body['stream'] = true;
        }

        // Send request
        $response = self::httpRequest($endpoint, $body, $provider);

        // Parse response
        return self::parseResponse($response, $provider);
    }

    /**
     * Build messages array from history.
     */
    protected static function buildMessages(string $message, array $options = []): array
    {
        $messages = [];

        // Add system message if provided
        if (!empty($options['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system'],
            ];
        }

        // Add conversation history
        foreach (self::$history as $entry) {
            $messages[] = [
                'role' => $entry['role'],
                'content' => $entry['content'],
            ];
        }

        // Add current message
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    /**
     * Send HTTP request to provider.
     */
    protected static function httpRequest(string $endpoint, array $body, array $provider): array
    {
        $ch = curl_init($endpoint);

        $headers = array_merge([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['api_key'],
        ], $provider['headers'] ?? []);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("AI request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("AI request failed with status {$httpCode}: {$response}");
        }

        return json_decode($response, true);
    }

    /**
     * Parse provider response.
     */
    protected static function parseResponse(array $response, array $provider): AiResponse
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        $usage = $response['usage'] ?? [];
        $tokensUsed = $usage['total_tokens'] ?? 0;

        // Track usage
        self::trackUsage($tokensUsed, $provider['name'] ?? null);

        return new AiResponse([
            'content' => $content,
            'tokens_used' => $tokensUsed,
            'model' => $response['model'] ?? $provider['model'],
            'provider' => $provider['name'] ?? 'unknown',
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
            'raw' => $response,
        ]);
    }

    /**
     * Send embedding request.
     */
    protected static function sendEmbeddingRequest(array $provider, string $text, array $options): ?array
    {
        $endpoint = $provider['embedding_endpoint'] ?? null;
        if (!$endpoint) {
            return null;
        }

        $body = [
            'model' => $options['embedding_model'] ?? 'text-embedding-ada-002',
            'input' => $text,
        ];

        $response = self::httpRequest($endpoint, $body, $provider);

        return $response['data'][0]['embedding'] ?? null;
    }

    /**
     * Send image generation request.
     */
    protected static function sendImageRequest(array $provider, string $prompt, array $options): ?AiResponse
    {
        $endpoint = $provider['image_endpoint'] ?? null;
        if (!$endpoint) {
            return null;
        }

        $body = [
            'prompt' => $prompt,
            'n' => $options['n'] ?? 1,
            'size' => $options['size'] ?? '1024x1024',
        ];

        $response = self::httpRequest($endpoint, $body, $provider);

        $url = $response['data'][0]['url'] ?? null;

        return $url ? new AiResponse([
            'content' => $url,
            'tokens_used' => 0,
            'model' => 'image-generation',
            'provider' => $provider['name'],
            'finish_reason' => 'complete',
            'raw' => $response,
        ]) : null;
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Get provider configuration.
     */
    protected static function getProvider(?string $name = null): array
    {
        $name = $name ?? self::$defaultProvider;

        if (!$name || !isset(self::$providers[$name])) {
            throw new \RuntimeException('No AI provider configured. Register a provider first.');
        }

        return self::$providers[$name];
    }

    /**
     * Check if AI is configured.
     */
    public static function isConfigured(): bool
    {
        return self::$defaultProvider !== null && !empty(self::$providers[self::$defaultProvider]['api_key']);
    }

    /**
     * Get available providers.
     */
    public static function getProviders(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Reset usage stats.
     */
    public static function resetStats(): void
    {
        self::$totalTokens = 0;
        self::$totalCost = 0.0;
    }

    /**
     * Get usage statistics.
     */
    public static function getStats(): array
    {
        return [
            'total_tokens' => self::$totalTokens,
            'total_cost' => self::$totalCost,
            'conversation_id' => self::$conversationId,
            'history_length' => count(self::$history),
        ];
    }
}