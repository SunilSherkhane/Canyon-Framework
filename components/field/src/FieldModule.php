<?php

namespace Canyon\Core\Field;

use Canyon\Core\Database;

/**
 * Field Module - Custom Field System
 *
 * Provides field types and fieldable entity support similar to Drupal's Field API.
 */
class FieldModule
{
    /**
     * Supported field types.
     */
    public const TYPES = [
        'text' => 'Plain text field',
        'text_long' => 'Long text field (textarea)',
        'text_with_summary' => 'Text with summary/teaser',
        'integer' => 'Integer number',
        'decimal' => 'Decimal/floating point',
        'float' => 'Floating point number',
        'date' => 'Date field',
        'datetime' => 'Date and time field',
        'boolean' => 'Yes/No checkbox',
        'select' => 'Select dropdown',
        'radio' => 'Radio buttons',
        'checkboxes' => 'Checkboxes',
        'list_string' => 'List of strings',
        'link' => 'Link field',
        'email' => 'Email field',
        'url' => 'URL field',
        'telephone' => 'Telephone number',
        'file' => 'File upload',
        'image' => 'Image upload',
        'entity_reference' => 'Reference to another content',
        'taxonomy_term' => 'Reference to taxonomy term',
    ];

    /**
     * Widget types.
     */
    public const WIDGETS = [
        'text_textfield' => 'Text field',
        'text_textarea' => 'Textarea',
        'text_timestamps' => 'Created/Updated timestamps',
        'number_number' => 'Number input',
        'number_float' => 'Float input',
        'datetime_datetime' => 'Date/Time picker',
        'datetime_date' => 'Date picker',
        'options_buttons' => 'Radio buttons',
        'options_checkboxes' => 'Checkboxes',
        'options_onoff' => 'On/Off switch',
        'options_select' => 'Select dropdown',
        'link_url' => 'URL input',
        'link_attributes' => 'Link with attributes',
        'file_file' => 'File upload',
        'image_image' => 'Image upload',
        'entity_reference_autocomplete' => 'Autocomplete reference',
    ];

    /**
     * Create a new field definition.
     */
    public static function createField(string $name, string $type, array $settings = []): int
    {
        return Database::insert('fields', [
            'name' => $name,
            'type' => $type,
            'settings' => json_encode($settings),
            'widget' => $settings['widget'] ?? 'text_textfield',
            'weight' => $settings['weight'] ?? 0,
            'required' => $settings['required'] ?? false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Attach a field to a content type.
     */
    public static function attach(string $fieldName, string $contentType, int $weight = 0): bool
    {
        return Database::insert('content_field_items', [
            'field_name' => $fieldName,
            'content_type' => $contentType,
            'weight' => $weight,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get fields for a content type.
     */
    public static function getFieldsForType(string $contentType): array
    {
        $result = Database::statement(
            'SELECT f.*, cfi.weight FROM fields f JOIN content_field_items cfi ON f.name = cfi.field_name WHERE cfi.content_type = ? ORDER BY cfi.weight ASC',
            [$contentType]
        );
        return $result;
    }

    /**
     * Get default fields (core fields).
     */
    public static function getDefaultFields(): array
    {
        return Database::statement(
            "SELECT * FROM fields WHERE name IN ('title', 'body', 'created', 'changed', 'status', 'uid') ORDER BY name"
        );
    }

    /**
     * Get field value for a content item.
     */
    public static function getValue(int $contentId, string $fieldName, int $langcode = 10): mixed
    {
        $result = Database::statement(
            'SELECT * FROM content_field_values WHERE content_id = ? AND field_name = ? AND langcode = ? LIMIT 1',
            [$contentId, $fieldName, $langcode]
        );
        return $result[0]['value'] ?? null;
    }

    /**
     * Set field value for a content item.
     */
    public static function setValue(int $contentId, string $fieldName, mixed $value, int $langcode = 10): bool
    {
        // Check if exists
        $existing = Database::statement(
            'SELECT id FROM content_field_values WHERE content_id = ? AND field_name = ? AND langcode = ?',
            [$contentId, $fieldName, $langcode]
        );

        $data = [
            'content_id' => $contentId,
            'field_name' => $fieldName,
            'langcode' => $langcode,
            'value' => is_scalar($value) ? $value : json_encode($value),
        ];

        if (!empty($existing)) {
            return Database::update('content_field_values', $data, ['id' => $existing[0]['id']]) > 0;
        }

        return Database::insert('content_field_values', $data) > 0;
    }

    /**
     * Delete field values for content.
     */
    public static function deleteValues(int $contentId): bool
    {
        return Database::delete('content_field_values', ['content_id' => $contentId]);
    }

    /**
     * Get formatted field value.
     */
    public static function formatValue(int $contentId, string $fieldName, string $format = 'default'): mixed
    {
        $value = self::getValue($contentId, $fieldName);
        $field = Database::statement('SELECT * FROM fields WHERE name = ?', [$fieldName]);

        if (empty($field) || empty($value)) {
            return null;
        }

        $field = $field[0];
        $type = $field['type'];

        return match ($type) {
            'date' => date('F j, Y', $value),
            'datetime' => date('F j, Y g:i a', $value),
            'link' => self::formatLink($value),
            'image' => self::formatImage($value),
            'email' => '<a href="mailto:' . e($value) . '">' . e($value) . '</a>',
            'url' => '<a href="' . e($value) . '" target="_blank">' . e($value) . '</a>',
            default => e($value),
        };
    }

    /**
     * Format a link value.
     */
    protected static function formatLink(string $value): string
    {
        $data = json_decode($value, true);
        if (!is_array($data) || !isset($data['url'])) {
            return e($value);
        }

        $url = $data['url'] ?? '';
        $title = $data['title'] ?? $url;
        $attributes = $data['attributes'] ?? [];

        $attrString = '';
        foreach ($attributes as $key => $val) {
            $attrString .= " {$key}=\"{$val}\"";
        }

        return "<a href=\"{$url}\"{$attrString}>" . e($title) . '</a>';
    }

    /**
     * Format an image value.
     */
    protected static function formatImage(string $value): string
    {
        $data = json_decode($value, true);
        if (!is_array($data) || !isset($data['fid'])) {
            return '';
        }

        $fid = $data['fid'];
        $style = $data['style'] ?? 'medium';
        $url = $data['url'] ?? '';

        return "<img src=\"{$url}\" alt=\"\" style=\"{$style}\" />";
    }
}