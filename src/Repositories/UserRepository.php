<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UserRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Find a user by their ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Find a user by their email address.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Create a new user and return the inserted ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash, full_name, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['email'],
            $data['password_hash'],
            $data['full_name'],
            $data['role'] ?? 'personnel',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update user profile fields.
     */
    public function update(int $id, array $data): bool
    {
        $allowedFields = ['full_name', 'email', 'role', 'is_active'];
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
        $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Count users grouped by role.
     */
    public function countByRole(): array
    {
        $stmt = $this->db->query('SELECT role, COUNT(*) AS count FROM users GROUP BY role');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['role']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * Reassign all open requests from one user to another (or unassign).
     */
    public function reassignRequests(int $fromUserId, ?int $toUserId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE requests SET assigned_to = ? WHERE assigned_to = ? AND status IN (\'submitted\', \'in_review\', \'approved\')'
        );
        $stmt->execute([$toUserId, $fromUserId]);

        return $stmt->rowCount();
    }

    /**
     * Update a user's password hash.
     */
    public function updatePassword(int $id, string $hash): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');

        return $stmt->execute([$hash, $id]);
    }

    /**
     * Update a user's role.
     */
    public function updateRole(int $id, string $role): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET role = ? WHERE id = ?');

        return $stmt->execute([$role, $id]);
    }

    /**
     * Record the current timestamp as the user's last login.
     */
    public function updateLastLogin(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Mark a user's email as verified.
     */
    public function verifyEmail(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Enable or disable two-factor authentication for a user.
     */
    public function setTwoFactorEnabled(int $id, bool $enabled): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET two_factor_enabled = ? WHERE id = ?');

        return $stmt->execute([(int) $enabled, $id]);
    }

    /**
     * Deactivate a user account.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Activate a user account.
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 1 WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Retrieve a paginated list of users with optional filters.
     *
     * Supported filters: role, is_active, search (LIKE on email/full_name).
     * Returns ['data' => rows, 'total' => count].
     */
    public function findAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['role'])) {
            $conditions[] = 'role = ?';
            $params[] = $filters['role'];
        }

        if (isset($filters['is_active'])) {
            $conditions[] = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(email LIKE ? OR full_name LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $where = '';
        if (!empty($conditions)) {
            $where = 'WHERE ' . implode(' AND ', $conditions);
        }

        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated data
        $dataStmt = $this->db->prepare("SELECT * FROM users {$where} LIMIT ? OFFSET ?");
        $dataParams = array_merge($params, [$limit, $offset]);

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
