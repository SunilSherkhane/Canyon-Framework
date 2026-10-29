<?php

namespace Canyon\Core;

/**
 * Canyon Framework - AI Chatbot Widget
 *
 * Drop-in chatbot widget for any page.
 * Supports multiple AI providers with automatic fallback.
 */
class AiChatbot
{
  /** @var array Chat configuration */
  protected static array $config = [];

  /** @var array Conversation history */
  protected static array $history = [];

  /** @var string Current session ID */
  protected static string $sessionId;

  /** @var bool Whether chatbot is initialized */
  protected static bool $initialized = false;

  /**
   * Initialize chatbot.
   */
  public static function init(): void
  {
    if (self::$initialized) {
      return;
    }

    self::$sessionId = session_id() ?: bin2hex(random_bytes(8));
    self::$history = [];
    self::$initialized = true;
  }

  /**
   * Register chatbot widget.
   */
  public static function register(string $position = 'bottom-right', array $options = []): void
  {
    self::init();

    self::$config = array_merge([
      'position' => $position,
      'title' => 'AI Assistant',
      'placeholder' => 'Ask me anything...',
      'welcome_message' => 'Hello! How can I help you today?',
      'primary_color' => '#3498db',
      'bot_avatar' => null,
      'user_avatar' => null,
      'max_messages' => 50,
      'show_timestamps' => true,
      'enable_voice' => false,
      'enable_attachments' => false,
    ], $options);

    // Register widget
    Widget::register('ai_chatbot', 'AI Chatbot', function ($data) {
      return self::render();
    }, [
      'access' => 'public',
      'position' => $position,
      'cache' => false,
    ]);
  }

