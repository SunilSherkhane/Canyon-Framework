<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Validation
 *
 * Input validation with rule parsing, Laravel's validator.
 */
class Validation
{
  /** @var array<string, string> Field rules */
  protected array $rules = [];

  /** @var array<string, string> Custom error messages */
  protected array $messages = [];

  /** @var array<string, string> Validation errors */
  protected array $errors = [];

  /**
   * Create a new validator instance.
   *
   * @param array $data Data to validate
   * @param array $rules Validation rules
   * @param array $messages Custom error messages
   */
  public function __construct(array $data, array $rules, array $messages = [])
  {
    $this->rules = $rules;
    $this->messages = $messages;
    $this->validate($data);
  }

  /**
   * Run validation on data.
   */
  protected function validate(array $data): void
  {
    foreach ($this->rules as $field => $rules) {
      $value = $data[$field] ?? null;
      $ruleList = is_array($rules) ? $rules : explode('|', $rules);

      foreach ($ruleList as $rule) {
        $this->applyRule($field, $value, $rule, $data);
      }
    }
  }

  /**
   * Apply a single validation rule.
   */
  protected function applyRule(string $field, mixed $value, string $rule, array $data): void
  {
    $params = [];
    if (str_contains($rule, ':')) {
      [$rule, $paramStr] = explode(':', $rule, 2);
      $params = explode(',', $paramStr);
    }

    $isValid = match ($rule) {
      'required' => $value !== null && $value !== '',
      'string' => is_string($value),
      'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
      'float', 'numeric' => is_numeric($value),
      'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
      'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
      'min' => is_numeric($value) && $value >= (int) $params[0],
      'max' => is_numeric($value) && $value <= (int) $params[0],
      'min_length' => is_string($value) && strlen($value) >= (int) $params[0],
      'max_length' => is_string($value) && strlen($value) <= (int) $params[0],
      'in' => in_array($value, $params, true),
      'confirmed' => isset($data[$field . '_confirmed']) && $value === $data[$field . '_confirmed'],
      'date' => strtotime((string) $value) !== false,
      'json' => is_string($value) && json_decode($value) !== null,
      'array' => is_array($value),
      default => true,
    };

    if (!$isValid) {
      $this->errors[$field] = $this->messages[$field] ?? $this->defaultMessage($field, $rule, $params);
    }
  }

  /**
   * Get default error message for a failed rule.
   */
  protected function defaultMessage(string $field, string $rule, array $params): string
  {
    $field = str_replace('_', ' ', $field);
    return match ($rule) {
      'required' => "The {$field} field is required.",
      'string' => "The {$field} field must be a string.",
      'email' => "The {$field} field must be a valid email address.",
      'min' => "The {$field} field must be at least {$params[0]}.",
      'max' => "The {$field} field must not exceed {$params[0]}.",
      'min_length' => "The {$field} field must be at least {$params[0]} characters.",
      'max_length' => "The {$field} field must not exceed {$params[0]} characters.",
      default => "The {$field} field is invalid.",
    };
  }

  /**
   * Check if validation passed.
   */
  public function passes(): bool
  {
    return empty($this->errors);
  }

  /**
   * Check if validation failed.
   */
  public function fails(): bool
  {
    return !empty($this->errors);
  }

  /**
   * Get validation errors.
   */
  public function errors(): array
  {
    return $this->errors;
  }

  /**
   * Get the first error message.
   */
  public function firstError(): ?string
  {
    return $this->errors ? reset($this->errors) : null;
  }

  /**
   * Validate and throw exception on failure.
   *
   * @throws \InvalidArgumentException
   */
  public function validateOrFail(): void
  {
    if ($this->fails()) {
      throw new \InvalidArgumentException($this->firstError() ?? 'Validation failed.');
    }
  }
}