<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Centralized input sanitization helper.
 *
 * Provides methods for cleaning user input to prevent XSS,
 * trim whitespace, and normalize data.
 */
class InputSanitizer
{
    /**
     * Sanitize a single string value: trim and strip tags.
     */
    public static function sanitizeString(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(strip_tags($value));
    }

    /**
     * Sanitize a string but allow basic HTML (for rich text fields).
     * Strips dangerous tags/attributes while keeping formatting.
     */
    public static function sanitizeHtml(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $allowed = '<p><br><strong><em><b><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote>';

        return trim(strip_tags($value, $allowed));
    }

    /**
     * Sanitize an email address.
     */
    public static function sanitizeEmail(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $filtered = filter_var(trim($value), FILTER_SANITIZE_EMAIL);

        return $filtered ?: '';
    }

    /**
     * Sanitize an integer value.
     */
    public static function sanitizeInt(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        return $filtered !== false ? $filtered : null;
    }

    /**
     * Sanitize an array of values recursively.
     */
    public static function sanitizeArray(array $data, array $rules = []): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $rule = $rules[$key] ?? 'string';

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value, $rules[$key] ?? []);
            } elseif ($rule === 'html') {
                $sanitized[$key] = self::sanitizeHtml($value);
            } elseif ($rule === 'email') {
                $sanitized[$key] = self::sanitizeEmail($value);
            } elseif ($rule === 'int') {
                $sanitized[$key] = self::sanitizeInt($value);
            } elseif ($rule === 'raw') {
                $sanitized[$key] = $value; // No sanitization (e.g., passwords)
            } else {
                $sanitized[$key] = self::sanitizeString($value);
            }
        }

        return $sanitized;
    }
}