  /**
   * Render chatbot HTML.
   */
  public static function render(): string
  {
    self::init();

    $config = htmlspecialchars(json_encode(self::$config), ENT_QUOTES, 'UTF-8');
    $sessionId = htmlspecialchars(self::$sessionId, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div id="ai-chatbot" class="ai-chatbot-container" data-session="{$sessionId}">
  <div class="ai-chatbot-header">
    <div class="ai-chatbot-avatar">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z" fill="#3498db"/>
        <path d="M12 6c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6-2.69-6-6-6z" fill="white"/>
      </svg>
    </div>
    <div class="ai-chatbot-title">AI Assistant</div>
    <button class="ai-chatbot-close" onclick="document.getElementById('ai-chatbot').style.display='none'">×</button>
  </div>
  <div class="ai-chatbot-messages" id="ai-chatbot-messages"></div>
  <div class="ai-chatbot-input">
    <input type="text" id="ai-chatbot-input" placeholder="Ask me anything..." onkeypress="if(event.key==='Enter')sendChatMessage()">
    <button class="ai-chatbot-send" onclick="sendChatMessage()">Send</button>
  </div>
</div>
<style>
.ai-chatbot-container {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 350px;
  height: 500px;
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  display: flex;
  flex-direction: column;
  z-index: 9999;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.ai-chatbot-header {
  padding: 15px;
  background: linear-gradient(135deg, #3498db, #2980b9);
  color: white;
  border-radius: 12px 12px 0 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ai-chatbot-avatar {
  width: 36px;
  height: 36px;
  background: white;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ai-chatbot-title { flex: 1; font-weight: 600; }
.ai-chatbot-close {
  background: none;
  border: none;
  color: white;
  font-size: 24px;
  cursor: pointer;
}
.ai-chatbot-messages {
  flex: 1;
  overflow-y: auto;
  padding: 15px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ai-chatbot-message {
  max-width: 80%;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 14px;
  line-height: 1.5;
}
.ai-chatbot-message.bot {
  background: #f0f2f5;
  align-self: flex-start;
  border-bottom-left-radius: 4px;
}
.ai-chatbot-message.user {
  background: #3498db;
  color: white;
  align-self: flex-end;
  border-bottom-right-radius: 4px;
}
.ai-chatbot-input {
  padding: 15px;
  border-top: 1px solid #eee;
  display: flex;
  gap: 10px;
}
.ai-chatbot-input input {
  flex: 1;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 20px;
  outline: none;
  font-size: 14px;
}
.ai-chatbot-input input:focus { border-color: #3498db; }
.ai-chatbot-send {
  padding: 10px 20px;
  background: #3498db;
  color: white;
  border: none;
  border-radius: 20px;
  cursor: pointer;
  font-size: 14px;
}
.ai-chatbot-send:hover { background: #2980b9; }
.ai-chatbot-typing {
  display: flex;
  gap: 4px;
  padding: 10px 14px;
  background: #f0f2f5;
  border-radius: 12px;
  align-self: flex-start;
}
.ai-chatbot-typing span {
  width: 8px;
  height: 8px;
  background: #999;
  border-radius: 50%;
  animation: typing 1s infinite;
}
.ai-chatbot-typing span:nth-child(2) { animation-delay: 0.2s; }
.ai-chatbot-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typing {
  0%, 60%, 100% { transform: translateY(0); }
  30% { transform: translateY(-10px); }
}
</style>
<script>
function sendChatMessage() {
  const input = document.getElementById('ai-chatbot-input');
  const message = input.value.trim();
  if (!message) return;

  const messagesContainer = document.getElementById('ai-chatbot-messages');

  // Add user message
  const userMsg = document.createElement('div');
  userMsg.className = 'ai-chatbot-message user';
  userMsg.textContent = message;
  messagesContainer.appendChild(userMsg);

  // Add typing indicator
  const typing = document.createElement('div');
  typing.className = 'ai-chatbot-typing';
  typing.innerHTML '<span></span><span></span><span></span>';
  typing.id = 'ai-chatbot-typing';
  messagesContainer.appendChild(typing);

  input.value = '';
  messagesContainer.scrollTop = messagesContainer.scrollHeight;

  // Send to server
  fetch('/api/ai/chat', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
    },
    body: JSON.stringify({
      message: message,
      session: '{$sessionId}'
    })
  })
  .then(r => r.json())
  .then(data => {
    typing.remove();
    const botMsg = document.createElement('div');
    botMsg.className = 'ai-chatbot-message bot';
    botMsg.textContent = data.response || 'Sorry, I could not process your request.';
    messagesContainer.appendChild(botMsg);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
  })
  .catch(() => {
    typing.remove();
    const botMsg = document.createElement('div');
    botMsg.className = 'ai-chatbot-message bot';
    botMsg.textContent = 'Sorry, there was an error. Please try again.';
    messagesContainer.appendChild(botMsg);
  });
}
</script>
HTML;
  }

  /**
   * Handle chat message (API endpoint).
   */
  public static function handleMessage(string $message, string $sessionId): array
  {
    self::init();

    // Reject prompt injection attempts
    $injection = self::detectPromptInjection($message);
    if ($injection) {
     Security::logEvent('ai_prompt_injection', [
      'session' => $sessionId,
      'pattern' => $injection,
     ], 'warning');
     return [
      'success' => false,
      'error' => 'Your message contains disallowed content. Please rephrase.',
     ];
    }

    // Add user message to history
    self::$history[] = [
      'role' => 'user',
      'content' => $message,
      'session' => $sessionId,
      'timestamp' => time(),
    ];

    // Get AI response
    try {
     $response = Ai::chat($message, [
      'system' => 'You are a helpful assistant embedded in a website. Be concise, friendly, and helpful.',
     ]);

     // Add bot response to history
     self::$history[] = [
      'role' => 'assistant',
      'content' => $response->content,
      'session' => $sessionId,
      'timestamp' => time(),
     ];

     return [
      'success' => true,
      'response' => $response->content,
      'tokens_used' => $response->tokensUsed,
     ];
    } catch (\Exception $e) {
     return [
      'success' => false,
      'error' => $e->getMessage(),
     ];
    }
  }

  /**
   * Detect common prompt injection patterns in user input.
   * Returns the matched pattern or null if clean.
   */
  protected static function detectPromptInjection(string $input): ?string
  {
    $lower = strtolower($input);

    $patterns = [
     'ignore previous',
     'ignore all previous',
     'you are now',
     'you will now',
     'system prompt',
     'disregard',
     'override',
     'pretend',
     'act as',
     'jailbreak',
     'developer mode',
     'dan mode',
     'do anything now',
     'bypass',
     'unfiltered',
     'no restrictions',
     'no rules',
     'forget your',
     'forget everything',
     'new instructions',
     'new directive',
     'ignore your',
     'reveal your',
     'show your',
     'print your',
     'output your',
     'repeat your',
     'say your',
     'tell me your',
     'what is your',
     'what are your',
     'describe your',
     'explain your',
    ];

    foreach ($patterns as $pattern) {
     if (str_contains($lower, $pattern)) {
      return $pattern;
     }
    }

    return null;
  }

  /**
   * Get conversation history.
   */
  public static function getHistory(string $sessionId = null): array
  {
    if ($sessionId) {
      return array_filter(self::$history, fn($msg) => $msg['session'] === $sessionId);
    }
    return self::$history;
  }

  /**
   * Clear conversation history.
   */
  public static function clearHistory(string $sessionId = null): void
  {
    if ($sessionId) {
      self::$history = array_filter(self::$history, fn($msg) => $msg['session'] !== $sessionId);
    } else {
      self::$history = [];
    }
  }

  /**
   * Get configuration.
   */
  public static function getConfig(): array
  {
    return self::$config;
  }

  /**
   * Update configuration.
   */
  public static function setConfig(array $config): void
  {
    self::$config = array_merge(self::$config, $config);
  }
}