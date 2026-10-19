<?php

namespace Canyon\Core;

/**
 * Canyon Framework - AI Helper Trait
 *
 * Add AI capabilities to any controller or class.
 *
 * Usage:
 *  class MyController extends Controller {
 *    use AiHelper;
 *
 *    public function ask(): string {
 *      $answer = $this->ai()->chat('What is the meaning of life?');
 *      return $this->view('answer', ['text' => $answer->content]);
 *    }
 *  }
 */
trait AiHelper
{
  /** @var array Conversation memory per instance */
  protected array $aiConversations = [];

  /**
   * Get AI client instance.
   */
  protected function ai(): Ai
  {
    return new Ai();
  }

  /**
   * Quick AI chat - send a message and get response.
   */
  protected function aiChat(string $message, array $options = []): AiResponse
  {
    return Ai::chat($message, $options);
  }

  /**
   * AI chat with system prompt.
   */
  protected function aiChatWithRole(string $systemPrompt, string $message, array $options = []): AiResponse
  {
    return Ai::chat($message, array_merge($options, ['system' => $systemPrompt]));
  }

  /**
   * AI completion - simple prompt completion.
   */
  protected function aiComplete(string $prompt, array $options = []): AiResponse
  {
    return Ai::complete($prompt, $options);
  }

  /**
   * AI template - render prompt template and send.
   */
  protected function aiPrompt(string $template, array $variables = [], array $options = []): AiResponse
  {
    return Ai::prompt($template, $variables, $options);
  }

  /**
   * AI summarize text.
   */
  protected function aiSummarize(string $text, array $options = []): string
  {
    $response = Ai::prompt('{{text}}', ['text' => $text], array_merge($options, [
      'system' => 'You are a helpful assistant that summarizes text concisely.',
    ]));

    return $response->content;
  }

  /**
   * AI translate text.
   */
  protected function aiTranslate(string $text, string $targetLanguage, array $options = []): string
  {
    $response = Ai::prompt('{{text}}', [
      'text' => $text,
      'language' => $targetLanguage,
    ], array_merge($options, [
      'system' => 'You are a professional translator. Translate the text accurately.',
    ]));

    return $response->content;
  }

  /**
   * AI improve text.
   */
  protected function aiImprove(string $text, array $options = []): string
  {
    $response = Ai::prompt('{{text}}', ['text' => $text], array_merge($options, [
      'system' => 'Improve the following text for clarity, grammar, and style. Return only the improved text.',
    ]));

    return $response->content;
  }

  /**
   * AI explain concept.
   */
  protected function aiExplain(string $concept, array $options = []): string
  {
    $response = Ai::prompt('{{text}}', ['text' => $concept], array_merge($options, [
      'system' => 'Explain the following concept in simple terms that anyone can understand.',
    ]));

    return $response->content;
  }

  /**
   * AI generate code.
   */
  protected function aiGenerateCode(string $description, string $language = 'php', array $options = []): string
  {
    $response = Ai::prompt('{{text}}', [
      'text' => $description,
    ], array_merge($options, [
      'system' => "Generate clean, well-documented {$language} code. Return only the code, no explanations.",
    ]));

    return $response->content;
  }

  /**
   * AI review code.
   */
  protected function aiReviewCode(string $code, array $options = []): string
  {
    $response = Ai::prompt('{{code}}', ['code' => $code], array_merge($options, [
      'system' => 'Review the following code for bugs, security issues, and improvements. Be specific and constructive.',
    ]));

    return $response->content;
  }

  /**
   * AI generate SQL.
   */
  protected function aiGenerateSql(string $description, array $options = []): string
  {
    $response = Ai::prompt('{{text}}', ['text' => $description], array_merge($options, [
      'system' => 'Generate optimized SQL queries. Return only the SQL code with brief comments.',
    ]));

    return $response->content;
  }

  /**
   * AI embed text for vector search.
   */
  protected function aiEmbed(string $text, array $options = []): ?array
  {
    return Ai::embed($text, $options);
  }

  /**
   * AI calculate text similarity.
   */
  protected function aiSimilarity(string $text1, string $text2, array $options = []): float
  {
    return Ai::similarity($text1, $text2, $options);
  }

  /**
   * AI generate image.
   */
  protected function aiImage(string $prompt, array $options = []): ?AiResponse
  {
    return Ai::generateImage($prompt, $options);
  }

  /**
   * AI streaming response.
   */
  protected function aiStream(string $message, callable $callback, array $options = []): void
  {
    Ai::stream($message, $callback, $options);
  }

  /**
   * Check if AI is configured.
   */
  protected function aiAvailable(): bool
  {
    return Ai::isConfigured();
  }

  /**
   * Get AI usage stats.
   */
  protected function aiStats(): array
  {
    return Ai::getStats();
  }
}