<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Manages system settings stored as key-value pairs in the `settings` table.
 *
 * Uses an in-memory cache so that repeated reads within the same request
 * (e.g. MaintenanceModeMiddleware + pre-render) hit the database only once.
 */
class SettingsRepository
{
    /** @var array<string, string|null>|null In-memory cache (null = not loaded) */
    private ?array $cache = null;

    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Get a setting value by key. Returns the default if not found.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $this->loadCache();

        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    /**
     * Set a setting value (insert or update).
     */
    public function set(string $key, ?string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) AS new_row
             ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value'
        );
        $stmt->execute([$key, $value]);

        // Invalidate cache
        $this->cache = null;
    }

    /**
     * Get all settings as an associative array.
     */
    public function getAll(): array
    {
        $this->loadCache();

        return $this->cache;
    }

    /**
     * Set multiple settings at once.
     */
    public function setMany(array $settings): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) AS new_row
             ON DUPLICATE KEY UPDATE setting_value = new_row.setting_value'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        // Invalidate cache
        $this->cache = null;
    }

    /**
     * Delete a setting by key.
     */
    public function delete(string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM settings WHERE setting_key = ?');
        $result = $stmt->execute([$key]);

        // Invalidate cache
        $this->cache = null;

        return $result;
    }

    /**
     * Load all settings into the in-memory cache (once per request).
     */
    private function loadCache(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $stmt = $this->db->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->cache = [];
        foreach ($rows as $row) {
            $this->cache[$row['setting_key']] = $row['setting_value'];
        }
    }
}
