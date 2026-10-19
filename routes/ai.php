<?php

use Canyon\Core\Router;
use Canyon\Core\Request;
use Canyon\Core\Response;
use Canyon\Core\AiChatbot;

/*
|--------------------------------------------------------------------------
| AI Routes
|--------------------------------------------------------------------------
| AI-powered endpoints for chatbot and completions.
| All routes require AI to be configured (config/ai.php).
*/

Router::group('throttle:60,1', function () {

    // AI Chatbot endpoint
    Router::post('/api/ai/chat', function () {
        $request = Request::createFromGlobals();
        $message = $request->input('message', '');
        $session = $request->input('session', session_id());

        if (empty($message)) {
            return Response::json(['error' => 'Message is required'], 422);
        }

        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        $result = AiChatbot::handleMessage($message, $session);

        if (!$result['success']) {
            return Response::json(['error' => $result['error'] ?? 'Unknown error'], 500);
        }

        return Response::json([
            'success' => true,
            'response' => $result['response'],
            'tokens_used' => $result['tokens_used'] ?? 0,
        ]);
    }, 'ai.chat');

    // AI Completion endpoint
    Router::post('/api/ai/complete', function () {
        $request = Request::createFromGlobals();
        $prompt = $request->input('prompt', '');
        $system = $request->input('system', '');

        if (empty($prompt)) {
            return Response::json(['error' => 'Prompt is required'], 422);
        }

        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        try {
            $response = Ai::chat($prompt, [
                'system' => $system ?: null,
            ]);

            return Response::json([
                'success' => true,
                'content' => $response->content,
                'tokens_used' => $response->tokensUsed,
                'model' => $response->model,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }, 'ai.complete');

    // AI Summarize endpoint
    Router::post('/api/ai/summarize', function () {
        $request = Request::createFromGlobals();
        $text = $request->input('text', '');

        if (empty($text)) {
            return Response::json(['error' => 'Text is required'], 422);
        }

        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        try {
            $response = Ai::prompt('{{text}}', ['text' => $text], [
                'system' => 'Summarize the following text concisely:',
            ]);

            return Response::json([
                'success' => true,
                'summary' => $response->content,
                'original_length' => strlen($text),
                'summary_length' => strlen($response->content),
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }, 'ai.summarize');

    // AI Translate endpoint
    Router::post('/api/ai/translate', function () {
        $request = Request::createFromGlobals();
        $text = $request->input('text', '');
        $language = $request->input('language', 'English');

        if (empty($text)) {
            return Response::json(['error' => 'Text is required'], 422);
        }

        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        try {
            $response = Ai::prompt('{{text}}', [
                'text' => $text,
                'language' => $language,
            ], [
                'system' => 'Translate the following text accurately:',
            ]);

            return Response::json([
                'success' => true,
                'translation' => $response->content,
                'language' => $language,
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }, 'ai.translate');

    // AI Embed endpoint
    Router::post('/api/ai/embed', function () {
        $request = Request::createFromGlobals();
        $text = $request->input('text', '');

        if (empty($text)) {
            return Response::json(['error' => 'Text is required'], 422);
        }

        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        $embedding = Ai::embed($text);

        if ($embedding === null) {
            return Response::json(['error' => 'Embeddings not supported by provider'], 501);
        }

        return Response::json([
            'success' => true,
            'embedding' => $embedding,
            'dimensions' => count($embedding),
        ]);
    }, 'ai.embed');

    // AI Stats endpoint
    Router::get('/api/ai/stats', function () {
        if (!Ai::isConfigured()) {
            return Response::json(['error' => 'AI is not configured'], 503);
        }

        return Response::json([
            'success' => true,
            'stats' => Ai::getStats(),
            'providers' => Ai::getProviders(),
        ]);
    }, 'ai.stats');

});