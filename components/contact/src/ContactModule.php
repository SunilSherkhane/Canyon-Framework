<?php

namespace Canyon\Core\Contact;

use Canyon\Core\Database;
use Canyon\Core\Email;
use Canyon\Core\Validation;

/**
 * Contact Module - Contact Forms
 *
 * Similar to Drupal's Contact module.
 * Provides contact forms, site-wide contact, and email delivery.
 */
class ContactModule
{
    /**
     * Create a contact form definition.
     */
    public static function createForm(array $data): int
    {
        return Database::insert('contact_forms', [
            'name' => $data['name'],
            'label' => $data['label'] ?? $data['name'],
            'recipients' => json_encode($data['recipients'] ?? []),
            'reply_to' => $data['reply_to'] ?? '',
            'status' => $data['status'] ?? 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get all contact forms.
     */
    public static function getForms(): array
    {
        return Database::statement('SELECT * FROM contact_forms WHERE status = 1 ORDER BY label');
    }

    /**
     * Get form by name.
     */
    public static function getForm(string $name): ?array
    {
        $result = Database::statement('SELECT * FROM contact_forms WHERE name = ? AND status = 1', [$name]);
        return $result[0] ?? null;
    }

    /**
     * Submit a contact form.
     */
    public static function submit(string $formName, array $data): array
    {
        $form = self::getForm($formName);
        if (!$form) {
            return ['success' => false, 'message' => 'Contact form not found'];
        }

        $validator = new Validation($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()];
        }

        $recipients = json_decode($form['recipients'], true);

        foreach ($recipients as $recipient) {
            Email::send([
                'to' => $recipient,
                'subject' => '[Contact] ' . $data['subject'],
                'body' => "Name: {$data['name']}\nEmail: {$data['email']}\n\n{$data['message']}",
                'from' => $data['email'],
            ]);
        }

        // Store submission
        Database::insert('contact_submissions', [
            'form_name' => $formName,
            'name' => $data['name'],
            'email' => $data['email'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => 'Your message has been sent.'];
    }

    /**
     * Get submissions for a form.
     */
    public static function getSubmissions(string $formName, int $limit = 50): array
    {
        return Database::statement(
            'SELECT * FROM contact_submissions WHERE form_name = ? ORDER BY created_at DESC LIMIT ?',
            [$formName, $limit]
        );
    }
}