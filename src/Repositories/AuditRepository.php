<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AuditRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Log an audit event. JSON-encodes old_values and new_values if provided.
     */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (action, user_id, entity_type, entity_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $action,
            $userId,
            $entityType,
            $entityId,
            $oldValues !== null ? json_encode($oldValues, JSON_THROW_ON_ERROR) : null,
            $newValues !== null ? json_encode($newValues, JSON_THROW_ON_ERROR) : null,
            $ipAddress,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Retrieve a paginated list of audit log entries with optional filters.
     *
     * Supported filters: user_id, action, entity_type, date_from, date_to.
     * Returns ['data' => rows, 'total' => count].
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['user_id'])) {
            $conditions[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $conditions[] = 'action = ?';
            $params[] = $filters['action'];
        }

        if (isset($filters['entity_type'])) {
            $conditions[] = 'entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (isset($filters['date_from'])) {
            $conditions[] = 'created_at >= ?';
            $params[] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $conditions[] = 'created_at <= ?';
            $params[] = $filters['date_to'];
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare("SELECT * FROM audit_logs {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?");

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
}
