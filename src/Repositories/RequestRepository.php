<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class RequestRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Find a request by its ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Find a request by ID with its items, status history, and attachments.
     */
    public function findByIdWithDetails(int $id): ?array
    {
        // Fetch request with submitter and assignee names
        $stmt = $this->db->prepare(
            'SELECT r.*, u.full_name AS submitter_name, a.full_name AS assigned_to_name
             FROM requests r
             LEFT JOIN users u ON r.submitted_by = u.id
             LEFT JOIN users a ON r.assigned_to = a.id
             WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($request === false) {
            return null;
        }

        // Fetch items
        $itemsStmt = $this->db->prepare(
            'SELECT ri.*, if(ri.item_name is null or ri.item_name="",ii.name, ri.item_name) AS item_name
            ,ri.item_name as r_item_name, ii.name as i_item_name
             FROM request_items ri
             LEFT JOIN inventory_items ii ON ri.inventory_item_id = ii.id
             WHERE ri.request_id = ?'
        );
        $itemsStmt->execute([$id]);
        $request['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch status history with user names
        $historyStmt = $this->db->prepare(
            'SELECT rsh.*, u.full_name AS changed_by_name
             FROM request_status_history rsh
             LEFT JOIN users u ON rsh.changed_by = u.id
             WHERE rsh.request_id = ?
             ORDER BY rsh.created_at DESC'
        );
        $historyStmt->execute([$id]);
        $request['history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch attachments
        $attachStmt = $this->db->prepare('SELECT * FROM attachments WHERE request_id = ?');
        $attachStmt->execute([$id]);
        $request['attachments'] = $attachStmt->fetchAll(PDO::FETCH_ASSOC);

        return $request;
    }

    /**
     * Retrieve a paginated list of requests for a specific submitter with optional filters.
     *
     * Supported filters: type, status, priority, search (LIKE on title), date_from, date_to.
     * Returns ['data' => rows, 'total' => count].
     */
    public function findBySubmitter(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $conditions = ['r.submitted_by = ?'];
        $params = [$userId];

        $this->applyFilters($conditions, $params, $filters);

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM requests r {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare(
            "SELECT r.* FROM requests r {$where} ORDER BY r.created_at DESC LIMIT ? OFFSET ?"
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
     * Retrieve a paginated list of all requests with optional filters.
     *
     * Supported filters: type, status, priority, search (LIKE on title), date_from, date_to, assigned_to.
     * Joins users table to include submitter_name.
     * Returns ['data' => rows, 'total' => count].
     */
    public function findAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        $this->applyFilters($conditions, $params, $filters);

        if (isset($filters['assigned_to'])) {
            $conditions[] = 'r.assigned_to = ?';
            $params[] = (int) $filters['assigned_to'];
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM requests r {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare(
            "SELECT r.*, u.full_name AS submitter_name, a.full_name AS assigned_to_name
             FROM requests r
             LEFT JOIN users u ON r.submitted_by = u.id
             LEFT JOIN users a ON r.assigned_to = a.id
             {$where}
             ORDER BY FIELD(r.priority, 'urgent', 'high', 'medium', 'low'), r.created_at DESC
             LIMIT ? OFFSET ?"
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
     * Create a new request and return the inserted ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO requests (type, title, description, priority, status, submitted_by, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['type'],
            $data['title'],
            $data['description'] ?? null,
            $data['priority'] ?? 'medium',
            $data['status'] ?? 'draft',
            $data['submitted_by'],
            $data['due_date'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a request with the given data fields.
     *
     * Supported fields: title, description, priority, due_date, status, assigned_to.
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = ['title', 'description', 'priority', 'due_date', 'status', 'assigned_to'];
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
        $sql = 'UPDATE requests SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Update the status of a request.
     */
    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE requests SET status = ? WHERE id = ?');

        return $stmt->execute([$status, $id]);
    }

    /**
     * Assign a request to a staff member.
     */
    public function assignTo(int $id, int $staffId): bool
    {
        $stmt = $this->db->prepare('UPDATE requests SET assigned_to = ? WHERE id = ?');

        return $stmt->execute([$staffId, $id]);
    }

    /**
     * Add an item to a request and return the inserted ID.
     */
    public function addItem(int $requestId, array $item): int
    {
        $inventoryItemId = !empty($item['inventory_item_id']) ? (int) $item['inventory_item_id'] : null;

        $stmt = $this->db->prepare(
            'INSERT INTO request_items (request_id, inventory_item_id, item_name, quantity, unit, notes) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $inventoryItemId,
            $item['item_name'],
            $item['quantity'],
            $item['unit'] ?? null,
            $item['notes'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete all items for a request.
     */
    public function deleteItems(int $requestId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM request_items WHERE request_id = ?');

        return $stmt->execute([$requestId]);
    }

    /**
     * Add a status history entry and return the inserted ID.
     */
    public function addStatusHistory(int $requestId, ?string $oldStatus, string $newStatus, int $changedBy, ?string $comment = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO request_status_history (request_id, old_status, new_status, changed_by, comment) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $oldStatus,
            $newStatus,
            $changedBy,
            $comment,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Count requests grouped by status, optionally filtered by submitter.
     *
     * Returns an associative array like ['submitted' => 5, 'approved' => 3, ...].
     */
    public function countByStatus(?int $submitterId = null): array
    {
        $where = '';
        $params = [];

        if ($submitterId !== null) {
            $where = 'WHERE submitted_by = ?';
            $params[] = $submitterId;
        }

        $stmt = $this->db->prepare("SELECT status, COUNT(*) AS count FROM requests {$where} GROUP BY status");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Apply common filters for request queries.
     */
    private function applyFilters(array &$conditions, array &$params, array $filters): void
    {
        if (isset($filters['type'])) {
            $conditions[] = 'r.type = ?';
            $params[] = $filters['type'];
        }

        if (isset($filters['status'])) {
            $conditions[] = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if (isset($filters['priority'])) {
            $conditions[] = 'r.priority = ?';
            $params[] = $filters['priority'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = 'r.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['date_from'])) {
            $conditions[] = 'r.created_at >= ?';
            $params[] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $conditions[] = 'r.created_at <= ?';
            $params[] = $filters['date_to'];
        }
    }
}
