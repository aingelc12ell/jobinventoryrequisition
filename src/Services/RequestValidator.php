<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Stateless validator for request data and status transitions.
 *
 * Validates input fields on create/update and enforces the
 * request lifecycle state machine.
 */
class RequestValidator
{
    /**
     * Allowed status transitions keyed by current status.
     */
    private const array STATUS_TRANSITIONS = [
        'draft'     => ['submitted'],
        'submitted' => ['in_review', 'rejected', 'cancelled'],
        'in_review' => ['approved', 'rejected', 'cancelled'],
        'approved'  => ['completed', 'cancelled'],
        'rejected'  => [],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * Valid request types.
     */
    private const array VALID_TYPES = ['job', 'inventory'];

    /**
     * Valid priority levels.
     */
    private const array VALID_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * Validate request data for creation or update.
     *
     * @param  array $data     The request data to validate.
     * @param  bool  $isUpdate Whether this is an update (relaxes required-field checks).
     * @return array An array of error message strings (empty = valid).
     */
    public function validate(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Type validation
        if (!$isUpdate) {
            if (empty($data['type'])) {
                $errors[] = 'Type is required.';
            } elseif (!in_array($data['type'], self::VALID_TYPES, true)) {
                $errors[] = "Type must be one of: " . implode(', ', self::VALID_TYPES) . '.';
            }
        }

        // Title validation
        if (!isset($data['title']) || !is_string($data['title']) || trim($data['title']) === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($data['title']) < 3) {
            $errors[] = 'Title must be at least 3 characters.';
        } elseif (mb_strlen($data['title']) > 255) {
            $errors[] = 'Title must not exceed 255 characters.';
        }

        // Description validation
        if (!isset($data['description']) || !is_string($data['description']) || trim($data['description']) === '') {
            $errors[] = 'Description is required.';
        } elseif (mb_strlen($data['description']) < 10) {
            $errors[] = 'Description must be at least 10 characters.';
        }

        // Priority validation (optional)
        if (isset($data['priority']) && !in_array($data['priority'], self::VALID_PRIORITIES, true)) {
            $errors[] = "Priority must be one of: " . implode(', ', self::VALID_PRIORITIES) . '.';
        }

        // Due date validation (optional)
        if (isset($data['due_date']) && $data['due_date'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $data['due_date']);

            if (!$date || $date->format('Y-m-d') !== $data['due_date']) {
                $errors[] = 'Due date must be a valid date in Y-m-d format.';
            } elseif ($date < new \DateTimeImmutable('today')) {
                $errors[] = 'Due date must not be in the past.';
            }
        }

        // Items validation (required for inventory requests on create)
        if (!$isUpdate && isset($data['type']) && $data['type'] === 'inventory') {
            if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
                $errors[] = 'Inventory requests must include at least one item.';
            } else {
                foreach ($data['items'] as $index => $item) {
                    $position = $index + 1;

                    if (!isset($item['item_name']) || !is_string($item['item_name']) || trim($item['item_name']) === '') {
                        $errors[] = "Item #{$position}: item_name is required.";
                    }

                    if (!isset($item['quantity']) || !is_int($item['quantity']) || $item['quantity'] < 1) {
                        $errors[] = "Item #{$position}: quantity must be a positive integer.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Check whether a status transition is allowed.
     *
     * @param  string $currentStatus The current request status.
     * @param  string $newStatus     The desired new status.
     * @return bool True if the transition is valid.
     */
    public function validateStatusChange(string $currentStatus, string $newStatus): bool
    {
        $allowed = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * Get the list of valid next statuses for a given current status.
     *
     * @param  string $currentStatus The current request status.
     * @return array Array of valid next status strings.
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::STATUS_TRANSITIONS[$currentStatus] ?? [];
    }
}
