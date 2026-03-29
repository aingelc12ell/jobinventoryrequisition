<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class InventoryRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Find an inventory item by its ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Find an inventory item by its SKU.
     */
    public function findBySku(string $sku): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM inventory_items WHERE sku = ?');
        $stmt->execute([$sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Retrieve a paginated list of inventory items with optional filters.
     *
     * Supported filters: category, is_active, search (LIKE on name/sku/description), low_stock.
     * Returns ['data' => rows, 'total' => count].
     */
    public function findAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['category'])) {
            $conditions[] = 'category = ?';
            $params[] = $filters['category'];
        }

        if (isset($filters['is_active'])) {
            $conditions[] = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(name LIKE ? OR sku LIKE ? OR description LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['low_stock'])) {
            $conditions[] = 'quantity_in_stock <= reorder_level AND reorder_level > 0';
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM inventory_items {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare(
            "SELECT * FROM inventory_items {$where} ORDER BY name ASC LIMIT ? OFFSET ?"
        );

        $paramIndex = 1;
        foreach ($params as $param) {
            $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $dataStmt->bindValue($paramIndex++, $param, $type);
        }
        $dataStmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
        $dataStmt->bindValue($paramIndex, $offset, PDO::PARAM_INT);

        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'total' => $total,
        ];
    }

    /**
     * Create a new inventory item and return the inserted ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_items (name, sku, category, description, unit, quantity_in_stock, reorder_level, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['sku'] ?? null,
            $data['category'] ?? null,
            $data['description'] ?? null,
            $data['unit'] ?? null,
            $data['quantity_in_stock'] ?? 0,
            $data['reorder_level'] ?? 0,
            $data['location'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an inventory item with the given data fields.
     *
     * Supported fields: name, sku, category, description, unit, reorder_level, location, is_active.
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = ['name', 'sku', 'category', 'description', 'unit', 'reorder_level', 'location', 'is_active'];
        $setClauses = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $params[] = $id;
        $sql = 'UPDATE inventory_items SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Set the absolute stock quantity for an inventory item.
     */
    public function updateStock(int $id, int $quantity): bool
    {
        $stmt = $this->db->prepare('UPDATE inventory_items SET quantity_in_stock = ? WHERE id = ?');

        return $stmt->execute([$quantity, $id]);
    }

    /**
     * Adjust stock quantity by a delta value (positive or negative).
     *
     * Prevents stock from going below zero using GREATEST().
     */
    public function adjustStock(int $id, int $delta): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE inventory_items SET quantity_in_stock = GREATEST(quantity_in_stock + ?, 0) WHERE id = ?'
        );

        return $stmt->execute([$delta, $id]);
    }

    /**
     * Deactivate an inventory item.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE inventory_items SET is_active = 0 WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Activate an inventory item.
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE inventory_items SET is_active = 1 WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Retrieve all active inventory items that are at or below their reorder level.
     *
     * Ordered by deficit (reorder_level - quantity_in_stock) descending.
     */
    public function getLowStockItems(): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM inventory_items
             WHERE quantity_in_stock <= reorder_level
               AND reorder_level > 0
               AND is_active = 1
             ORDER BY (reorder_level - quantity_in_stock) DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve a flat list of distinct category strings.
     */
    public function getCategories(): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL ORDER BY category'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Search active inventory items by name, SKU, category, or description for autocomplete.
     */
    public function searchByName(string $term, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, sku, category, description, unit, quantity_in_stock, reorder_level, location
             FROM inventory_items
             WHERE is_active = 1 AND (name LIKE ? OR sku LIKE ? OR category LIKE ? OR description LIKE ?)
             LIMIT ?'
        );

        $searchTerm = '%' . $term . '%';
        $stmt->bindValue(1, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(2, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(3, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(4, $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(5, $limit, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add an inventory transaction record and return the inserted ID.
     */
    public function addTransaction(int $itemId, string $type, int $quantity, int $performedBy, ?string $referenceType = null, ?int $referenceId = null, ?string $notes = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_transactions (inventory_item_id, type, quantity, performed_by, reference_type, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $itemId,
            $type,
            $quantity,
            $performedBy,
            $referenceType,
            $referenceId,
            $notes,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Retrieve paginated transactions for a specific inventory item.
     *
     * Joins users table to include performed_by_name.
     * Returns ['data' => rows, 'total' => count].
     */
    public function getTransactions(int $itemId, int $limit = 50, int $offset = 0): array
    {
        // Get total count
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM inventory_transactions WHERE inventory_item_id = ?'
        );
        $countStmt->execute([$itemId]);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare(
            'SELECT it.*, u.full_name AS performed_by_name
             FROM inventory_transactions it
             LEFT JOIN users u ON it.performed_by = u.id
             WHERE it.inventory_item_id = ?
             ORDER BY it.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $dataStmt->bindValue(1, $itemId, PDO::PARAM_INT);
        $dataStmt->bindValue(2, $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(3, $offset, PDO::PARAM_INT);

        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'total' => $total,
        ];
    }

    /**
     * Retrieve recent inventory transactions across all items.
     *
     * Joins inventory_items and users tables for item_name and performed_by_name.
     */
    public function getRecentTransactions(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT it.*, ii.name AS item_name, u.full_name AS performed_by_name
             FROM inventory_transactions it
             LEFT JOIN inventory_items ii ON it.inventory_item_id = ii.id
             LEFT JOIN users u ON it.performed_by = u.id
             ORDER BY it.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
