<?php

/**
 * Canyon Framework - AI Configuration
 *
 * Configure your AI providers here.
 * NEVER commit this file with real API keys to version control!
 */

return [
    // Default provider to use when none is specified
    'default_provider' => 'openai',

    // Provider configurations
    'providers' => [
        'openai' => [
            'api_key' => 'sk-your-openai-key-here', // Replace with your key
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model' => 'gpt-4-turbo',
            'embedding_endpoint' => 'https://api.openai.com/v1/embeddings',
            'image_endpoint' => 'https://api.openai.com/v1/images/generations',
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'cost_per_token' => 0.00001, // Approximate cost per token
        ],

        'anthropic' => [
            'api_key' => 'sk-ant-your-anthropic-key-here', // Replace with your key
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'model' => 'claude-3-opus-20240229',
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'cost_per_token' => 0.000015,
        ],

        'google' => [
            'api_key' => 'your-google-ai-key-here', // Replace with your key
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent',
            'model' => 'gemini-pro',
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'cost_per_token' => 0.000001,
        ],

        // Local models via Ollama
        'ollama' => [
            'api_key' => 'ollama', // Not used but required
            'endpoint' => 'http://localhost:11434/api/chat',
            'model' => 'llama3',
            'max_tokens' => 2048,
            'temperature' => 0.7,
            'cost_per_token' => 0.0, // Free - local model
        ],
    ],

    // System prompt templates
    'prompts' => [
        'summarize' => 'Summarize the following text concisely:\n\n{{text}}',
        'translate' => 'Translate the following text to {{language}}:\n\n{{text}}',
        'improve' => 'Improve the following text for clarity and grammar:\n\n{{text}}',
        'explain' => 'Explain the following concept in simple terms:\n\n{{text}}',
        'code_review' => 'Review the following code for bugs, security issues, and improvements:\n\n{{code}}',
        'sql_optimize' => 'Optimize this SQL query:\n\n{{query}}',
    ],

    // Conversation settings
    'conversation' => [
        'max_history' => 20, // Maximum messages to keep in history
        'history_ttl' => 3600, // Seconds to keep conversation history
        'auto_save' => true, // Auto-save conversations to database
    ],

    // Rate limiting
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 20,
        'tokens_per_minute' => 100000,
    ],
];