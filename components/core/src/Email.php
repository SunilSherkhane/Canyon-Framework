<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Email Service
 *
 * Email sending service with template support.
 * Laravel's Mail and Symfony Mailer.
 */
class Email
{
    /** @var array Config */
    protected static array $config = [];

    /** @var array Sent email log */
    protected static array $sent = [];

    /** @var array Queued emails */
    protected static array $queue = [];

    /**
     * Initialize email service.
     */
    public static function init(): void
    {
        self::$config = [
            'driver' => env('MAIL_DRIVER', 'smtp'),
            'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
            'port' => (int) env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME', ''),
            'password' => env('MAIL_PASSWORD', ''),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@canyon.local'),
                'name' => env('MAIL_FROM_NAME', 'Canyon Framework'),
            ],
        ];
    }

    /**
     * Send an email.
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML or plain text)
     * @param array $options Additional options (cc, bcc, attachments)
     * @return bool
     */
    public static function send(string $to, string $subject, string $body, array $options = []): bool
    {
        self::init();

        $options = array_merge([
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'cc' => [],
            'bcc' => [],
            'attachments' => [],
            'headers' => [],
        ], $options);

        // Log the email
        self::$sent[] = [
            'to' => $to,
            'subject' => $subject,
            'time' => time(),
            'options' => $options,
        ];

        // In production, use SMTP
        // In development, just log
        if (Application::isProduction()) {
            return self::sendViaSmtp($options);
        }

        return true;
    }

    /**
     * Queue an email for later sending.
     */
    public static function queue(string $to, string $subject, string $body, array $options = []): string
    {
        self::init();
        $id = uniqid('email_', true);

        self::$queue[$id] = [
            'id' => $id,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'options' => $options,
            'created_at' => time(),
        ];

        return $id;
    }

    /**
     * Process queued emails.
     */
    public static function processQueue(): array
    {
        $processed = [];

        foreach (self::$queue as $id => $email) {
            $success = self::send($email['to'], $email['subject'], $email['body'], $email['options']);
            $processed[] = [
                'id' => $id,
                'success' => $success,
            ];

            if ($success) {
                unset(self::$queue[$id]);
            }
        }

        return $processed;
    }

    /**
     * Send email using SMTP.
     */
    protected static function sendViaSmtp(array $options): bool
    {
        // In a real implementation, use PHPMailer or Symfony Mailer
        // For now, just log and return true
        $logEntry = sprintf(
            "[%s] EMAIL | To: %s | Subject: %s | Body: %s\n",
            date('Y-m-d H:i:s'),
            $options['to'],
            $options['subject'],
            substr($options['body'], 0, 100)
        );

        $logDir = CANYON_STORAGE_PATH . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        file_put_contents($logDir . '/email.log', $logEntry, FILE_APPEND | LOCK_EX);

        return true;
    }

    /**
     * Send a templated email.
     */
    public static function sendTemplate(string $to, string $template, array $data = []): bool
    {
        $templateFile = CANYON_RESOURCES_PATH . '/emails/' . $template . '.php';

        if (!file_exists($templateFile)) {
            return false;
        }

        // Extract data to variables
        extract($data);

        // Start output buffering
        ob_start();
        include $templateFile;
        $body = ob_get_clean();

        return self::send($to, $data['subject'] ?? 'No Subject', $body);
    }

    /**
     * Send a notification email.
     */
    public static function sendNotification(string $to, string $type, array $data = []): bool
    {
        $subject = match ($type) {
            'welcome' => 'Welcome to ' . env('APP_NAME', 'Canyon'),
            'password_reset' => 'Password Reset Request',
            'account_activated' => 'Account Activated',
            default => 'Notification from ' . env('APP_NAME', 'Canyon'),
        };

        $body = match ($type) {
            'welcome' => "Welcome, {$data['name']}! Your account has been created.",
            'password_reset' => "Click here to reset your password: {$data['link']}",
            'account_activated' => "Your account has been activated.",
            default => json_encode($data),
        };

        return self::send($to, $subject, $body);
    }

    /**
     * Get sent email log.
     */
    public static function getSent(): array
    {
        return self::$sent;
    }

    /**
     * Get queued emails.
     */
    public static function getQueue(): array
    {
        return self::$queue;
    }

    /**
     * Clear sent log.
     */
    public static function clearLog(): void
    {
        self::$sent = [];
    }
}