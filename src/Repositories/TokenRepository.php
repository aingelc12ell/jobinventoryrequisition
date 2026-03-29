<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTime;
use PDO;

class TokenRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Create a new user token and return the inserted ID.
     */
    public function create(int $userId, string $tokenHash, string $type, DateTime $expiresAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_tokens (user_id, token_hash, type, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $tokenHash,
            $type,
            $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a valid (unused, non-expired) token by its hash and type.
     */
    public function findValidToken(string $tokenHash, string $type): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$tokenHash, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Mark a token as used by setting used_at to the current time.
     */
    public function markUsed(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = ?');

        return $stmt->execute([$id]);
    }

    /**
     * Delete all expired tokens and return the number of rows removed.
     */
    public function deleteExpired(): int
    {
        $stmt = $this->db->prepare('DELETE FROM user_tokens WHERE expires_at < NOW()');
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Save a two-factor authentication code for a user.
     */
    public function saveTwoFactorCode(int $userId, string $code, DateTime $expiresAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $code,
            $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a valid (unused, non-expired) two-factor code for a user.
     */
    public function findValidTwoFactorCode(int $userId, string $code): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM two_factor_codes WHERE user_id = ? AND code = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Mark a two-factor code as used by setting used_at to the current time.
     */
    public function markTwoFactorUsed(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE two_factor_codes SET used_at = NOW() WHERE id = ?');

        return $stmt->execute([$id]);
    }
}
