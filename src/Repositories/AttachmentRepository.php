<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AttachmentRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Create a new attachment record and return the inserted ID.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO attachments (request_id, file_name, file_path, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['request_id'],
            $data['file_name'],
            $data['file_path'],
            $data['mime_type'],
            $data['file_size'],
            $data['uploaded_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find an attachment by its ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Find all attachments for a given request.
     */
    public function findByRequestId(int $requestId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE request_id = ? ORDER BY created_at DESC');
        $stmt->execute([$requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete an attachment by its ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM attachments WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Delete all attachments for a given request.
     */
    public function deleteByRequestId(int $requestId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM attachments WHERE request_id = ?');

        return $stmt->execute([$requestId]);
    }
}
