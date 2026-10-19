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

  // ---------------------------------------------------------------
  // OpenRouter - Access 100+ models through a single API
  // Models: openai/gpt-4o, anthropic/claude-3.5-sonnet,
  //   meta-llama/llama-3.1-70b, mistralai/mistral-large,
  //   google/gemini-pro, deepseek/deepseek-chat, etc.
  // Docs: https://openrouter.ai/docs
  // ---------------------------------------------------------------
  'openrouter' => [
   'api_key' => 'sk-or-your-openrouter-key-here',
   'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
   'model' => 'openai/gpt-4o',
   'embedding_endpoint' => 'https://openrouter.ai/api/v1/embeddings',
   'max_tokens' => 4096,
   'temperature' => 0.7,
   'cost_per_token' => 0.000005,
   // OpenRouter optional headers for model ranking
   'headers' => [
    'HTTP-Referer: https://yourdomain.com',
    'X-Title: Canyon Framework',
   ],
   // Popular model choices available on OpenRouter
   'available_models' => [
    'openai/gpt-4o',
    'openai/gpt-4o-mini',
    'anthropic/claude-3.5-sonnet',
    'anthropic/claude-3-haiku',
    'google/gemini-pro',
    'google/gemini-flash-1.5',
    'meta-llama/llama-3.1-70b-instruct',
    'mistralai/mistral-large',
    'mistralai/mistral-7b-instruct',
    'deepseek/deepseek-chat',
    'deepseek/deepseek-coder',
   ],
  ],

  // ---------------------------------------------------------------
  // OpenCode - AI Coding Agent
  // OpenCode provides an AI-powered coding assistant that can
  // generate code, review changes, debug issues, and plan features.
  //
  // API mode: Point endpoint to your OpenCode server URL
  // CLI mode: Use AiOpenCodeCli class for terminal-based usage
  //
  // Code modes:
  // chat - General Q&A and code generation
  // edit - Inline code editing with context
  // debug - Analyze errors and suggest fixes
  // plan - Architecture and implementation planning
  // ---------------------------------------------------------------
  'opencode' => [
   'api_key' => 'your-opencode-api-key-here',
   'endpoint' => 'http://localhost:3000/v1/chat/completions',
   'model' => 'opencode/auto',
   'max_tokens' => 8192,
   'temperature' => 0.2,
   'cost_per_token' => 0.0,
   'headers' => [
    'X-OpenCode-Mode: chat',
    'X-OpenCode-Client: canyon-framework',
   ],
   // OpenCode-specific settings
   'code_mode' => 'chat',
   'auto_detect_language' => true,
   'include_context' => true,
   'stream_responses' => true,
  ],

  // Ollama - Local models (free, private)
  'ollama' => [
   'api_key' => 'ollama',
   'endpoint' => 'http://localhost:11434/api/chat',
   'model' => 'llama3.1',
   'max_tokens' => 2048,
   'temperature' => 0.7,
   'cost_per_token' => 0.0,
   'headers' => [],
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